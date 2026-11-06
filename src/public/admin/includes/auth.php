<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: /admin/login.php'); exit; }
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
$stmt=getDBConnection()->prepare('SELECT name,is_admin,is_blocked FROM users WHERE id=? LIMIT 1');
$stmt->execute([(int)$_SESSION['admin_id']]);$admin=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$admin || (int)$admin['is_admin']!==1 || (int)$admin['is_blocked']===1){unset($_SESSION['admin_id'],$_SESSION['admin_name'],$_SESSION['is_admin']);header('Location: /admin/login.php?revoked=1');exit;}
$_SESSION['admin_name']=(string)$admin['name'];
