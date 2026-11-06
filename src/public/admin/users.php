<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Models/Phone.php';
$db = getDBConnection();
if (empty($_SESSION['admin_users_csrf'])) $_SESSION['admin_users_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['admin_users_csrf'];
function userGo(string $notice, int $id = 0): void { header('Location: /admin/users.php?notice=' . rawurlencode($notice) . ($id ? '&id=' . $id : '')); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Сессия формы устарела. Обновите страницу.'); }
    $action = (string)($_POST['action'] ?? '');
    $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$id || !in_array($action, ['save','password','block','unblock','delete','merge','role'], true)) { http_response_code(400); exit('Некорректные данные.'); }
    $id = (int)$id;
    if ($id === (int)$_SESSION['admin_id'] && in_array($action, ['block','delete','merge','role'], true)) userGo('self', $id);
    try {
        if ($action === 'save') {
            $name = trim((string)($_POST['name'] ?? ''));
            $phone = Phone::normalizeRussian((string)($_POST['phone'] ?? ''));
            $discount = filter_var($_POST['discount'] ?? null, FILTER_VALIDATE_INT);
            $dateRaw = trim((string)($_POST['discount_end_date'] ?? ''));
            if ($name === '' || strlen($name)>255 || $phone===null || $discount===false || $discount<0 || $discount>100) userGo('invalid',$id);
            $end = null;
            if ($dateRaw!=='') { $dt=DateTime::createFromFormat('Y-m-d\\TH:i',$dateRaw); if(!$dt || $dt->format('Y-m-d\\TH:i')!==$dateRaw) userGo('invalid',$id); $end=$dt->format('Y-m-d H:i:s'); }
            foreach ($db->query('SELECT id, phone FROM users WHERE id <> '.(int)$id)->fetchAll(PDO::FETCH_ASSOC) as $other) {
                if (Phone::normalizeRussian((string)$other['phone'])===$phone) userGo('phone_exists',$id);
            }
            $db->prepare('UPDATE users SET name=?, phone=?, discount=?, discount_end_date=? WHERE id=?')->execute([$name,$phone,$discount,$end,$id]);
            userGo('saved',$id);
        }
        if ($action === 'password') {
            $pass=(string)($_POST['password']??''); $confirm=(string)($_POST['password_confirm']??'');
            if(strlen($pass)<6 || $pass!==$confirm) userGo('password_invalid',$id);
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pass,PASSWORD_DEFAULT),$id]); userGo('password_saved',$id);
        }
        if ($action === 'block' || $action === 'unblock') {
            $check=$db->prepare('SELECT is_admin FROM users WHERE id=?');$check->execute([$id]);$current=$check->fetch(PDO::FETCH_ASSOC);
            if(!$current) userGo('missing',$id);
            if($action==='block' && (int)$current['is_admin']===1) userGo('demote_first',$id);
            $db->prepare('UPDATE users SET is_blocked=? WHERE id=?')->execute([$action==='block'?1:0,$id]); userGo($action==='block'?'blocked':'unblocked',$id);
        }
        if ($action === 'role') {
            $desired=(string)($_POST['is_admin']??''); if(!in_array($desired,['0','1'],true)) userGo('invalid',$id);
            $db->beginTransaction(); $lock=$db->prepare('SELECT id,is_admin,is_blocked FROM users WHERE id=? FOR UPDATE');$lock->execute([$id]);$target=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$target){$db->rollBack();userGo('missing',$id);}
            if($desired==='1' && (int)$target['is_blocked']===1){$db->rollBack();userGo('blocked_promote',$id);}
            if((int)$target['is_admin']===1 && $desired==='0') { $admins=$db->query('SELECT id FROM users WHERE is_admin=1 FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN); if(count($admins)<=1){$db->rollBack();userGo('last_admin',$id);} }
            $db->prepare('UPDATE users SET is_admin=? WHERE id=?')->execute([(int)$desired,$id]);$db->commit();userGo($desired==='1'?'promoted':'demoted',$id);
        }
        if ($action === 'delete') {
            $db->beginTransaction(); $lock=$db->prepare('SELECT id,is_admin FROM users WHERE id=? FOR UPDATE');$lock->execute([$id]);$target=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$target){$db->rollBack();userGo('missing',$id);}
            if((int)$target['is_admin']===1){$db->rollBack();userGo('demote_first',$id);}
            $o=$db->prepare('SELECT COUNT(*) FROM orders WHERE user_id=?');$o->execute([$id]);if((int)$o->fetchColumn()>0){$db->rollBack();userGo('has_orders',$id);}
            $c=$db->prepare('SELECT id FROM carts WHERE user_id=?');$c->execute([$id]);$cartIds=array_map('intval',$c->fetchAll(PDO::FETCH_COLUMN));
            if($cartIds){$marks=implode(',',array_fill(0,count($cartIds),'?'));$db->prepare("DELETE FROM cart_items WHERE cart_id IN ($marks)")->execute($cartIds);$db->prepare("DELETE FROM carts WHERE id IN ($marks)")->execute($cartIds);}
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);$db->commit();userGo('deleted');
        }
        if ($action === 'merge') {
            $targetId=filter_var($_POST['target_user_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if(!$targetId || (int)$targetId===$id || (int)$targetId===(int)$_SESSION['admin_id']) userGo('merge_invalid',$id);
            $targetId=(int)$targetId; $db->beginTransaction(); $ids=[$id,$targetId];sort($ids);
            $lock=$db->prepare('SELECT id,phone,is_admin,is_blocked FROM users WHERE id IN (?,?) ORDER BY id FOR UPDATE');$lock->execute($ids);$rows=$lock->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==2){$db->rollBack();userGo('merge_missing',$id);} $by=[];foreach($rows as $r)$by[(int)$r['id']]=$r;
            if((int)$by[$id]['is_admin'] || (int)$by[$id]['is_blocked'] || (int)$by[$targetId]['is_blocked']){$db->rollBack();userGo('merge_not_allowed',$id);}
            if(Phone::normalizeRussian((string)$by[$id]['phone'])!==Phone::normalizeRussian((string)$by[$targetId]['phone'])){$db->rollBack();userGo('merge_different',$id);}
            // Keep the chosen target's name, password and discount; move source orders and carts, then remove the duplicate row.
            $db->prepare('UPDATE orders SET user_id=? WHERE user_id=?')->execute([$targetId,$id]);
            $sourceCarts=$db->prepare('SELECT id FROM carts WHERE user_id=?');$sourceCarts->execute([$id]);
            foreach($sourceCarts->fetchAll(PDO::FETCH_COLUMN) as $sourceCartId){
                $targetCart=$db->prepare('SELECT id FROM carts WHERE user_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE');$targetCart->execute([$targetId]);$targetCartId=$targetCart->fetchColumn();
                if($targetCartId===false){$db->prepare('UPDATE carts SET user_id=? WHERE id=?')->execute([$targetId,(int)$sourceCartId]);continue;}
                $items=$db->prepare('SELECT id,product_id,quantity,notify_on_available FROM cart_items WHERE cart_id=?');$items->execute([(int)$sourceCartId]);
                foreach($items->fetchAll(PDO::FETCH_ASSOC) as $item){$find=$db->prepare('SELECT id,quantity FROM cart_items WHERE cart_id=? AND product_id=? ORDER BY id LIMIT 1 FOR UPDATE');$find->execute([(int)$targetCartId,(int)$item['product_id']]);$old=$find->fetch(PDO::FETCH_ASSOC);
                    if($old)$db->prepare('UPDATE cart_items SET quantity=?, notify_on_available=GREATEST(notify_on_available,?) WHERE id=?')->execute([min(999,(int)$old['quantity']+(int)$item['quantity']),(int)$item['notify_on_available'],(int)$old['id']]);
                    else $db->prepare('UPDATE cart_items SET cart_id=? WHERE id=?')->execute([(int)$targetCartId,(int)$item['id']]);}
                $db->prepare('DELETE FROM carts WHERE id=?')->execute([(int)$sourceCartId]);
            }
            $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);$db->commit();userGo('merged',$targetId);
        }
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('Admin users action failed: '.$e->getMessage());http_response_code(500);exit('Не удалось выполнить действие. Проверьте журналы сервера.');}
}

$q=trim((string)($_GET['q']??''));if(function_exists('mb_substr'))$q=mb_substr($q,0,100,'UTF-8');else$q=substr($q,0,100);
$selectedId=max(0,(int)($_GET['id']??0));$page=max(1,(int)($_GET['page']??1));$perPage=30;$where='';$params=[];
if($q!==''){$where=' WHERE name LIKE ? OR phone LIKE ?';$params=['%'.$q.'%','%'.$q.'%'];}
$c=$db->prepare('SELECT COUNT(*) FROM users'.$where);$c->execute($params);$total=(int)$c->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$offset=($page-1)*$perPage;
$list=$db->prepare('SELECT id,name,phone,discount,discount_end_date,is_admin,is_blocked,created_at FROM users'.$where.' ORDER BY id DESC LIMIT ? OFFSET ?');foreach($params as $i=>$v)$list->bindValue($i+1,$v,PDO::PARAM_STR);$list->bindValue(count($params)+1,$perPage,PDO::PARAM_INT);$list->bindValue(count($params)+2,$offset,PDO::PARAM_INT);$list->execute();$users=$list->fetchAll(PDO::FETCH_ASSOC);
$all=$db->query('SELECT id,phone FROM users')->fetchAll(PDO::FETCH_ASSOC);$groups=[];foreach($all as $r){$n=Phone::normalizeRussian((string)$r['phone']);if($n!==null)$groups[$n][]=(int)$r['id'];}
$admins=(int)$db->query('SELECT COUNT(*) FROM users WHERE is_admin=1')->fetchColumn();
$msg=['saved'=>'Данные и скидка сохранены.','phone_exists'=>'Этот телефон уже используется другим пользователем.','invalid'=>'Проверьте данные: имя, российский номер и скидка от 0 до 100%.','password_invalid'=>'Пароли не совпадают или короче 6 символов.','password_saved'=>'Пароль изменён.','blocked'=>'Пользователь заблокирован.','unblocked'=>'Блокировка снята.','deleted'=>'Пользователь удалён.','has_orders'=>'Удаление невозможно: у пользователя есть заказы. Для сохранения истории заказов используйте объединение аккаунтов или блокировку.','last_admin'=>'Нельзя снять права у последнего администратора.','blocked_promote'=>'Сначала разблокируйте пользователя, затем назначайте его администратором.','demote_first'=>'Сначала снимите с пользователя роль администратора.','self'=>'Нельзя выполнять это действие над собственной учётной записью.','missing'=>'Пользователь не найден.','promoted'=>'Пользователь назначен администратором.','demoted'=>'Права администратора сняты.','merge_invalid'=>'Выберите целевой аккаунт.','merge_missing'=>'Один из аккаунтов не найден.','merge_not_allowed'=>'Объединять можно только незаблокированный аккаунт-покупатель с незаблокированным аккаунтом.','merge_different'=>'Номера этих аккаунтов не совпадают после нормализации.','merged'=>'Аккаунты объединены: заказы и корзина перенесены, целевой аккаунт сохранён.'];$notice=$msg[(string)($_GET['notice']??'')]??'';
$pageTitle='Пользователи — Админ-панель';require __DIR__.'/../templates/header.php';
?>
<div class="admin admin-users">
<div class="admin__header"><h1>Пользователи</h1><a class="btn btn--small" href="/admin/index.php">← В админку</a></div>
<p class="admin-users__summary">Пользователей: <strong><?= $total ?></strong> · Администраторов: <strong><?= $admins ?></strong></p>
<?php if($notice):?><p class="admin-notice"><?=htmlspecialchars($notice,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></p><?php endif;?>
<form class="admin-users__search" method="get"><label for="uq">Поиск по имени или телефону</label><div><input id="uq" type="search" name="q" value="<?=htmlspecialchars($q,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"><button class="btn btn--primary btn--small">Найти</button></div></form>
<?php foreach($groups as $canonical=>$ids):if(count($ids)<2)continue;$marks=implode(',',array_fill(0,count($ids),'?'));$s=$db->prepare("SELECT id,name,phone,is_admin,is_blocked FROM users WHERE id IN ($marks) ORDER BY id");$s->execute($ids);$dupes=$s->fetchAll(PDO::FETCH_ASSOC);?>
<section class="admin-users__duplicates"><h2>Дубли номера <?=htmlspecialchars($canonical,ENT_QUOTES,'UTF-8')?></h2><p>Выберите аккаунт, который останется. Заказы и корзина дубля будут перенесены; имя, пароль и скидка целевого аккаунта сохранятся.</p>
<?php foreach($dupes as $d):?><div class="admin-users__duplicate-row"><span>#<?=(int)$d['id']?> · <?=htmlspecialchars((string)$d['name'],ENT_QUOTES,'UTF-8')?> · <?=htmlspecialchars((string)$d['phone'],ENT_QUOTES,'UTF-8')?><?=((int)$d['is_admin']?' · администратор':'')?><?=((int)$d['is_blocked']?' · заблокирован':'')?></span>
<?php if(!(int)$d['is_admin']&&!(int)$d['is_blocked']&&(int)$d['id']!==(int)$_SESSION['admin_id']):?><form method="post" onsubmit="return confirm('Объединить этот аккаунт с выбранным?');"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="action" value="merge"><input type="hidden" name="user_id" value="<?=(int)$d['id']?>"><select name="target_user_id" required><option value="">Оставить аккаунт…</option><?php foreach($dupes as $o):if((int)$o['id']===(int)$d['id']||(int)$o['is_blocked'])continue;?><option value="<?=(int)$o['id']?>">#<?=(int)$o['id']?> · <?=htmlspecialchars((string)$o['name'],ENT_QUOTES,'UTF-8')?></option><?php endforeach;?></select><button class="btn btn--small">Объединить</button></form><?php endif;?></div><?php endforeach;?></section><?php endforeach;?>
<div class="admin-users__table-wrap"><table class="admin-table admin-users__table"><thead><tr><th>ID</th><th>Имя</th><th>Телефон</th><th>Скидка</th><th>Регистрация</th><th>Статус</th><th>Действия</th></tr></thead><tbody>
<?php foreach($users as $u):$id=(int)$u['id'];$admin=(int)$u['is_admin']===1;$blocked=(int)$u['is_blocked']===1;$edit=$selectedId===$id;?><tr><td><?=$id?></td><td><?=htmlspecialchars((string)$u['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?:'—'?></td><td><?=htmlspecialchars((string)$u['phone'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></td><td><?=(int)$u['discount']?>%</td><td><?=htmlspecialchars((string)$u['created_at'],ENT_QUOTES,'UTF-8')?></td><td><?=$admin?'Администратор':'Покупатель'?><?=$blocked?' · Заблокирован':''?></td><td class="admin-users__actions"><a class="btn btn--small" href="?q=<?=rawurlencode($q)?>&amp;page=<?=$page?>&amp;id=<?=$id?>"><?=$edit?'Закрыть':'Редактировать'?></a>
<?php if($id!==(int)$_SESSION['admin_id']):?><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="user_id" value="<?=$id?>"><input type="hidden" name="action" value="<?=$blocked?'unblock':'block'?>"><button class="btn btn--small"><?=$blocked?'Разблокировать':'Заблокировать'?></button></form>
<?php if(!$admin):?><form method="post" onsubmit="return confirm('Назначить пользователя администратором?');"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="user_id" value="<?=$id?>"><input type="hidden" name="action" value="role"><input type="hidden" name="is_admin" value="1"><button class="btn btn--small">Назначить админом</button></form><?php else:?><form method="post" onsubmit="return confirm('Снять права администратора?');"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="user_id" value="<?=$id?>"><input type="hidden" name="action" value="role"><input type="hidden" name="is_admin" value="0"><button class="btn btn--small">Снять права</button></form><?php endif;?>
<form method="post" onsubmit="return confirm('Удалить пользователя? Заказы сохраняются и поэтому аккаунт с заказами удалить нельзя.');"><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="user_id" value="<?=$id?>"><input type="hidden" name="action" value="delete"><button class="btn btn--small admin-users__delete">Удалить</button></form><?php endif;?></td></tr>
<?php if($edit):?><tr><td colspan="7"><div class="admin-users__edit-grid"><form method="post" class="admin-users__edit-form"><h3>Данные и скидка</h3><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="action" value="save"><input type="hidden" name="user_id" value="<?=$id?>"><label>Имя<input name="name" required maxlength="255" value="<?=htmlspecialchars((string)$u['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"></label><label>Телефон<input type="tel" name="phone" required value="<?=htmlspecialchars((string)$u['phone'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>"></label><label>Скидка %<input type="number" name="discount" min="0" max="100" value="<?=(int)$u['discount']?>"></label><label>Скидка до (необязательно)<input type="datetime-local" name="discount_end_date" value="<?=$u['discount_end_date']?htmlspecialchars(date('Y-m-d\\TH:i',strtotime($u['discount_end_date'])),ENT_QUOTES,'UTF-8'):''?>"></label><button class="btn btn--primary btn--small">Сохранить</button></form><form method="post" class="admin-users__edit-form"><h3>Новый пароль</h3><input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="action" value="password"><input type="hidden" name="user_id" value="<?=$id?>"><label>Пароль, минимум 6 символов<input type="password" name="password" required minlength="6" autocomplete="new-password"></label><label>Повторите пароль<input type="password" name="password_confirm" required minlength="6" autocomplete="new-password"></label><button class="btn btn--primary btn--small">Изменить пароль</button></form></div></td></tr><?php endif;?><?php endforeach;?></tbody></table></div>
<?php if($pages>1):?><nav class="pagination"><?php for($i=1;$i<=$pages;$i++):?><a class="pagination__link <?=$i===$page?'pagination__link--active':''?>" href="?q=<?=rawurlencode($q)?>&amp;page=<?=$i?>"><?=$i?></a><?php endfor;?></nav><?php endif;?></div>
<?php require __DIR__.'/../templates/footer.php';?>
