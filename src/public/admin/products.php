<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../Models/Product.php';

$page = max(1, (int) ($_GET['page'] ?? 1));
$products = Product::getList($page);
$totalProducts = Product::getTotalCount();
$totalPages = ceil($totalProducts / PRODUCTS_PER_PAGE);

$pageTitle = 'Товары — Админ-панель';

require __DIR__ . '/../templates/header.php';
?>

<div class="admin">
    <div class="admin__header">
        <h1>Товары</h1>
        <a href="/admin/product-edit.php" class="btn btn--primary">+ Добавить товар</a>
    </div>
    
    <?php if (empty($products)): ?>
        <p>Товаров пока нет.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Фото</th>
                    <th>Артикул</th>
                    <th>Название</th>
                    <th>Цена</th>
                    <th>Остаток</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <tr class="<?= (int) $product['quantity'] === 0 ? 'admin-table__row--out' : '' ?>">
                        <td><?= $product['id'] ?></td>
                        <td>
                            <?php if (!empty($product['main_image'])): ?>
                                <img src="/uploads/products/thumbs/<?= htmlspecialchars($product['main_image']) ?>" 
                                     alt="" width="50">
                            <?php else: ?>
                                <span class="admin-table__no-image">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($product['article']) ?></td>
                        <td><?= htmlspecialchars(mb_strimwidth($product['name'], 0, 50, '...')) ?></td>
                        <td><?= number_format((float) $product['price'], 0, '.', ' ') ?> ₽</td>
                        <td>
                            <?= (int) $product['quantity'] === 0 
                                ? '<span class="badge badge--warning">Под заказ</span>' 
                                : (int) $product['quantity'] ?>
                        </td>
                        <td class="admin-table__actions">
                            <a href="/admin/product-edit.php?id=<?= $product['id'] ?>" 
                               class="btn btn--small">✏️</a>
                            <a href="/product.php?id=<?= $product['id'] ?>" 
                               class="btn btn--small">👁️</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i ?>" 
                       class="pagination__link <?= $i === $page ? 'pagination__link--active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>