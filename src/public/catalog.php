<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/Models/Product.php';
require_once __DIR__ . '/Models/Category.php';

// Получаем параметры
$page = max(1, (int) ($_GET['page'] ?? 1));
$categorySlug = $_GET['category'] ?? '';
$sort = $_GET['sort'] ?? 'new';
$search = trim($_GET['search'] ?? '');

// Получаем категории для фильтра
$categories = Category::getAll();

// Фильтрация и сортировка
$where = [];
$params = [];

if (!empty($categorySlug)) {
    $category = Category::getBySlug($categorySlug);
    if ($category) {
        $where[] = "p.id IN (SELECT product_id FROM product_categories WHERE category_id = ?)";
        $params[] = $category['id'];
    }
}

if (!empty($search)) {
    $where[] = "(p.name LIKE ? OR p.article LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Сортировка
$orderSql = match($sort) {
    'price_asc' => 'ORDER BY p.price ASC',
    'price_desc' => 'ORDER BY p.price DESC',
    'name' => 'ORDER BY p.name ASC',
    default => 'ORDER BY p.created_at DESC'
};

// Получаем товары
$db = getDBConnection();
$offset = ($page - 1) * PRODUCTS_PER_PAGE;

$sql = "SELECT p.*, 
               COALESCE(pi.file_path, '') as main_image
        FROM products p
        LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.sort_order = 0
        $whereSql
        $orderSql
        LIMIT " . PRODUCTS_PER_PAGE . " OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Общее количество для пагинации
$countSql = "SELECT COUNT(*) FROM products p $whereSql";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalProducts = $stmt->fetchColumn();
$totalPages = ceil($totalProducts / PRODUCTS_PER_PAGE);

$pageTitle = 'Каталог — ' . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>

<div class="catalog">
    <h1 class="catalog__title">Каталог</h1>
    
    <!-- Фильтры -->
    <div class="catalog__filters">
        <form method="GET" class="filters-form">
            <div class="filters-form__row">
                <div class="filters-form__group">
                    <label>Категория:</label>
                    <select name="category">
                        <option value="">Все категории</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat['slug']) ?>"
                                    <?= $categorySlug === $cat['slug'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filters-form__group">
                    <label>Сортировка:</label>
                    <select name="sort">
                        <option value="new" <?= $sort === 'new' ? 'selected' : '' ?>>Сначала новые</option>
                        <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Цена ↑</option>
                        <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Цена ↓</option>
                        <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>По названию</option>
                    </select>
                </div>
                
                <div class="filters-form__group">
                    <label>Поиск:</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Название или артикул...">
                </div>
                
                <div class="filters-form__group">
                    <button type="submit" class="btn btn--primary">Применить</button>
                    <a href="/catalog.php" class="btn">Сбросить</a>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Результаты -->
    <?php if (empty($products)): ?>
        <p class="catalog__empty">Товары не найдены.</p>
    <?php else: ?>
        <p class="catalog__count">Найдено: <?= $totalProducts ?></p>
        
        <div class="products-grid">
            <?php foreach ($products as $product): ?>
                <?php
                $inStock = (int) $product['quantity'] > 0;
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
                <?php 
                $queryParams = $_GET;
                for ($i = 1; $i <= $totalPages; $i++): 
                    $queryParams['page'] = $i;
                ?>
                    <a href="?<?= http_build_query($queryParams) ?>" 
                       class="pagination__link <?= $i === $page ? 'pagination__link--active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/templates/footer.php'; ?>