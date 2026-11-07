<?php
declare(strict_types=1);

/** DB-backed throttling for unauthenticated registration and login attempts. */
final class AuthRateLimiter
{
    private PDO $db;
    private const WINDOW_SECONDS = 600;
    private const PHONE_IP_LIMIT = 5;
    private const IP_LIMIT = 20;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    private function clientIp(): string
    {
        // REMOTE_ADDR only: forwarded headers are client-controlled unless a trusted proxy is configured.
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return strlen($ip) <= 64 ? $ip : 'unknown';
    }

    private function buckets(string $action, string $identity): array
    {
        $ipHash = hash('sha256', $this->clientIp());
        $identity = trim(function_exists('mb_substr') ? mb_substr($identity, 0, 120, 'UTF-8') : substr($identity, 0, 120));
        $identityHash = hash('sha256', $identity);
        return [
            ['type' => 'pair', 'hash' => hash('sha256', $ipHash . ':' . $identityHash), 'limit' => self::PHONE_IP_LIMIT],
            ['type' => 'ip', 'hash' => $ipHash, 'limit' => self::IP_LIMIT],
        ];
    }

    /** Seconds remaining in a lock, or zero when another attempt is allowed. */
    public function blockedSeconds(string $action, string $identity): int
    {
        $action = $this->validAction($action);
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), blocked_until))), 0)
            FROM auth_rate_limits WHERE action_name = ? AND bucket_type = ? AND bucket_hash = ? AND blocked_until > UTC_TIMESTAMP()");
        $remaining = 0;
        foreach ($this->buckets($action, $identity) as $bucket) {
            $stmt->execute([$action, $bucket['type'], $bucket['hash']]);
            $remaining = max($remaining, (int)$stmt->fetchColumn());
        }
        return $remaining;
    }

    /** Record a failed POST. Five attempts for the same IP+identifier, or 20 from one IP,
     * trigger a ten-minute pause. Registration and login have separate counters. */
    public function recordFailure(string $action, string $identity): void
    {
        $action = $this->validAction($action);
        $now = time();
        $windowStart = gmdate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS);
        $nowUtc = gmdate('Y-m-d H:i:s', $now);
        $blockedUntil = gmdate('Y-m-d H:i:s', $now + self::WINDOW_SECONDS);

        foreach ($this->buckets($action, $identity) as $bucket) {
            $this->db->beginTransaction();
            try {
                $insert = $this->db->prepare('INSERT IGNORE INTO auth_rate_limits (action_name, bucket_type, bucket_hash, attempts, window_started_at, blocked_until) VALUES (?, ?, ?, 0, ?, NULL)');
                $insert->execute([$action, $bucket['type'], $bucket['hash'], $nowUtc]);
                $select = $this->db->prepare('SELECT attempts, window_started_at, blocked_until FROM auth_rate_limits WHERE action_name = ? AND bucket_type = ? AND bucket_hash = ? FOR UPDATE');
                $select->execute([$action, $bucket['type'], $bucket['hash']]);
                $row = $select->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new RuntimeException('Rate limit bucket could not be locked.');

                // Do not extend a lock due to requests arriving during the lock.
                if (!empty($row['blocked_until']) && strtotime((string)$row['blocked_until'] . ' UTC') > $now) {
                    $this->db->commit();
                    continue;
                }
                $windowExpired = strtotime((string)$row['window_started_at'] . ' UTC') <= $now - self::WINDOW_SECONDS;
                $attempts = $windowExpired ? 1 : ((int)$row['attempts'] + 1);
                $started = $windowExpired ? $nowUtc : (string)$row['window_started_at'];
                $lockUntil = $attempts >= $bucket['limit'] ? $blockedUntil : null;
                $update = $this->db->prepare('UPDATE auth_rate_limits SET attempts = ?, window_started_at = ?, blocked_until = ?, updated_at = UTC_TIMESTAMP() WHERE action_name = ? AND bucket_type = ? AND bucket_hash = ?');
                $update->execute([$attempts, $started, $lockUntil, $action, $bucket['type'], $bucket['hash']]);
                $this->db->commit();
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
        }

        // Opportunistic cleanup; only old anonymized hashes are removed.
        if (random_int(1, 100) === 1) {
            $this->db->exec('DELETE FROM auth_rate_limits WHERE window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)');
        }
    }

    /** Clear only this IP+identifier bucket after success; shared-IP limits remain intact. */
    public function clearPair(string $action, string $identity): void
    {
        $action = $this->validAction($action);
        foreach ($this->buckets($action, $identity) as $bucket) {
            if ($bucket['type'] !== 'pair') continue;
            $stmt = $this->db->prepare('DELETE FROM auth_rate_limits WHERE action_name = ? AND bucket_type = ? AND bucket_hash = ?');
            $stmt->execute([$action, $bucket['type'], $bucket['hash']]);
        }
    }

    private function validAction(string $action): string
    {
        if (!in_array($action, ['register', 'login'], true)) throw new InvalidArgumentException('Unsupported rate-limit action.');
        return $action;
    }
}
