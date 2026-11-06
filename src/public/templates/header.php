<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
if (!defined('SITE_NAME')) require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../Models/Cart.php';
$headerCart = Cart::summary();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars((string)($pageTitle ?? SITE_NAME), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="icon" href="/assets/images/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="64x64" href="/assets/images/favicon.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/apple-touch-icon.png">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<header class="header">
    <div class="container">
        <div class="header__inner">
            <a href="/" class="logo" aria-label="<?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?> — главная">
                <img class="logo__image" src="/assets/images/logo.png" alt="Логотип Японика" width="46" height="46">
                <span><?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <nav class="nav" aria-label="Основная навигация">
                <a href="/" class="nav__link">Главная</a>
                <a href="/catalog.php" class="nav__link">Каталог</a>
                <a href="/cart.php" class="nav__link nav__cart" aria-label="Корзина: <?= (int)$headerCart['count'] ?> шт., <?= number_format((float)$headerCart['total'], 0, '.', ' ') ?> рублей">
                    🛒 Корзина <span class="nav__cart-count" id="header-cart-count"><?= (int)$headerCart['count'] ?></span>
                    <span class="nav__cart-total" id="header-cart-total"><?= number_format((float)$headerCart['total'], 0, '.', ' ') ?> ₽</span>
                </a>
                <?php if (isset($_SESSION['admin_id'])): ?>
                    <a href="/admin/index.php" class="nav__link">Админка</a>
                <?php elseif (isset($_SESSION['user_id'])): ?>
                    <a href="/account.php" class="nav__link">Личный кабинет</a>
                    <a href="/logout.php" class="nav__link">Выйти</a>
                <?php else: ?>
                    <a href="/login.php" class="nav__link">Войти</a>
                <?php endif; ?>
            </nav>
        </div>
    </div>
</header>
<main class="main"><div class="container">
