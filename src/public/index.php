<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/Models/Product.php';
require_once __DIR__ . '/Models/Category.php';

// Получаем параметры
$page = max(1, (int) ($_GET['page'] ?? 1));

// Получаем товары
$products = Product::getList($page);
$totalProducts = Product::getTotalCount();
$totalPages = ceil($totalProducts / PRODUCTS_PER_PAGE);

// Получаем категории для меню
$categories = Category::getAll();

// Заголовок страницы
$pageTitle = SITE_NAME . ' — Каталог';

// Подключаем шаблоны
require __DIR__ . '/templates/header.php';
?>

<div class="catalog">
    <h1 class="catalog__title">Каталог товаров</h1>
    
    <?php if (empty($products)): ?>
        <p class="catalog__empty">Пока нет товаров. Загрузите через админ-панель.</p>
    <?php else: ?>
        <div class="products-grid">
            <?php foreach ($products as $product): ?>
                <?php
                $inStock = Product::isInStock($product);
                $imageUrl = !empty($product['main_image']) 
                    ? '/uploads/products/' . htmlspecialchars($product['main_image'])
                    : '/assets/images/no-image.jpg';
                ?>
                <div class="product-card <?= $inStock ? '' : 'product-card--out-of-stock' ?>">
                    <a href="/product.php?id=<?= $product['id'] ?>" class="product-card__link">
                        <div class="product-card__image">
                            <img src="<?= $imageUrl ?>" 
                                 alt="<?= htmlspecialchars($product['name']) ?>"
                                 loading="lazy">
                            <?php if (!$inStock): ?>
                                <span class="product-card__badge">Под заказ</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="product-card__info">
                            <div class="product-card__article">
                                Арт. <?= htmlspecialchars($product['article']) ?>
                            </div>
                            <h3 class="product-card__name">
                                <?= htmlspecialchars($product['name']) ?>
                            </h3>
                            <div class="product-card__price">
                                <?= number_format((float) $product['price'], 0, '.', ' ') ?> ₽
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        
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

<?php require __DIR__ . '/templates/footer.php'; ?>