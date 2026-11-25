<?php
declare(strict_types=1);

/**
 * 1C user personal-discount snapshot endpoint.
 * Updates only discount fields for existing accounts and creates passwordless users.
 * The interactive admin file import remains available separately.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 3) . '/config/database.php';
require_once dirname(__DIR__, 3) . '/Models/Phone.php';

const ONEC_USERS_MAX_BODY = 20 * 1024 * 1024;
const ONEC_USERS_MAX_ROWS = 50000;
const ONEC_USERS_LOCK_NAME = 'japonika_1c_users_import';

final class OneCUsersApiProblem extends RuntimeException
{
    public int $httpStatus;
    public string $errorCode;

    public function __construct(int $httpStatus, string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
    }
}

function onecUsersRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function onecUsersRequestId(): string
{
    try { return 'users-' . bin2hex(random_bytes(12)); }
    catch (Throwable $e) { return 'users-' . str_replace('.', '', uniqid('', true)); }
}

function onecUsersAuthorizationHeader(): string
{
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) return trim((string)$_SERVER['HTTP_AUTHORIZATION']);
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    if (isset($_SERVER['HTTP_X_AUTHORIZATION'])) return trim((string)$_SERVER['HTTP_X_AUTHORIZATION']);
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) return trim((string)$value);
        }
    }
    return '';
}

function onecUsersLog(?PDO $db, string $result, string $message): void
{
    try {
        $db ??= getDBConnection();
        $stmt = $db->prepare('INSERT INTO exchange_logs (operation, result, message) VALUES (?, ?, ?)');
        $stmt->execute(['1c_users_api', $result, mb_substr($message, 0, 4000, 'UTF-8')]);
    } catch (Throwable $e) {
        error_log('1C users API exchange log write failed: ' . $e->getMessage());
    }
}

function onecUsersReleaseLock(?PDO $db, bool &$lockAcquired): void
{
    if (!$lockAcquired || !$db) return;
    try { $db->query("SELECT RELEASE_LOCK('" . ONEC_USERS_LOCK_NAME . "')"); }
    catch (Throwable $e) { error_log('1C users API lock release failed: ' . $e->getMessage()); }
    $lockAcquired = false;
}

/** Convert a 1C local date-time (no offset) to the site's Moscow-local DATETIME. */
function onecUsersDate(mixed $raw): ?string
{
    if ($raw === null || trim((string)$raw) === '') return null;
    $value = trim((string)$raw);
    $zone = new DateTimeZone('Europe/Moscow');
    foreach (['!Y-m-d\\TH:i:s', '!Y-m-d\\TH:i', '!Y-m-d H:i:s', '!d.m.Y H:i:s', '!d.m.Y G:i:s', '!d.m.Y H:i'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value, $zone);
        $issues = DateTimeImmutable::getLastErrors();
        if ($date && ($issues === false || ($issues['warning_count'] === 0 && $issues['error_count'] === 0))) {
            if ($date->format(ltrim($format, '!')) === $value) return $date->format('Y-m-d H:i:s');
        }
    }
    throw new OneCUsersApiProblem(422, 'invalid_discount_end_date', 'Некорректная дата окончания скидки: ' . $value);
}

/** Normalize the root-array schema used by the interactive users import. */
function onecUsersNormalize(string $json): array
{
    try { $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new OneCUsersApiProblem(400, 'invalid_json', 'Тело запроса не является корректным JSON.'); }
    if (!is_array($rows) || !array_is_list($rows)) throw new OneCUsersApiProblem(422, 'invalid_schema', 'Ожидается JSON-массив пользователей на верхнем уровне.');
    if (count($rows) > ONEC_USERS_MAX_ROWS) throw new OneCUsersApiProblem(422, 'too_many_users', 'В запросе допускается не более 50 000 записей.');

    $valid = [];
    $skipped = 0;
    $duplicates = 0;
    $multiplePhoneRows = 0;
    foreach ($rows as $index => $row) {
        if (!is_array($row)) { $skipped++; continue; }
        $phoneValue = $row['phone'] ?? null;
        if (!is_string($phoneValue) && !is_numeric($phoneValue)) { $skipped++; continue; }
        $phoneParts = preg_split('/[,;\\r\\n]+/u', trim((string)$phoneValue)) ?: [];
        $phoneCandidate = trim((string)($phoneParts[0] ?? ''));
        $phone = $phoneCandidate === '' ? null : Phone::normalizeRussian($phoneCandidate);
        if ($phone === null) { $skipped++; continue; }
        if (count($phoneParts) > 1) $multiplePhoneRows++;

        $discountRaw = $row['discount'] ?? 0;
        if ($discountRaw === null || (is_string($discountRaw) && trim($discountRaw) === '')) $discount = 0;
        elseif (is_int($discountRaw) || is_float($discountRaw) || (is_string($discountRaw) && preg_match('/^\\d{1,3}$/', trim($discountRaw)))) $discount = (int)$discountRaw;
        else { $skipped++; continue; }
        if ($discount < 0 || $discount > 100) { $skipped++; continue; }
        try { $endDate = onecUsersDate($row['discount_end_date'] ?? null); }
        catch (OneCUsersApiProblem $e) { $skipped++; continue; }
        if ($discount === 0 || $endDate === null) {
            $discount = 0;
            $endDate = null;
        }

        if (isset($valid[$phone])) $duplicates++;
        $valid[$phone] = ['phone' => $phone, 'discount' => $discount, 'discount_end_date' => $endDate, 'source_row' => $index + 1];
    }
    return [array_values($valid), count($rows), $duplicates, $multiplePhoneRows, $skipped];
}

$db = null;
$lockAcquired = false;
$inTransaction = false;
$requestId = onecUsersRequestId();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new OneCUsersApiProblem(405, 'method_not_allowed', 'Используйте HTTP-метод POST.');
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if (!$isHttps && $forwardedProto === 'https') $isHttps = true;
    if (!$isHttps) throw new OneCUsersApiProblem(400, 'https_required', 'Обмен разрешён только по HTTPS.');

    $secretPath = dirname(__DIR__, 4) . '/private/onec_api.php';
    if (!is_file($secretPath)) throw new RuntimeException('1C API secret file is not installed.');
    $secretConfig = require $secretPath;
    $expectedToken = is_array($secretConfig) ? (string)($secretConfig['token'] ?? '') : '';
    if (strlen($expectedToken) < 40 || str_contains($expectedToken, 'REPLACE_WITH')) throw new RuntimeException('1C API token is not configured or is too short.');
    $authorization = onecUsersAuthorizationHeader();
    if (!preg_match('/^Bearer\\s+(.+)$/i', $authorization, $authMatch) || !hash_equals($expectedToken, trim($authMatch[1]))) {
        throw new OneCUsersApiProblem(401, 'unauthorized', 'Отсутствует или неверен API-токен.');
    }

    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') throw new OneCUsersApiProblem(415, 'unsupported_media_type', 'Передайте Content-Type: application/json.');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > ONEC_USERS_MAX_BODY) throw new OneCUsersApiProblem(413, 'payload_too_large', 'Максимальный размер JSON — 20 МБ.');
    $rawBody = file_get_contents('php://input', false, null, 0, ONEC_USERS_MAX_BODY + 1);
    if ($rawBody === false || $rawBody === '') throw new OneCUsersApiProblem(400, 'empty_body', 'Тело запроса пустое или не читается.');
    if (strlen($rawBody) > ONEC_USERS_MAX_BODY) throw new OneCUsersApiProblem(413, 'payload_too_large', 'Максимальный размер JSON — 20 МБ.');
    [$users, $totalRows, $duplicates, $multiPhoneRows, $skippedRows] = onecUsersNormalize($rawBody);

    $db = getDBConnection();
    $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([ONEC_USERS_LOCK_NAME]);
    if ((int)$lock->fetchColumn() !== 1) throw new OneCUsersApiProblem(409, 'import_in_progress', 'Импорт пользователей уже выполняется. Повторите запрос позднее.');
    $lockAcquired = true;

    $db->beginTransaction();
    $inTransaction = true;
    $existingRows = $db->query('SELECT id, phone FROM users')->fetchAll(PDO::FETCH_ASSOC);
    $byPhone = [];
    foreach ($existingRows as $row) {
        $canonical = Phone::normalizeRussian((string)$row['phone']);
        if ($canonical !== null) $byPhone[$canonical][] = (int)$row['id'];
    }
    $update = $db->prepare('UPDATE users SET discount = ?, discount_end_date = ? WHERE id = ?');
    $insert = $db->prepare('INSERT INTO users (phone, name, password_hash, discount, discount_end_date, is_admin, is_blocked) VALUES (?, NULL, NULL, ?, ?, 0, 0)');
    $stats = ['created' => 0, 'updated' => 0, 'ambiguous' => 0, 'failed' => 0];
    foreach ($users as $user) {
        $phone = $user['phone'];
        $matches = $byPhone[$phone] ?? [];
        if (count($matches) > 1) {
            $stats['ambiguous']++;
            continue;
        }
        if (count($matches) === 1) {
            $update->execute([$user['discount'], $user['discount_end_date'], $matches[0]]);
            $stats['updated']++;
            continue;
        }
        try {
            $insert->execute([$phone, $user['discount'], $user['discount_end_date']]);
            $id = (int)$db->lastInsertId();
            $byPhone[$phone] = [$id];
            $stats['created']++;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            $stats['failed']++;
        }
    }
    $db->commit();
    $inTransaction = false;
    onecUsersReleaseLock($db, $lockAcquired);
    onecUsersLog($db, 'Ok', sprintf('request_id=%s received=%d applied=%d created=%d updated=%d ambiguous=%d duplicate_phones=%d multiple_phone_rows=%d skipped=%d failed=%d', $requestId, $totalRows, count($users), $stats['created'], $stats['updated'], $stats['ambiguous'], $duplicates, $multiPhoneRows, $skippedRows, $stats['failed']));
    onecUsersRespond(200, ['success' => true, 'request_id' => $requestId, 'received' => $totalRows, 'applied' => count($users), 'created' => $stats['created'], 'updated' => $stats['updated'], 'ambiguous' => $stats['ambiguous'], 'duplicate_phones' => $duplicates, 'multiple_phone_rows' => $multiPhoneRows, 'skipped' => $skippedRows, 'failed' => $stats['failed']]);
} catch (OneCUsersApiProblem $e) {
    if ($db && $inTransaction && $db->inTransaction()) $db->rollBack();
    onecUsersReleaseLock($db, $lockAcquired);
    onecUsersLog($db, 'Error', sprintf('request_id=%s code=%s message=%s', $requestId, $e->errorCode, $e->getMessage()));
    onecUsersRespond($e->httpStatus, ['success' => false, 'request_id' => $requestId, 'error' => $e->errorCode, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($db && $inTransaction && $db->inTransaction()) $db->rollBack();
    onecUsersReleaseLock($db, $lockAcquired);
    error_log('1C users API failure request_id=' . $requestId . ': ' . $e->getMessage());
    onecUsersLog($db, 'Error', 'request_id=' . $requestId . ' code=internal_error');
    onecUsersRespond(500, ['success' => false, 'request_id' => $requestId, 'error' => 'internal_error', 'message' => 'Внутренняя ошибка импорта. Сообщите request_id администратору.']);
}
