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
$deletedImagePaths = [];

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

                // Remove only image rows belonging to this product; actual files are unlinked after commit.
                $deleteIdsRaw = $_POST['delete_images'] ?? [];
                if (!is_array($deleteIdsRaw)) $deleteIdsRaw = [];
                $deleteIds = [];
                foreach ($deleteIdsRaw as $rawImageId) {
                    $imageId = filter_var($rawImageId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($imageId) $deleteIds[(int)$imageId] = (int)$imageId;
                }
                $deleteIds = array_values($deleteIds);
                if ($deleteIds) {
                    $marks = implode(',', array_fill(0, count($deleteIds), '?'));
                    $stmt = $db->prepare("SELECT id, file_path FROM product_images WHERE product_id = ? AND id IN ($marks)");
                    $stmt->execute(array_merge([$id], $deleteIds));
                    $rowsToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (count($rowsToDelete) !== count($deleteIds)) {
                        throw new RuntimeException('Некоторые выбранные изображения не принадлежат этому товару. Обновите страницу и повторите.');
                    }
                    foreach ($rowsToDelete as $imageRow) $deletedImagePaths[] = (string)$imageRow['file_path'];
                    $stmt = $db->prepare("DELETE FROM product_images WHERE product_id = ? AND id IN ($marks)");
                    $stmt->execute(array_merge([$id], $deleteIds));
                }

                if ($newImageName !== null) {
                    $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = ?');
                    $stmt->execute([$id]);
                    $sortOrder = (int)$stmt->fetchColumn();
                    $stmt = $db->prepare('INSERT INTO product_images (product_id, file_path, sort_order) VALUES (?, ?, ?)');
                    $stmt->execute([$id, $newImageName, $sortOrder]);
                }

                // Set the selected image as primary. If the previous primary was deleted,
                // make the first remaining image the primary automatically.
                $mainImageId = filter_var($_POST['main_image_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $imageRowsStmt = $db->prepare('SELECT id, sort_order FROM product_images WHERE product_id = ? ORDER BY sort_order, id');
                $imageRowsStmt->execute([$id]);
                $remainingImages = $imageRowsStmt->fetchAll(PDO::FETCH_ASSOC);
                $remainingIds = array_map(static fn($row) => (int)$row['id'], $remainingImages);
                // If the previously selected main image is also marked for deletion,
                // choose the first remaining photo below instead of rejecting the form.
                if ($mainImageId && in_array((int)$mainImageId, $deleteIds, true)) $mainImageId = null;
                if ($mainImageId && !in_array((int)$mainImageId, $remainingIds, true)) {
                    throw new RuntimeException('Выберите главное изображение из оставшихся фотографий.');
                }
                if ($remainingImages) {
                    if (!$mainImageId) {
                        foreach ($remainingImages as $row) {
                            if ((int)$row['sort_order'] === 0) { $mainImageId = (int)$row['id']; break; }
                        }
                    }
                    if (!$mainImageId) $mainImageId = $remainingIds[0];
                    $orderedIds = array_values(array_unique(array_merge([(int)$mainImageId], $remainingIds)));
                    $sortStmt = $db->prepare('UPDATE product_images SET sort_order = ? WHERE id = ? AND product_id = ?');
                    foreach ($orderedIds as $position => $imageId) $sortStmt->execute([$position, $imageId, $id]);
                }

                $db->commit();
                // Physical image files are removed only after DB commit. Ensure they are not referenced elsewhere.
                try {
                    $uploadRoot = realpath(UPLOAD_PATH . '/products');
                    if ($uploadRoot !== false) {
                        foreach (array_unique($deletedImagePaths) as $relativePath) {
                            $filename = basename($relativePath);
                            if ($filename === '' || $filename === '.' || $filename === '..') continue;
                            $refCheck = $db->prepare('SELECT COUNT(*) FROM product_images WHERE file_path = ?');
                            $refCheck->execute([$relativePath]);
                            if ((int)$refCheck->fetchColumn() > 0) continue;
                            foreach ([$uploadRoot, $uploadRoot . DIRECTORY_SEPARATOR . 'thumbs'] as $dir) {
                                $realDir = realpath($dir);
                                if ($realDir === false) continue;
                                $filePath = $realDir . DIRECTORY_SEPARATOR . $filename;
                                if (is_file($filePath) && !is_link($filePath) && !@unlink($filePath)) {
                                    error_log('Could not unlink deleted product image: ' . $filePath);
                                }
                            }
                        }
                    }
                } catch (Throwable $cleanupError) {
                    // DB changes are already committed; file cleanup failure should not claim the save failed.
                    error_log('Product image cleanup failed: ' . $cleanupError->getMessage());
                }
                $deletedCount = count($deletedImagePaths);
                $successParts = ['Товар сохранён.'];
                if ($newImageName !== null) $successParts[] = 'Новое изображение добавлено.';
                if ($deletedCount > 0) $successParts[] = 'Удалено изображений: ' . $deletedCount . '.';
                $success = implode(' ', $successParts);

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

    <form method="POST" enctype="multipart/form-data" class="form form--admin" onsubmit="return !this.querySelector('.product-images__delete input:checked') || confirm('Удалить отмеченные изображения?');">
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
                    <?php foreach ($images as $img): $file = basename((string)$img['file_path']); $isMain = (int)$img['sort_order'] === 0; ?>
                        <div class="product-images__item <?= $isMain ? 'is-main' : '' ?>">
                            <img src="/uploads/products/thumbs/<?= rawurlencode($file) ?>" alt="" loading="lazy">
                            <label class="product-images__choice">
                                <input type="radio" name="main_image_id" value="<?= (int)$img['id'] ?>" <?= $isMain ? 'checked' : '' ?>>
                                <span><?= $isMain ? 'Главная' : 'Сделать главной' ?></span>
                            </label>
                            <label class="product-images__delete">
                                <input type="checkbox" name="delete_images[]" value="<?= (int)$img['id'] ?>">
                                <span>Удалить фото</span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="form__hint">Выберите основное фото. Чтобы удалить фото, отметьте «Удалить фото» и нажмите «Сохранить».</p>
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
.product-images { display: flex; gap: 12px; flex-wrap: wrap; }
.product-images__item { display: flex; width: 138px; flex-direction: column; gap: 7px; padding: 7px; background: #fff; border: 1px solid #e5dce0; border-radius: 9px; }
.product-images__item.is-main { border: 2px solid #e91e63; box-shadow: 0 0 0 2px rgba(233,30,99,.1); }
.product-images__item > img { width: 100%; height: 112px; object-fit: cover; border-radius: 5px; }
.product-images__choice, .product-images__delete { display: flex !important; align-items: center; gap: 6px; margin: 0 !important; font-size: 12px !important; line-height: 1.3; cursor: pointer; }
.product-images__choice input, .product-images__delete input { flex: 0 0 auto; margin: 0; }
.product-images__delete { color: #a22; }
.form__hint { margin: 8px 0 0; color: #777; font-size: 13px; }
</style>
<?php require __DIR__ . '/../templates/footer.php'; ?>
