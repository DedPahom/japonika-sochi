<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/Models/Product.php';
require_once __DIR__ . '/Models/Cart.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$isAdmin = isset($_SESSION['admin_id']);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: /'); exit; }
$product = Product::getById($id);
if (!$product) {
    http_response_code(404);
    $pageTitle = 'Товар не найден — ' . SITE_NAME;
    require __DIR__ . '/templates/header.php';
    echo '<h1>Товар не найден</h1>';
    require __DIR__ . '/templates/footer.php';
    exit;
}
$images = Product::getImages($id);
$categories = Product::getCategories($id);
$inStock = Product::isInStock($product);
$finalPrice = Product::getFinalPrice($product);
$cartCsrf = Cart::csrfToken();
$pageTitle = $product['name'] . ' — ' . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>
<div class="product-page">
    <a class="product-back" href="/catalog.php">← Вернуться в каталог</a>
    <div class="product">
        <div class="product__gallery">
            <?php if ($images): ?>
                <div class="product__main-image"><img src="/uploads/products/<?= rawurlencode(basename($images[0]['file_path'])) ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <?php if (count($images) > 1): ?><div class="product__thumbnails"><?php foreach ($images as $img): ?><img src="/uploads/products/thumbs/<?= rawurlencode(basename($img['file_path'])) ?>" alt="" class="product__thumbnail"><?php endforeach; ?></div><?php endif; ?>
            <?php else: ?><div class="product__main-image"><img src="/assets/images/no-image.jpg" alt="Нет фото"></div><?php endif; ?>
        </div>
        <div class="product__info">
            <div class="product__article">Артикул: <?= htmlspecialchars($product['article'], ENT_QUOTES, 'UTF-8') ?></div>
            <h1 class="product__name"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></h1>
            <?php if ($categories): ?><div class="product__categories">Категории: <?php foreach ($categories as $cat): ?><span class="product__category"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?></div><?php endif; ?>
            <div class="product__price"><span class="product__price-value"><?= number_format($finalPrice, 0, '.', ' ') ?> ₽</span><?php if ($finalPrice < (float)$product['price']): ?><span class="product__price-old"><?= number_format((float)$product['price'], 0, '.', ' ') ?> ₽</span><?php endif; ?></div>
            <div class="product__stock"><?php if ($inStock): ?><span class="product__stock-status product__stock-status--in">✓ В наличии (<?= (int)$product['quantity'] ?> шт.)</span><?php else: ?><span class="product__stock-status product__stock-status--out">Под заказ</span><?php endif; ?></div>
            <?php if (!empty($product['description'])): ?><div class="product__description"><h3>Описание</h3><?= nl2br(htmlspecialchars($product['description'], ENT_QUOTES, 'UTF-8')) ?></div><?php endif; ?>
            <div class="product__actions product-card__quick-buy">
                <label class="visually-hidden" for="product-quantity">Количество</label>
                <input id="product-quantity" class="quick-buy__quantity" type="number" min="1" max="999" value="1" inputmode="numeric">
                <button type="button" class="btn btn--primary quick-buy__button" data-cart-add="<?= (int)$product['id'] ?>" data-cart-csrf="<?= htmlspecialchars($cartCsrf, ENT_QUOTES, 'UTF-8') ?>">🛒 В корзину</button>
                <?php if ($isAdmin): ?><a class="btn btn--small admin-product-edit" href="/admin/product-edit.php?id=<?= (int)$product['id'] ?>">✏️ Редактировать</a><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
