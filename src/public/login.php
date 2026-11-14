<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/Models/Phone.php';
require_once __DIR__ . '/Models/AuthRateLimiter.php';
$error = '';
if (empty($_SESSION['login_csrf'])) $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
$login_csrf = (string)$_SESSION['login_csrf'];
$db = getDBConnection();
$limiter = new AuthRateLimiter($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($login_csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Сессия формы истекла. Обновите страницу.'); }
    if (trim((string)($_POST['website_url'] ?? '')) !== '') { http_response_code(400); exit('Не удалось обработать форму.'); }
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
            if (count($matches) > 1) {
                $error = 'Для этого номера найдено несколько аккаунтов. Обратитесь в магазин для их объединения.';
            } elseif (!$matches || empty($matches[0]['password_hash']) || !password_verify($password, (string)$matches[0]['password_hash'])) {
                $error = 'Неверный телефон или пароль.';
            } elseif ((int)$matches[0]['is_blocked'] === 1) {
                $error = 'Вход в этот аккаунт временно заблокирован. Свяжитесь с магазином.';
            } else {
                $user = $matches[0];
                $limiter->clearPair('login', $rateIdentity);
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
    if ($error !== '' && $remaining === 0) $limiter->recordFailure('login', $rateIdentity);
}
$pageTitle = 'Вход — ' . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>
<div class="login-page">
    <h1>Вход</h1>
    <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" class="form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($login_csrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="security-honeypot" aria-hidden="true"><label for="login_website-url">Не заполняйте это поле</label><input id="login_website-url" type="text" name="website_url" value="" tabindex="-1" autocomplete="off"></div>
        <div class="form__group"><label for="phone">Телефон:</label><input type="tel" id="phone" name="phone" required autocomplete="tel" placeholder="+7 999 999-99-99" value="<?= htmlspecialchars((string)($_POST['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
        <div class="form__group"><label for="password">Пароль:</label><input type="password" id="password" name="password" required autocomplete="current-password"></div>
        <button type="submit" class="btn btn--primary">Войти</button>
        <p class="form__hint">Нет аккаунта? <a href="/register.php">Зарегистрироваться</a></p>
    </form>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
