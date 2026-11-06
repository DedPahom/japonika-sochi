<?php
declare(strict_types=1);

final class UserAccess
{
    /** Clear customer/admin session fields immediately when the account was blocked or deleted. */
    public static function clearBlockedSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) return;
        require_once __DIR__ . '/../config/config.php';
        require_once __DIR__ . '/../config/database.php';
        $stmt = getDBConnection()->prepare('SELECT is_blocked FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $blocked = $stmt->fetchColumn();
        if ($blocked === false || (int)$blocked === 1) {
            unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_phone'], $_SESSION['is_admin'], $_SESSION['admin_id'], $_SESSION['admin_name']);
            session_regenerate_id(true);
        }
    }
}
