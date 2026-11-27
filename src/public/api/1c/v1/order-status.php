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
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new OneCOrderApiProblem(405, 'method_not_allowed', 'Используйте HTTP-метод POST.');
    }
    onecOrderRequireAuthentication();
    $data = onecOrderReadJsonBody();
    $orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if ($orderId === false) throw new OneCOrderApiProblem(422, 'invalid_order_id', 'order_id должен быть положительным целым числом.');
    $status = $data['status'] ?? null;
    $allowedStatuses = ['Получен', 'В работе', 'Согласован', 'На выполнении', 'Отправлен', 'Закрыт'];
    if (!is_string($status) || !in_array($status, $allowedStatuses, true)) {
        throw new OneCOrderApiProblem(422, 'invalid_status', 'Недопустимый статус заказа.');
    }

    $db = getDBConnection();
    $allowedTransitions = [
        'Получен' => ['Новый'],
        'В работе' => ['Получен', 'Новый'],
        'Согласован' => ['В работе', 'Получен', 'Новый'],
        'На выполнении' => ['Согласован', 'В работе', 'Получен'],
        'Отправлен' => ['На выполнении', 'Согласован', 'Получен'],
        'Закрыт' => ['Отправлен', 'На выполнении', 'Согласован', 'В работе', 'Получен', 'Новый'],
    ];
    $expectedStatus = $data['expected_status'] ?? ($allowedTransitions[$status][0] ?? null);
    if (!is_string($expectedStatus) || !in_array($expectedStatus, $allowedTransitions[$status], true)) {
        throw new OneCOrderApiProblem(422, 'invalid_transition', 'Недопустимый переход статуса заказа.');
    }
    $update = $db->prepare('UPDATE orders SET status = ? WHERE id = ? AND status = ?');
    $update->execute([$status, $orderId, $expectedStatus]);
    if ($update->rowCount() === 1) {
        onecOrderLog($db, 'Ok', sprintf('request_id=%s order_id=%d status=%s previous_status=%s', $requestId, $orderId, $status, $expectedStatus));
        echo json_encode(['success' => true, 'request_id' => $requestId, 'order_id' => (int)$orderId, 'status' => $status, 'updated' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $currentStmt = $db->prepare('SELECT status FROM orders WHERE id = ?');
    $currentStmt->execute([$orderId]);
    $currentStatus = $currentStmt->fetchColumn();
    if ($currentStatus === false) throw new OneCOrderApiProblem(404, 'order_not_found', 'Заказ не найден.');
    if ((string)$currentStatus === $status) {
        onecOrderLog($db, 'Ok', sprintf('request_id=%s order_id=%d status=%s idempotent=true', $requestId, $orderId, $status));
        echo json_encode(['success' => true, 'request_id' => $requestId, 'order_id' => (int)$orderId, 'status' => $status, 'updated' => false, 'idempotent' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ((string)$currentStatus === 'Закрыт') {
        throw new OneCOrderApiProblem(409, 'terminal_status', 'Заказ уже закрыт; обратный переход запрещён.');
    }
    throw new OneCOrderApiProblem(409, 'status_conflict', 'Статус заказа уже изменён; обновление не применено.');
} catch (OneCOrderApiProblem $e) {
    onecOrderLog($db, 'Error', sprintf('request_id=%s code=%s message=%s', $requestId, $e->errorCode, $e->getMessage()));
    onecOrderRespond($e->httpStatus, ['success' => false, 'request_id' => $requestId, 'error' => $e->errorCode, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('1C order status API failure request_id=' . $requestId . ': ' . $e->getMessage());
    onecOrderLog($db, 'Error', 'request_id=' . $requestId . ' code=internal_error');
    onecOrderRespond(500, ['success' => false, 'request_id' => $requestId, 'error' => 'internal_error', 'message' => 'Внутренняя ошибка API. Сообщите request_id администратору.']);
}
