<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
if (empty($_SESSION['admin_promotions_csrf'])) $_SESSION['admin_promotions_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['admin_promotions_csrf'];

function promoAdminH(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function promoAdminRedirect(string $notice, int $editId = 0): void
{
    $query = ['notice' => $notice];
    if ($editId > 0) $query['edit'] = $editId;
    header('Location: /admin/promotions.php?' . http_build_query($query));
    exit;
}
function promoAdminDate(string $value, string $field): string
{
    $value = trim($value);
    $format = strlen($value) === 10 ? '!Y-m-d' : '!Y-m-d\\TH:i:s';
    $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('Europe/Moscow'));
    $errors = DateTimeImmutable::getLastErrors();
    $expected = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d\\TH:i:s';
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format($expected) !== $value) {
        throw new RuntimeException("Поле {$field}: ожидается YYYY-MM-DD либо YYYY-MM-DDT00:00:00.");
    }
    return $date->format('Y-m-d');
}
function promoAdminResolveProducts(PDO $db, array $guids): array
{
    $guids = array_values(array_unique(array_map(static fn($v) => strtolower(trim((string)$v)), $guids)));
    if (!$guids || count($guids) > 5000) throw new RuntimeException('В акции должен быть список от 1 до 5000 товаров.');
    foreach ($guids as $guid) if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $guid)) throw new RuntimeException("Некорректный onec_id: {$guid}");
    $marks = implode(',', array_fill(0, count($guids), '?'));
    $stmt = $db->prepare("SELECT id, onec_id, article, name, no_discount FROM products WHERE LOWER(onec_id) IN ({$marks})");
    $stmt->execute($guids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $found = [];
    foreach ($rows as $row) $found[strtolower((string)$row['onec_id'])] = $row;
    $missing = array_values(array_diff($guids, array_keys($found)));
    if ($missing) throw new RuntimeException('В каталоге не найдены onec_id: ' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' …' : ''));
    $blocked = [];
    foreach ($found as $guid => $row) if ((int)$row['no_discount'] === 1) $blocked[] = $guid;
    if ($blocked) throw new RuntimeException('Товары no_discount нельзя включать в акции: ' . implode(', ', array_slice($blocked, 0, 10)) . (count($blocked) > 10 ? ' …' : ''));
    $ids = [];
    foreach ($guids as $guid) $ids[] = (int)$found[$guid]['id'];
    return $ids;
}
function promoAdminStorePicture(array $file): string
{
    if (!isset($file['error']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) return '';
    if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Не удалось загрузить изображение плашки.');
    if ((int)($file['size'] ?? 0) < 1 || (int)$file['size'] > 4 * 1024 * 1024) throw new RuntimeException('Изображение должно быть не больше 4 МБ.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Загруженный файл изображения не прошёл проверку.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    if (!isset($extensions[$mime])) throw new RuntimeException('Поддерживаются только JPG, PNG, WebP и GIF (SVG не допускается).');
    $directory = rtrim(UPLOAD_PATH, '/\\') . '/promotions';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Не удалось создать папку для изображений акций.');
    if (!is_writable($directory)) throw new RuntimeException('Папка изображений акций недоступна для записи.');
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($tmp, $directory . '/' . $filename)) throw new RuntimeException('Не удалось сохранить изображение акции.');
    return $filename;
}
function promoAdminDeletePicture(string $filename): void
{
    $filename = basename($filename);
    if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|gif)$/', $filename)) return;
    $path = rtrim(UPLOAD_PATH, '/\\') . '/promotions/' . $filename;
    if (is_file($path)) @unlink($path);
}

function promoAdminSaveLinks(PDO $db, int $promoId, array $productIds): void
{
    $db->prepare('DELETE FROM product_promos WHERE promo_id = ?')->execute([$promoId]);
    $stmt = $db->prepare('INSERT INTO product_promos (product_id, promo_id) VALUES (?, ?)');
    foreach (array_unique(array_map('intval', $productIds)) as $productId) $stmt->execute([$productId, $promoId]);
}

$notices = ['saved'=>'Акция сохранена.','deleted'=>'Акция удалена.'];
$notice = $notices[(string)($_GET['notice'] ?? '')] ?? '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Сессия формы устарела. Обновите страницу.'); }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            $id = filter_var($_POST['promo_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $type = (string)($_POST['type'] ?? '');
            if (!in_array($type,['product_discount','quantity_discount'],true)) throw new RuntimeException('Выберите корректный тип акции.');
            $title=trim((string)($_POST['title']??'')); if($title===''||mb_strlen($title,'UTF-8')>255)throw new RuntimeException('Название обязательно и не длиннее 255 символов.');
            $discount=filter_var($_POST['discount_percent']??null,FILTER_VALIDATE_INT); if($discount===false||$discount<1||$discount>99)throw new RuntimeException('Процент скидки должен быть от 1 до 99.');
            $start=promoAdminDate((string)($_POST['start_date']??''),'начала'); $end=promoAdminDate((string)($_POST['end_date']??''),'окончания'); if($end<$start)throw new RuntimeException('Дата окончания акции раньше даты начала.');
            $min=0; if($type==='quantity_discount'){$min=filter_var($_POST['min_quantity']??null,FILTER_VALIDATE_INT);if($min===false||$min<2||$min>100000)throw new RuntimeException('Минимальное количество — целое число от 2 до 100000.');}
            $enabled=isset($_POST['enabled'])?1:0;
            $rawIds=preg_split('/[\\s,;]+/',trim((string)($_POST['product_onec_ids']??'')),-1,PREG_SPLIT_NO_EMPTY) ?: [];
            $productIds=promoAdminResolveProducts($db,$rawIds);
            $pubName=trim((string)($_POST['pub_name']??'')); if(mb_strlen($pubName,'UTF-8')>255)throw new RuntimeException('Pub_Name не может быть длиннее 255 символов.');
            $existingPicture='';
            if($id){$q=$db->prepare('SELECT picture FROM promos WHERE id=?');$q->execute([(int)$id]);$existingPicture=(string)($q->fetchColumn()?:'');}
            $removePicture=isset($_POST['remove_picture']);
            $newPicture=promoAdminStorePicture($_FILES['picture']??[]);
            $picture=$newPicture!==''?$newPicture:($removePicture?'':$existingPicture);
            $oldPictureToDelete=($existingPicture!==''&&$picture!==$existingPicture)?$existingPicture:'';
            try {
                $db->beginTransaction();
                if($id){$check=$db->prepare('SELECT id FROM promos WHERE id=? FOR UPDATE');$check->execute([(int)$id]);if(!$check->fetchColumn())throw new RuntimeException('Акция не найдена.');$db->prepare('UPDATE promos SET name=?,pub_name=?,picture=?,discount=?,start_date=?,end_date=?,promotion_type=?,minimum_quantity=?,enabled=? WHERE id=?')->execute([$title,$pubName!==''?$pubName:null,$picture!==''?$picture:null,$discount,$start.' 00:00:00',$end.' 23:59:59',$type,$min,$enabled,(int)$id]);$promoId=(int)$id;}
                else{$db->prepare('INSERT INTO promos (name,pub_name,picture,discount,start_date,end_date,promotion_type,minimum_quantity,enabled,import_key) VALUES (?,?,?,?,?,?,?,?,?,NULL)')->execute([$title,$pubName!==''?$pubName:null,$picture!==''?$picture:null,$discount,$start.' 00:00:00',$end.' 23:59:59',$type,$min,$enabled]);$promoId=(int)$db->lastInsertId();}
                promoAdminSaveLinks($db,$promoId,$productIds);$db->commit();
            } catch(Throwable $saveError) {
                if($db->inTransaction())$db->rollBack();
                if($newPicture!=='')promoAdminDeletePicture($newPicture);
                throw $saveError;
            }
            if($oldPictureToDelete!=='')promoAdminDeletePicture($oldPictureToDelete);
            promoAdminRedirect('saved',$promoId);
        } elseif ($action === 'delete') {
            $id=filter_var($_POST['promo_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new RuntimeException('Некорректный ID акции.');$q=$db->prepare('SELECT picture FROM promos WHERE id=?');$q->execute([(int)$id]);$pictureToDelete=(string)($q->fetchColumn()?:'');$db->prepare('DELETE FROM promos WHERE id=?')->execute([(int)$id]);if($pictureToDelete!=='')promoAdminDeletePicture($pictureToDelete);promoAdminRedirect('deleted');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Promotion admin error: '.$e->getMessage());
        $error=$e instanceof JsonException ? 'JSON повреждён или имеет неверный формат.' : $e->getMessage();
    }
}
$editId=max(0,(int)($_GET['edit']??0));
$editing=null;$selectedGuids=[];
if($editId){$stmt=$db->prepare('SELECT * FROM promos WHERE id=?');$stmt->execute([$editId]);$editing=$stmt->fetch(PDO::FETCH_ASSOC)?:null;if($editing){$q=$db->prepare('SELECT p.onec_id FROM product_promos pp JOIN products p ON p.id=pp.product_id WHERE pp.promo_id=? ORDER BY p.article');$q->execute([$editId]);$selectedGuids=$q->fetchAll(PDO::FETCH_COLUMN);}}
$list=$db->query("SELECT p.*,COUNT(pp.product_id) AS product_count FROM promos p LEFT JOIN product_promos pp ON pp.promo_id=p.id GROUP BY p.id ORDER BY p.enabled DESC,p.start_date DESC,p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Акции — Админ-панель'; require __DIR__.'/../templates/header.php';
?>
<div class="admin admin-promotions">
    <div class="admin__header"><h1>Управление акциями</h1><div><a href="/admin/import-promotions.php" class="btn btn--primary btn--small">Загрузить JSON из 1С</a> <a href="/admin/index.php" class="btn btn--small">← В админку</a></div></div>
    <?php if($notice): ?><div class="admin-notice"><?=promoAdminH($notice)?></div><?php endif; ?>
    <?php if($error!==''): ?><div class="alert alert--error"><?=promoAdminH($error)?></div><?php endif; ?>
    <section class="promo-admin-panel"><div class="promo-admin-heading"><h2><?= $editing ? 'Редактировать акцию' : 'Создать акцию вручную' ?></h2><?php if($editing): ?><a class="btn btn--small" href="/admin/promotions.php">Новая акция</a><?php endif; ?></div>
        <form method="post" enctype="multipart/form-data" class="promo-admin-form"><input type="hidden" name="csrf" value="<?=promoAdminH($csrf)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="promo_id" value="<?= (int)($editing['id']??0) ?>">
            <label>Название акции<input name="title" maxlength="255" required value="<?=promoAdminH($editing['name']??'')?>"></label>
            <label>Pub_Name — текст плашки <small>Необязательно. Если заполнено, на плашке будет показано это имя вместо названия акции.</small><input name="pub_name" maxlength="255" value="<?=promoAdminH($editing['pub_name']??'')?>"></label>
            <label>Picture — изображение подложки плашки <small>Необязательно. JPG, PNG, WebP или GIF, максимум 4 МБ.</small><input type="file" name="picture" accept="image/jpeg,image/png,image/webp,image/gif">
                <?php if (!empty($editing['picture']) && preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|gif)$/', (string)$editing['picture'])): ?><span class="promo-admin-picture-preview"><img src="/uploads/promotions/<?=rawurlencode((string)$editing['picture'])?>" alt="Текущая подложка плашки"><span class="promo-admin-check"><input type="checkbox" name="remove_picture" value="1"> Удалить текущее изображение</span></span><?php endif; ?>
            </label>
            <div class="promo-admin-grid"><label>Тип<select name="type"><option value="product_discount" <?=($editing['promotion_type']??'product_discount')==='product_discount'?'selected':''?>>Скидка на товар</option><option value="quantity_discount" <?=($editing['promotion_type']??'')==='quantity_discount'?'selected':''?>>Скидка от количества группы</option></select></label><label>Скидка, %<input type="number" min="1" max="99" name="discount_percent" required value="<?= (int)($editing['discount']??10) ?>"></label><label>Порог количества (для групповой акции)<input type="number" min="2" max="100000" name="min_quantity" value="<?= (int)($editing['minimum_quantity']??10) ?>"></label></div>
            <div class="promo-admin-grid"><label>Начало, Москва<input type="date" name="start_date" required value="<?=promoAdminH(isset($editing['start_date'])?substr($editing['start_date'],0,10):date('Y-m-d'))?>"></label><label>Окончание, Москва<input type="date" name="end_date" required value="<?=promoAdminH(isset($editing['end_date'])?substr($editing['end_date'],0,10):date('Y-m-d'))?>"></label></div>
            <label class="promo-admin-check"><input type="checkbox" name="enabled" value="1" <?=!isset($editing['enabled'])||(int)$editing['enabled']===1?'checked':''?>> Акция включена</label>
            <label>onec_id участвующих товаров <small>По одному GUID на строку; можно также разделять запятыми или пробелами. Товары `no_discount` запрещены.</small><textarea name="product_onec_ids" rows="7" required><?=promoAdminH(implode("\n",$selectedGuids))?></textarea></label>
            <button class="btn btn--primary">Сохранить акцию</button>
        </form>
    </section>
    <section class="promo-admin-panel"><h2>Все акции</h2>
        <?php if(!$list): ?><p>Акций пока нет.</p><?php else: ?><div class="promo-admin-table-wrap"><table class="admin-table"><thead><tr><th>Название / тип</th><th>Скидка</th><th>Даты действия</th><th>Товары</th><th>Статус</th><th>Действия</th></tr></thead><tbody>
        <?php foreach($list as $p): ?><tr><td><strong><?=promoAdminH($p['name'])?></strong><br><small><?= $p['promotion_type']==='quantity_discount'?'От '.(int)$p['minimum_quantity'].' шт.':'На каждый товар' ?><?=!empty($p['import_key'])?' · JSON':''?></small></td><td><?= (int)$p['discount']?>%</td><td><?=promoAdminH(substr($p['start_date'],0,10))?> — <?=promoAdminH(substr($p['end_date'],0,10))?></td><td><?= (int)$p['product_count']?></td><td><?= (int)$p['enabled']?'Включена':'Выключена'?></td><td class="promo-admin-actions"><a class="btn btn--small" href="/admin/promotions.php?edit=<?= (int)$p['id']?>">Изменить</a><form method="post" onsubmit="return confirm('Удалить акцию и её связи с товарами?')"><input type="hidden" name="csrf" value="<?=promoAdminH($csrf)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="promo_id" value="<?= (int)$p['id']?>"><button class="btn btn--small btn--danger">Удалить</button></form></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
</div>
<?php require __DIR__.'/../templates/footer.php'; ?>
