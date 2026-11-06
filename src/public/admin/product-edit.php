<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /admin/products.php');
    exit;
}

$stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) {
    header('Location: /admin/products.php');
    exit;
}

if (empty($_SESSION['product_edit_csrf'])) {
    $_SESSION['product_edit_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['product_edit_csrf'];
$error = '';
$success = '';
$uploadedFiles = [];

function productEditH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$categories = $db->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$stmt = $db->prepare('SELECT category_id FROM product_categories WHERE product_id = ?');
$stmt->execute([$id]);
$productCategories = array_map('intval', array_column($stmt->fetchAll(), 'category_id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'Форма устарела. Обновите страницу и повторите.';
    } else {
        $article = trim((string)($_POST['article'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $price = (float)($_POST['price'] ?? 0);
        $quantity = filter_var($_POST['quantity'] ?? 0, FILTER_VALIDATE_INT);
        $noDiscount = isset($_POST['no_discount']) ? 1 : 0;
        $selectedCategories = $_POST['categories'] ?? [];
        if (!is_array($selectedCategories)) $selectedCategories = [];

        if ($article === '') {
            $error = 'Укажите артикул.';
        } elseif (mb_strlen($article) > 100) {
            $error = 'Артикул не должен превышать 100 символов.';
        } elseif ($name === '') {
            $error = 'Укажите название.';
        } elseif (mb_strlen($name) > 500) {
            $error = 'Название не должно превышать 500 символов.';
        } elseif (!is_numeric($_POST['price'] ?? null) || $price < 0) {
            $error = 'Укажите корректную цену.';
        } elseif ($quantity === false || $quantity < 0) {
            $error = 'Остаток должен быть целым неотрицательным числом.';
        }

        // If an image was selected, validate and save the uploaded file.
        $newImageName = null;
        if ($error === '' && isset($_FILES['image']) && (int)$_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload = $_FILES['image'];
            if (!isset($upload['error'], $upload['tmp_name'], $upload['size']) || !is_uploaded_file((string)$upload['tmp_name'])) {
                $error = 'Загрузка изображения не прошла проверку. Выберите файл ещё раз.';
            } elseif ((int)$upload['error'] !== UPLOAD_ERR_OK) {
                $error = match ((int)$upload['error']) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой. Максимальный размер — 8 МБ.',
                    UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью. Повторите загрузку.',
                    UPLOAD_ERR_NO_TMP_DIR => 'На сервере не настроена временная папка для загрузок.',
                    UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать загруженный файл.',
                    default => 'Ошибка загрузки файла (код ' . (int)$upload['error'] . ').',
                };
            } elseif ((int)$upload['size'] <= 0 || (int)$upload['size'] > 8 * 1024 * 1024) {
                $error = 'Изображение должно быть не больше 8 МБ.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file((string)$upload['tmp_name']);
                $extensions = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];
                $imageInfo = @getimagesize((string)$upload['tmp_name']);
                if (!isset($extensions[$mime]) || $imageInfo === false) {
                    $error = 'Поддерживаются только корректные изображения JPG, PNG и WebP.';
                } else {
                    $uploadDir = rtrim(UPLOAD_PATH, '/\\') . '/products';
                    $thumbDir = $uploadDir . '/thumbs';
                    foreach ([$uploadDir, $thumbDir] as $dir) {
                        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                            $error = 'Не удалось создать папку для изображений. Проверьте права на uploads.';
                            break;
                        }
                    }
                    if ($error === '' && !is_writable($uploadDir)) {
                        $error = 'Папка uploads/products недоступна для записи. Проверьте права на сервере.';
                    }
                    if ($error === '') {
                        $newImageName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                        $originalPath = $uploadDir . '/' . $newImageName;
                        $thumbPath = $thumbDir . '/' . $newImageName;
                        if (!move_uploaded_file((string)$upload['tmp_name'], $originalPath)) {
                            $newImageName = null;
                            $error = 'Не удалось переместить изображение в uploads/products. Проверьте права на папку.';
                        } else {
                            $uploadedFiles = [$originalPath, $thumbPath];
                            // The frontend expects a matching file under /thumbs. Keep a valid image there;
                            // CSS scales/crops it for the small preview.
                            if (!@copy($originalPath, $thumbPath)) {
                                $error = 'Изображение загружено, но не удалось создать файл предпросмотра.';
                            }
                        }
                    }
                }
            }
        }

        if ($error === '') {
            try {
                $db->beginTransaction();
                $stmt = $db->prepare('UPDATE products SET article = ?, name = ?, description = ?, price = ?, quantity = ?, no_discount = ? WHERE id = ?');
                $stmt->execute([$article, $name, $description, $price, $quantity, $noDiscount, $id]);

                $db->prepare('DELETE FROM product_categories WHERE product_id = ?')->execute([$id]);
                if ($selectedCategories) {
                    $insertCategory = $db->prepare('INSERT INTO product_categories (product_id, category_id) VALUES (?, ?)');
                    foreach ($selectedCategories as $categoryId) {
                        $categoryId = filter_var($categoryId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        if (!$categoryId) continue;
                        $insertCategory->execute([$id, $categoryId]);
                    }
                }

                if ($newImageName !== null) {
                    $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = ?');
                    $stmt->execute([$id]);
                    $sortOrder = (int)$stmt->fetchColumn();
                    $stmt = $db->prepare('INSERT INTO product_images (product_id, file_path, sort_order) VALUES (?, ?, ?)');
                    $stmt->execute([$id, $newImageName, $sortOrder]);
                }
                $db->commit();
                $success = $newImageName !== null ? 'Товар и изображение сохранены.' : 'Товар сохранён.';

                $stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
                $stmt->execute([$id]);
                $product = $stmt->fetch();
                $stmt = $db->prepare('SELECT category_id FROM product_categories WHERE product_id = ?');
                $stmt->execute([$id]);
                $productCategories = array_map('intval', array_column($stmt->fetchAll(), 'category_id'));
                unset($_POST); // Render the saved values from the database after successful submission.
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                foreach ($uploadedFiles as $file) if (is_file($file)) @unlink($file);
                error_log('Product edit/save failed: ' . $e->getMessage());
                $error = 'Не удалось сохранить товар. Проверьте журнал ошибок сервера.';
            }
        } elseif ($uploadedFiles) {
            foreach ($uploadedFiles as $file) if (is_file($file)) @unlink($file);
        }
    }
}

$stmt = $db->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
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
    <?php if ($error): ?><div class="alert alert--error"><?= productEditH($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert--success"><?= productEditH($success) ?></div><?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="form form--admin">
        <input type="hidden" name="csrf" value="<?= productEditH($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="8388608">

        <div class="form__group">
            <label for="article">Артикул *</label>
            <input type="text" id="article" name="article" required maxlength="100" value="<?= productEditH($_POST['article'] ?? $product['article']) ?>">
        </div>
        <div class="form__group">
            <label for="name">Название *</label>
            <input type="text" id="name" name="name" required maxlength="500" value="<?= productEditH($_POST['name'] ?? $product['name']) ?>">
        </div>
        <div class="form__group">
            <label for="price">Цена (₽) *</label>
            <input type="number" id="price" name="price" step="0.01" min="0" required value="<?= productEditH($_POST['price'] ?? $product['price']) ?>">
        </div>
        <div class="form__group">
            <label for="quantity">Остаток</label>
            <input type="number" id="quantity" name="quantity" min="0" step="1" value="<?= productEditH($_POST['quantity'] ?? $product['quantity']) ?>">
        </div>
        <div class="form__group">
            <label for="description">Описание</label>
            <textarea id="description" name="description" rows="5"><?= productEditH($_POST['description'] ?? $product['description']) ?></textarea>
        </div>
        <div class="form__group">
            <label>Категории</label>
            <div class="form__checkboxes">
                <?php foreach ($categories as $cat): $catId = (int)$cat['id']; ?>
                    <label class="checkbox">
                        <input type="checkbox" name="categories[]" value="<?= $catId ?>" <?= in_array($catId, $productCategories, true) || in_array((string)$catId, $_POST['categories'] ?? [], true) ? 'checked' : '' ?>>
                        <?= productEditH($cat['name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form__group">
            <label class="checkbox">
                <input type="checkbox" name="no_discount" value="1" <?= (isset($_POST['no_discount']) || (($_POST === []) && !empty($product['no_discount']))) ? 'checked' : '' ?>>
                Скидки не применяются
            </label>
        </div>

        <?php if ($images): ?>
            <div class="form__group">
                <label>Текущие изображения</label>
                <div class="product-images">
                    <?php foreach ($images as $img): $file = basename((string)$img['file_path']); ?>
                        <div class="product-images__item">
                            <img src="/uploads/products/thumbs/<?= rawurlencode($file) ?>" alt="">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div class="form__group">
            <label for="image">Загрузить изображение (JPG, PNG, WebP; до 8 МБ)</label>
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
.product-images { display: flex; gap: 10px; flex-wrap: wrap; }
.product-images__item { width: 100px; height: 100px; border-radius: 8px; overflow: hidden; border: 2px solid #ddd; }
.product-images__item img { width: 100%; height: 100%; object-fit: cover; }
</style>
<?php require __DIR__ . '/../templates/footer.php'; ?>
