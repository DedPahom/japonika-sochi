<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}
if (!hash_equals((string)($_SESSION['admin_products_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('Неверный токен безопасности. Обновите страницу и повторите.');
}
$id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = max(1, (int)($_POST['return_page'] ?? 1));
$sortMap = ['id', 'article', 'name', 'price', 'quantity', 'views', 'orders', 'ordered'];
$sort = (string)($_POST['return_sort'] ?? '');
$direction = strtoupper((string)($_POST['return_dir'] ?? ''));
if (!in_array($sort, $sortMap, true) || !in_array($direction, ['ASC', 'DESC'], true)) {
    $sort = '';
    $direction = '';
}
if (!$id) {
    http_response_code(400);
    exit('Некорректный ID товара.');
}

$db = getDBConnection();
try {
    $db->beginTransaction();

    // Remember image names for safe cleanup after the database transaction commits.
    $stmt = $db->prepare('SELECT file_path FROM product_images WHERE product_id = ?');
    $stmt->execute([$id]);
    $imagePaths = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach (['product_categories', 'product_components', 'product_promos', 'product_images'] as $table) {
        $db->prepare("DELETE FROM `$table` WHERE product_id = ?")->execute([$id]);
    }
    $stmt = $db->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) throw new RuntimeException('Товар не найден.');
    $db->commit();

    $uploadRoot = realpath(__DIR__ . '/../uploads/products');
    if ($uploadRoot !== false) {
        foreach ($imagePaths as $imagePath) {
            $filename = basename((string)$imagePath);
            if ($filename === '' || $filename === '.' || $filename === '..') continue;
            foreach ([$uploadRoot, $uploadRoot . DIRECTORY_SEPARATOR . 'thumbs'] as $directory) {
                $realDirectory = realpath($directory);
                if ($realDirectory === false) continue;
                $file = $realDirectory . DIRECTORY_SEPARATOR . $filename;
                if (is_file($file) && !is_link($file)) @unlink($file);
            }
        }
    }

    $query = ['page' => $page, 'deleted' => '1'];
    if ($sort !== '') {
        $query['sort'] = $sort;
        $query['dir'] = $direction;
    }
    header('Location: /admin/products.php?' . http_build_query($query));
    exit;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('Product delete failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Не удалось удалить товар. Проверьте журнал ошибок сервера.');
}
