<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/Models/Phone.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = Phone::normalizeRussian((string)($_POST['phone'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($phone === null || $password === '') $error = 'Введите корректный российский номер телефона и пароль.';
    else {
        $db = getDBConnection();
        $matches = [];
        foreach ($db->query('SELECT id, phone, name, password_hash, is_admin, is_blocked FROM users')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (Phone::normalizeRussian((string)$row['phone']) === $phone) $matches[] = $row;
        }
        if (count($matches) > 1) $error = 'Для этого номера найдено несколько аккаунтов. Пожалуйста, обратитесь в магазин для их объединения.';
        elseif (!$matches || !password_verify($password, (string)($matches[0]['password_hash'] ?? ''))) $error = 'Неверный телефон или пароль.';
        elseif ((int)$matches[0]['is_blocked'] === 1) $error = 'Вход в этот аккаунт временно заблокирован. Свяжитесь с магазином.';
        else {
            $user = $matches[0];
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = (string)$user['name'];
            $_SESSION['user_phone'] = (string)$user['phone'];
            $_SESSION['is_admin'] = (bool)$user['is_admin'];
            if ((int)$user['is_admin'] === 1) {
                $_SESSION['admin_id'] = (int)$user['id'];
                $_SESSION['admin_name'] = (string)$user['name'];
                header('Location: /admin/index.php');
            } else {
                header('Location: /account.php');
            }
            exit;
        }
    }
}
$pageTitle = 'Вход — ' . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>
<div class="login-page">
    <h1>Вход</h1>
    <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" class="form">
        <div class="form__group"><label for="phone">Телефон:</label><input type="tel" id="phone" name="phone" required autocomplete="tel" placeholder="+7 918 202-95-26" value="<?= htmlspecialchars((string)($_POST['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
        <div class="form__group"><label for="password">Пароль:</label><input type="password" id="password" name="password" required autocomplete="current-password"></div>
        <button type="submit" class="btn btn--primary">Войти</button>
        <p class="form__hint">Нет аккаунта? <a href="/register.php">Зарегистрироваться</a></p>
    </form>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
