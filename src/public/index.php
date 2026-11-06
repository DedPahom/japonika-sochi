<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/Models/Product.php';
require_once __DIR__ . '/Models/Category.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$categories = Category::getRoots();
$products = Product::getList($page);
$totalProducts = Product::getTotalCount();
$totalPages = (int)ceil($totalProducts / PRODUCTS_PER_PAGE);
$pageTitle = SITE_NAME . ' — Каталог';
require __DIR__ . '/templates/header.php';
?>
<div class="catalog">
    <h1 class="catalog__title">Каталог товаров</h1>
    <nav class="category-nav" aria-label="Разделы каталога">
        <?php foreach ($categories as $category): ?>
            <a class="category-nav__link" href="/catalog.php?category=<?= rawurlencode($category['slug']) ?>"><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
    </nav>
    <h2 class="catalog__subtitle">Все товары</h2>
    <?php if (!$products): ?>
        <p class="catalog__empty">Пока нет товаров. Загрузите каталог через админ-панель.</p>
    <?php else: ?>
        <div class="products-grid">
            <?php foreach ($products as $product): $inStock = Product::isInStock($product); ?>
                <div class="product-card <?= $inStock ? '' : 'product-card--out-of-stock' ?>">
                    <a href="/product.php?id=<?= (int)$product['id'] ?>" class="product-card__link">
                        <div class="product-card__image">
                            <img src="<?= !empty($product['main_image']) ? '/uploads/products/' . rawurlencode($product['main_image']) : '/assets/images/no-image.jpg' ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                            <?php if (!$inStock): ?><span class="product-card__badge">Под заказ</span><?php endif; ?>
                        </div>
                        <div class="product-card__info">
                            <div class="product-card__article">Арт. <?= htmlspecialchars($product['article'], ENT_QUOTES, 'UTF-8') ?></div>
                            <h3 class="product-card__name"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <div class="product-card__price"><?= number_format((float)$product['price'], 0, '.', ' ') ?> ₽</div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($totalPages > 1): ?><div class="pagination">
            <?php for ($i=1; $i<=$totalPages; $i++): ?><a href="?page=<?= $i ?>" class="pagination__link <?= $i===$page?'pagination__link--active':'' ?>"><?= $i ?></a><?php endfor; ?>
        </div><?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
