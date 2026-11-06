<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim((string)($_POST['phone'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    
    if (empty($phone) || empty($password)) {
        $error = 'Введите телефон и пароль';
    } else {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Сохраняем в сессию
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_phone'] = $user['phone'];
            $_SESSION['is_admin'] = (bool)$user['is_admin'];
            
            // Если админ — на админ-панель, если нет — в личный кабинет
            if ($user['is_admin']) {
                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_name'] = $user['name'];
                header('Location: /admin/index.php');
            } else {
                header('Location: /account.php');
            }
            exit;
        } else {
            $error = 'Неверный телефон или пароль';
        }
    }
}

$pageTitle = 'Вход — ' . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>

<div class="login-page">
    <h1>Вход</h1>
    
    <?php if ($error): ?>
        <div class="alert alert--error"><?= htmlspecialchars((string)$error) ?></div>
    <?php endif; ?>
    
    <form method="POST" class="form">
        <div class="form__group">
            <label for="phone">Телефон:</label>
            <input type="tel" id="phone" name="phone" required 
                   placeholder="+79000000000"
                   value="<?= htmlspecialchars((string)($_POST['phone'] ?? '')) ?>">
        </div>
        
        <div class="form__group">
            <label for="password">Пароль:</label>
            <input type="password" id="password" name="password" required>
        </div>
        
        <button type="submit" class="btn btn--primary">Войти</button>
        
        <p class="form__hint">
            Нет аккаунта? <a href="/register.php">Зарегистрироваться</a>
        </p>
    </form>
</div>

<?php require __DIR__ . '/templates/footer.php'; ?>