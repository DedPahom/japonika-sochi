<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/Models/Product.php';
require_once __DIR__ . '/Models/CustomerPricing.php';
require_once __DIR__ . '/Models/Category.php';
require_once __DIR__ . '/Models/Cart.php';
require_once __DIR__ . '/Models/Promotions.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$isAdmin = isset($_SESSION['admin_id']);
$userDiscount = CustomerPricing::currentDiscount();
$page = max(1, (int)($_GET['page'] ?? 1));
$sort = (string)($_GET['sort'] ?? 'popular');
if (!in_array($sort, ['popular', 'article', 'cheaper', 'expensive'], true)) $sort = 'popular';
$slug = trim((string)($_GET['category'] ?? ''));
$scope = (($_GET['scope'] ?? 'all') === 'current') ? 'current' : 'all';
$includeDescendants = $scope === 'all';
$category = $slug !== '' ? Category::getBySlug($slug) : null;
if ($slug !== '' && !$category) { http_response_code(404); }
$categories = $category ? Category::getChildren((int)$category['id']) : Category::getRoots();
$path = $category ? Category::getPath((int)$category['id']) : [];

if ($category) {
    $products = Category::getProducts((int)$category['id'], $includeDescendants, $page, PRODUCTS_PER_PAGE, $sort);
    $totalProducts = Category::countProducts((int)$category['id'], $includeDescendants);
    $pageTitle = $category['name'] . ' — Каталог — ' . SITE_NAME;
} else {
    $products = Product::getList($page, PRODUCTS_PER_PAGE, $sort);
    $totalProducts = Product::getTotalCount();
    $pageTitle = 'Каталог — ' . SITE_NAME;
}
$promoBadges = Promotions::badgesByProductIds(array_column($products, 'id'));
$totalPages = (int)ceil($totalProducts / PRODUCTS_PER_PAGE);
$cartCsrf = Cart::csrfToken();
$baseQuery = $category ? 'category=' . rawurlencode($category['slug']) : '';
$modeQuery = $category ? '&scope=' . ($includeDescendants ? 'all' : 'current') : '';
require __DIR__ . '/templates/header.php';
?>
<div class="catalog">
    <h1 class="catalog__title"><?= $category ? htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') : 'Каталог товаров' ?></h1>
    <?php if ($path): ?><nav class="breadcrumbs" aria-label="Навигация">
        <a href="/">Главная</a><span>›</span><a href="/catalog.php">Каталог</a>
        <?php foreach ($path as $crumb): ?><span>›</span><?php if ((int)$crumb['id'] === (int)$category['id']): ?><span aria-current="page"><?= htmlspecialchars($crumb['name'], ENT_QUOTES, 'UTF-8') ?></span><?php else: ?><a href="/catalog.php?category=<?= rawurlencode($crumb['slug']) ?>"><?= htmlspecialchars($crumb['name'], ENT_QUOTES, 'UTF-8') ?></a><?php endif; ?><?php endforeach; ?>
    </nav><?php endif; ?>
    <?php if ($category): ?>
        <?php $parentPath = array_slice($path, 0, -1); $parent = $parentPath ? end($parentPath) : null; ?>
        <a class="btn btn--small category-back" href="<?= $parent ? '/catalog.php?category=' . rawurlencode($parent['slug']) : '/catalog.php' ?>">← <?= $parent ? 'Назад' : 'Все разделы' ?></a>
    <?php endif; ?>
    <?php if ($categories): ?><nav class="category-nav" aria-label="Подразделы">
        <?php foreach ($categories as $item): ?><a class="category-nav__link" href="/catalog.php?category=<?= rawurlencode($item['slug']) ?>"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></a><?php endforeach; ?>
    </nav><?php endif; ?>
    <?php if ($category): ?><div class="catalog-scope" role="group" aria-label="Какие товары показывать">
        <span>Показать:</span>
        <a class="catalog-scope__link <?= !$includeDescendants ? 'is-active' : '' ?>" href="?category=<?= rawurlencode($category['slug']) ?>&amp;scope=current&amp;sort=<?= rawurlencode($sort) ?>">Только в этом разделе</a>
        <a class="catalog-scope__link <?= $includeDescendants ? 'is-active' : '' ?>" href="?category=<?= rawurlencode($category['slug']) ?>&amp;scope=all&amp;sort=<?= rawurlencode($sort) ?>">Включая вложенные</a>
    </div><?php endif; ?>
    <form class="catalog-sort" method="get" action="/catalog.php">
        <?php if ($category): ?><input type="hidden" name="category" value="<?= htmlspecialchars((string)$category['slug'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="scope" value="<?= $includeDescendants ? 'all' : 'current' ?>"><?php endif; ?>
        <label for="catalog-sort">Сортировка
            <select id="catalog-sort" name="sort" onchange="this.form.submit()">
                <option value="popular" <?= $sort === 'popular' ? 'selected' : '' ?>>Сначала популярные</option>
                <option value="article" <?= $sort === 'article' ? 'selected' : '' ?>>По артикулу</option>
                <option value="cheaper" <?= $sort === 'cheaper' ? 'selected' : '' ?>>Дешевле</option>
                <option value="expensive" <?= $sort === 'expensive' ? 'selected' : '' ?>>Дороже</option>
            </select>
        </label>
        <noscript><button class="btn btn--small" type="submit">Применить</button></noscript>
    </form>
    <?php if (!$products): ?><p class="catalog__empty"><?= $category ? 'В этом режиме товаров нет.' : 'Пока нет товаров.' ?></p>
    <?php else: ?><div class="products-grid">
        <?php foreach ($products as $product): $inStock = Product::isInStock($product); $finalPrice = Product::getFinalPrice($product, $userDiscount); $appliedDiscount = (!empty($product['no_discount']) || $finalPrice >= (float)$product['price']) ? 0 : $userDiscount; ?>
            <article class="product-card <?= $inStock ? '' : 'product-card--out-of-stock' ?>">
                <a href="/product.php?id=<?= (int)$product['id'] ?>" class="product-card__link">
                    <div class="product-card__image"><img src="<?= !empty($product['main_image']) ? '/uploads/products/' . rawurlencode(basename($product['main_image'])) : '/assets/images/no-image.jpg' ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy"><?php if (!$inStock): ?><span class="product-card__badge">Под заказ</span><?php endif; ?><?php if (!empty($promoBadges[(int)$product['id']])): $promoBadge = $promoBadges[(int)$product['id']]; ?><span class="product-promo-badge product-promo-badge--overlay"<?= !empty($promoBadge['picture_url']) ? ' style="background-image:url(' . htmlspecialchars($promoBadge['picture_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')"' : '' ?>><?= htmlspecialchars($promoBadge['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= (int)$promoBadge['discount_percent'] ?>%<?= $promoBadge['type'] === 'quantity_discount' ? ' · от ' . (int)$promoBadge['min_quantity'] . ' шт.' : '' ?></span><?php endif; ?></div>
                    <div class="product-card__info"><div class="product-card__article">Арт. <?= htmlspecialchars($product['article'], ENT_QUOTES, 'UTF-8') ?></div><h3 class="product-card__name"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></h3><div class="product-card__price"><?= number_format($finalPrice, 0, '.', ' ') ?> ₽<?php if ($finalPrice < (float)$product['price']): ?> <del class="product-card__price-old"><?= number_format((float)$product['price'], 0, '.', ' ') ?> ₽</del><?php if ($appliedDiscount > 0): ?> <span class="price-discount">(<?= $appliedDiscount ?>%)</span><?php endif; ?><?php endif; ?></div></div>
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
    <?php if ($totalPages > 1): ?><div class="pagination"><?php for ($i=1; $i<=$totalPages; $i++): ?><a href="?<?= $baseQuery ?><?= $baseQuery ? '&amp;' : '' ?>page=<?= $i ?><?= $modeQuery ?>&amp;sort=<?= rawurlencode($sort) ?>" class="pagination__link <?= $i===$page?'pagination__link--active':'' ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
