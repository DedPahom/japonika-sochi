<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Подключаем конфиг если ещё не подключен
if (!defined('SITE_NAME')) {
    require_once __DIR__ . '/../config/config.php';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string)($pageTitle ?? SITE_NAME)) ?></title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="container">
            <div class="header__inner">
                <a href="/" class="logo">
                    🌸 <?= SITE_NAME ?>
                </a>
                
                <nav class="nav">
                    <a href="/" class="nav__link">Главная</a>
                    <a href="/catalog.php" class="nav__link">Каталог</a>
                    <a href="/cart.php" class="nav__link">Корзина</a>
                    
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <?php if (($_SESSION['is_admin'] ?? false) || isset($_SESSION['admin_id'])): ?>
                            <a href="/admin/index.php" class="nav__link">Админка</a>
                        <?php else: ?>
                            <a href="/account.php" class="nav__link">Личный кабинет</a>
                        <?php endif; ?>
                        <a href="/logout.php" class="nav__link">Выйти (<?= htmlspecialchars((string)($_SESSION['user_name'] ?? $_SESSION['admin_name'] ?? 'Пользователь')) ?>)</a>
                    <?php else: ?>
                        <a href="/login.php" class="nav__link">Войти</a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </header>
    
    <main class="main">
        <div class="container">