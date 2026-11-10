<?php
declare(strict_types=1);

/**
 * 1C stock snapshot endpoint.
 * Deploy as: src/public/api/1c/v1/stocks.php
 * Secret file: src/private/onec_api.php (outside the public web root).
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 3) . '/config/database.php';

const ONEC_STOCKS_MAX_BODY = 2 * 1024 * 1024;
const ONEC_STOCKS_MAX_PRODUCTS = 5000;
const ONEC_STOCKS_LOCK_NAME = 'japonika_1c_stock_snapshot';

final class OneCStocksApiProblem extends RuntimeException
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

function onecStocksRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function onecStocksRequestId(): string
{
    try {
        return 'stock-' . bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        return 'stock-' . str_replace('.', '', uniqid('', true));
    }
}

function onecStocksAuthorizationHeader(): string
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

function onecStocksLog(?PDO $db, string $result, string $message): void
{
    try {
        $db ??= getDBConnection();
        $stmt = $db->prepare('INSERT INTO exchange_logs (operation, result, message) VALUES (?, ?, ?)');
        $stmt->execute(['1c_stocks_api', $result, mb_substr($message, 0, 4000, 'UTF-8')]);
    } catch (Throwable $e) {
        // Logging must never turn a successful stock update into an apparent API failure.
        error_log('1C stock API exchange log write failed: ' . $e->getMessage());
    }
}

function onecStocksReleaseLock(?PDO $db, bool &$lockAcquired): void
{
    if (!$lockAcquired || !$db) return;
    try {
        $db->query("SELECT RELEASE_LOCK('" . ONEC_STOCKS_LOCK_NAME . "')");
    } catch (Throwable $e) {
        error_log('1C stock API lock release failed: ' . $e->getMessage());
    }
    $lockAcquired = false;
}

$db = null;
$lockAcquired = false;
$inTransaction = false;
$requestId = onecStocksRequestId();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new OneCStocksApiProblem(405, 'method_not_allowed', 'Используйте HTTP-метод POST.');
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['REQUEST_SCHEME'] ?? '') === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if (!$isHttps && $forwardedProto === 'https') $isHttps = true;
    if (!$isHttps) {
        throw new OneCStocksApiProblem(400, 'https_required', 'Обмен разрешён только по HTTPS.');
    }

    $secretPath = dirname(__DIR__, 4) . '/private/onec_api.php';
    if (!is_file($secretPath)) {
        throw new RuntimeException('1C API secret file is not installed.');
    }
    $secretConfig = require $secretPath;
    $expectedToken = is_array($secretConfig) ? (string)($secretConfig['token'] ?? '') : '';
    if (strlen($expectedToken) < 40 || str_contains($expectedToken, 'REPLACE_WITH')) {
        throw new RuntimeException('1C API token is not configured or is too short.');
    }
    $authorization = onecStocksAuthorizationHeader();
    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $authMatch)
        || !hash_equals($expectedToken, trim($authMatch[1]))) {
        throw new OneCStocksApiProblem(401, 'unauthorized', 'Отсутствует или неверен API-токен.');
    }

    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        throw new OneCStocksApiProblem(415, 'unsupported_media_type', 'Передайте тело запроса с Content-Type: application/json.');
    }
    $declaredLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declaredLength > ONEC_STOCKS_MAX_BODY) {
        throw new OneCStocksApiProblem(413, 'payload_too_large', 'Максимальный размер JSON — 2 МБ.');
    }
    $rawBody = file_get_contents('php://input', false, null, 0, ONEC_STOCKS_MAX_BODY + 1);
    if ($rawBody === false) throw new OneCStocksApiProblem(400, 'empty_body', 'Не удалось прочитать тело запроса.');
    if (strlen($rawBody) > ONEC_STOCKS_MAX_BODY) {
        throw new OneCStocksApiProblem(413, 'payload_too_large', 'Максимальный размер JSON — 2 МБ.');
    }
    if ($rawBody === '') throw new OneCStocksApiProblem(400, 'empty_body', 'Тело запроса пустое.');

    try {
        $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new OneCStocksApiProblem(400, 'invalid_json', 'Тело запроса не является корректным JSON.');
    }
    if (!is_array($data) || (int)($data['schema_version'] ?? 0) !== 1 || !isset($data['products']) || !is_array($data['products'])) {
        throw new OneCStocksApiProblem(422, 'invalid_schema', 'Ожидается объект JSON с schema_version=1 и массивом products.');
    }
    $receivedCount = count($data['products']);
    if ($receivedCount < 1) {
        throw new OneCStocksApiProblem(422, 'empty_snapshot', 'Пустой снимок отклонён: остатки не изменены. Передайте полный непустой список товаров.');
    }
    if ($receivedCount > ONEC_STOCKS_MAX_PRODUCTS) {
        throw new OneCStocksApiProblem(422, 'too_many_products', 'В снимке допускается не более 5000 товаров.');
    }
    if (isset($data['request_id'])) {
        $providedRequestId = trim((string)$data['request_id']);
        if (!preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $providedRequestId)) {
            throw new OneCStocksApiProblem(422, 'invalid_request_id', 'request_id может содержать только латинские буквы, цифры, точку, подчёркивание, двоеточие и дефис (до 100 символов).');
        }
        $requestId = $providedRequestId;
    }

    $snapshot = [];
    foreach ($data['products'] as $index => $row) {
        $line = $index + 1;
        if (!is_array($row)) {
            throw new OneCStocksApiProblem(422, 'invalid_product', "Строка {$line}: ожидался объект товара.");
        }
        $onecId = strtolower(trim((string)($row['onec_id'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $onecId)) {
            throw new OneCStocksApiProblem(422, 'invalid_onec_id', "Строка {$line}: некорректный onec_id; ожидается GUID.");
        }
        if (array_key_exists($onecId, $snapshot)) {
            throw new OneCStocksApiProblem(422, 'duplicate_onec_id', "Строка {$line}: onec_id {$onecId} повторяется в полном снимке.");
        }
        $quantityValue = $row['quantity'] ?? null;
        if (is_int($quantityValue)) {
            $quantity = $quantityValue;
        } elseif (is_string($quantityValue) && preg_match('/^\d{1,10}$/', trim($quantityValue))) {
            $quantity = (int)trim($quantityValue);
        } else {
            throw new OneCStocksApiProblem(422, 'invalid_quantity', "Строка {$line}: quantity должен быть целым неотрицательным числом.");
        }
        if ($quantity < 0 || $quantity > 2147483647) {
            throw new OneCStocksApiProblem(422, 'invalid_quantity', "Строка {$line}: quantity вне диапазона поля базы данных.");
        }
        $snapshot[$onecId] = $quantity;
    }

    $db = getDBConnection();
    $lockQuery = $db->prepare('SELECT GET_LOCK(?, 0)');
    $lockQuery->execute([ONEC_STOCKS_LOCK_NAME]);
    if ((int)$lockQuery->fetchColumn() !== 1) {
        throw new OneCStocksApiProblem(409, 'exchange_in_progress', 'Другой обмен остатками уже выполняется. Повторите запрос позднее.');
    }
    $lockAcquired = true;

    $guids = array_keys($snapshot);
    $placeholders = implode(',', array_fill(0, count($guids), '?'));
    $find = $db->prepare("SELECT id, LOWER(onec_id) AS onec_id FROM products WHERE onec_id IN ({$placeholders})");
    $find->execute($guids);
    $knownRows = $find->fetchAll(PDO::FETCH_ASSOC);
    $knownByGuid = [];
    foreach ($knownRows as $row) $knownByGuid[(string)$row['onec_id']] = (int)$row['id'];
    if (!$knownByGuid) {
        throw new OneCStocksApiProblem(409, 'no_known_products', 'Ни один onec_id из снимка не найден в каталоге. Остатки не изменены; проверьте сопоставление GUID.');
    }

    $unknownIds = array_values(array_diff($guids, array_keys($knownByGuid)));
    $knownGuids = array_keys($knownByGuid);
    $knownMarks = implode(',', array_fill(0, count($knownGuids), '?'));

    $db->beginTransaction();
    $inTransaction = true;

    $update = $db->prepare('UPDATE products SET quantity = ? WHERE id = ?');
    $changedFromSnapshot = 0;
    foreach ($knownByGuid as $guid => $productId) {
        $update->execute([$snapshot[$guid], $productId]);
        $changedFromSnapshot += $update->rowCount();
    }

    $countMissing = $db->prepare("SELECT COUNT(*) FROM products WHERE onec_id IS NOT NULL AND onec_id <> '' AND onec_id NOT IN ({$knownMarks})");
    $countMissing->execute($knownGuids);
    $missingFromSnapshot = (int)$countMissing->fetchColumn();

    $zeroMissing = $db->prepare("UPDATE products SET quantity = 0 WHERE onec_id IS NOT NULL AND onec_id <> '' AND onec_id NOT IN ({$knownMarks})");
    $zeroMissing->execute($knownGuids);
    $changedToZero = $zeroMissing->rowCount();

    $db->commit();
    $inTransaction = false;
    onecStocksReleaseLock($db, $lockAcquired);

    $unknownForLog = count($unknownIds) > 0 ? ' unknown_ids=' . implode(',', array_slice($unknownIds, 0, 50)) : '';
    $summary = sprintf(
        'request_id=%s received=%d matched=%d unknown=%d absent_from_snapshot=%d changed_from_snapshot=%d changed_to_zero=%d%s',
        $requestId,
        $receivedCount,
        count($knownByGuid),
        count($unknownIds),
        $missingFromSnapshot,
        $changedFromSnapshot,
        $changedToZero,
        $unknownForLog
    );
    onecStocksLog($db, 'Ok', $summary);

    onecStocksRespond(200, [
        'success' => true,
        'request_id' => $requestId,
        'received' => $receivedCount,
        'matched' => count($knownByGuid),
        'unknown_ids' => $unknownIds,
        'products_absent_from_snapshot' => $missingFromSnapshot,
        'stocks_changed_from_snapshot' => $changedFromSnapshot,
        'stocks_changed_to_zero' => $changedToZero,
    ]);
} catch (OneCStocksApiProblem $e) {
    if ($db && $inTransaction && $db->inTransaction()) $db->rollBack();
    onecStocksReleaseLock($db, $lockAcquired);
    onecStocksLog($db, 'Error', sprintf('request_id=%s code=%s message=%s', $requestId, $e->errorCode, $e->getMessage()));
    onecStocksRespond($e->httpStatus, [
        'success' => false,
        'request_id' => $requestId,
        'error' => $e->errorCode,
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    if ($db && $inTransaction && $db->inTransaction()) $db->rollBack();
    onecStocksReleaseLock($db, $lockAcquired);
    error_log('1C stock API failure request_id=' . $requestId . ': ' . $e->getMessage());
    onecStocksLog($db, 'Error', 'request_id=' . $requestId . ' code=internal_error');
    onecStocksRespond(500, [
        'success' => false,
        'request_id' => $requestId,
        'error' => 'internal_error',
        'message' => 'Внутренняя ошибка обмена. Остатки не применены; сообщите request_id администратору.',
    ]);
}
