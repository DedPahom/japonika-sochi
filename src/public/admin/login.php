<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($phone) || empty($password)) {
        $error = 'Введите телефон и пароль';
    } else {
        $db = getDBConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE phone = :phone AND is_admin = 1");
        $stmt->bindValue(':phone', $phone);
        $stmt->execute();
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_name'] = $user['name'];
            header('Location: /admin/index.php');
            exit;
        } else {
            $error = 'Неверный телефон или пароль';
        }
    }
}

$pageTitle = 'Вход в админ-панель — ' . SITE_NAME;
require __DIR__ . '/../templates/header.php';
?>

<div class="admin-login">
    <h1>Вход в админ-панель</h1>
    
    <?php if ($error): ?>
        <div class="alert alert--error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST" class="form">
        <div class="form__group">
            <label for="phone">Телефон:</label>
            <input type="tel" id="phone" name="phone" required 
                   placeholder="+79000000000"
                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
        </div>
        
        <div class="form__group">
            <label for="password">Пароль:</label>
            <input type="password" id="password" name="password" required>
        </div>
        
        <button type="submit" class="btn btn--primary">Войти</button>
    </form>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>