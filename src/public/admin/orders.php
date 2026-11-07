<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
$pageTitle = 'Заказы — Админ-панель';

function ordersH(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function ordersMoney(mixed $value): string
{
    return number_format((float)$value, 0, '.', ' ') . ' ₽';
}
function ordersStatusLabel(string $status): string
{
    return $status === '' ? 'Новый · ожидает 1С' : $status;
}
function ordersUrl(array $params): string
{
    return '/admin/orders.php' . ($params ? '?' . http_build_query($params) : '');
}

// Support a database where the optional replacement column has not yet been migrated.
$hasReplacementColumn = false;
try {
    $columnCheck = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $columnCheck->execute(['order_items', 'request_replacement']);
    $hasReplacementColumn = (int)$columnCheck->fetchColumn() > 0;
} catch (Throwable $e) {
    error_log('Unable to inspect order_items columns: ' . $e->getMessage());
}

$allowedStatuses = ['', 'Получен', 'Отправлен', 'Согласован', 'Закрыт'];
$status = (string)($_GET['status'] ?? '');
if ($status !== '__all' && !in_array($status, $allowedStatuses, true)) $status = '__all';
$q = trim((string)($_GET['q'] ?? ''));
if (function_exists('mb_substr')) $q = mb_substr($q, 0, 100, 'UTF-8'); else $q = substr($q, 0, 100);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$viewId = filter_var($_GET['view'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

$conditions = [];
$params = [];
if ($status !== '__all') {
    $conditions[] = 'o.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $conditions[] = '(CAST(o.id AS CHAR) LIKE ? OR o.name LIKE ? OR o.phone LIKE ?)';
    $term = '%' . $q . '%';
    array_push($params, $term, $term, $term);
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

$countStmt = $db->prepare('SELECT COUNT(*) FROM orders o' . $where);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $db->prepare("SELECT o.id, o.user_id, o.phone, o.name, o.comment, o.status,
        o.total_retail, o.total_final, o.created_at, o.updated_at,
        (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count,
        (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
    FROM orders o{$where}
    ORDER BY CASE WHEN o.status = '' THEN 0 ELSE 1 END, o.created_at DESC, o.id DESC
    LIMIT ? OFFSET ?");
foreach ($params as $i => $value) $listStmt->bindValue($i + 1, $value, PDO::PARAM_STR);
$listStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$listStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$listStmt->execute();
$orders = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedOrder = null;
$orderItems = [];
if ($viewId) {
    $orderStmt = $db->prepare('SELECT id,user_id,phone,name,comment,status,total_retail,total_final,created_at,updated_at FROM orders WHERE id = ? LIMIT 1');
    $orderStmt->execute([(int)$viewId]);
    $selectedOrder = $orderStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($selectedOrder) {
        $replacementField = $hasReplacementColumn ? 'oi.request_replacement' : '0 AS request_replacement';
        $itemsStmt = $db->prepare("SELECT oi.id,oi.product_id,oi.product_name,oi.product_article,
                oi.price_retail,oi.price_final,oi.quantity,oi.is_preorder,oi.notify_on_available,
                {$replacementField}
            FROM order_items oi WHERE oi.order_id = ? ORDER BY oi.id");
        $itemsStmt->execute([(int)$viewId]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$statusCounts = ['' => 0, 'Получен' => 0, 'Отправлен' => 0, 'Согласован' => 0, 'Закрыт' => 0];
try {
    foreach ($db->query('SELECT status, COUNT(*) AS amount FROM orders GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string)$row['status'];
        if (array_key_exists($key, $statusCounts)) $statusCounts[$key] = (int)$row['amount'];
    }
} catch (Throwable $e) {
    error_log('Unable to count order statuses: ' . $e->getMessage());
}

require __DIR__ . '/../templates/header.php';
?>
<div class="admin admin-orders">
    <div class="admin__header">
        <h1>Заказы</h1>
        <a class="btn btn--small" href="/admin/index.php">← В админку</a>
    </div>

    <div class="admin-orders__summary" aria-label="Заказы по статусам">
        <a class="admin-orders__count" href="<?= ordersH(ordersUrl(['status' => '', 'q' => $q])) ?>"><span>Новые · ждут 1С</span><strong><?= (int)$statusCounts[''] ?></strong></a>
        <a class="admin-orders__count" href="<?= ordersH(ordersUrl(['status' => 'Получен', 'q' => $q])) ?>"><span>Получены</span><strong><?= (int)$statusCounts['Получен'] ?></strong></a>
        <a class="admin-orders__count" href="<?= ordersH(ordersUrl(['status' => 'Согласован', 'q' => $q])) ?>"><span>Согласованы</span><strong><?= (int)$statusCounts['Согласован'] ?></strong></a>
    </div>

    <?php if ($viewId && !$selectedOrder): ?>
        <div class="alert alert--error">Заказ №<?= (int)$viewId ?> не найден.</div>
    <?php endif; ?>

    <?php if ($selectedOrder): ?>
        <section class="admin-orders__detail">
            <div class="admin-orders__detail-head">
                <h2>Заказ №<?= (int)$selectedOrder['id'] ?></h2>
                <a class="btn btn--small" href="<?= ordersH(ordersUrl(['status' => $status, 'q' => $q, 'page' => $page])) ?>">← К списку</a>
            </div>
            <div class="admin-orders__customer">
                <div><span>Покупатель</span><strong><?= ordersH($selectedOrder['name'] ?: '—') ?></strong></div>
                <div><span>Телефон</span><strong><a href="tel:<?= ordersH($selectedOrder['phone']) ?>"><?= ordersH($selectedOrder['phone']) ?></a></strong></div>
                <div><span>Дата</span><strong><?= ordersH($selectedOrder['created_at']) ?></strong></div>
                <div><span>Статус</span><strong class="admin-orders__status admin-orders__status--<?= $selectedOrder['status'] === '' ? 'new' : 'other' ?>"><?= ordersH(ordersStatusLabel((string)$selectedOrder['status'])) ?></strong></div>
            </div>
            <?php if (!empty($selectedOrder['comment'])): ?><div class="admin-orders__comment"><strong>Комментарий покупателя:</strong><p><?= nl2br(ordersH($selectedOrder['comment'])) ?></p></div><?php endif; ?>

            <div class="admin-orders__table-wrap">
                <table class="admin-table admin-orders__items">
                    <thead><tr><th>Товар</th><th>Кол-во</th><th>Розничная цена</th><th>Цена заказа</th><th>Сумма</th><th>Наличие / просьба</th></tr></thead>
                    <tbody>
                    <?php foreach ($orderItems as $item): ?>
                        <tr>
                            <td><strong><?= ordersH($item['product_name']) ?></strong><small>Арт. <?= ordersH($item['product_article']) ?><?php if ($item['product_id'] !== null): ?> · ID <?= (int)$item['product_id'] ?><?php endif; ?></small></td>
                            <td><?= (int)$item['quantity'] ?></td>
                            <td><?= ordersMoney($item['price_retail']) ?></td>
                            <td><?= ordersMoney($item['price_final']) ?></td>
                            <td><strong><?= ordersMoney((float)$item['price_final'] * (int)$item['quantity']) ?></strong></td>
                            <td><?php if ((int)$item['is_preorder'] === 1): ?><span class="admin-orders__preorder">Под заказ</span><small><?php if ((int)$item['request_replacement'] === 1): ?>Предложить замену<?php elseif ((int)$item['notify_on_available'] === 1): ?>Сообщить о поступлении<?php else: ?>Выбор не сохранён<?php endif; ?></small><?php else: ?>В наличии на момент заказа<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$orderItems): ?><tr><td colspan="6">В заказе нет сохранённых позиций.</td></tr><?php endif; ?>
                    </tbody>
                    <tfoot><tr><th colspan="4">Итого</th><th><?= ordersMoney($selectedOrder['total_final']) ?></th><th></th></tr></tfoot>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <form class="admin-orders__filters" method="get" action="/admin/orders.php">
        <label for="order-status">Статус
            <select id="order-status" name="status">
                <option value="__all" <?= $status === '__all' ? 'selected' : '' ?>>Все статусы</option>
                <?php foreach ($allowedStatuses as $value): ?>
                    <option value="<?= ordersH($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= ordersH(ordersStatusLabel($value)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label for="order-q">Поиск по №, имени или телефону
            <input id="order-q" type="search" name="q" maxlength="100" value="<?= ordersH($q) ?>" placeholder="Например, 125 или 9182029526">
        </label>
        <button class="btn btn--primary btn--small" type="submit">Найти</button>
        <a class="btn btn--small" href="/admin/orders.php">Сбросить</a>
    </form>

    <p class="admin-orders__result-count">Найдено заказов: <strong><?= $totalRows ?></strong></p>
    <div class="admin-orders__table-wrap">
        <table class="admin-table admin-orders__list">
            <thead><tr><th>Заказ</th><th>Дата</th><th>Покупатель</th><th>Телефон</th><th>Позиции</th><th>Сумма</th><th>Статус</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><strong>№<?= (int)$order['id'] ?></strong></td>
                    <td><?= ordersH($order['created_at']) ?></td>
                    <td><?= ordersH($order['name'] ?: '—') ?></td>
                    <td><a href="tel:<?= ordersH($order['phone']) ?>"><?= ordersH($order['phone']) ?></a></td>
                    <td><?= (int)$order['line_count'] ?> поз. / <?= (int)$order['item_count'] ?> шт.</td>
                    <td><strong><?= ordersMoney($order['total_final']) ?></strong></td>
                    <td><span class="admin-orders__status admin-orders__status--<?= $order['status'] === '' ? 'new' : 'other' ?>"><?= ordersH(ordersStatusLabel((string)$order['status'])) ?></span></td>
                    <td><a class="btn btn--small" href="<?= ordersH(ordersUrl(['status' => $status, 'q' => $q, 'page' => $page, 'view' => (int)$order['id']])) ?>">Просмотр</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$orders): ?><tr><td colspan="8" class="admin-orders__empty">Заказов по заданным условиям не найдено.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="admin-orders__pagination" aria-label="Страницы заказов">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a class="<?= $i === $page ? 'is-active' : '' ?>" href="<?= ordersH(ordersUrl(['status' => $status, 'q' => $q, 'page' => $i])) ?>"><?= $i ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
