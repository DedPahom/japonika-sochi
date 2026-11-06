<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../Models/Product.php';

$pageTitle = 'Админ-панель — ' . SITE_NAME;

// Получаем статистику
$db = getDBConnection();
$totalProducts = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$outOfStock = $db->query("SELECT COUNT(*) FROM products WHERE quantity = 0")->fetchColumn();
$totalOrders = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();

require __DIR__ . '/../templates/header.php';
?>

<div class="admin">
    <h1>Админ-панель</h1>
    
    <div class="admin__welcome">
        Привет, <?= htmlspecialchars($_SESSION['admin_name']) ?>!
        <a href="/admin/logout.php" class="btn btn--small">Выйти</a>
    </div>
    
    <div class="admin__stats">
        <div class="stat-card">
            <div class="stat-card__value"><?= $totalProducts ?></div>
            <div class="stat-card__label">Товаров</div>
        </div>
        <div class="stat-card stat-card--warning">
            <div class="stat-card__value"><?= $outOfStock ?></div>
            <div class="stat-card__label">Под заказ</div>
        </div>
        <div class="stat-card">
            <div class="stat-card__value"><?= $totalOrders ?></div>
            <div class="stat-card__label">Заказов</div>
        </div>
    </div>
    
    <div class="admin__menu">
        <a href="/admin/products.php" class="admin__menu-item">
            <span class="admin__menu-icon">📦</span>
            <span>Товары</span>
        </a>
        <a href="/admin/orders.php" class="admin__menu-item">
            <span class="admin__menu-icon">🛒</span>
            <span>Заказы</span>
        </a>
        <a href="/admin/import.php" class="admin__menu-item">
            <span class="admin__menu-icon">📥</span>
            <span>Импорт из 1С</span>
        </a>
        <a href="/admin/export.php" class="admin__menu-item">
            <span class="admin__menu-icon">📤</span>
            <span>Экспорт в 1С</span>
        </a>
    </div>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>