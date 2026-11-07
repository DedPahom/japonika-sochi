<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/Models/Phone.php';
require_once __DIR__ . '/Models/AuthRateLimiter.php';
if (isset($_SESSION['user_id'])) { header('Location: /account.php'); exit; }
$error = '';
$success = '';
$db = getDBConnection();
$limiter = new AuthRateLimiter($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPhone = trim((string)($_POST['phone'] ?? ''));
    $phone = Phone::normalizeRussian($rawPhone);
    $rateIdentity = $phone ?? $rawPhone;
    $remaining = $limiter->blockedSeconds('register', $rateIdentity);

    if ($remaining > 0) {
        $error = 'Слишком много попыток. Попробуйте снова примерно через 10 минут.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

        if ($name === '') $error = 'Укажите имя.';
        elseif ($phone === null) $error = 'Введите российский номер: 10 цифр или номер, начинающийся с +7/8.';
        elseif (strlen($password) < 6) $error = 'Пароль должен быть не короче 6 символов.';
        elseif ($password !== $passwordConfirm) $error = 'Пароли не совпадают.';
        else {
            // Match both canonical and legacy formatted phone values.
            $matches = [];
            foreach ($db->query('SELECT id, phone, password_hash, is_blocked, is_admin FROM users')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (Phone::normalizeRussian((string)$row['phone']) === $phone) $matches[] = $row;
            }
            if (count($matches) > 1) {
                $error = 'Для этого номера найдено несколько аккаунтов. Обратитесь в магазин, чтобы объединить записи.';
            } elseif ($matches && (int)$matches[0]['is_admin'] === 1) {
                $error = 'Этот номер уже связан с учётной записью магазина. Войдите или обратитесь к администратору.';
            } elseif ($matches && (int)$matches[0]['is_blocked'] === 1) {
                $error = 'Регистрация для этого номера временно недоступна. Свяжитесь с магазином.';
            } elseif ($matches && !empty($matches[0]['password_hash'])) {
                $error = 'Аккаунт с этим номером уже зарегистрирован. Войдите или обратитесь в магазин, если забыли пароль.';
            } elseif ($matches) {
                // Claim a phone record imported from 1C: set password/name only; preserve discount, role and other fields.
                $stmt = $db->prepare('UPDATE users SET name = ?, password_hash = ? WHERE id = ? AND (password_hash IS NULL OR password_hash = \'\') AND is_blocked = 0 AND is_admin = 0');
                $stmt->execute([$name, password_hash($password, PASSWORD_DEFAULT), (int)$matches[0]['id']]);
                if ($stmt->rowCount() === 1) {
                    $success = 'Аккаунт активирован. Теперь можно войти с указанным телефоном и паролем.';
                    $limiter->clearPair('register', $rateIdentity);
                } else {
                    $error = 'Не удалось активировать аккаунт. Попробуйте войти или обратитесь в магазин.';
                }
            } else {
                try {
                    $stmt = $db->prepare('INSERT INTO users (phone, name, password_hash, is_admin, is_blocked) VALUES (?, ?, ?, 0, 0)');
                    $stmt->execute([$phone, $name, password_hash($password, PASSWORD_DEFAULT)]);
                    $success = 'Регистрация прошла успешно. Теперь можно войти.';
                    $limiter->clearPair('register', $rateIdentity);
                    $_POST = [];
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') $error = 'Аккаунт с этим номером уже зарегистрирован. Попробуйте войти.';
                    else { error_log('Registration failed: ' . $e->getMessage()); $error = 'Не удалось завершить регистрацию. Попробуйте позже.'; }
                }
            }
        }
    }
    if ($error !== '' && $remaining === 0) $limiter->recordFailure('register', $rateIdentity);
}

$pageTitle = 'Регистрация — ' . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>
<div class="login-page">
    <h1>Регистрация</h1>
    <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert--success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" class="form">
        <div class="form__group"><label for="name">Имя *</label><input type="text" id="name" name="name" required maxlength="255" value="<?= htmlspecialchars((string)($_POST['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
        <div class="form__group"><label for="phone">Телефон *</label><input type="tel" id="phone" name="phone" required autocomplete="tel" placeholder="+7 918 202-95-26" value="<?= htmlspecialchars((string)($_POST['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><small>Номер сохраняется в едином формате +7XXXXXXXXXX.</small></div>
        <div class="form__group"><label for="password">Пароль *</label><input type="password" id="password" name="password" required minlength="6" autocomplete="new-password"></div>
        <div class="form__group"><label for="password_confirm">Повторите пароль *</label><input type="password" id="password_confirm" name="password_confirm" required minlength="6" autocomplete="new-password"></div>
        <button type="submit" class="btn btn--primary">Зарегистрироваться</button>
        <p class="form__hint">Уже есть аккаунт? <a href="/login.php">Войти</a></p>
    </form>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
