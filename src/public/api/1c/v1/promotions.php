<?php
declare(strict_types=1);

/**
 * 1C promotion snapshot endpoint. Applies only promotions present in the payload.
 * The interactive admin file import remains available separately.
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 3) . '/config/database.php';

const ONEC_PROMOTIONS_MAX_BODY = 10 * 1024 * 1024;
const ONEC_PROMOTIONS_MAX_ROWS = 2000;
const ONEC_PROMOTIONS_LOCK_NAME = 'japonika_1c_promotions_import';

final class OneCPromotionsApiProblem extends RuntimeException
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

function onecPromotionsRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function onecPromotionsRequestId(): string
{
    try { return 'promotions-' . bin2hex(random_bytes(12)); }
    catch (Throwable $e) { return 'promotions-' . str_replace('.', '', uniqid('', true)); }
}

function onecPromotionsAuthorizationHeader(): string
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

function onecPromotionsLog(?PDO $db, string $result, string $message): void
{
    try {
        $db ??= getDBConnection();
        $stmt = $db->prepare('INSERT INTO exchange_logs (operation, result, message) VALUES (?, ?, ?)');
        $stmt->execute(['1c_promotions_api', $result, mb_substr($message, 0, 4000, 'UTF-8')]);
    } catch (Throwable $e) {
        error_log('1C promotions API exchange log write failed: ' . $e->getMessage());
    }
}

function onecPromotionsReleaseLock(?PDO $db, bool &$lockAcquired): void
{
    if (!$lockAcquired || !$db) return;
    try { $db->query("SELECT RELEASE_LOCK('" . ONEC_PROMOTIONS_LOCK_NAME . "')"); }
    catch (Throwable $e) { error_log('1C promotions API lock release failed: ' . $e->getMessage()); }
    $lockAcquired = false;
}

function onecPromotionsDate(string $value, string $field): string
{
    $value = trim($value);
    $format = strlen($value) === 10 ? '!Y-m-d' : '!Y-m-d\\TH:i:s';
    $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('Europe/Moscow'));
    $errors = DateTimeImmutable::getLastErrors();
    $expected = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d\\TH:i:s';
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format($expected) !== $value) {
        throw new OneCPromotionsApiProblem(422, 'invalid_date', "Поле {$field}: ожидается YYYY-MM-DD либо YYYY-MM-DDTHH:MM:SS.");
    }
    return $date->format('Y-m-d');
}

/** Validate and resolve the same schema used by the interactive promotion import. */
function onecPromotionsNormalize(PDO $db, array $data): array
{
    if ((int)($data['schema_version'] ?? 0) !== 1 || !isset($data['promotions']) || !is_array($data['promotions']) || !array_is_list($data['promotions'])) {
        throw new OneCPromotionsApiProblem(422, 'invalid_schema', 'Ожидается JSON schema_version=1 и массив promotions.');
    }
    if (count($data['promotions']) < 1) throw new OneCPromotionsApiProblem(422, 'empty_promotions', 'Передайте непустой список акций.');
    if (count($data['promotions']) > ONEC_PROMOTIONS_MAX_ROWS) throw new OneCPromotionsApiProblem(422, 'too_many_promotions', 'В запросе допускается не более 2000 акций.');

    $normalized = [];
    $keys = [];
    foreach ($data['promotions'] as $index => $row) {
        $line = $index + 1;
        if (!is_array($row)) throw new OneCPromotionsApiProblem(422, 'invalid_promotion', "Акция {$line}: ожидался объект.");
        $type = (string)($row['type'] ?? '');
        if (!in_array($type, ['product_discount', 'quantity_discount'], true)) throw new OneCPromotionsApiProblem(422, 'invalid_promotion_type', "Акция {$line}: неверный type.");
        $title = trim((string)($row['title'] ?? ''));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 255) throw new OneCPromotionsApiProblem(422, 'invalid_title', "Акция {$line}: title обязателен и не длиннее 255 символов.");
        $discount = filter_var($row['discount_percent'] ?? null, FILTER_VALIDATE_INT);
        if ($discount === false || $discount < 1 || $discount > 99) throw new OneCPromotionsApiProblem(422, 'invalid_discount', "Акция {$line}: discount_percent должен быть целым числом от 1 до 99.");
        $start = onecPromotionsDate((string)($row['start_date'] ?? ''), "start_date акции {$line}");
        $end = onecPromotionsDate((string)($row['end_date'] ?? ''), "end_date акции {$line}");
        if ($end < $start) throw new OneCPromotionsApiProblem(422, 'invalid_period', "Акция {$line}: end_date раньше start_date.");
        $enabled = $row['enabled'] ?? null;
        if (!is_bool($enabled)) throw new OneCPromotionsApiProblem(422, 'invalid_enabled', "Акция {$line}: enabled должен быть true или false.");
        $minimum = 0;
        if ($type === 'quantity_discount') {
            $minimum = filter_var($row['min_quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($minimum === false || $minimum < 2 || $minimum > 100000) throw new OneCPromotionsApiProblem(422, 'invalid_min_quantity', "Акция {$line}: min_quantity должен быть целым числом от 2 до 100000.");
        }
        $guids = $row['product_onec_ids'] ?? null;
        if (!is_array($guids) || !array_is_list($guids) || !$guids || count($guids) > 5000) throw new OneCPromotionsApiProblem(422, 'invalid_products', "Акция {$line}: product_onec_ids должен быть непустым массивом.");
        $guids = array_values(array_unique(array_map(static fn($v) => strtolower(trim((string)$v)), $guids)));
        foreach ($guids as $guid) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $guid)) {
                throw new OneCPromotionsApiProblem(422, 'invalid_onec_id', "Акция {$line}: некорректный onec_id.");
            }
        }
        $marks = implode(',', array_fill(0, count($guids), '?'));
        $stmt = $db->prepare("SELECT id, LOWER(onec_id) AS onec_id, no_discount FROM products WHERE LOWER(onec_id) IN ({$marks})");
        $stmt->execute($guids);
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) $found[strtolower((string)$product['onec_id'])] = $product;
        $missing = array_values(array_diff($guids, array_keys($found)));
        if ($missing) throw new OneCPromotionsApiProblem(422, 'unknown_product', "Акция {$line}: в каталоге не найден onec_id " . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' …' : ''));
        foreach ($found as $guid => $product) {
            if ((int)$product['no_discount'] === 1) throw new OneCPromotionsApiProblem(422, 'discount_forbidden', "Акция {$line}: товар {$guid} запрещён для скидок.");
        }
        $productIds = [];
        foreach ($guids as $guid) $productIds[] = (int)$found[$guid]['id'];
        $importKey = 'json:title:' . hash('sha256', mb_strtolower($title, 'UTF-8'));
        if (isset($keys[$importKey])) throw new OneCPromotionsApiProblem(422, 'duplicate_title', "Акция {$line}: название повторяется в запросе.");
        $keys[$importKey] = true;
        $normalized[] = [
            'type' => $type,
            'title' => $title,
            'discount_percent' => $discount,
            'start_date' => $start,
            'end_date' => $end,
            'enabled' => $enabled,
            'min_quantity' => $minimum,
            'product_ids' => $productIds,
            'import_key' => $importKey,
        ];
    }
    return $normalized;
}

$db = null;
$lockAcquired = false;
$inTransaction = false;
$requestId = onecPromotionsRequestId();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new OneCPromotionsApiProblem(405, 'method_not_allowed', 'Используйте HTTP-метод POST.');
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if (!$isHttps && $forwardedProto === 'https') $isHttps = true;
    if (!$isHttps) throw new OneCPromotionsApiProblem(400, 'https_required', 'Обмен разрешён только по HTTPS.');

    $secretPath = dirname(__DIR__, 4) . '/private/onec_api.php';
    if (!is_file($secretPath)) throw new RuntimeException('1C API secret file is not installed.');
    $secretConfig = require $secretPath;
    $expectedToken = is_array($secretConfig) ? (string)($secretConfig['token'] ?? '') : '';
    if (strlen($expectedToken) < 40 || str_contains($expectedToken, 'REPLACE_WITH')) throw new RuntimeException('1C API token is not configured or is too short.');
    $authorization = onecPromotionsAuthorizationHeader();
    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $authMatch) || !hash_equals($expectedToken, trim($authMatch[1]))) {
        throw new OneCPromotionsApiProblem(401, 'unauthorized', 'Отсутствует или неверен API-токен.');
    }

    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') throw new OneCPromotionsApiProblem(415, 'unsupported_media_type', 'Передайте Content-Type: application/json.');
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > ONEC_PROMOTIONS_MAX_BODY) throw new OneCPromotionsApiProblem(413, 'payload_too_large', 'Максимальный размер JSON — 10 МБ.');
    $rawBody = file_get_contents('php://input', false, null, 0, ONEC_PROMOTIONS_MAX_BODY + 1);
    if ($rawBody === false || $rawBody === '') throw new OneCPromotionsApiProblem(400, 'empty_body', 'Тело запроса пустое или не читается.');
    if (strlen($rawBody) > ONEC_PROMOTIONS_MAX_BODY) throw new OneCPromotionsApiProblem(413, 'payload_too_large', 'Максимальный размер JSON — 10 МБ.');
    try { $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new OneCPromotionsApiProblem(400, 'invalid_json', 'Тело запроса не является корректным JSON.'); }
    if (!is_array($data) || array_is_list($data)) throw new OneCPromotionsApiProblem(422, 'invalid_schema', 'Ожидается JSON-объект акций.');

    $db = getDBConnection();
    $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([ONEC_PROMOTIONS_LOCK_NAME]);
    if ((int)$lock->fetchColumn() !== 1) throw new OneCPromotionsApiProblem(409, 'import_in_progress', 'Импорт акций уже выполняется. Повторите запрос позднее.');
    $lockAcquired = true;
    $promotions = onecPromotionsNormalize($db, $data);

    $db->beginTransaction();
    $inTransaction = true;
    $find = $db->prepare('SELECT id FROM promos WHERE import_key = ? LIMIT 1');
    $legacyFind = $db->prepare('SELECT id FROM promos WHERE import_key IN (?, ?) ORDER BY id LIMIT 1');
    $update = $db->prepare('UPDATE promos SET name=?,discount=?,start_date=?,end_date=?,promotion_type=?,minimum_quantity=?,enabled=?,import_key=? WHERE id=?');
    $insert = $db->prepare('INSERT INTO promos (name,discount,start_date,end_date,promotion_type,minimum_quantity,enabled,import_key) VALUES (?,?,?,?,?,?,?,?)');
    $stats = ['created' => 0, 'updated' => 0, 'links' => 0];
    foreach ($promotions as $promotion) {
        $find->execute([$promotion['import_key']]);
        $id = $find->fetchColumn();
        if ($id === false) {
            $legacyKeys = [];
            foreach (['product_discount', 'quantity_discount'] as $legacyType) {
                $legacyKeys[] = 'json:' . hash('sha256', $legacyType . "\0" . 'title:' . mb_strtolower($promotion['title'], 'UTF-8'));
            }
            $legacyFind->execute($legacyKeys);
            $id = $legacyFind->fetchColumn();
        }
        $startAt = $promotion['start_date'] . ' 00:00:00';
        $endAt = $promotion['end_date'] . ' 23:59:59';
        if ($id !== false) {
            $promoId = (int)$id;
            $update->execute([$promotion['title'], $promotion['discount_percent'], $startAt, $endAt, $promotion['type'], $promotion['min_quantity'], (int)$promotion['enabled'], $promotion['import_key'], $promoId]);
            $stats['updated']++;
        } else {
            $insert->execute([$promotion['title'], $promotion['discount_percent'], $startAt, $endAt, $promotion['type'], $promotion['min_quantity'], (int)$promotion['enabled'], $promotion['import_key']]);
            $promoId = (int)$db->lastInsertId();
            $stats['created']++;
        }
        $db->prepare('DELETE FROM product_promos WHERE promo_id = ?')->execute([$promoId]);
        $link = $db->prepare('INSERT INTO product_promos (product_id, promo_id) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $promotion['product_ids'])) as $productId) {
            $link->execute([$productId, $promoId]);
            $stats['links']++;
        }
    }
    $db->commit();
    $inTransaction = false;
    onecPromotionsReleaseLock($db, $lockAcquired);
    onecPromotionsLog($db, 'Ok', sprintf('request_id=%s received=%d created=%d updated=%d product_links=%d', $requestId, count($promotions), $stats['created'], $stats['updated'], $stats['links']));
    onecPromotionsRespond(200, ['success' => true, 'request_id' => $requestId, 'received' => count($promotions), 'created' => $stats['created'], 'updated' => $stats['updated'], 'product_links' => $stats['links']]);
} catch (OneCPromotionsApiProblem $e) {
    if ($db && $inTransaction && $db->inTransaction()) $db->rollBack();
    onecPromotionsReleaseLock($db, $lockAcquired);
    onecPromotionsLog($db, 'Error', sprintf('request_id=%s code=%s message=%s', $requestId, $e->errorCode, $e->getMessage()));
    onecPromotionsRespond($e->httpStatus, ['success' => false, 'request_id' => $requestId, 'error' => $e->errorCode, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($db && $inTransaction && $db->inTransaction()) $db->rollBack();
    onecPromotionsReleaseLock($db, $lockAcquired);
    error_log('1C promotions API failure request_id=' . $requestId . ': ' . $e->getMessage());
    onecPromotionsLog($db, 'Error', 'request_id=' . $requestId . ' code=internal_error');
    onecPromotionsRespond(500, ['success' => false, 'request_id' => $requestId, 'error' => 'internal_error', 'message' => 'Внутренняя ошибка импорта. Сообщите request_id администратору.']);
}
