<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../Models/Product.php';

$db = getDBConnection();
$totalProducts = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
$outOfStock = (int)$db->query('SELECT COUNT(*) FROM products WHERE quantity = 0')->fetchColumn();
$totalOrders = (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$totalUsers = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
$pageTitle = 'Админ-панель — ' . SITE_NAME;
require __DIR__ . '/../templates/header.php';
?>
<div class="admin admin-dashboard">
    <div class="admin__header">
        <h1>Админ-панель</h1>
        <div class="admin__welcome">
            Привет, <?= htmlspecialchars((string)($_SESSION['admin_name'] ?? 'администратор'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>!
            <a href="/admin/logout.php" class="btn btn--small">Выйти</a>
        </div>
    </div>

    <section class="admin-dashboard__overview" aria-label="Управление и статистика">
        <div class="admin-dashboard__controls">
            <h2>Управление</h2>
            <div class="admin-dashboard__group">
                <h3>Магазин</h3>
                <a href="/admin/products.php" class="admin-dashboard__button"><span aria-hidden="true">📦</span> Товары</a>
                <a href="/admin/orders.php" class="admin-dashboard__button"><span aria-hidden="true">🛒</span> Заказы</a>
                <a href="/admin/users.php" class="admin-dashboard__button"><span aria-hidden="true">👥</span> Пользователи</a>
            </div>
            <div class="admin-dashboard__group">
                <h3>Обмен с 1С</h3>
                <a href="/admin/import.php" class="admin-dashboard__button"><span aria-hidden="true">📥</span> Импорт каталога</a>
                <a href="/admin/import-users.php" class="admin-dashboard__button"><span aria-hidden="true">👤</span> Импорт пользователей</a>
                <a href="/admin/export.php" class="admin-dashboard__button"><span aria-hidden="true">📤</span> Экспорт</a>
            </div>
        </div>

        <div class="admin-dashboard__statistics">
            <h2>Статистика</h2>
            <div class="admin-dashboard__stat"><span>Всего товаров</span><strong><?= number_format($totalProducts, 0, '.', ' ') ?></strong></div>
            <div class="admin-dashboard__stat"><span>Товаров нет в наличии</span><strong><?= number_format($outOfStock, 0, '.', ' ') ?></strong></div>
            <div class="admin-dashboard__stat"><span>Заказов</span><strong><?= number_format($totalOrders, 0, '.', ' ') ?></strong></div>
            <div class="admin-dashboard__stat"><span>Пользователей</span><strong><?= number_format($totalUsers, 0, '.', ' ') ?></strong></div>
        </div>
    </section>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
