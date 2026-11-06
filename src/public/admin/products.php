<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../Models/Product.php';

if (empty($_SESSION['admin_products_csrf'])) $_SESSION['admin_products_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['admin_products_csrf'];
$page = max(1, (int)($_GET['page'] ?? 1));
$products = Product::getList($page);
$totalProducts = Product::getTotalCount();
$totalPages = (int)ceil($totalProducts / PRODUCTS_PER_PAGE);
$notice = (($_GET['deleted'] ?? '') === '1') ? 'Товар удалён.' : '';
$pageTitle = 'Товары — Админ-панель';
require __DIR__ . '/../templates/header.php';
?>
<div class="admin">
    <div class="admin__header">
        <h1>Товары</h1>
        <a href="/admin/product-edit.php" class="btn btn--primary">+ Добавить товар</a>
    </div>
    <?php if ($notice): ?><p class="admin-notice"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if (!$products): ?><p>Товаров пока нет.</p><?php else: ?>
        <table class="admin-table">
            <thead><tr><th>ID</th><th>Фото</th><th>Артикул</th><th>Название</th><th>Цена</th><th>Остаток</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($products as $product): ?>
                <tr class="<?= (int)$product['quantity'] === 0 ? 'admin-table__row--out' : '' ?>">
                    <td><?= (int)$product['id'] ?></td>
                    <td><?php if (!empty($product['main_image'])): ?><img src="/uploads/products/thumbs/<?= rawurlencode(basename($product['main_image'])) ?>" alt="" width="50"><?php else: ?><span class="admin-table__no-image">—</span><?php endif; ?></td>
                    <td><?= htmlspecialchars($product['article'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(mb_strimwidth($product['name'], 0, 50, '...'), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= number_format((float)$product['price'], 0, '.', ' ') ?> ₽</td>
                    <td><?= (int)$product['quantity'] === 0 ? '<span class="badge badge--warning">Под заказ</span>' : (int)$product['quantity'] ?></td>
                    <td class="admin-table__actions">
                        <a href="/admin/product-edit.php?id=<?= (int)$product['id'] ?>" class="btn btn--small" aria-label="Редактировать">✏️</a>
                        <a href="/product.php?id=<?= (int)$product['id'] ?>" class="btn btn--small" aria-label="Просмотреть">👁️</a>
                        <form method="post" action="/admin/delete-product.php" class="admin-delete-form" onsubmit="return confirm('Удалить этот товар? Это действие нельзя отменить.');">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                            <input type="hidden" name="return_page" value="<?= $page ?>">
                            <button type="submit" class="btn btn--small btn--danger" aria-label="Удалить товар" title="Удалить">🗑️</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($totalPages > 1): ?><div class="pagination"><?php for ($i=1; $i<=$totalPages; $i++): ?><a href="?page=<?= $i ?>" class="pagination__link <?= $i===$page?'pagination__link--active':'' ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
