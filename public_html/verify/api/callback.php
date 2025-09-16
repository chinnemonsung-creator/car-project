<?php
// /verify/api/callback.php
declare(strict_types=1);

/*
 * Return URL จาก DLT → อัปเดต verify_sessions → แสดงผลสั้น ๆ และพากลับ /verify/{token}
 * กันพัง/ดีบัก:
 *  - คำนวณ path หา /tbfadmin แบบยืดหยุ่น
 *  - เช็คไฟล์ก่อน require และ log error แทนการตายด้วย 500
 *  - log ลง /tbfadmin/var/php_errors.log
 * ปลอดภัย:
 *  - ไม่ auto-confirm เมื่อ result/status ไม่ชัดเจน
 *  - รองรับ session_id (optional)
 *  - รองรับ HMAC ถ้าตั้ง $config['app']['callback_secret']
 */

header('Content-Type: text/html; charset=utf-8');

/* ---------- Path & logging bootstrap ---------- */
// โครงสร้างที่คาดหวัง: <docroot>/{tbfadmin, verify}
// จากไฟล์นี้ (verify/api/...) → verify → parent = root → root/tbfadmin
$verifyDir = dirname(__DIR__);          // /verify
$rootDir   = dirname($verifyDir);       // parent of verify
$tbfDir    = $rootDir . '/tbfadmin';    // /tbfadmin

$varDir = $tbfDir . '/var';
if (!is_dir($varDir)) { @mkdir($varDir, 0775, true); }
ini_set('log_errors', '1');
ini_set('error_log', $varDir . '/php_errors.log');

function log_debug(string $msg, array $ctx = []): void {
  $line = '[verify-callback] ' . $msg;
  if ($ctx) $line .= ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  error_log($line);
}

function render_done_page(string $title, ?string $token, string $kind='info'): void {
  $safeToken = $token ? htmlspecialchars($token, ENT_QUOTES, 'UTF-8') : '';
  $redirectHref = $token ? "/verify/{$safeToken}" : "/verify";
  $meta = $token ? '<meta http-equiv="refresh" content="2;url='.$redirectHref.'">' : '';
  echo '<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>Verify — Callback</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
'.$meta.'
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-lg-7">
        <div class="alert alert-'.$kind.' shadow-sm">
          <h1 class="h5 mb-2">'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h1>'.
          ($token ? '<div class="small text-muted">กำลังพากลับไปยังหน้าสถานะ… หากไม่ไปเอง <a href="'.$redirectHref.'">คลิกที่นี่</a></div>' : '<div class="small text-muted"><a href="/verify">กลับหน้า Verify</a></div>')
        .'</div>
      </div>
    </div>
  </div>
</body>
</html>';
}

/* ---------- Require files defensively ---------- */
$bootstrapPath = $tbfDir . '/src/Bootstrap.php';
$dbPath        = $tbfDir . '/src/Database.php';
$auditPath     = $tbfDir . '/src/Audit.php';

if (!file_exists($bootstrapPath)) {
  log_debug('bootstrap_missing', ['path'=>$bootstrapPath, 'cwd'=>__DIR__]);
  render_done_page('ไฟล์ระบบหาย (Bootstrap)', null, 'danger'); exit;
}
require_once $bootstrapPath;

if (!file_exists($dbPath)) {
  log_debug('database_missing', ['path'=>$dbPath]);
  render_done_page('ไฟล์ระบบหาย (Database)', null, 'danger'); exit;
}
require_once $dbPath;

if (file_exists($auditPath)) {
  require_once $auditPath;
} else {
  log_debug('audit_optional_missing', ['path'=>$auditPath]);
}

/* ---------- Read inputs ---------- */
$in     = $_GET + $_POST;
$token  = (string)($in['token'] ?? '');
$result = (string)($in['result'] ?? ($in['status'] ?? ''));
$code   = (string)($in['code'] ?? '');
$msg    = (string)($in['message'] ?? '');
$sessId = (string)($in['session_id'] ?? '');
$sig    = (string)($in['sig'] ?? '');

if (!preg_match('/^[A-Za-z0-9]{16,64}$/', $token)) {
  log_debug('invalid_token', ['token'=>$token]);
  render_done_page('ไม่พบโทเคนที่ถูกต้อง', null, 'danger'); exit;
}

/* ---------- Map DLT result → internal status ---------- */
$norm = strtolower(trim($result));
$mapped = match (true) {
  in_array($norm, ['ok','success','confirmed','approve','approved'], true) => 'confirmed',
  in_array($norm, ['cancel','canceled','cancelled','reject','rejected','fail','failed','error'], true) => 'failed',
  default => '', // unknown
};

/* ---------- Optional HMAC (if configured) ---------- */
$secret = $GLOBALS['config']['app']['callback_secret'] ?? '';
if ($secret) {
  $base = $token.'|'.($mapped ?: $norm).'|'.$sessId;
  $expect = hash_hmac('sha256', $base, $secret);
  if (!hash_equals($expect, $sig)) {
    log_debug('bad_signature', ['base'=>$base, 'given'=>$sig]);
    render_done_page('ลายเซ็นไม่ผ่านการตรวจสอบ', $token, 'danger'); exit;
  }
} else {
  log_debug('no_secret', ['note'=>'set app.callback_secret to enforce HMAC']);
}

/* ---------- DB connect ---------- */
try {
  $pdo = Database::getConnection();
} catch (Throwable $e) {
  log_debug('db_connect_error', ['error'=>$e->getMessage()]);
  render_done_page('ไม่สามารถเชื่อมต่อฐานข้อมูลได้', $token, 'danger'); exit;
}

/* ---------- Load verify_session by token ---------- */
try {
  $st = $pdo->prepare("SELECT id, order_id, status, expires_at, session_id FROM verify_sessions WHERE token=? LIMIT 1");
  $st->execute([$token]);
  $vs = $st->fetch(PDO::FETCH_ASSOC);
  if (!$vs) {
    log_debug('vs_not_found', ['token'=>$token]);
    render_done_page('ไม่พบข้อมูลการยืนยัน', $token, 'danger'); exit;
  }
} catch (Throwable $e) {
  log_debug('db_read_vs_failed', ['error'=>$e->getMessage(), 'token'=>$token]);
  render_done_page('เกิดข้อผิดพลาดระหว่างอ่านข้อมูล', $token, 'danger'); exit;
}

/* ---------- Decide final status ---------- */
$final = '';
if ($mapped !== '') {
  $final = $mapped;
} else {
  // unknown result: อย่าคอนเฟิร์มเอง
  $now   = time();
  $expTs = isset($vs['expires_at']) ? strtotime((string)$vs['expires_at']) : 0;
  $expired = ($expTs > 0 && $now > $expTs);
  $final = $expired ? 'expired' : (string)$vs['status']; // คงสถานะเดิมไว้ (เช่น pending)
}

/* ---------- Update verify_sessions ---------- */
try {
  $pdo->beginTransaction();

  // ตรวจว่ามีคอลัมน์ verified_at ไหม
  $hasVerifiedAt = false;
  try { $pdo->query("SELECT verified_at FROM verify_sessions WHERE 1=0"); $hasVerifiedAt = true; } catch (Throwable $e) {}

  $sql = "UPDATE verify_sessions SET status=?, updated_at=NOW()";
  $params = [$final];

  if ($final === 'confirmed' && $hasVerifiedAt) {
    $sql .= ", verified_at=NOW()";
  }
  if ($sessId !== '') {
    $sql .= ", session_id=?";
    $params[] = $sessId;
  }
  $sql .= " WHERE id=?";
  $params[] = (int)$vs['id'];

  $up = $pdo->prepare($sql);
  $up->execute($params);

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  log_debug('db_update_vs_failed', ['error'=>$e->getMessage(), 'token'=>$token]);
  render_done_page('บันทึกผลล้มเหลว', $token, 'danger'); exit;
}

/* ---------- Log & Audit ---------- */
log_debug('vs_finalized', ['token'=>$token, 'final'=>$final, 'code'=>$code, 'msg'=>$msg, 'session_id'=>$sessId]);
if (class_exists('Audit')) {
  try { Audit::log('verify.callback', ['final'=>$final, 'code'=>$code], 'verify_session', (int)$vs['id']); } catch (Throwable $e) {}
}

/* ---------- Render & redirect ---------- */
$title = match ($final) {
  'confirmed' => 'ยืนยันเสร็จสิ้น',
  'expired'   => 'ลิงก์หมดอายุ',
  'failed'    => 'การยืนยันไม่สำเร็จ',
  default     => 'อัปเดตสถานะแล้ว',
};
render_done_page($title, $token, $final === 'confirmed' ? 'success' : ($final==='expired'?'warning':'info'));
exit;
