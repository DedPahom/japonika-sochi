<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

const MAX_IMPORT_BYTES = 20 * 1024 * 1024;
const IMPORT_SESSION_KEY = 'catalog_import_stage';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['catalog_import_csrf'])) {
    $_SESSION['catalog_import_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['catalog_import_csrf'];
$message = '';
$errors = [];
$preview = null;

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function failImport(string $message): void { throw new RuntimeException($message); }

/** Validate, normalize and merge duplicate rows by 1C GUID. */
function parseCatalog(string $json): array
{
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['products']) || !is_array($data['products'])) {
        failImport('В JSON должен быть массив products.');
    }
    if (count($data['products']) > 50000) failImport('В файле больше 50 000 записей.');

    $groups = [];
    $errors = [];
    foreach ($data['products'] as $index => $row) {
        $line = $index + 1;
        if (!is_array($row)) { $errors[] = "Запись {$line}: ожидался объект."; continue; }
        $guid = trim((string)($row['onec_id'] ?? ''));
        $article = trim((string)($row['article'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $guid)) {
            $errors[] = "Запись {$line}: некорректный onec_id (ожидается GUID из 36 символов)."; continue;
        }
        if ($article === '' || mb_strlen($article) > 100) { $errors[] = "Запись {$line}: article пустой или длиннее 100 символов."; continue; }
        if ($name === '' || mb_strlen($name) > 500) { $errors[] = "Запись {$line}: name пустой или длиннее 500 символов."; continue; }

        $price = null;
        if (array_key_exists('price', $row) && trim((string)$row['price']) !== '') {
            $p = str_replace(',', '.', trim((string)$row['price']));
            if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $p)) { $errors[] = "Запись {$line}: некорректная цена."; continue; }
            $price = number_format((float)$p, 2, '.', '');
        }
        $quantity = null;
        if (array_key_exists('quantity', $row) && trim((string)$row['quantity']) !== '') {
            $q = trim((string)$row['quantity']);
            if (!preg_match('/^\d{1,9}$/', $q)) { $errors[] = "Запись {$line}: quantity должен быть целым неотрицательным числом или пустой строкой."; continue; }
            $quantity = (int)$q;
        }
        $description = trim((string)($row['description'] ?? ''));
        $path = trim((string)($row['category_path'] ?? ''));
        $segments = [];
        if ($path !== '') {
            $segments = array_map('trim', explode('##', $path));
            if (count($segments) > 12 || in_array('', $segments, true)) { $errors[] = "Запись {$line}: некорректный category_path (пустой уровень или больше 12 уровней)."; continue; }
            foreach ($segments as $segment) if (mb_strlen($segment) > 255) { $errors[] = "Запись {$line}: имя категории длиннее 255 символов."; continue 2; }
        } else { $errors[] = "Запись {$line}: отсутствует category_path."; continue; }

        $key = strtolower($guid);
        $record = ['onec_id'=>$guid,'article'=>$article,'name'=>$name,'price'=>$price,'quantity'=>$quantity,'description'=>$description,'paths'=>[$segments]];
        if (!isset($groups[$key])) { $groups[$key] = $record; continue; }
        $old =& $groups[$key];
        foreach (['article','name'] as $field) {
            if ($old[$field] !== $record[$field]) $errors[] = "Записи с onec_id {$guid}: разные значения {$field}.";
        }
        foreach (['price','quantity','description'] as $field) {
            $incoming = $record[$field];
            $existing = $old[$field];
            $emptyIncoming = $incoming === null || $incoming === '';
            $emptyExisting = $existing === null || $existing === '';
            if (!$emptyIncoming && !$emptyExisting && (string)$incoming !== (string)$existing) {
                $errors[] = "Записи с onec_id {$guid}: конфликтующие значения {$field}.";
            } elseif ($emptyExisting && !$emptyIncoming) { $old[$field] = $incoming; }
        }
        $pathKey = implode('##', $segments);
        $seen = array_map(static fn($p) => implode('##', $p), $old['paths']);
        if (!in_array($pathKey, $seen, true)) $old['paths'][] = $segments;
        unset($old);
    }

    // An empty price cannot create a new product; existing products may retain their price.
    if (!$groups) $errors[] = 'В файле нет корректных товаров.';
    return [$groups, $errors];
}

function makeSlug(string $name): string
{
    $value = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
    $value = strtolower((string)$value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? substr($value, 0, 180) : 'category';
}

function getOrCreateCategory(PDO $db, string $name, ?int $parentId, array &$cache): int
{
    $cacheKey = ($parentId ?? 0) . '|' . mb_strtolower($name, 'UTF-8');
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    if ($parentId === null) {
        $stmt = $db->prepare('SELECT id FROM categories WHERE parent_id IS NULL AND name = ? LIMIT 1');
        $stmt->execute([$name]);
    } else {
        $stmt = $db->prepare('SELECT id FROM categories WHERE parent_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$parentId, $name]);
    }
    $id = $stmt->fetchColumn();
    if ($id !== false) return $cache[$cacheKey] = (int)$id;

    $base = makeSlug($name);
    $slug = $base;
    $n = 2;
    while (true) {
        $check = $db->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
        $check->execute([$slug]);
        if ($check->fetchColumn() === false) break;
        $suffix = '-' . $n++;
        $slug = substr($base, 0, 190 - strlen($suffix)) . $suffix;
    }
    $insert = $db->prepare('INSERT INTO categories (name, slug, parent_id) VALUES (?, ?, ?)');
    $insert->execute([$name, $slug, $parentId]);
    return $cache[$cacheKey] = (int)$db->lastInsertId();
}

function applyCatalog(PDO $db, array $groups): array
{
    $db->beginTransaction();
    try {
        $categoryCache = [];
        $stats = ['created'=>0,'updated'=>0,'categories_created'=>0,'links'=>0];
        $findGuid = $db->prepare('SELECT id, article FROM products WHERE onec_id = ?');
        $findArticle = $db->prepare('SELECT id, onec_id FROM products WHERE article = ?');
        $insertProduct = $db->prepare('INSERT INTO products (onec_id, article, name, description, price, quantity) VALUES (?, ?, ?, ?, ?, ?)');
        $updateProduct = $db->prepare('UPDATE products SET article = ?, name = ?, description = ?, price = ?, quantity = ?, onec_id = ? WHERE id = ?');
        $deleteLinks = $db->prepare('DELETE FROM product_categories WHERE product_id = ?');
        $addLink = $db->prepare('INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (?, ?)');
        $categoryCountBefore = (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn();

        foreach ($groups as $product) {
            $findGuid->execute([$product['onec_id']]);
            $existing = $findGuid->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                $findArticle->execute([$product['article']]);
                $byArticle = $findArticle->fetch(PDO::FETCH_ASSOC);
                if ($byArticle) {
                    if (!empty($byArticle['onec_id']) && strcasecmp($byArticle['onec_id'], $product['onec_id']) !== 0) {
                        failImport("Артикул {$product['article']} уже принадлежит другому onec_id.");
                    }
                    $existing = $byArticle;
                }
            }
            if (!$existing && $product['price'] === null) failImport("Новый товар {$product['article']}: price пустая.");

            if (!$existing) {
                $insertProduct->execute([$product['onec_id'], $product['article'], $product['name'], ($product['description'] !== '' ? $product['description'] : null), $product['price'], $product['quantity'] ?? 0]);
                $productId = (int)$db->lastInsertId();
                $stats['created']++;
            } else {
                $productId = (int)$existing['id'];
                // Blank description/price/quantity preserve the current value. New nonblank description replaces it.
                $sets = ['article = ?','name = ?'];
                $params = [$product['article'], $product['name']];
                if ($product['description'] !== '') { $sets[] = 'description = ?'; $params[] = $product['description']; }
                if ($product['price'] !== null) { $sets[] = 'price = ?'; $params[] = $product['price']; }
                if ($product['quantity'] !== null) { $sets[] = 'quantity = ?'; $params[] = $product['quantity']; }
                $sets[] = 'onec_id = ?'; $params[] = $product['onec_id']; $params[] = $productId;
                $db->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
                $stats['updated']++;
            }

            // The supplied JSON paths are the complete category set for this imported product.
            $deleteLinks->execute([$productId]);
            $categoryIds = [];
            foreach ($product['paths'] as $segments) {
                $parentId = null;
                foreach ($segments as $segment) {
                    $categoryId = getOrCreateCategory($db, $segment, $parentId, $categoryCache);
                    $categoryIds[$categoryId] = true;
                    $parentId = $categoryId;
                }
            }
            foreach (array_keys($categoryIds) as $categoryId) {
                $addLink->execute([$productId, $categoryId]);
                $stats['links']++;
            }
        }
        $stats['categories_created'] = (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn() - $categoryCountBefore;
        $db->commit();
        return $stats;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Сессия формы истекла. Обновите страницу и повторите.';
    } elseif (isset($_POST['confirm_import'])) {
        $stage = $_SESSION[IMPORT_SESSION_KEY] ?? null;
        if (!$stage || !is_file($stage)) {
            $errors[] = 'Файл для подтверждения не найден. Загрузите его заново.';
        } else {
            try {
                $json = file_get_contents($stage);
                [$groups, $parseErrors] = parseCatalog($json);
                if ($parseErrors) $errors = $parseErrors;
                else {
                    $stats = applyCatalog(getDBConnection(), $groups);
                    @unlink($stage);
                    unset($_SESSION[IMPORT_SESSION_KEY]);
                    $message = "Импорт завершён. Создано товаров: {$stats['created']}; обновлено: {$stats['updated']}; создано категорий: {$stats['categories_created']}; связей товар–категория: {$stats['links']}.";
                }
            } catch (Throwable $e) { $errors[] = 'Импорт отменён: ' . $e->getMessage(); }
        }
    } elseif (isset($_FILES['catalog_json'])) {
        unset($_SESSION[IMPORT_SESSION_KEY]);
        $f = $_FILES['catalog_json'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Не удалось загрузить файл (код ' . (int)$f['error'] . ').';
        } elseif ((int)$f['size'] > MAX_IMPORT_BYTES) {
            $errors[] = 'Файл больше допустимых 20 МБ.';
        } elseif (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'json') {
            $errors[] = 'Загрузите файл с расширением .json.';
        } else {
            $json = file_get_contents((string)$f['tmp_name']);
            try {
                [$groups, $parseErrors] = parseCatalog($json);
                if ($parseErrors) $errors = $parseErrors;
                else {
                    $db = getDBConnection();
                    $newCount = $updateCount = $newCats = 0;
                    $checkGuid = $db->prepare('SELECT id FROM products WHERE onec_id = ?');
                    foreach ($groups as $p) { $checkGuid->execute([$p['onec_id']]); if ($checkGuid->fetchColumn()) $updateCount++; else $newCount++; }
                    // Stage privately, outside the document root; only the session owner can confirm it.
                    $stage = tempnam(sys_get_temp_dir(), 'japonika-import-');
                    if ($stage === false || file_put_contents($stage, $json, LOCK_EX) === false) failImport('Не удалось сохранить файл для подтверждения.');
                    @chmod($stage, 0600);
                    $_SESSION[IMPORT_SESSION_KEY] = $stage;
                    $preview = ['products'=>count($groups),'new'=>$newCount,'updated'=>$updateCount,'categories_to_be_created'=>'будут созданы при импорте'];
                }
            } catch (Throwable $e) { $errors[] = 'Не удалось прочитать JSON: ' . $e->getMessage(); }
        }
    }
}

$pageTitle = 'Импорт каталога из 1С — ' . SITE_NAME;
require __DIR__ . '/../templates/header.php';
?>
<main class="container" style="max-width:900px;margin:32px auto;padding:0 16px">
    <h1>Импорт каталога из 1С</h1>
    <p>JSON до 20 МБ. Товары сопоставляются по onec_id. Пустые description, price и quantity не затирают данные существующего товара; у нового товара пустая цена недопустима.</p>
    <?php if ($message !== ''): ?><div style="padding:12px;background:#e8f6e8;margin:16px 0"><?= h($message) ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div style="padding:10px;background:#fde8e8;margin:8px 0"><?= h($error) ?></div><?php endforeach; ?>

    <?php if ($preview): ?>
        <section style="padding:16px;border:1px solid #ccc;margin:20px 0">
            <h2>Проверка завершена — подтвердите импорт</h2>
            <p>Уникальных товаров: <?= (int)$preview['products'] ?>; новых: <?= (int)$preview['new'] ?>; найденных для обновления по GUID: <?= (int)$preview['updated'] ?>.</p>
            <p>Создание категорий и обновление данных выполнятся в одной транзакции. Если возникнет ошибка, изменения будут отменены.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <button class="btn btn--primary" type="submit" name="confirm_import" value="1">Подтвердить импорт</button>
            </form>
        </section>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data" style="padding:16px;border:1px solid #ccc;margin:20px 0">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_IMPORT_BYTES ?>">
            <label for="catalog_json">Файл каталога (.json)</label><br>
            <input id="catalog_json" type="file" name="catalog_json" accept=".json,application/json" required>
            <button class="btn btn--primary" type="submit">Проверить файл</button>
        </form>
    <?php endif; ?>
    <p><a href="/admin/index.php">← Вернуться в админку</a></p>
</main>
<?php require __DIR__ . '/../templates/footer.php'; ?>
