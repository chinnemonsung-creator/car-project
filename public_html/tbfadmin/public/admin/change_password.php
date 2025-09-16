<?php
// /tbfadmin/public/admin/change_password.php
declare(strict_types=1);

/* ---------- LOG (ไม่กระทบ DB.php) ---------- */
@ini_set('log_errors','1');
$__logDir = __DIR__ . '/../../var';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }
@ini_set('error_log', $__logDir . '/php-error.log');

/* ---------- BOOT ---------- */
require __DIR__ . '/../../src/Bootstrap.php';
require_once __DIR__ . '/../../src/Database.php';

/* ---------- SESSION & GUARD ---------- */
$sessionName = $GLOBALS['config']['app']['admin_session_name'] ?? 'bb_admin';
session_name($sessionName);
session_start();

if (empty($_SESSION['admin'])) {
  header('Location: /tbfadmin/public/admin/login.php'); exit;
}

$uid    = (int)($_SESSION['admin']['id'] ?? 0);
$uname  = (string)($_SESSION['admin']['username'] ?? '');
$msg    = null;
$err    = null;

/* ---------- HANDLE POST ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $old = (string)($_POST['old_password'] ?? '');
  $new = (string)($_POST['new_password'] ?? '');
  $re  = (string)($_POST['new_password2'] ?? '');

  if ($new === '' || $re === '') {
    $err = 'กรุณากรอกรหัสผ่านใหม่ให้ครบ';
  } elseif ($new !== $re) {
    $err = 'รหัสผ่านใหม่ทั้งสองช่องไม่ตรงกัน';
  } else {
    try {
      $pdo = Database::pdo(); // ใช้ pdo() ตามที่มีจริง

      // ดึงรหัสเดิม (เก็บแบบตัวอักษรตรง ๆ)
      $stmt = $pdo->prepare("SELECT password_hash FROM admin_users WHERE id = ? AND is_active = 1 LIMIT 1");
      $stmt->execute([$uid]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$row) {
        $err = 'ไม่พบผู้ใช้ หรือผู้ใช้ถูกปิดใช้งาน';
      } else {
        $stored = (string)($row['password_hash'] ?? '');
        // เทียบตรง ๆ
        if (!hash_equals($stored, $old)) {
          $err = 'รหัสผ่านเดิมไม่ถูกต้อง';
        } else {
          // อัปเดตรหัสแบบตัวอักษรตรง ๆ (ชั่วคราวตามที่ตกลง)
          $upd = $pdo->prepare("UPDATE admin_users SET password_hash = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
          $upd->execute([$new, $uid]);
          $msg = 'เปลี่ยนรหัสผ่านเรียบร้อย';
        }
      }
    } catch (\Throwable $e) {
      $err = 'เกิดข้อผิดพลาดของระบบ โปรดลองใหม่อีกครั้ง';
      error_log('[change_password] '.$e->getMessage());
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>เปลี่ยนรหัสผ่าน</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#fff;margin:0}
    .box{max-width:420px;margin:10vh auto;background:#fff;padding:24px;border-radius:12px;box-shadow:0 4px 12px rgba(0,0,0,.08)}
    h1{font-size:20px;margin:0 0 10px;text-align:center}
    .sub{font-size:13px;color:#666;text-align:center;margin-bottom:16px}
    label{display:block;font-size:14px;margin:12px 0 6px}
    input{width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;font-size:14px}
    button{width:100%;padding:12px;margin-top:18px;border:0;border-radius:6px;background:#007bff;color:#fff;font-size:15px;cursor:pointer}
    button:hover{background:#0069d9}
    .msg{background:#e8fff1;color:#0a6b3c;padding:10px;border-radius:6px;margin-bottom:14px;font-size:13px;text-align:center}
    .err{background:#ffecec;color:#b00020;padding:10px;border-radius:6px;margin-bottom:14px;font-size:13px;text-align:center}
    .row{display:flex;gap:10px;margin-top:14px}
    .btn{flex:1;text-align:center;text-decoration:none;display:inline-block;padding:10px;border-radius:6px;border:1px solid #ddd;color:#333;background:#fafafa}
    .btn:hover{background:#f2f2f2}
  </style>
</head>
<body>
  <div class="box">
    <h1>เปลี่ยนรหัสผ่าน</h1>
    <div class="sub">ผู้ใช้: <b><?=htmlspecialchars($uname,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></b></div>
    <?php if ($msg): ?><div class="msg"><?=htmlspecialchars($msg,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></div><?php endif; ?>
    <?php if ($err): ?><div class="err"><?=htmlspecialchars($err,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <label>รหัสผ่านเดิม</label>
      <input name="old_password" type="password" required>

      <label>รหัสผ่านใหม่</label>
      <input name="new_password" type="password" required>

      <label>ยืนยันรหัสผ่านใหม่</label>
      <input name="new_password2" type="password" required>

      <button type="submit">บันทึกการเปลี่ยนรหัสผ่าน</button>
    </form>

    <div class="row">
      <a class="btn" href="/tbfadmin/public/admin/dashboard.php">← กลับ Dashboard</a>
      <a class="btn" href="/tbfadmin/public/admin/login.php">ไปหน้า Login</a>
    </div>
  </div>
</body>
</html>
