<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 3) . '/config/database.php';
require_once dirname(__DIR__, 4) . '/private/order_api_common.php';

$requestId = onecOrderRequestId();
$db = null;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        throw new OneCOrderApiProblem(405, 'method_not_allowed', 'Используйте HTTP-метод GET.');
    }
    onecOrderRequireAuthentication();
    $limit = filter_var($_GET['limit'] ?? 100, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
    if ($limit === false) throw new OneCOrderApiProblem(422, 'invalid_limit', 'Параметр limit должен быть от 1 до 500.');
    $afterId = filter_var($_GET['after_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]);
    if ($afterId === false) throw new OneCOrderApiProblem(422, 'invalid_after_id', 'Параметр after_id должен быть целым числом от 0.');

    $db = getDBConnection();
    $orderStmt = $db->prepare("SELECT id, user_id, phone, name, comment, status, total_retail, total_final,
            DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:%s') AS created_at
        FROM orders WHERE status IN ('Новый', 'Получен', 'В работе', 'Согласован', 'На выполнении', 'Отправлен') AND id > ? ORDER BY id LIMIT ?");
    $orderStmt->bindValue(1, $afterId, PDO::PARAM_INT);
    $orderStmt->bindValue(2, $limit, PDO::PARAM_INT);
    $orderStmt->execute();
    $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($orders) {
        $ids = array_map(static fn(array $order): int => (int)$order['id'], $orders);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $itemStmt = $db->prepare("SELECT oi.order_id, oi.product_id, p.onec_id, oi.product_article AS article,
                oi.product_name AS name, oi.quantity, oi.price_retail, oi.price_final,
                oi.applied_discount_percent, oi.applied_promotion_name,
                oi.is_preorder, oi.notify_on_available, oi.request_replacement
            FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id IN ({$placeholders}) ORDER BY oi.order_id, oi.id");
        $itemStmt->execute($ids);
        $itemsByOrder = [];
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $orderId = (int)$item['order_id'];
            unset($item['order_id']);
            $item['product_id'] = $item['product_id'] === null ? null : (int)$item['product_id'];
            $item['quantity'] = (int)$item['quantity'];
            $item['applied_discount_percent'] = (int)$item['applied_discount_percent'];
            $item['is_preorder'] = (bool)$item['is_preorder'];
            $item['notify_on_available'] = (bool)$item['notify_on_available'];
            $item['request_replacement'] = (bool)$item['request_replacement'];
            $itemsByOrder[$orderId][] = $item;
        }
        foreach ($orders as &$order) {
            $order['id'] = (int)$order['id'];
            $order['user_id'] = (int)$order['user_id'];
            $order['total_retail'] = (float)$order['total_retail'];
            $order['total_final'] = (float)$order['total_final'];
            $order['items'] = $itemsByOrder[$order['id']] ?? [];
        }
        unset($order);
    }
    $nextAfterId = $orders ? (int)$orders[count($orders) - 1]['id'] : $afterId;
    onecOrderLog($db, 'Ok', sprintf('request_id=%s returned=%d after_id=%d next_after_id=%d', $requestId, count($orders), $afterId, $nextAfterId));
    echo json_encode(['success' => true, 'request_id' => $requestId, 'orders' => $orders, 'next_after_id' => $nextAfterId, 'has_more' => count($orders) === $limit], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (OneCOrderApiProblem $e) {
    onecOrderLog($db, 'Error', sprintf('request_id=%s code=%s message=%s', $requestId, $e->errorCode, $e->getMessage()));
    onecOrderRespond($e->httpStatus, ['success' => false, 'request_id' => $requestId, 'error' => $e->errorCode, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('1C orders API failure request_id=' . $requestId . ': ' . $e->getMessage());
    onecOrderLog($db, 'Error', 'request_id=' . $requestId . ' code=internal_error');
    onecOrderRespond(500, ['success' => false, 'request_id' => $requestId, 'error' => 'internal_error', 'message' => 'Внутренняя ошибка API. Сообщите request_id администратору.']);
}
