<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
if (empty($_SESSION['admin_categories_csrf'])) $_SESSION['admin_categories_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['admin_categories_csrf'];

function catH(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function catRedirect(string $notice, int $id = 0): void
{
    $query = ['notice' => $notice];
    if ($id > 0) $query['id'] = $id;
    header('Location: /admin/categories.php?' . http_build_query($query));
    exit;
}
function catSlugBase(string $name): string
{
    $ascii = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
    $slug = strtolower((string)$ascii);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return substr($slug !== '' ? $slug : 'category', 0, 180);
}
function catSubtreeIds(PDO $db, int $root): array
{
    $rows = $db->query('SELECT id,parent_id FROM categories')->fetchAll(PDO::FETCH_ASSOC);
    $children = [];
    foreach ($rows as $row) $children[(int)($row['parent_id'] ?? 0)][] = (int)$row['id'];
    $ids = [];
    $stack = [$root];
    while ($stack) {
        $id = array_pop($stack);
        if (isset($ids[$id])) continue;
        $ids[$id] = true;
        foreach ($children[$id] ?? [] as $child) $stack[] = $child;
    }
    return array_map('intval', array_keys($ids));
}
function catMakeUniqueSlug(PDO $db, string $name, int $ignoreId = 0): string
{
    $base = catSlugBase($name);
    $slug = $base;
    for ($suffix = 2; ; $suffix++) {
        $stmt = $db->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $found = $stmt->fetchColumn();
        if ($found === false || (int)$found === $ignoreId) return $slug;
        $tail = '-' . $suffix;
        $slug = substr($base, 0, 190 - strlen($tail)) . $tail;
    }
}

$noticeMap = [
    'created' => 'Раздел создан.', 'renamed' => 'Название раздела обновлено.', 'moved' => 'Раздел перемещён.',
    'deleted' => 'Пустой раздел удалён.', 'products_moved' => 'Выбранные товары перенесены.',
    'invalid' => 'Проверьте введённые данные.', 'not_found' => 'Раздел не найден.',
    'has_products' => 'Удаление запрещено: в разделе или его подразделах есть товары.',
    'has_children' => 'Сначала переместите или удалите дочерние разделы.', 'cycle' => 'Нельзя переместить раздел внутрь самого себя или своего подраздела.',
    'same_parent' => 'Выберите другой родительский раздел.', 'no_products' => 'В этом разделе нет напрямую размещённых товаров для переноса.',
    'choose_products' => 'Отметьте хотя бы один товар.', 'move_conflict' => 'Один или несколько товаров уже привязаны к выбранному разделу.',
    'parent_missing' => 'Родительский раздел не найден.', 'db_error' => 'Операция не выполнена. Проверьте журнал ошибок сервера.',
];
$notice = $noticeMap[(string)($_GET['notice'] ?? '')] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Сессия формы устарела. Обновите страницу.'); }
    $action = (string)($_POST['action'] ?? '');
    $id = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $parentRaw = (string)($_POST['parent_id'] ?? '0');
            $parentId = $parentRaw === '0' ? null : filter_var($parentRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($name === '' || mb_strlen($name, 'UTF-8') > 255 || ($parentRaw !== '0' && !$parentId)) catRedirect('invalid');
            if ($parentId !== null) { $check = $db->prepare('SELECT id FROM categories WHERE id=?'); $check->execute([$parentId]); if (!$check->fetchColumn()) catRedirect('parent_missing'); }
            $slug = catMakeUniqueSlug($db, $name);
            $db->prepare('INSERT INTO categories (name,slug,parent_id) VALUES (?,?,?)')->execute([$name,$slug,$parentId]);
            catRedirect('created', (int)$db->lastInsertId());
        }

        if ($action === 'rename' && $id) {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name, 'UTF-8') > 255) catRedirect('invalid', (int)$id);
            $stmt = $db->prepare('SELECT id FROM categories WHERE id=?'); $stmt->execute([(int)$id]); if (!$stmt->fetchColumn()) catRedirect('not_found');
            // Keep the existing URL slug stable when only the display name changes.
            $db->prepare('UPDATE categories SET name=? WHERE id=?')->execute([$name,(int)$id]);
            catRedirect('renamed', (int)$id);
        }

        if ($action === 'move_category' && $id) {
            $parentRaw = (string)($_POST['parent_id'] ?? '0');
            $parentId = $parentRaw === '0' ? null : filter_var($parentRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($parentRaw !== '0' && !$parentId) catRedirect('invalid', (int)$id);
            $stmt = $db->prepare('SELECT id,parent_id FROM categories WHERE id=?'); $stmt->execute([(int)$id]); $category = $stmt->fetch(PDO::FETCH_ASSOC); if (!$category) catRedirect('not_found');
            if ($parentId !== null) {
                $p = $db->prepare('SELECT id FROM categories WHERE id=?'); $p->execute([$parentId]); if (!$p->fetchColumn()) catRedirect('parent_missing', (int)$id);
                if ((int)$parentId === (int)$id || in_array((int)$parentId, catSubtreeIds($db, (int)$id), true)) catRedirect('cycle', (int)$id);
                if ((int)$category['parent_id'] === (int)$parentId) catRedirect('same_parent', (int)$id);
            } elseif ($category['parent_id'] === null) catRedirect('same_parent', (int)$id);
            $db->prepare('UPDATE categories SET parent_id=? WHERE id=?')->execute([$parentId,(int)$id]);
            catRedirect('moved', (int)$id);
        }

        if ($action === 'delete' && $id) {
            $db->beginTransaction();
            $lock = $db->prepare('SELECT id FROM categories WHERE id=? FOR UPDATE'); $lock->execute([(int)$id]); if (!$lock->fetchColumn()) { $db->rollBack(); catRedirect('not_found'); }
            $subtree = catSubtreeIds($db, (int)$id);
            $marks = implode(',', array_fill(0, count($subtree), '?'));
            $products = $db->prepare("SELECT COUNT(DISTINCT product_id) FROM product_categories WHERE category_id IN ($marks)"); $products->execute($subtree);
            if ((int)$products->fetchColumn() > 0) { $db->rollBack(); catRedirect('has_products', (int)$id); }
            $children = $db->prepare('SELECT COUNT(*) FROM categories WHERE parent_id=?'); $children->execute([(int)$id]);
            if ((int)$children->fetchColumn() > 0) { $db->rollBack(); catRedirect('has_children', (int)$id); }
            $db->prepare('DELETE FROM categories WHERE id=?')->execute([(int)$id]); $db->commit(); catRedirect('deleted');
        }

        if ($action === 'move_products' && $id) {
            $sourceId = (int)$id;
            $targetId = filter_var($_POST['target_category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $productIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['product_ids'] ?? [])), static fn($v) => $v > 0)));
            if (!$targetId || !$productIds) catRedirect('choose_products', $sourceId);
            if ((int)$targetId === $sourceId) catRedirect('invalid', $sourceId);
            $db->beginTransaction();
            $lock = $db->prepare('SELECT id FROM categories WHERE id IN (?,?) ORDER BY id FOR UPDATE'); $lock->execute([$sourceId,(int)$targetId]); if (count($lock->fetchAll(PDO::FETCH_COLUMN)) !== 2) { $db->rollBack(); catRedirect('not_found', $sourceId); }
            $verify = $db->prepare('SELECT product_id FROM product_categories WHERE category_id=? AND product_id=? FOR UPDATE');
            $remove = $db->prepare('DELETE FROM product_categories WHERE category_id=? AND product_id=?');
            $add = $db->prepare('INSERT IGNORE INTO product_categories (product_id,category_id) VALUES (?,?)');
            $movedCount = 0;
            foreach ($productIds as $productId) {
                $verify->execute([$sourceId,$productId]);
                if (!$verify->fetchColumn()) continue; // Ignore forged IDs not directly in this source category.
                $already = $db->prepare('SELECT 1 FROM product_categories WHERE product_id=? AND category_id=?'); $already->execute([$productId,(int)$targetId]);
                if ($already->fetchColumn()) { $db->rollBack(); catRedirect('move_conflict', $sourceId); }
                $add->execute([$productId,(int)$targetId]);
                $remove->execute([$sourceId,$productId]);
                $movedCount++;
            }
            if ($movedCount === 0) { $db->rollBack(); catRedirect('no_products', $sourceId); }
            $db->commit(); catRedirect('products_moved', $sourceId);
        }

        catRedirect('invalid', $id ? (int)$id : 0);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Admin category management failed: ' . $e->getMessage());
        catRedirect('db_error', $id ? (int)$id : 0);
    }
}

$allCategories = $db->query('SELECT c.id,c.name,c.slug,c.parent_id,(SELECT COUNT(*) FROM product_categories pc WHERE pc.category_id=c.id) AS direct_count FROM categories c ORDER BY c.name')->fetchAll(PDO::FETCH_ASSOC);
$byId = []; $children = [];
foreach ($allCategories as $c) { $idc=(int)$c['id']; $byId[$idc]=$c; $children[(int)($c['parent_id'] ?? 0)][]=$idc; }
$selectedId = max(0, (int)($_GET['id'] ?? 0));
if ($selectedId && !isset($byId[$selectedId])) $selectedId = 0;
$search = trim((string)($_GET['q'] ?? '')); if (mb_strlen($search,'UTF-8') > 100) $search = mb_substr($search,0,100,'UTF-8');
$page = max(1,(int)($_GET['page'] ?? 1)); $perPage=40; $directTotal=0; $products=[];
if ($selectedId) {
    $where = ' WHERE pc.category_id = ?'; $params=[$selectedId];
    if ($search !== '') { $where .= ' AND (p.name LIKE ? OR p.article LIKE ?)'; $params[]='%'.$search.'%'; $params[]='%'.$search.'%'; }
    $cnt=$db->prepare('SELECT COUNT(*) FROM product_categories pc JOIN products p ON p.id=pc.product_id'.$where); $cnt->execute($params); $directTotal=(int)$cnt->fetchColumn();
    $pages=max(1,(int)ceil($directTotal/$perPage)); $page=min($page,$pages); $offset=($page-1)*$perPage;
    $stmt=$db->prepare('SELECT p.id,p.article,p.name,p.price,p.quantity FROM product_categories pc JOIN products p ON p.id=pc.product_id'.$where.' ORDER BY p.name,p.id LIMIT ? OFFSET ?');
    foreach($params as $i=>$v)$stmt->bindValue($i+1,$v,PDO::PARAM_STR); $stmt->bindValue(count($params)+1,$perPage,PDO::PARAM_INT); $stmt->bindValue(count($params)+2,$offset,PDO::PARAM_INT); $stmt->execute(); $products=$stmt->fetchAll(PDO::FETCH_ASSOC);
} else { $pages=1; }
$subtreeSelected = $selectedId ? catSubtreeIds($db,$selectedId) : [];
$subtreeDirectProducts=0;
if ($subtreeSelected) { $marks=implode(',',array_fill(0,count($subtreeSelected),'?')); $c=$db->prepare("SELECT COUNT(DISTINCT product_id) FROM product_categories WHERE category_id IN ($marks)"); $c->execute($subtreeSelected); $subtreeDirectProducts=(int)$c->fetchColumn(); }
$parentOptions = $allCategories;
$categoriesWithCounts = [];
$directCounts=[]; foreach($allCategories as $c)$directCounts[(int)$c['id']]=(int)$c['direct_count'];
$pageTitle='Категории каталога — Админ-панель'; require __DIR__ . '/../templates/header.php';
?>
<div class="admin admin-categories">
    <div class="admin__header"><h1>Категории каталога</h1><a class="btn btn--small" href="/admin/index.php">← В админку</a></div>
    <?php if ($notice): ?><p class="admin-notice"><?= catH($notice) ?></p><?php endif; ?>
    <div class="admin-categories__layout">
        <section class="admin-categories__tree-panel"><h2>Дерево каталога</h2>
            <?php if (!$allCategories): ?><p>Разделов пока нет.</p><?php else: ?><ul class="admin-categories__tree"><?php
            $renderTree = function(int $parent) use (&$renderTree,$children,$byId,$selectedId): void {
                foreach($children[$parent] ?? [] as $childId) { $c=$byId[$childId]; echo '<li><a class="'.($childId===$selectedId?'is-active':'').'" href="/admin/categories.php?id='.$childId.'">'.catH($c['name']).'</a> <small>'.(int)$c['direct_count'].' товаров напрямую</small>'; if(!empty($children[$childId])) { echo '<ul>'; $renderTree($childId); echo '</ul>'; } echo '</li>'; }
            };
            $renderTree(0); ?></ul><?php endif; ?>
            <form method="post" class="admin-categories__form"><input type="hidden" name="csrf" value="<?= catH($csrf) ?>"><input type="hidden" name="action" value="create"><h3>Добавить раздел</h3><label>Название<input name="name" maxlength="255" required></label><label>Родительский раздел<select name="parent_id"><option value="0">Корневой раздел</option><?php foreach($allCategories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $selectedId===(int)$c['id']?'selected':'' ?>><?= catH($c['name']) ?> (ID <?= (int)$c['id'] ?>)</option><?php endforeach; ?></select></label><button class="btn btn--primary btn--small">Добавить</button></form>
        </section>

        <?php if ($selectedId): $selected=$byId[$selectedId]; ?>
        <section class="admin-categories__detail">
            <h2><?= catH($selected['name']) ?></h2><p class="admin-categories__meta">Прямая привязка: <?= (int)$selected['direct_count'] ?> товаров · В этой ветке всего: <?= $subtreeDirectProducts ?> товаров</p>
            <form method="post" class="admin-categories__form"><input type="hidden" name="csrf" value="<?= catH($csrf) ?>"><input type="hidden" name="action" value="rename"><input type="hidden" name="category_id" value="<?= $selectedId ?>"><label>Переименовать<input name="name" maxlength="255" required value="<?= catH($selected['name']) ?>"></label><button class="btn btn--small">Сохранить</button></form>
            <form method="post" class="admin-categories__form"><input type="hidden" name="csrf" value="<?= catH($csrf) ?>"><input type="hidden" name="action" value="move_category"><input type="hidden" name="category_id" value="<?= $selectedId ?>"><label>Переместить раздел<select name="parent_id"><option value="0" <?= $selected['parent_id']===null?'selected':'' ?>>В корень</option><?php foreach($parentOptions as $c): if((int)$c['id']===$selectedId)continue; ?><option value="<?= (int)$c['id'] ?>" <?= (int)$selected['parent_id']===(int)$c['id']?'selected':'' ?>><?= catH($c['name']) ?> (ID <?= (int)$c['id'] ?>)</option><?php endforeach; ?></select></label><button class="btn btn--small">Переместить</button></form>
            <form method="post" class="admin-categories__delete" onsubmit="return confirm('Удалить этот пустой раздел?');"><input type="hidden" name="csrf" value="<?= catH($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="category_id" value="<?= $selectedId ?>"><button class="btn btn--small btn--danger" <?= $subtreeDirectProducts>0?'disabled':'' ?>>Удалить пустой раздел</button><small>Удаление запрещено, если товары есть прямо в разделе или в его вложенных ветках. Если у пустого раздела есть подразделы, сначала удалите их.</small></form>

            <h3>Товары, назначенные непосредственно в этот раздел</h3>
            <form method="get" class="admin-categories__search"><input type="hidden" name="id" value="<?= $selectedId ?>"><label>Поиск по названию или артикулу<input type="search" name="q" value="<?= catH($search) ?>"></label><button class="btn btn--small">Найти</button><a class="btn btn--small" href="/admin/categories.php?id=<?= $selectedId ?>">Сбросить</a></form>
            <?php if ($products): ?><form method="post" id="category-products-form"><input type="hidden" name="csrf" value="<?= catH($csrf) ?>"><input type="hidden" name="action" value="move_products"><input type="hidden" name="category_id" value="<?= $selectedId ?>"><div class="admin-categories__move-bar"><label>Перенести отмеченные товары в<select name="target_category_id" required><option value="">Выберите раздел</option><?php foreach($allCategories as $c): if((int)$c['id']===$selectedId)continue; ?><option value="<?= (int)$c['id'] ?>"><?= catH($c['name']) ?> (ID <?= (int)$c['id'] ?>)</option><?php endforeach; ?></select></label><button class="btn btn--primary btn--small">Перенести</button></div>
                <div class="admin-categories__table-wrap"><table class="admin-table"><thead><tr><th><input type="checkbox" id="select-all-products" aria-label="Выбрать все товары на странице"></th><th>Артикул</th><th>Название</th><th>Цена</th><th>Остаток</th></tr></thead><tbody><?php foreach($products as $p): ?><tr><td><input type="checkbox" name="product_ids[]" value="<?= (int)$p['id'] ?>" class="category-product-check"></td><td><?= catH($p['article']) ?></td><td><a href="/admin/product-edit.php?id=<?= (int)$p['id'] ?>"><?= catH($p['name']) ?></a></td><td><?= number_format((float)$p['price'],0,'.',' ') ?> ₽</td><td><?= (int)$p['quantity'] ?></td></tr><?php endforeach; ?></tbody></table></div></form>
            <?php else: ?><p>В этом разделе нет напрямую назначенных товаров<?= $search!==''?' по данному поиску':'' ?>.</p><?php endif; ?>
            <?php if($pages>1): ?><nav class="admin-categories__pagination"><?php for($i=1;$i<=$pages;$i++): ?><a class="<?= $i===$page?'is-active':'' ?>" href="?id=<?= $selectedId ?>&amp;q=<?= rawurlencode($search) ?>&amp;page=<?= $i ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?>
        </section>
        <?php else: ?><section class="admin-categories__detail"><h2>Управление разделами</h2><p>Выберите раздел в дереве, чтобы переименовать, переместить его или перенести товары. Удаление раздела с товарами, в том числе во вложенных категориях, заблокировано.</p></section><?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){var all=document.getElementById('select-all-products');if(all)all.addEventListener('change',function(){document.querySelectorAll('.category-product-check').forEach(function(box){box.checked=all.checked;});});var form=document.getElementById('category-products-form');if(form)form.addEventListener('submit',function(e){if(!document.querySelector('.category-product-check:checked')){e.preventDefault();alert('Отметьте хотя бы один товар.');}});});
</script>
<?php require __DIR__ . '/../templates/footer.php'; ?>
