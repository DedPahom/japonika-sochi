<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/Models/Product.php';
require_once __DIR__ . '/Models/Cart.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$isAdmin = isset($_SESSION['admin_id']);
$query = trim((string)($_GET['q'] ?? ''));
if (function_exists('mb_substr')) $query = mb_substr($query, 0, 120, 'UTF-8');
else $query = substr($query, 0, 120);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = defined('PRODUCTS_PER_PAGE') ? PRODUCTS_PER_PAGE : 24;
$products = [];
$total = 0;
if ($query !== '') {
    // Treat %, _ and ! in user queries literally instead of as SQL wildcards.
    $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query);
    $pattern = '%' . $escaped . '%';
    $where = "(p.name LIKE ? ESCAPE '!' OR p.article LIKE ? ESCAPE '!')";
    $db = getDBConnection();
    $count = $db->prepare("SELECT COUNT(*) FROM products p WHERE $where");
    $count->execute([$pattern, $pattern]);
    $total = (int)$count->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare("SELECT p.*, COALESCE((SELECT pi.file_path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1), '') AS main_image
        FROM products p WHERE $where
        ORDER BY CASE WHEN p.quantity > 0 THEN 0 ELSE 1 END, p.created_at DESC, p.id DESC
        LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $pattern, PDO::PARAM_STR);
    $stmt->bindValue(2, $pattern, PDO::PARAM_STR);
    $stmt->bindValue(3, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(4, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll();
}
$totalPages = (int)ceil($total / $perPage);
$cartCsrf = Cart::csrfToken();
$pageTitle = ($query !== '' ? 'Поиск: ' . $query . ' — ' : 'Поиск — ') . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>
<div class="catalog search-page">
    <h1 class="catalog__title">Поиск товаров</h1>
    <?php if ($query === ''): ?>
        <p class="search-page__note">Введите название товара или артикул в строку поиска.</p>
    <?php else: ?>
        <p class="search-page__note">По запросу «<?= htmlspecialchars($query, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>» найдено: <?= $total ?></p>
        <?php if (!$products): ?>
            <p class="catalog__empty">Ничего не найдено. Попробуйте изменить запрос.</p>
        <?php else: ?>
            <div class="products-grid">
                <?php foreach ($products as $product): $inStock = Product::isInStock($product); ?>
                    <article class="product-card <?= $inStock ? '' : 'product-card--out-of-stock' ?>">
                        <a href="/product.php?id=<?= (int)$product['id'] ?>" class="product-card__link">
                            <div class="product-card__image">
                                <img src="<?= !empty($product['main_image']) ? '/uploads/products/' . rawurlencode(basename($product['main_image'])) : '/assets/images/no-image.jpg' ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" loading="lazy">
                                <?php if (!$inStock): ?><span class="product-card__badge">Под заказ</span><?php endif; ?>
                            </div>
                            <div class="product-card__info">
                                <div class="product-card__article">Арт. <?= htmlspecialchars((string)$product['article'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <h2 class="product-card__name"><?= htmlspecialchars((string)$product['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                                <div class="product-card__price"><?= number_format((float)$product['price'], 0, '.', ' ') ?> ₽</div>
                            </div>
                        </a>
                        <div class="product-card__quick-buy">
                            <label class="visually-hidden" for="quick-qty-<?= (int)$product['id'] ?>">Количество</label>
                            <input id="quick-qty-<?= (int)$product['id'] ?>" class="quick-buy__quantity" type="number" min="1" max="999" value="1" inputmode="numeric">
                            <button type="button" class="btn btn--primary quick-buy__button" data-cart-add="<?= (int)$product['id'] ?>" data-cart-csrf="<?= htmlspecialchars($cartCsrf, ENT_QUOTES, 'UTF-8') ?>">В корзину</button>
                            <?php if ($isAdmin): ?><a class="product-card__edit" href="/admin/product-edit.php?id=<?= (int)$product['id'] ?>" aria-label="Редактировать товар" title="Редактировать товар">✏️</a><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($totalPages > 1): ?><div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?><a href="?q=<?= rawurlencode($query) ?>&amp;page=<?= $i ?>" class="pagination__link <?= $i === $page ? 'pagination__link--active' : '' ?>"><?= $i ?></a><?php endfor; ?>
            </div><?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
