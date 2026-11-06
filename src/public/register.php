<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

// Если уже авторизован — на главную
if (isset($_SESSION['user_id'])) {
    header('Location: /account.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password_confirm = (string)($_POST['password_confirm'] ?? '');
    
    // Валидация
    if (empty($name)) {
        $error = 'Укажите имя';
    } elseif (empty($phone)) {
        $error = 'Укажите телефон';
    } elseif (!preg_match('/^\+?[0-9\s\-\(\)]{10,20}$/', $phone)) {
        $error = 'Некорректный формат телефона';
    } elseif (strlen($password) < 6) {
        $error = 'Пароль должен быть не менее 6 символов';
    } elseif ($password !== $password_confirm) {
        $error = 'Пароли не совпадают';
    } else {
        $db = getDBConnection();
        
        // Проверяем, нет ли уже такого телефона
        $stmt = $db->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt->execute([$phone]);
        
        if ($stmt->fetch()) {
            $error = 'Пользователь с таким телефоном уже зарегистрирован';
        } else {
            // Регистрируем
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            try {
                $stmt = $db->prepare("
                    INSERT INTO users (phone, name, password_hash, is_admin) 
                    VALUES (?, ?, ?, 0)
                ");
                $stmt->execute([$phone, $name, $password_hash]);
                
                $success = 'Регистрация успешна! Теперь вы можете войти.';
                $_POST = [];
                
            } catch (PDOException $e) {
                $error = 'Ошибка регистрации: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Регистрация — ' . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>

<div class="login-page">
    <h1>Регистрация</h1>
    
    <?php if ($error): ?>
        <div class="alert alert--error"><?= htmlspecialchars((string)$error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert--success"><?= htmlspecialchars((string)$success) ?></div>
    <?php endif; ?>
    
    <form method="POST" class="form">
        <div class="form__group">
            <label for="name">Имя *</label>
            <input type="text" id="name" name="name" required
                   placeholder="Как к вам обращаться?"
                   value="<?= htmlspecialchars((string)($_POST['name'] ?? '')) ?>">
        </div>
        
        <div class="form__group">
            <label for="phone">Телефон *</label>
            <input type="tel" id="phone" name="phone" required
                   placeholder="+79001234567"
                   value="<?= htmlspecialchars((string)($_POST['phone'] ?? '')) ?>">
        </div>
        
        <div class="form__group">
            <label for="password">Пароль *</label>
            <input type="password" id="password" name="password" required
                   placeholder="Минимум 6 символов"
                   minlength="6">
        </div>
        
        <div class="form__group">
            <label for="password_confirm">Повторите пароль *</label>
            <input type="password" id="password_confirm" name="password_confirm" required
                   placeholder="Ещё раз"
                   minlength="6">
        </div>
        
        <button type="submit" class="btn btn--primary">Зарегистрироваться</button>
        
        <p class="form__hint">
            Уже есть аккаунт? <a href="/login.php">Войти</a>
        </p>
    </form>
</div>

<?php require __DIR__ . '/templates/footer.php'; ?>