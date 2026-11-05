<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? SITE_NAME) ?></title>
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
                    <a href="/login.php" class="nav__link">Войти</a>
                </nav>
            </div>
        </div>
    </header>
    
    <main class="main">
        <div class="container">