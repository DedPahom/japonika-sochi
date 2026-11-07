<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class CustomerPricing
{
    /** Return the active personal discount for the logged-in customer (or admin account). */
    public static function currentDiscount(): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $userId = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0);
        if ($userId < 1) return 0;

        $db = getDBConnection();
        $stmt = $db->prepare('SELECT discount, discount_end_date, is_blocked FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int)$user['is_blocked'] === 1) return 0;

        $discount = max(0, min(100, (int)$user['discount']));
        $rawEnd = trim((string)($user['discount_end_date'] ?? ''));
        // A positive discount without an end date is not active; the importer sets it to 0.
        if ($discount === 0 || $rawEnd === '') return 0;

        $zone = new DateTimeZone('Europe/Moscow');
        $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $rawEnd, $zone);
        $issues = DateTimeImmutable::getLastErrors();
        if (!$end || ($issues !== false && ($issues['warning_count'] > 0 || $issues['error_count'] > 0))) return 0;
        $now = new DateTimeImmutable('now', $zone);
        return $end >= $now ? $discount : 0;
    }
}
