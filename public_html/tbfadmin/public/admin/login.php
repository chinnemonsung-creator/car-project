<?php
// /tbfadmin/public/admin/login.php
declare(strict_types=1);

/* ---------- LOG ---------- */
@ini_set('log_errors','1');
$__logDir = __DIR__ . '/../../var';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }
@ini_set('error_log', $__logDir . '/php-error.log');

/* ---------- BOOT ---------- */
require __DIR__ . '/../../src/Bootstrap.php';
require_once __DIR__ . '/../../src/Database.php';

/* ---------- SESSION ---------- */
$sessionName = $GLOBALS['config']['app']['admin_session_name'] ?? 'bb_admin';
session_name($sessionName);
session_start();

/* ถ้าเข้าสู่ระบบแล้ว → ไป dashboard */
if (!empty($_SESSION['admin'])) {
  header('Location: /tbfadmin/public/admin/dashboard.php'); exit;
}

$error = null;

/* ---------- HANDLE POST ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $u = trim((string)($_POST['username'] ?? ''));
  $p = (string)($_POST['password'] ?? '');

  try {
    $pdo = Database::pdo(); // ใช้ pdo() ตามที่มีจริง
    $stmt = $pdo->prepare("
      SELECT id, username, password_hash, role, is_active
      FROM admin_users
      WHERE username = :u
      LIMIT 1
    ");
    $stmt->execute([':u' => $u]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $ok = false;
    if ($user && (int)$user['is_active'] === 1) {
      $stored = (string)($user['password_hash'] ?? '');
      // โหมดปัจจุบัน: เทียบรหัสตรง ๆ (ยังไม่ hash)
      if ($stored !== '') {
        $ok = hash_equals($stored, $p);
      }
    }

    if (!$ok) {
      $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
      error_log('[login.php] INVALID user='.$u);
    } else {
      $_SESSION['admin'] = [
        'id'        => (int)$user['id'],
        'username'  => $user['username'],
        'role'      => $user['role'],
        'login_at'  => date('Y-m-d H:i:s'),
      ];
      // อัปเดตเวลาเข้า (ไม่บังคับ)
      try { $pdo->prepare("UPDATE admin_users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]); } catch (\Throwable $e) {}
      header('Location: /tbfadmin/public/admin/dashboard.php'); exit;
    }
  } catch (\Throwable $e) {
    $error = 'เกิดข้อผิดพลาดของระบบ โปรดลองใหม่อีกครั้ง';
    error_log('[login.php] DB/Login error: '.$e->getMessage());
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>เข้าสู่ระบบแอดมิน</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#fff;margin:0}
    .box{max-width:380px;margin:10vh auto;background:#fff;padding:24px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.08)}
    h1{font-size:20px;margin:0 0 20px;text-align:center}
    label{display:block;font-size:14px;margin:12px 0 6px}
    input{width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;font-size:14px}
    button{width:100%;padding:12px;margin-top:18px;border:0;border-radius:6px;background:#007bff;color:#fff;font-size:15px;cursor:pointer}
    button:hover{background:#0069d9}
    .err{background:#ffecec;color:#b00020;padding:10px;border-radius:6px;margin-bottom:14px;font-size:13px;text-align:center}
  </style>
</head>
<body>
  <div class="box">
    <h1>เข้าสู่ระบบแอดมิน</h1>
    <?php if ($error): ?>
      <div class="err"><?=htmlspecialchars($error,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <label>ชื่อผู้ใช้</label>
      <input name="username" required autofocus>
      <label>รหัสผ่าน</label>
      <input name="password" type="password" required>
      <button type="submit">เข้าสู่ระบบ</button>
    </form>
  </div>
</body>
</html>
