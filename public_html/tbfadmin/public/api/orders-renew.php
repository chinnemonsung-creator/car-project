<?php
// /tbfadmin/public/api/orders-renew.php
declare(strict_types=1);

/*
 * ต่ออายุลิงก์ยืนยันตัวตน (Extend/Renew TTL) — ADMIN ONLY
 *
 * ใช้กรณีลิงก์ใกล้หมดอายุ หรือหมดอายุแล้ว ต้องออกลิงก์ใหม่
 *
 * INPUT (POST):
 *   - order_id (int)   : รหัสออเดอร์
 *   - csrf (string)    : CSRF token ของฝั่งแอดมิน
 *   - minutes (int?)   : จำนวนนาทีที่จะต่ออายุ (1..30, default 5)
 *   - mode (string?)   : 'extend' (ต่ออายุโทเคนเดิมถ้ายัง pending ไม่หมดอายุ)
 *                        'renew'  (ออกเรคอร์ด+โทเคนใหม่เสมอ)
 *                        หากไม่ส่ง จะ auto: ถ้า vs ยัง pending และไม่หมดอายุ -> extend, นอกนั้น renew
 *
 * RESPONSE:
 *   { ok, order_id, action: 'extended'|'renewed', status, expires_at, ttl_seconds, verify_url }
 *
 * DEBUG:
 *   - logger: /tbfadmin/var/php_errors.log
 *   - ไม่โชว์รายละเอียดเชิงระบบให้ client
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed','error_code'=>'METHOD']); exit; }

/* ---------- Logging helper ---------- */
$varDir = dirname(__DIR__, 2) . '/var'; // /tbfadmin/var
if (!is_dir($varDir)) { @mkdir($varDir, 0775, true); }
ini_set('log_errors', '1');
ini_set('error_log', $varDir . '/php_errors.log');

function log_debug(string $msg, array $ctx = []): void {
  $line = '[orders-renew] ' . $msg;
  if ($ctx) $line .= ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  error_log($line);
}

function jexit(array $arr, int $code=200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Bootstrap & deps ---------- */
require_once __DIR__ . '/../../src/Bootstrap.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Security.php';
if (file_exists(__DIR__ . '/../../src/Audit.php')) {
  require_once __DIR__ . '/../../src/Audit.php';
}

/* ---------- Require admin session (for CSRF) ---------- */
session_name($GLOBALS['config']['app']['admin_session_name'] ?? 'bb_admin');
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

/* ---------- Read inputs ---------- */
$order_id = (int)($_POST['order_id'] ?? 0);
$csrf     = (string)($_POST['csrf'] ?? '');
$admin    = $_SESSION['admin_username'] ?? 'unknown';

$minutes  = (int)($_POST['minutes'] ?? 5);
$minutes  = max(1, min(30, $minutes)); // clamp 1..30
$mode     = strtolower((string)($_POST['mode'] ?? '')); // extend | renew | ''

if (!Security::verifyCsrf($csrf)) {
  log_debug('invalid_csrf', ['order_id'=>$order_id, 'admin'=>$admin, 'sid'=>session_id()]);
  jexit(['ok'=>false,'error'=>'invalid_csrf','error_code'=>'CSRF'], 403);
}
if ($order_id <= 0) {
  log_debug('invalid_order_id', ['order_id'=>$_POST['order_id'] ?? null, 'admin'=>$admin]);
  jexit(['ok'=>false,'error'=>'invalid_order_id','error_code'=>'ORDER_ID'], 400);
}

/* ---------- DB connection ---------- */
try {
  $pdo = Database::getConnection();
} catch (Throwable $e) {
  log_debug('db_connect_error', ['error'=>$e->getMessage()]);
  jexit(['ok'=>false,'error'=>'db_connect_error','error_code'=>'DB_CONN'], 500);
}

/* ---------- Load order ---------- */
try {
  $st = $pdo->prepare("SELECT id, session_id FROM orders WHERE id=? LIMIT 1");
  $st->execute([$order_id]);
  $order = $st->fetch(PDO::FETCH_ASSOC);
  if (!$order) {
    log_debug('order_not_found', ['order_id'=>$order_id]);
    jexit(['ok'=>false,'error'=>'order_not_found','error_code'=>'ORDER_NOT_FOUND'], 404);
  }
} catch (Throwable $e) {
  log_debug('db_read_order_failed', ['error'=>$e->getMessage(), 'order_id'=>$order_id]);
  jexit(['ok'=>false,'error'=>'db_error','error_code'=>'DB_READ_ORDER'], 500);
}

/* ---------- Load latest verify_session ---------- */
try {
  $st2 = $pdo->prepare("SELECT id, token, status, expires_at, updated_at
                        FROM verify_sessions
                        WHERE order_id=?
                        ORDER BY id DESC
                        LIMIT 1");
  $st2->execute([$order_id]);
  $vs = $st2->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  log_debug('db_read_verify_session_failed', ['error'=>$e->getMessage(), 'order_id'=>$order_id]);
  jexit(['ok'=>false,'error'=>'db_error','error_code'=>'DB_READ_VS'], 500);
}

/* ---------- Decide extend or renew ---------- */
$nowTs = time();
$doAction = 'renewed'; // default
if ($mode === 'extend') {
  $doAction = 'extended';
} elseif ($mode === 'renew') {
  $doAction = 'renewed';
} else {
  // auto
  if ($vs) {
    $status = strtolower((string)$vs['status']);
    $expTs  = $vs['expires_at'] ? strtotime((string)$vs['expires_at']) : 0;
    $notExpired = ($expTs === 0) ? false : ($nowTs <= $expTs);
    if ($status === 'pending' && $notExpired) {
      $doAction = 'extended';
    } else {
      $doAction = 'renewed';
    }
  } else {
    $doAction = 'renewed';
  }
}

/* ---------- Execute ---------- */
$host   = $_SERVER['HTTP_HOST'] ?? ($GLOBALS['config']['app']['public_host'] ?? 'bellafleur-benly.com');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

if ($doAction === 'extended' && $vs) {
  // ต่ออายุเรคอร์ดเดิม (ยัง pending และไม่หมดอายุ)
  $expTs = $nowTs + ($minutes * 60);
  $expires_at = date('Y-m-d H:i:s', $expTs);

  // safety: set status ให้เป็น pending เสมอ
  try {
    $sql = "UPDATE verify_sessions
            SET status='pending', expires_at=?, updated_at=NOW()
            WHERE id=?";
    $u = $pdo->prepare($sql);
    $u->execute([$expires_at, (int)$vs['id']]);
  } catch (Throwable $e) {
    log_debug('extend_failed', ['error'=>$e->getMessage(), 'vs_id'=>$vs['id'] ?? null]);
    jexit(['ok'=>false,'error'=>'extend_failed','error_code'=>'EXTEND_FAILED'], 500);
  }

  $verify_url = sprintf('%s://%s/verify/%s', $scheme, $host, rawurlencode((string)$vs['token']));
  $ttl = max(0, $expTs - $nowTs);

  log_debug('extended_ok', ['order_id'=>$order_id, 'vs_id'=>$vs['id'], 'minutes'=>$minutes, 'admin'=>$admin]);
  if (class_exists('Audit')) {
    try { Audit::log('verify.extend', ['minutes'=>$minutes], 'order', (int)$order['id']); } catch (Throwable $e) {}
  }

  jexit([
    'ok'          => true,
    'order_id'    => (int)$order['id'],
    'action'      => 'extended',
    'status'      => 'pending',
    'expires_at'  => $expires_at,
    'ttl_seconds' => $ttl,
    'verify_url'  => $verify_url,
  ]);

} else {
  // ออกเรคอร์ดใหม่ (renew): token ใหม่, status=pending, TTL จาก minutes
  try {
    $pdo->beginTransaction();
    try { $token = bin2hex(random_bytes(16)); }
    catch (Throwable $e) { $token = md5(uniqid('', true)); }

    $expTs = $nowTs + ($minutes * 60);
    $expires_at = date('Y-m-d H:i:s', $expTs);

    $ins = $pdo->prepare("INSERT INTO verify_sessions
      (order_id, token, session_id, status, expires_at, created_by, created_at, updated_at)
      VALUES (?, ?, ?, 'pending', ?, ?, NOW(), NOW())");
    $ins->execute([(int)$order['id'], $token, (string)$order['session_id'], $expires_at, $admin]);
    $newId = (int)$pdo->lastInsertId();
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    log_debug('renew_failed', ['error'=>$e->getMessage(), 'order_id'=>$order_id]);
    jexit(['ok'=>false,'error'=>'renew_failed','error_code'=>'RENEW_FAILED'], 500);
  }

  $verify_url = sprintf('%s://%s/verify/%s', $scheme, $host, rawurlencode($token));
  $ttl = max(0, $expTs - $nowTs);

  log_debug('renewed_ok', ['order_id'=>$order_id, 'vs_id'=>$newId, 'minutes'=>$minutes, 'admin'=>$admin]);
  if (class_exists('Audit')) {
    try { Audit::log('verify.renew', ['minutes'=>$minutes], 'order', (int)$order['id']); } catch (Throwable $e) {}
  }

  jexit([
    'ok'          => true,
    'order_id'    => (int)$order['id'],
    'action'      => 'renewed',
    'status'      => 'pending',
    'expires_at'  => $expires_at,
    'ttl_seconds' => $ttl,
    'verify_url'  => $verify_url,
  ]);
}
