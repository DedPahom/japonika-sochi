<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/Models/Product.php';
require_once __DIR__ . '/Models/CustomerPricing.php';
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
$userDiscount = CustomerPricing::currentDiscount();
$finalPrice = Product::getFinalPrice($product, $userDiscount);
$appliedDiscount = (!empty($product['no_discount']) || $finalPrice >= (float)$product['price']) ? 0 : $userDiscount;
$cartCsrf = Cart::csrfToken();
$pageTitle = $product['name'] . ' — ' . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>
<div class="product-page">
    <a class="product-back" href="/catalog.php">← Вернуться в каталог</a>
    <div class="product">
        <div class="product__gallery" data-product-gallery>
            <?php if ($images): ?>
                <div class="gallery-main" aria-label="Фотографии товара">
                    <?php if (count($images) > 1): ?>
                        <button class="gallery-arrow gallery-arrow--prev" type="button" data-gallery-prev aria-label="Предыдущее изображение">‹</button>
                    <?php endif; ?>
                    <button class="gallery-main__open" type="button" data-gallery-open aria-label="Увеличить изображение">
                        <img class="gallery-main__image" data-gallery-main src="/uploads/products/<?= rawurlencode(basename($images[0]['file_path'])) ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>" fetchpriority="high">
                        <span class="gallery-main__zoom" aria-hidden="true">⌕ Увеличить</span>
                    </button>
                    <?php if (count($images) > 1): ?>
                        <button class="gallery-arrow gallery-arrow--next" type="button" data-gallery-next aria-label="Следующее изображение">›</button>
                        <div class="gallery-main__counter" data-gallery-counter>1 / <?= count($images) ?></div>
                    <?php endif; ?>
                </div>
                <?php if (count($images) > 1): ?>
                    <div class="gallery-thumbs-wrap">
                        <button class="gallery-thumbs-arrow" type="button" data-thumbs-prev aria-label="Прокрутить миниатюры влево">‹</button>
                        <div class="gallery-thumbs" data-gallery-thumbs role="list" aria-label="Выбрать фотографию">
                            <?php foreach ($images as $index => $img): $filename = basename((string)$img['file_path']); ?>
                                <button class="gallery-thumb<?= $index === 0 ? ' is-active' : '' ?>" type="button" data-gallery-thumb data-index="<?= (int)$index ?>" data-full="/uploads/products/<?= rawurlencode($filename) ?>" data-alt="<?= htmlspecialchars($product['name'] . ' — фото ' . ($index + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Изображение <?= $index + 1 ?> из <?= count($images) ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>">
                                    <img src="/uploads/products/thumbs/<?= rawurlencode($filename) ?>" alt="" loading="lazy">
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <button class="gallery-thumbs-arrow" type="button" data-thumbs-next aria-label="Прокрутить миниатюры вправо">›</button>
                    </div>
                <?php endif; ?>

                <dialog class="gallery-lightbox" data-gallery-dialog aria-label="Просмотр изображений товара">
                    <div class="gallery-lightbox__bar">
                        <span data-lightbox-counter>1 / <?= count($images) ?></span>
                        <button type="button" class="gallery-lightbox__close" data-lightbox-close aria-label="Закрыть просмотр">×</button>
                    </div>
                    <?php if (count($images) > 1): ?><button type="button" class="gallery-lightbox__arrow gallery-lightbox__arrow--prev" data-lightbox-prev aria-label="Предыдущее изображение">‹</button><?php endif; ?>
                    <img class="gallery-lightbox__image" data-lightbox-image src="/uploads/products/<?= rawurlencode(basename($images[0]['file_path'])) ?>" alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php if (count($images) > 1): ?><button type="button" class="gallery-lightbox__arrow gallery-lightbox__arrow--next" data-lightbox-next aria-label="Следующее изображение">›</button><?php endif; ?>
                </dialog>
            <?php else: ?>
                <div class="product__main-image"><img src="/assets/images/no-image.jpg" alt="Нет фото"></div>
            <?php endif; ?>
        </div>

        <div class="product__info">
            <div class="product__article">Артикул: <?= htmlspecialchars($product['article'], ENT_QUOTES, 'UTF-8') ?></div>
            <h1 class="product__name"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></h1>
            <?php if ($categories): ?><div class="product__categories">Категории: <?php foreach ($categories as $cat): ?><span class="product__category"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?></div><?php endif; ?>
            <div class="product__price"><span class="product__price-value"><?= number_format($finalPrice, 0, '.', ' ') ?> ₽</span><?php if ($finalPrice < (float)$product['price']): ?><span class="product__price-old"><?= number_format((float)$product['price'], 0, '.', ' ') ?> ₽</span><?php if ($appliedDiscount > 0): ?><span class="price-discount">(<?= $appliedDiscount ?>%)</span><?php endif; ?><?php endif; ?></div>
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
<?php if ($images): ?><script src="/assets/js/product-gallery.js" defer></script><?php endif; ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
