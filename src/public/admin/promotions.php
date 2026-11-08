<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
if (empty($_SESSION['admin_promotions_csrf'])) $_SESSION['admin_promotions_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['admin_promotions_csrf'];
$stageKey = 'promotion_import_stage';

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
function promoAdminNormalizeJson(PDO $db, string $json): array
{
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || (int)($data['schema_version'] ?? 0) !== 1 || !isset($data['promotions']) || !is_array($data['promotions'])) throw new RuntimeException('Ожидается JSON schema_version=1 и массив promotions.');
    if (count($data['promotions']) > 2000) throw new RuntimeException('В файле не должно быть более 2000 акций.');
    $normalized = []; $keys = [];
    foreach ($data['promotions'] as $index => $row) {
        $line = $index + 1;
        if (!is_array($row)) throw new RuntimeException("Акция {$line}: ожидался объект.");
        $type = (string)($row['type'] ?? '');
        if (!in_array($type, ['product_discount', 'quantity_discount'], true)) throw new RuntimeException("Акция {$line}: неверный type.");
        $title = trim((string)($row['title'] ?? ''));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 255) throw new RuntimeException("Акция {$line}: title обязателен и не длиннее 255 символов.");
        $discount = filter_var($row['discount_percent'] ?? null, FILTER_VALIDATE_INT);
        if ($discount === false || $discount < 1 || $discount > 99) throw new RuntimeException("Акция {$line}: discount_percent должен быть целым числом от 1 до 99.");
        $start = promoAdminDate((string)($row['start_date'] ?? ''), "start_date акции {$line}");
        $end = promoAdminDate((string)($row['end_date'] ?? ''), "end_date акции {$line}");
        if ($end < $start) throw new RuntimeException("Акция {$line}: end_date раньше start_date.");
        $enabled = $row['enabled'] ?? null;
        if (!is_bool($enabled)) throw new RuntimeException("Акция {$line}: enabled должен быть true или false.");
        $minimum = 0;
        if ($type === 'quantity_discount') {
            $minimum = filter_var($row['min_quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($minimum === false || $minimum < 2 || $minimum > 100000) throw new RuntimeException("Акция {$line}: min_quantity должен быть целым числом от 2 до 100000.");
        }
        $guids = $row['product_onec_ids'] ?? null;
        if (!is_array($guids) || !$guids || count($guids) > 5000) throw new RuntimeException("Акция {$line}: product_onec_ids должен быть непустым массивом.");
        $productIds = promoAdminResolveProducts($db, $guids);
        $externalId = trim((string)($row['promotion_id'] ?? ''));
        if ($externalId !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $externalId)) throw new RuntimeException("Акция {$line}: promotion_id содержит недопустимые символы.");
        // The supplied example has no ID; stable key from type+title makes repeated uploads update that promo.
        $identity = $externalId !== '' ? 'id:' . $externalId : 'title:' . mb_strtolower($title, 'UTF-8');
        $importKey = 'json:' . hash('sha256', $type . "\0" . $identity);
        if (isset($keys[$importKey])) throw new RuntimeException("Акция {$line}: повторяется promotion_id или пара type + title.");
        $keys[$importKey] = true;
        $normalized[] = ['type'=>$type,'title'=>$title,'discount_percent'=>$discount,'start_date'=>$start,'end_date'=>$end,'enabled'=>$enabled,'min_quantity'=>$minimum,'product_ids'=>$productIds,'import_key'=>$importKey];
    }
    return $normalized;
}
function promoAdminSaveLinks(PDO $db, int $promoId, array $productIds): void
{
    $db->prepare('DELETE FROM product_promos WHERE promo_id = ?')->execute([$promoId]);
    $stmt = $db->prepare('INSERT INTO product_promos (product_id, promo_id) VALUES (?, ?)');
    foreach (array_unique(array_map('intval', $productIds)) as $productId) $stmt->execute([$productId, $promoId]);
}

$notices = ['imported'=>'Импорт завершён: акции обновлены/созданы, связи с товарами обновлены.','saved'=>'Акция сохранена.','deleted'=>'Акция удалена.','stage_expired'=>'Предпросмотр импорта истёк. Загрузите файл ещё раз.'];
$notice = $notices[(string)($_GET['notice'] ?? '')] ?? '';
$error = '';
$preview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Сессия формы устарела. Обновите страницу.'); }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'upload_preview') {
            if (empty($_FILES['promotions_json']) || (int)$_FILES['promotions_json']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Выберите JSON-файл акций.');
            if ((int)$_FILES['promotions_json']['size'] > 10 * 1024 * 1024) throw new RuntimeException('Максимальный размер файла — 10 МБ.');
            $raw = file_get_contents((string)$_FILES['promotions_json']['tmp_name']);
            if ($raw === false) throw new RuntimeException('Не удалось прочитать файл.');
            $promos = promoAdminNormalizeJson($db, $raw);
            if (!$promos) throw new RuntimeException('В JSON нет акций для импорта.');
            $stagePath = tempnam(sys_get_temp_dir(), 'promotions-import-');
            if ($stagePath === false || file_put_contents($stagePath, json_encode($promos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить файл предпросмотра.');
            @chmod($stagePath, 0600);
            if (!empty($_SESSION[$stageKey]) && is_file((string)$_SESSION[$stageKey])) @unlink((string)$_SESSION[$stageKey]);
            $_SESSION[$stageKey] = $stagePath;
            $preview = ['count'=>count($promos),'products'=>array_sum(array_map(static fn($p)=>count($p['product_ids']),$promos)),'items'=>$promos];
        } elseif ($action === 'confirm_import') {
            $path = (string)($_SESSION[$stageKey] ?? '');
            unset($_SESSION[$stageKey]);
            if ($path === '' || !is_file($path)) promoAdminRedirect('stage_expired');
            $promos = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); @unlink($path);
            $db->beginTransaction();
            try {
                $find = $db->prepare('SELECT id FROM promos WHERE import_key = ? LIMIT 1');
                foreach ($promos as $p) {
                    $startAt = $p['start_date'] . ' 00:00:00';
                    $endAt = $p['end_date'] . ' 23:59:59';
                    $find->execute([$p['import_key']]); $id = $find->fetchColumn();
                    if ($id !== false) {
                        $promoId = (int)$id;
                        $db->prepare('UPDATE promos SET name=?,discount=?,start_date=?,end_date=?,promotion_type=?,minimum_quantity=?,enabled=? WHERE id=?')->execute([$p['title'],$p['discount_percent'],$startAt,$endAt,$p['type'],$p['min_quantity'],(int)$p['enabled'],$promoId]);
                    } else {
                        $db->prepare('INSERT INTO promos (name,discount,start_date,end_date,promotion_type,minimum_quantity,enabled,import_key) VALUES (?,?,?,?,?,?,?,?)')->execute([$p['title'],$p['discount_percent'],$startAt,$endAt,$p['type'],$p['min_quantity'],(int)$p['enabled'],$p['import_key']]);
                        $promoId=(int)$db->lastInsertId();
                    }
                    promoAdminSaveLinks($db,$promoId,$p['product_ids']);
                }
                $db->commit();
            } catch (Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
            promoAdminRedirect('imported');
        } elseif ($action === 'save') {
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
            $db->beginTransaction();
            if($id){$check=$db->prepare('SELECT id FROM promos WHERE id=? FOR UPDATE');$check->execute([(int)$id]);if(!$check->fetchColumn())throw new RuntimeException('Акция не найдена.');$db->prepare('UPDATE promos SET name=?,discount=?,start_date=?,end_date=?,promotion_type=?,minimum_quantity=?,enabled=? WHERE id=?')->execute([$title,$discount,$start.' 00:00:00',$end.' 23:59:59',$type,$min,$enabled,(int)$id]);$promoId=(int)$id;}
            else{$db->prepare('INSERT INTO promos (name,discount,start_date,end_date,promotion_type,minimum_quantity,enabled,import_key) VALUES (?,?,?,?,?,?,?,NULL)')->execute([$title,$discount,$start.' 00:00:00',$end.' 23:59:59',$type,$min,$enabled]);$promoId=(int)$db->lastInsertId();}
            promoAdminSaveLinks($db,$promoId,$productIds);$db->commit(); promoAdminRedirect('saved',$promoId);
        } elseif ($action === 'delete') {
            $id=filter_var($_POST['promo_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new RuntimeException('Некорректный ID акции.');$db->prepare('DELETE FROM promos WHERE id=?')->execute([(int)$id]);promoAdminRedirect('deleted');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Promotion admin error: '.$e->getMessage());
        $error=$e instanceof JsonException ? 'JSON повреждён или имеет неверный формат.' : $e->getMessage();
    }
}
if (!$preview && !empty($_SESSION[$stageKey]) && is_file((string)$_SESSION[$stageKey])) {
    $staged=json_decode((string)file_get_contents((string)$_SESSION[$stageKey]),true);
    if(is_array($staged))$preview=['count'=>count($staged),'products'=>array_sum(array_map(static fn($p)=>count($p['product_ids']??[]),$staged)),'items'=>$staged];
}
$editId=max(0,(int)($_GET['edit']??0));
$editing=null;$selectedGuids=[];
if($editId){$stmt=$db->prepare('SELECT * FROM promos WHERE id=?');$stmt->execute([$editId]);$editing=$stmt->fetch(PDO::FETCH_ASSOC)?:null;if($editing){$q=$db->prepare('SELECT p.onec_id FROM product_promos pp JOIN products p ON p.id=pp.product_id WHERE pp.promo_id=? ORDER BY p.article');$q->execute([$editId]);$selectedGuids=$q->fetchAll(PDO::FETCH_COLUMN);}}
$list=$db->query("SELECT p.*,COUNT(pp.product_id) AS product_count FROM promos p LEFT JOIN product_promos pp ON pp.promo_id=p.id GROUP BY p.id ORDER BY p.enabled DESC,p.start_date DESC,p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Акции — Админ-панель'; require __DIR__.'/../templates/header.php';
?>
<div class="admin admin-promotions">
    <div class="admin__header"><h1>Акции</h1><a href="/admin/index.php" class="btn btn--small">← В админку</a></div>
    <?php if($notice): ?><div class="admin-notice"><?=promoAdminH($notice)?></div><?php endif; ?>
    <?php if($error!==''): ?><div class="alert alert--error"><?=promoAdminH($error)?></div><?php endif; ?>
    <section class="promo-admin-panel" id="promo-upload"><h2>Загрузить акции из JSON</h2>
        <p>Импорт обновляет или создаёт акции по promotion_id (если передан) либо по сочетанию типа и названия. Отсутствующие в файле акции автоматически не удаляются.</p>
        <form method="post" enctype="multipart/form-data" class="promo-admin-upload"><input type="hidden" name="csrf" value="<?=promoAdminH($csrf)?>"><input type="hidden" name="action" value="upload_preview"><label>JSON-файл<input type="file" name="promotions_json" accept="application/json,.json" required></label><button class="btn btn--primary">Проверить файл</button></form>
        <?php if($preview): ?><div class="promo-admin-preview"><strong>Предпросмотр:</strong> <?= (int)$preview['count'] ?> акций, <?= (int)$preview['products'] ?> связей акция–товар. Неизвестные товары и `no_discount` были отклонены при проверке.<form method="post"><input type="hidden" name="csrf" value="<?=promoAdminH($csrf)?>"><input type="hidden" name="action" value="confirm_import"><button class="btn btn--primary">Подтвердить импорт</button></form></div><?php endif; ?>
    </section>
    <section class="promo-admin-panel" id="promo-editor"><div class="promo-admin-heading"><h2><?= $editing ? 'Редактировать акцию' : 'Создать акцию вручную' ?></h2><?php if($editing): ?><a class="btn btn--small" href="/admin/promotions.php">Новая акция</a><?php endif; ?></div>
        <form method="post" class="promo-admin-form"><input type="hidden" name="csrf" value="<?=promoAdminH($csrf)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="promo_id" value="<?= (int)($editing['id']??0) ?>">
            <label>Название акции<input name="title" maxlength="255" required value="<?=promoAdminH($editing['name']??'')?>"></label>
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
