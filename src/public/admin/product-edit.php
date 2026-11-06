<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: /admin/products.php');
    exit;
}

$db = getDBConnection();

// Получаем товар
$stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    header('Location: /admin/products.php');
    exit;
}

$error = '';
$success = '';

// Получаем категории
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// Получаем категории товара
$stmt = $db->prepare("SELECT category_id FROM product_categories WHERE product_id = ?");
$stmt->execute([$id]);
$productCategories = array_column($stmt->fetchAll(), 'category_id');

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $article = trim((string)($_POST['article'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $price = (float) ($_POST['price'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $noDiscount = isset($_POST['no_discount']) ? 1 : 0;
    $selectedCategories = $_POST['categories'] ?? [];
    
    if (empty($article)) {
        $error = 'Укажите артикул';
    } elseif (empty($name)) {
        $error = 'Укажите название';
    } elseif ($price <= 0) {
        $error = 'Укажите корректную цену';
    } else {
        try {
            $stmt = $db->prepare("
                UPDATE products 
                SET article = :article, name = :name, description = :description,
                    price = :price, quantity = :quantity, no_discount = :no_discount
                WHERE id = :id
            ");
            
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':article', $article);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':description', $description);
            $stmt->bindValue(':price', $price);
            $stmt->bindValue(':quantity', $quantity);
            $stmt->bindValue(':no_discount', $noDiscount, PDO::PARAM_INT);
            $stmt->execute();
            
            // Обновляем категории
            $db->prepare("DELETE FROM product_categories WHERE product_id = ?")->execute([$id]);
            if (!empty($selectedCategories)) {
                $stmt = $db->prepare("INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)");
                foreach ($selectedCategories as $catId) {
                    $stmt->execute([$id, (int) $catId]);
                }
            }
            
            $success = 'Товар сохранён';
            
            // Обновляем данные
            $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $product = $stmt->fetch();
            
            $stmt = $db->prepare("SELECT category_id FROM product_categories WHERE product_id = ?");
            $stmt->execute([$id]);
            $productCategories = array_column($stmt->fetchAll(), 'category_id');
            
        } catch (PDOException $e) {
            $error = 'Ошибка сохранения: ' . $e->getMessage();
        }
    }
}

// Получаем изображения товара
$stmt = $db->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order");
$stmt->execute([$id]);
$images = $stmt->fetchAll();

$pageTitle = 'Редактирование товара — Админ-панель';
require __DIR__ . '/../templates/header.php';
?>

<div class="admin">
    <div class="admin__header">
        <h1>Редактировать товар</h1>
        <a href="/admin/products.php" class="btn">← Назад к списку</a>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert--error"><?= htmlspecialchars((string)$error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert--success"><?= htmlspecialchars((string)$success) ?></div>
    <?php endif; ?>
    
    <form method="POST" enctype="multipart/form-data" class="form form--admin">
        
        <div class="form__group">
            <label for="article">Артикул *</label>
            <input type="text" id="article" name="article" required
                   value="<?= htmlspecialchars((string)($_POST['article'] ?? ($product['article'] ?? ''))) ?>">
        </div>
        
        <div class="form__group">
            <label for="name">Название *</label>
            <input type="text" id="name" name="name" required
                   value="<?= htmlspecialchars((string)($_POST['name'] ?? ($product['name'] ?? ''))) ?>">
        </div>
        
        <div class="form__group">
            <label for="price">Цена (₽) *</label>
            <input type="number" id="price" name="price" step="0.01" min="0" required
                   value="<?= htmlspecialchars((string)($_POST['price'] ?? ($product['price'] ?? ''))) ?>">
        </div>
        
        <div class="form__group">
            <label for="quantity">Остаток</label>
            <input type="number" id="quantity" name="quantity" min="0"
                   value="<?= htmlspecialchars((string)($_POST['quantity'] ?? ($product['quantity'] ?? 0))) ?>">
        </div>
        
        <div class="form__group">
            <label for="description">Описание</label>
            <textarea id="description" name="description" rows="5"><?= 
                htmlspecialchars((string)($_POST['description'] ?? ($product['description'] ?? '')), ENT_QUOTES, 'UTF-8') 
            ?></textarea>
        </div>
        
        <div class="form__group">
            <label>Категории</label>
            <div class="form__checkboxes">
                <?php foreach ($categories as $cat): ?>
                    <label class="checkbox">
                        <input type="checkbox" name="categories[]" 
                               value="<?= (int)$cat['id'] ?>"
                               <?= in_array((int)$cat['id'], $productCategories) ? 'checked' : '' ?>>
                        <?= htmlspecialchars((string)$cat['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="form__group">
            <label class="checkbox">
                <input type="checkbox" name="no_discount" value="1"
                       <?= (isset($_POST['no_discount']) || ($product['no_discount'] ?? 0)) ? 'checked' : '' ?>>
                Скидки не применяются
            </label>
        </div>
        
        <?php if (!empty($images)): ?>
            <div class="form__group">
                <label>Текущие изображения</label>
                <div class="product-images">
                    <?php foreach ($images as $img): ?>
                        <div class="product-images__item">
                            <img src="/uploads/products/thumbs/<?= htmlspecialchars((string)$img['file_path']) ?>" alt="">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="form__group">
            <label for="image">Загрузить изображение</label>
            <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">
        </div>
        
        <div class="form__actions">
            <button type="submit" class="btn btn--primary">💾 Сохранить</button>
        </div>
        
    </form>
</div>

<style>
.form__group { margin-bottom: 20px; }
.form__group label { display: block; margin-bottom: 5px; font-weight: 500; }
.form__group input[type="text"], .form__group input[type="number"], .form__group textarea { 
    width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; 
}
.form__checkboxes { display: flex; flex-wrap: wrap; gap: 15px; }
.checkbox { display: flex; align-items: center; gap: 8px; cursor: pointer; }
.product-images { display: flex; gap: 10px; }
.product-images__item { width: 100px; height: 100px; border-radius: 8px; overflow: hidden; border: 2px solid #ddd; }
.product-images__item img { width: 100%; height: 100%; object-fit: cover; }
.form__actions { margin-top: 25px; }
</style>

<?php require __DIR__ . '/../templates/footer.php'; ?>