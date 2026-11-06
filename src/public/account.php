<?php
declare(strict_types=1);

session_start();

// Проверяем авторизацию
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$db = getDBConnection();

// Получаем данные пользователя
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Получаем заказы пользователя
$stmt = $db->prepare("
    SELECT o.*, 
           COUNT(oi.id) as items_count,
           SUM(oi.quantity * oi.price_final) as total_sum
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE o.user_id = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$orders = $stmt->fetchAll();

$pageTitle = 'Личный кабинет — ' . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>

<div class="account">
    <h1>Личный кабинет</h1>
    
    <div class="account__info">
        <h2>Мои данные</h2>
        <p><strong>Имя:</strong> <?= htmlspecialchars((string)$user['name']) ?></p>
        <p><strong>Телефон:</strong> <?= htmlspecialchars((string)$user['phone']) ?></p>
        <p><strong>Email:</strong> <?= htmlspecialchars((string)($user['email'] ?? '—')) ?></p>
        
        <a href="/logout.php" class="btn">Выйти</a>
    </div>
    
    <div class="account__orders">
        <h2>Мои заказы</h2>
        
        <?php if (empty($orders)): ?>
            <p>У вас пока нет заказов.</p>
            <a href="/catalog.php" class="btn btn--primary">Перейти в каталог</a>
        <?php else: ?>
            <table class="orders-table">
                <thead>
                    <tr>
                        <th>№ заказа</th>
                        <th>Дата</th>
                        <th>Товаров</th>
                        <th>Сумма</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$order['order_number']) ?></td>
                            <td><?= date('d.m.Y', strtotime($order['created_at'])) ?></td>
                            <td><?= (int)$order['items_count'] ?></td>
                            <td><?= number_format((float)$order['total_sum'], 0, '.', ' ') ?> ₽</td>
                            <td>
                                <span class="order-status order-status--<?= htmlspecialchars((string)$order['status']) ?>">
                                    <?php
                                    $statuses = [
                                        'new' => 'Новый',
                                        'processing' => 'В обработке',
                                        'confirmed' => 'Подтверждён',
                                        'shipped' => 'Отправлен',
                                        'delivered' => 'Доставлен',
                                        'cancelled' => 'Отменён'
                                    ];
                                    echo $statuses[$order['status']] ?? $order['status'];
                                    ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<style>
.account__info {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.account__orders {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.orders-table {
    width: 100%;
    border-collapse: collapse;
}

.orders-table th,
.orders-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.orders-table th {
    background: #f5f5f5;
    font-weight: 600;
}

.order-status {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 500;
}

.order-status--new { background: #e3f2fd; color: #1565c0; }
.order-status--processing { background: #fff3e0; color: #e65100; }
.order-status--confirmed { background: #e8f5e9; color: #2e7d32; }
.order-status--shipped { background: #f3e5f5; color: #7b1fa2; }
.order-status--delivered { background: #e8f5e9; color: #1b5e20; }
.order-status--cancelled { background: #ffebee; color: #c62828; }
</style>

<?php require __DIR__ . '/templates/footer.php'; ?>