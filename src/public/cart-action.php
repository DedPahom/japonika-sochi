<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/Models/Cart.php';
require_once __DIR__ . '/Models/UserAccess.php';
UserAccess::clearBlockedSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cartJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') cartJson(405, ['ok'=>false, 'message'=>'Метод не поддерживается.']);
if (!Cart::validateCsrf($_POST['csrf'] ?? null)) cartJson(403, ['ok'=>false, 'message'=>'Сессия устарела. Обновите страницу.']);

$action = (string)($_POST['action'] ?? 'add');
$productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$quantity = filter_var($_POST['quantity'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1, 'max_range'=>999]]);
if (!$productId || !$quantity) cartJson(422, ['ok'=>false, 'message'=>'Некорректный товар или количество.']);

try {
    if ($action === 'add') Cart::add((int)$productId, (int)$quantity);
    elseif ($action === 'set') Cart::setQuantity((int)$productId, (int)$quantity);
    elseif ($action === 'remove') Cart::remove((int)$productId);
    else cartJson(422, ['ok'=>false, 'message'=>'Неизвестное действие.']);
    $summary = Cart::summary();
    cartJson(200, ['ok'=>true, 'message'=>$action === 'add' ? 'Добавлено в корзину.' : 'Корзина обновлена.', 'count'=>$summary['count'], 'total'=>$summary['total'], 'formatted_total'=>number_format($summary['total'], 0, '.', ' ') . ' ₽']);
} catch (Throwable $e) {
    error_log('Cart action failed: ' . $e->getMessage());
    cartJson(400, ['ok'=>false, 'message'=>$e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'Не удалось обновить корзину.']);
}
