<?php
// /tbfadmin/public/admin/logout.php
declare(strict_types=1);

/* ใช้ชื่อเซสชันเดียวกับระบบ */
$sessionName = $GLOBALS['config']['app']['admin_session_name'] ?? 'bb_admin';
session_name($sessionName);
session_start();

/* ล้างข้อมูลเซสชันทั้งหมด */
$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? true);
}
session_destroy();

/* กลับไปหน้า login */
header('Location: /tbfadmin/public/admin/login.php');
exit;
