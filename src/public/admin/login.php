<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Models/Phone.php';
require_once __DIR__ . '/../Models/AuthRateLimiter.php';
$db = getDBConnection();
$limiter = new AuthRateLimiter($db);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPhone = trim((string)($_POST['phone'] ?? ''));
    $phone = Phone::normalizeRussian($rawPhone);
    $rateIdentity = $phone ?? $rawPhone;
    $remaining = $limiter->blockedSeconds('login', $rateIdentity);
    if ($remaining > 0) {
        $error = 'Слишком много попыток входа. Попробуйте снова примерно через 10 минут.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        if ($phone === null || $password === '') {
            $error = 'Введите корректный российский номер телефона и пароль.';
        } else {
            $matches = [];
            foreach ($db->query('SELECT id, phone, name, password_hash, is_admin, is_blocked FROM users')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (Phone::normalizeRussian((string)$row['phone']) === $phone) $matches[] = $row;
            }
            if (count($matches) > 1 || !$matches || empty($matches[0]['password_hash']) || !password_verify($password, (string)($matches[0]['password_hash'] ?? '')) || (int)($matches[0]['is_admin'] ?? 0) !== 1) {
                $error = 'Неверный телефон или пароль администратора.';
            } elseif ((int)$matches[0]['is_blocked'] === 1) {
                $error = 'Учётная запись заблокирована.';
            } else {
                $admin = $matches[0];
                $limiter->clearPair('login', $rateIdentity);
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int)$admin['id'];
                $_SESSION['admin_name'] = (string)$admin['name'];
                $_SESSION['user_id'] = (int)$admin['id'];
                $_SESSION['user_name'] = (string)$admin['name'];
                $_SESSION['user_phone'] = (string)$admin['phone'];
                $_SESSION['is_admin'] = true;
                header('Location: /admin/index.php');
                exit;
            }
        }
    }
    if ($error !== '' && $remaining === 0) $limiter->recordFailure('login', $rateIdentity);
}
$pageTitle = 'Вход в админ-панель — ' . SITE_NAME;
require __DIR__ . '/../templates/header.php';
?>
<div class="admin-login">
    <h1>Вход в админ-панель</h1>
    <?php if (isset($_GET['revoked'])): ?><div class="alert alert--error">Права администратора отозваны или аккаунт заблокирован.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" class="form">
        <div class="form__group"><label for="phone">Телефон:</label><input type="tel" id="phone" name="phone" required autocomplete="tel" value="<?= htmlspecialchars((string)($_POST['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
        <div class="form__group"><label for="password">Пароль:</label><input type="password" id="password" name="password" required autocomplete="current-password"></div>
        <button type="submit" class="btn btn--primary">Войти</button>
    </form>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
