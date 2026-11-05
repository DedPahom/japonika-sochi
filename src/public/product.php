<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/src/Models/Product.php';

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: /');
    exit;
}

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

$pageTitle = $product['name'] . ' — ' . SITE_NAME;

require __DIR__ . '/templates/header.php';
?>

<div class="product">
    <div class="product__gallery">
        <?php if (!empty($images)): ?>
            <div class="product__main-image">
                <img src="/uploads/products/<?= htmlspecialchars($images[0]['file_path']) ?>" 
                     alt="<?= htmlspecialchars($product['name']) ?>">
            </div>
            <?php if (count($images) > 1): ?>
                <div class="product__thumbnails">
                    <?php foreach ($images as $img): ?>
                        <img src="/uploads/products/thumbs/<?= htmlspecialchars($img['file_path']) ?>" 
                             alt=""
                             class="product__thumbnail">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="product__main-image product__main-image--no-image">
                <span>Нет фото</span>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="product__info">
        <div class="product__article">
            Артикул: <?= htmlspecialchars($product['article']) ?>
        </div>
        
        <h1 class="product__name"><?= htmlspecialchars($product['name']) ?></h1>
        
        <?php if (!empty($categories)): ?>
            <div class="product__categories">
                Категории: 
                <?php foreach ($categories as $cat): ?>
                    <span class="product__category"><?= htmlspecialchars($cat['name']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="product__price">
            <span class="product__price-value"><?= number_format($finalPrice, 0, '.', ' ') ?> ₽</span>
            <?php if ($finalPrice < (float) $product['price']): ?>
                <span class="product__price-old"><?= number_format((float) $product['price'], 0, '.', ' ') ?> ₽</span>
            <?php endif; ?>
        </div>
        
        <div class="product__stock">
            <?php if ($inStock): ?>
                <span class="product__stock-status product__stock-status--in">✓ В наличии (<?= $product['quantity'] ?> шт.)</span>
            <?php else: ?>
                <span class="product__stock-status product__stock-status--out">Под заказ</span>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($product['description'])): ?>
            <div class="product__description">
                <h3>Описание</h3>
                <?= nl2br(htmlspecialchars($product['description'])) ?>
            </div>
        <?php endif; ?>
        
        <div class="product__actions">
            <button type="button" class="btn btn--primary" onclick="addToCart(<?= $product['id'] ?>)">
                🛒 В корзину
            </button>
            
            <?php if (!$inStock): ?>
                <button type="button" class="btn btn--secondary" onclick="notifyWhenAvailable(<?= $product['id'] ?>)">
                    🔔 Сообщить, когда появится
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function addToCart(productId) {
    alert('Функция корзины будет добавлена позже');
}

function notifyWhenAvailable(productId) {
    alert('Функция уведомлений будет добавлена позже');
}
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>