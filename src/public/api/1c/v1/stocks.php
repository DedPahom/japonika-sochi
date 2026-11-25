<?php
declare(strict_types=1);

/**
 * 1C full catalog + stock snapshot endpoint.
 * Repeated GUID rows are consolidated into one product with all distinct categories.
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

/** Build a URL-safe category slug, matching the catalog importer. */
function onecStocksMakeSlug(string $name): string
{
    $value = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
    $value = strtolower((string)$value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? substr($value, 0, 180) : 'category';
}

/** Find or create a globally unique category name under the requested parent. */
function onecStocksGetOrCreateCategory(PDO $db, string $name, ?int $parentId, array &$cache, int &$createdCount): int
{
    $cacheKey = mb_strtolower(trim($name), 'UTF-8');
    if (isset($cache[$cacheKey])) {
        $categoryId = $cache[$cacheKey];
        $parentStmt = $db->prepare('SELECT parent_id FROM categories WHERE id = ?');
        $parentStmt->execute([$categoryId]);
        $currentParent = $parentStmt->fetchColumn();
        $currentParent = $currentParent === false || $currentParent === null ? null : (int)$currentParent;
        if ($currentParent !== $parentId) {
            throw new OneCStocksApiProblem(409, 'category_path_conflict', "Категория «{$name}» указана в снимке с разными родителями.");
        }
        return $categoryId;
    }

    $find = $db->prepare('SELECT id, parent_id FROM categories WHERE name = ? ORDER BY id LIMIT 2');
    $find->execute([$name]);
    $matches = $find->fetchAll(PDO::FETCH_ASSOC);
    if (count($matches) > 1) {
        throw new OneCStocksApiProblem(409, 'duplicate_category_name', "В каталоге несколько категорий «{$name}»; импорт остановлен.");
    }

    if ($matches) {
        $categoryId = (int)$matches[0]['id'];
        $currentParent = $matches[0]['parent_id'] === null ? null : (int)$matches[0]['parent_id'];
        if ($currentParent !== $parentId) {
            // Keep the existing category tree intact; do not silently move categories via stock exchange.
            throw new OneCStocksApiProblem(409, 'category_path_conflict', "Категория «{$name}» уже находится в другой ветке каталога.");
        }
    } else {
        $base = onecStocksMakeSlug($name);
        $slug = $base;
        $suffixNo = 2;
        while (true) {
            $check = $db->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
            $check->execute([$slug]);
            if ($check->fetchColumn() === false) break;
            $suffix = '-' . $suffixNo++;
            $slug = substr($base, 0, 190 - strlen($suffix)) . $suffix;
        }
        $insert = $db->prepare('INSERT INTO categories (name, slug, parent_id) VALUES (?, ?, ?)');
        $insert->execute([$name, $slug, $parentId]);
        $categoryId = (int)$db->lastInsertId();
        $createdCount++;
    }

    $cache[$cacheKey] = $categoryId;
    return $categoryId;
}

/** Validate the catalog fields supplied for an incoming product row. */
function onecStocksPrepareNewProduct(array $row, int $line): ?array
{
    $catalogFields = ['article', 'name', 'price', 'category_path', 'description'];
    $hasCatalogData = false;
    foreach ($catalogFields as $field) {
        if (array_key_exists($field, $row)) { $hasCatalogData = true; break; }
    }
    // Keep compatibility with stock-only clients: unknown GUID without catalog fields remains reported, not created.
    if (!$hasCatalogData) return null;

    $article = trim((string)($row['article'] ?? ''));
    $name = trim((string)($row['name'] ?? ''));
    $priceRaw = str_replace(',', '.', trim((string)($row['price'] ?? '')));
    $pathRaw = trim((string)($row['category_path'] ?? ''));
    $description = trim((string)($row['description'] ?? ''));

    if ($article === '' || mb_strlen($article, 'UTF-8') > 100) {
        throw new OneCStocksApiProblem(422, 'invalid_article', "Строка {$line}: для нового товара article обязателен и должен быть не длиннее 100 символов.");
    }
    if ($name === '' || mb_strlen($name, 'UTF-8') > 500) {
        throw new OneCStocksApiProblem(422, 'invalid_name', "Строка {$line}: для нового товара name обязателен и должен быть не длиннее 500 символов.");
    }
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $priceRaw)) {
        throw new OneCStocksApiProblem(422, 'invalid_price', "Строка {$line}: для нового товара price обязателен (до 8 цифр до точки и 2 после).");
    }
    if ($pathRaw === '') {
        throw new OneCStocksApiProblem(422, 'invalid_category_path', "Строка {$line}: для нового товара обязателен category_path с разделителем ##.");
    }
    $segments = array_map('trim', explode('##', $pathRaw));
    if (count($segments) > 12 || in_array('', $segments, true)) {
        throw new OneCStocksApiProblem(422, 'invalid_category_path', "Строка {$line}: category_path должен содержать от 1 до 12 непустых уровней через ##.");
    }
    foreach ($segments as $segment) {
        if (mb_strlen($segment, 'UTF-8') > 255) {
            throw new OneCStocksApiProblem(422, 'invalid_category_path', "Строка {$line}: имя категории в category_path длиннее 255 символов.");
        }
    }
    if (strlen($description) > 60000) {
        throw new OneCStocksApiProblem(422, 'invalid_description', "Строка {$line}: description слишком длинный для поля товара.");
    }
    $priceValue = (float)$priceRaw;
    if ($priceValue < 0 || $priceValue > 99999999.99) {
        throw new OneCStocksApiProblem(422, 'invalid_price', "Строка {$line}: price вне диапазона поля базы данных.");
    }

    return [
        'article' => $article,
        'name' => $name,
        'price' => number_format($priceValue, 2, '.', ''),
        'description' => $description !== '' ? $description : null,
        'category_segments' => $segments,
    ];
}

/** Resolve or create the leaf category for an incoming category path. */
function onecStocksGetCategoryId(PDO $db, array $segments, array &$categoryCache, int &$createdCount): int
{
    $parentId = null;
    $leafCategoryId = null;
    foreach ($segments as $segment) {
        $leafCategoryId = onecStocksGetOrCreateCategory($db, $segment, $parentId, $categoryCache, $createdCount);
        $parentId = $leafCategoryId;
    }
    if ($leafCategoryId === null) {
        throw new OneCStocksApiProblem(422, 'invalid_category_path', 'category_path пуст.');
    }
    return $leafCategoryId;
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
    $productRows = [];
    $productHasCatalogData = [];
    $productCategoryPaths = [];
    $firstRowNumbers = [];
    foreach ($data['products'] as $index => $row) {
        $line = $index + 1;
        if (!is_array($row)) {
            throw new OneCStocksApiProblem(422, 'invalid_product', "Строка {$line}: ожидался объект товара.");
        }
        $onecId = strtolower(trim((string)($row['onec_id'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $onecId)) {
            throw new OneCStocksApiProblem(422, 'invalid_onec_id', "Строка {$line}: некорректный onec_id; ожидается GUID.");
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

        if (!array_key_exists($onecId, $snapshot)) {
            $snapshot[$onecId] = $quantity;
            $productRows[$onecId] = [];
            $productHasCatalogData[$onecId] = false;
            $productCategoryPaths[$onecId] = [];
            $firstRowNumbers[$onecId] = $line;
        } else {
            if ($snapshot[$onecId] !== $quantity) {
                throw new OneCStocksApiProblem(422, 'inconsistent_duplicate_product', "Строка {$line}: quantity для onec_id {$onecId} отличается от строки {$firstRowNumbers[$onecId]}.");
            }
        }

        if (!$productHasCatalogData[$onecId]) {
            foreach (['article', 'name', 'price', 'category_path', 'description'] as $catalogField) {
                if (array_key_exists($catalogField, $row)) {
                    $productHasCatalogData[$onecId] = true;
                    break;
                }
            }
        }
        foreach (['article', 'name', 'price', 'description'] as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $incomingValue = trim((string)$row[$field]);
            if (array_key_exists($field, $productRows[$onecId])
                && trim((string)$productRows[$onecId][$field]) !== $incomingValue) {
                throw new OneCStocksApiProblem(422, 'inconsistent_duplicate_product', "Строка {$line}: поле {$field} для onec_id {$onecId} отличается от строки {$firstRowNumbers[$onecId]}.");
            }
            $productRows[$onecId][$field] = $row[$field];
        }
        $categoryPath = trim((string)($row['category_path'] ?? ''));
        if ($categoryPath !== '' && !array_key_exists('category_path', $productRows[$onecId])) {
            $productRows[$onecId]['category_path'] = $categoryPath;
        }
        if ($categoryPath !== '') {
            $productCategoryPaths[$onecId][$categoryPath] = true;
        }
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

    $unknownIds = array_values(array_diff($guids, array_keys($knownByGuid)));
    $db->beginTransaction();
    $inTransaction = true;

    $createdProducts = 0;
    $updatedProducts = 0;
    $createdCategories = 0;
    $createdCategoryLinks = 0;
    $categoryCache = [];
    $findArticle = $db->prepare('SELECT id FROM products WHERE article = ? LIMIT 1');
    $findOnecIdByArticle = $db->prepare('SELECT LOWER(onec_id) FROM products WHERE article = ? LIMIT 1');
    $insertProduct = $db->prepare('INSERT INTO products (onec_id, article, name, description, price, quantity) VALUES (?, ?, ?, ?, ?, ?)');
    $updateProduct = $db->prepare('UPDATE products SET article = ?, name = ?, description = ?, price = ? WHERE id = ?');
    $deleteProductCategories = $db->prepare('DELETE FROM product_categories WHERE product_id = ?');
    $linkProductCategory = $db->prepare('INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (?, ?)');
    $deleteProductCategoryLinks = $db->prepare('DELETE FROM product_categories WHERE product_id = ?');

    foreach ($guids as $guid) {
        $line = array_search($guid, $guids, true) + 1;
        if (!$productHasCatalogData[$guid]) {
            continue; // Stock-only rows update quantity but preserve catalog fields.
        }
        if (!$productCategoryPaths[$guid]) {
            throw new OneCStocksApiProblem(422, 'invalid_category_path', "Строка {$line}: для товара с каталоговыми данными обязателен category_path.");
        }
        $newProduct = onecStocksPrepareNewProduct($productRows[$guid], $line);
        if ($newProduct === null) {
            continue;
        }

        $findArticle->execute([$newProduct['article']]);
        $articleOwner = $findArticle->fetchColumn();
        if ($articleOwner !== false && (!isset($knownByGuid[$guid]) || (int)$articleOwner !== $knownByGuid[$guid])) {
            $findOnecIdByArticle->execute([$newProduct['article']]);
            $articleOwnerGuid = strtolower((string)$findOnecIdByArticle->fetchColumn());
            if ($articleOwnerGuid !== '' && isset($productRows[$articleOwnerGuid])) {
                throw new OneCStocksApiProblem(409, 'duplicate_article', "Строка {$line}: несколько GUID в снимке переданы с одним артикулом.");
            }
            if ($articleOwnerGuid === '' || !isset($knownByGuid[$articleOwnerGuid])) {
                throw new OneCStocksApiProblem(409, 'article_conflict', "Строка {$line}: артикул уже используется товаром без совпавшего GUID в снимке.");
            }

            $ownerProductId = $knownByGuid[$articleOwnerGuid];
            $db->prepare('UPDATE products SET onec_id = NULL WHERE id = ?')->execute([$ownerProductId]);
            $deleteProductCategoryLinks->execute([$ownerProductId]);
            unset($knownByGuid[$articleOwnerGuid]);
            $knownByGuid[$guid] = $ownerProductId;
        }

        $categoryPaths = array_keys($productCategoryPaths[$guid]);
        $categoryIds = [];
        foreach ($categoryPaths as $categoryPath) {
            $pathSegments = array_map('trim', explode('##', $categoryPath));
            if (count($pathSegments) > 12 || in_array('', $pathSegments, true)) {
                throw new OneCStocksApiProblem(422, 'invalid_category_path', "Строка {$line}: category_path должен содержать от 1 до 12 непустых уровней через ##.");
            }
            foreach ($pathSegments as $segment) {
                if (mb_strlen($segment, 'UTF-8') > 255) {
                    throw new OneCStocksApiProblem(422, 'invalid_category_path', "Строка {$line}: имя категории в category_path длиннее 255 символов.");
                }
            }
            $categoryId = onecStocksGetCategoryId($db, $pathSegments, $categoryCache, $createdCategories);
            $categoryIds[$categoryId] = true;
        }

        if (isset($knownByGuid[$guid])) {
            $productId = $knownByGuid[$guid];
            $updateProduct->execute([
                $newProduct['article'],
                $newProduct['name'],
                $newProduct['description'],
                $newProduct['price'],
                $productId,
            ]);
            $deleteProductCategoryLinks->execute([$productId]);
            foreach (array_keys($categoryIds) as $categoryId) {
                $linkProductCategory->execute([$productId, $categoryId]);
                $createdCategoryLinks += $linkProductCategory->rowCount();
            }
            $updatedProducts++;
            continue;
        }

        $insertProduct->execute([
            $guid,
            $newProduct['article'],
            $newProduct['name'],
            $newProduct['description'],
            $newProduct['price'],
            $snapshot[$guid],
        ]);
        $productId = (int)$db->lastInsertId();
        foreach (array_keys($categoryIds) as $categoryId) {
            $linkProductCategory->execute([$productId, $categoryId]);
            $createdCategoryLinks += $linkProductCategory->rowCount();
        }
        $knownByGuid[$guid] = $productId;
        $createdProducts++;
    }

    $unknownIds = array_values(array_diff($guids, array_keys($knownByGuid)));
    if (!$knownByGuid) {
        throw new OneCStocksApiProblem(409, 'no_known_products', 'Ни один onec_id из снимка не найден или не создан. Передайте полный каталог с обязательными полями для новых товаров. Остатки не изменены.');
    }

    $knownGuids = array_keys($knownByGuid);
    $knownMarks = implode(',', array_fill(0, count($knownGuids), '?'));

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
        'request_id=%s received=%d matched=%d created=%d categories_created=%d unknown=%d absent_from_snapshot=%d changed_from_snapshot=%d changed_to_zero=%d%s',
        $requestId,
        $receivedCount,
        count($knownByGuid) - $createdProducts,
        $createdProducts,
        $createdCategories,
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
        'matched' => count($knownByGuid) - $createdProducts,
        'products_updated' => $updatedProducts,
        'products_created' => $createdProducts,
        'categories_created' => $createdCategories,
        'category_links_created' => $createdCategoryLinks,
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
