<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
if (empty($_SESSION['admin_products_csrf'])) $_SESSION['admin_products_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['admin_products_csrf'];

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = PRODUCTS_PER_PAGE;
$totalProducts = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
$totalPages = max(1, (int)ceil($totalProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Only predefined SQL expressions are allowed into ORDER BY; never use raw query input.
$sortMap = [
    'id' => 'p.id',
    'article' => 'p.article',
    'name' => 'p.name',
    'price' => 'p.price',
    'quantity' => 'p.quantity',
    'views' => 'p.view_count',
    'orders' => 'p.order_count',
    'ordered' => 'p.ordered_quantity',
];
$sort = (string)($_GET['sort'] ?? '');
$direction = strtoupper((string)($_GET['dir'] ?? ''));
if (!isset($sortMap[$sort]) || !in_array($direction, ['ASC', 'DESC'], true)) {
    $sort = '';
    $direction = '';
}

if ($sort === '') {
    // Preserve the existing default: in-stock items first, then most recently updated/created.
    $orderBy = 'CASE WHEN p.quantity > 0 THEN 0 ELSE 1 END, p.created_at DESC, p.id DESC';
} else {
    $orderBy = $sortMap[$sort] . ' ' . $direction . ', p.id DESC';
}

$stmt = $db->prepare("SELECT p.id, p.article, p.name, p.price, p.quantity, p.view_count, p.order_count, p.ordered_quantity,
        COALESCE(pi.file_path, '') AS main_image
    FROM products p
    LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.sort_order = 0
    ORDER BY {$orderBy}
    LIMIT ? OFFSET ?");
$stmt->bindValue(1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

function adminProductsSortUrl(string $field, string $currentSort, string $currentDirection): string
{
    $nextDirection = ($currentSort === $field && $currentDirection === 'ASC') ? 'DESC' : 'ASC';
    return '/admin/products.php?' . http_build_query(['sort' => $field, 'dir' => $nextDirection, 'page' => 1]);
}
function adminProductsSortIndicator(string $field, string $currentSort, string $currentDirection): string
{
    if ($field !== $currentSort) return '↕';
    return $currentDirection === 'ASC' ? '↑' : '↓';
}
function adminProductsH(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$notice = (($_GET['deleted'] ?? '') === '1') ? 'Товар удалён.' : '';
$pageTitle = 'Товары — Админ-панель';
require __DIR__ . '/../templates/header.php';
?>
<div class="admin admin-products">
    <div class="admin__header">
        <h1>Товары</h1>
        <a href="/admin/product-edit.php" class="btn btn--primary">+ Добавить товар</a>
    </div>
    <?php if ($notice): ?><p class="admin-notice"><?= adminProductsH($notice) ?></p><?php endif; ?>
    <?php if (!$products): ?><p>Товаров пока нет.</p><?php else: ?>
        <p class="admin-products__sort-hint">Нажмите на название столбца, чтобы отсортировать список. Повторное нажатие изменит направление сортировки.</p>
        <div class="admin-products__table-wrap">
            <table class="admin-table admin-products__table">
                <thead><tr>
                    <th><a class="admin-products__sort-link" href="<?= adminProductsSortUrl('id', $sort, $direction) ?>">ID <span><?= adminProductsSortIndicator('id', $sort, $direction) ?></span></a></th>
                    <th>Фото</th>
                    <th><a class="admin-products__sort-link" href="<?= adminProductsSortUrl('article', $sort, $direction) ?>">Артикул <span><?= adminProductsSortIndicator('article', $sort, $direction) ?></span></a></th>
                    <th><a class="admin-products__sort-link" href="<?= adminProductsSortUrl('name', $sort, $direction) ?>">Название <span><?= adminProductsSortIndicator('name', $sort, $direction) ?></span></a></th>
                    <th><a class="admin-products__sort-link" href="<?= adminProductsSortUrl('price', $sort, $direction) ?>">Цена <span><?= adminProductsSortIndicator('price', $sort, $direction) ?></span></a></th>
                    <th><a class="admin-products__sort-link" href="<?= adminProductsSortUrl('quantity', $sort, $direction) ?>">Остаток <span><?= adminProductsSortIndicator('quantity', $sort, $direction) ?></span></a></th>
                    <th><a class="admin-products__sort-link" href="<?= adminProductsSortUrl('views', $sort, $direction) ?>">Просмотры <span><?= adminProductsSortIndicator('views', $sort, $direction) ?></span></a></th>
                    <th><a class="admin-products__sort-link" href="<?= adminProductsSortUrl('orders', $sort, $direction) ?>">Заказов <span><?= adminProductsSortIndicator('orders', $sort, $direction) ?></span></a></th>
                    <th><a class="admin-products__sort-link" href="<?= adminProductsSortUrl('ordered', $sort, $direction) ?>">Заказано, шт. <span><?= adminProductsSortIndicator('ordered', $sort, $direction) ?></span></a></th>
                    <th>Действия</th>
                </tr></thead>
                <tbody>
                <?php foreach ($products as $product): ?>
                    <tr class="<?= (int)$product['quantity'] === 0 ? 'admin-table__row--out' : '' ?>">
                        <td><?= (int)$product['id'] ?></td>
                        <td><?php if (!empty($product['main_image'])): ?><img src="/uploads/products/thumbs/<?= rawurlencode(basename((string)$product['main_image'])) ?>" alt="" width="50"><?php else: ?><span class="admin-table__no-image">—</span><?php endif; ?></td>
                        <td><?= adminProductsH($product['article']) ?></td>
                        <td><?= adminProductsH(function_exists('mb_strimwidth') ? mb_strimwidth((string)$product['name'], 0, 50, '...') : substr((string)$product['name'], 0, 50)) ?></td>
                        <td><?= number_format((float)$product['price'], 0, '.', ' ') ?> ₽</td>
                        <td><?= (int)$product['quantity'] === 0 ? '<span class="badge badge--warning">Под заказ</span>' : (int)$product['quantity'] ?></td>
                        <td><?= number_format((int)$product['view_count'], 0, '.', ' ') ?></td>
                        <td><?= number_format((int)$product['order_count'], 0, '.', ' ') ?></td>
                        <td><?= number_format((int)$product['ordered_quantity'], 0, '.', ' ') ?></td>
                        <td class="admin-table__actions">
                            <a href="/admin/product-edit.php?id=<?= (int)$product['id'] ?>" class="btn btn--small" aria-label="Редактировать">✏️</a>
                            <a href="/product.php?id=<?= (int)$product['id'] ?>" class="btn btn--small" aria-label="Просмотреть">👁️</a>
                            <form method="post" action="/admin/delete-product.php" class="admin-delete-form" onsubmit="return confirm('Удалить этот товар? Это действие нельзя отменить.');">
                                <input type="hidden" name="csrf" value="<?= adminProductsH($csrf) ?>">
                                <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                                <input type="hidden" name="return_page" value="<?= $page ?>">
                                <input type="hidden" name="return_sort" value="<?= adminProductsH($sort) ?>">
                                <input type="hidden" name="return_dir" value="<?= adminProductsH($direction) ?>">
                                <button type="submit" class="btn btn--small btn--danger" aria-label="Удалить товар" title="Удалить">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="<?= adminProductsH('/admin/products.php?' . http_build_query(array_filter(['sort' => $sort ?: null, 'dir' => $direction ?: null, 'page' => $i]))) ?>" class="pagination__link <?= $i === $page ? 'pagination__link--active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
