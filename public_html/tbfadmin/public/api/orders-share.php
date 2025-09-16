<?php
// /tbfadmin/public/api/orders-share.php
declare(strict_types=1);

/*
 * DEBUG MODE (safe-to-expose)
 * - Log: /tbfadmin/var/php_errors.log
 * - Response เป็น JSON เสมอ พร้อม error_code ช่วยดีบัก
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'method_not_allowed','error_code'=>'METHOD']);
  exit;
}

/* ---------- Logging helper ---------- */
$varDir = dirname(__DIR__, 2) . '/var'; // /tbfadmin/var
if (!is_dir($varDir)) { @mkdir($varDir, 0775, true); }
@ini_set('log_errors', '1');
@ini_set('error_log', $varDir . '/php_errors.log');

function log_debug(string $msg, array $ctx = []): void {
  $line = '[orders-share] ' . $msg;
  if ($ctx) $line .= ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  error_log($line);
}
function jexit(array $arr, int $code=200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit;
}

/* ---------- Bootstrap & deps ---------- */
require_once __DIR__ . '/../../src/Bootstrap.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/Security.php';
if (file_exists(__DIR__ . '/../../src/Audit.php')) {
  require_once __DIR__ . '/../../src/Audit.php';
}

/* ---------- Session (for CSRF) ---------- */
session_name($GLOBALS['config']['app']['admin_session_name'] ?? 'bb_admin');
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

/* ---------- Inputs ---------- */
$order_id = (int)($_POST['order_id'] ?? 0);
$csrf     = (string)($_POST['csrf'] ?? '');
$admin    = $_SESSION['admin_username'] ?? 'unknown';

if (!Security::verifyCsrf($csrf)) {
  log_debug('invalid_csrf', ['order_id'=>$order_id, 'admin'=>$admin, 'sid'=>session_id()]);
  jexit(['ok'=>false,'error'=>'invalid_csrf','error_code'=>'CSRF'], 403);
}
if ($order_id <= 0) {
  log_debug('invalid_order_id', ['order_id'=>$_POST['order_id'] ?? null, 'admin'=>$admin]);
  jexit(['ok'=>false,'error'=>'invalid_order_id','error_code'=>'ORDER_ID'], 400);
}

/* ---------- DB ---------- */
try {
  $pdo = Database::conn();   // ✅ ใช้ conn() เท่านั้น
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

/* ---------- Get latest verify_session ---------- */
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

/* ---------- Decide: reuse or create new ---------- */
$now  = time();
$ttlS = 3 * 60; // TTL 3 นาที
$needNew = true;

if ($vs) {
  $status = strtolower((string)$vs['status']);
  $expTs  = strtotime((string)$vs['expires_at'] ?? '');
  // ถ้ายัง pending และยังไม่หมดอายุ (เหลือ >15s) ⇒ reuse
  if ($status === 'pending' && $expTs && ($expTs - $now) > 15) {
    $needNew = false;
  }
}

if ($needNew) {
  try {
    $pdo->beginTransaction();
    try { $token = bin2hex(random_bytes(16)); }
    catch (Throwable $e) { $token = md5(uniqid('', true)); }

    $expires_at = date('Y-m-d H:i:s', $now + $ttlS);

    // สร้างให้เข้ากับ schema เดิมของคุณ (มี session_id, created_by)
    $ins = $pdo->prepare("INSERT INTO verify_sessions
        (order_id, token, session_id, status, expires_at, created_by, created_at, updated_at)
        VALUES (?, ?, ?, 'pending', ?, ?, NOW(), NOW())");
    $ins->execute([(int)$order['id'], $token, (string)$order['session_id'], $expires_at, $admin]);

    $newId = (int)$pdo->lastInsertId();
    $pdo->commit();

    $vs = [
      'id'         => $newId,
      'token'      => $token,
      'status'     => 'pending',
      'expires_at' => $expires_at,
      'updated_at' => date('Y-m-d H:i:s'),
    ];
    log_debug('verify_session_created', ['order_id'=>$order_id, 'vs_id'=>$newId, 'admin'=>$admin]);
    if (class_exists('Audit')) {
      Audit::log('verify.create', ['from'=>'orders-share', 'vs_id'=>$newId], 'order', (int)$order['id']);
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    log_debug('create_verify_session_failed', ['error'=>$e->getMessage(), 'order_id'=>$order_id]);
    jexit(['ok'=>false,'error'=>'create_verify_session_failed','error_code'=>'VS_CREATE'], 500);
  }
}

/* ---------- Build verify_url ---------- */
$host   = $_SERVER['HTTP_HOST'] ?? ($GLOBALS['config']['app']['public_host'] ?? 'bellafleur-benly.com');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$verify_url = sprintf('%s://%s/verify/%s', $scheme, $host, rawurlencode((string)$vs['token']));

$expTs = strtotime((string)$vs['expires_at'] ?? '');
$ttl_left = $expTs ? max(0, $expTs - time()) : null;

/* ---------- Response ---------- */
jexit([
  'ok'           => true,
  'order_id'     => (int)$order['id'],
  'status'       => (string)($vs['status'] ?? 'pending'),
  'expires_at'   => (string)($vs['expires_at'] ?? ''),
  'ttl_seconds'  => $ttl_left,
  'verify_url'   => $verify_url,
], 200);
