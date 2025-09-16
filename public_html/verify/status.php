<?php
// /verify/status.php
declare(strict_types=1);

/*
 * STATUS API (by sid)
 * - GET /verify/status.php?sid=xxxxxxxx
 * - ตอบ: { ok, status, expires_at, ttl_seconds, session_id, attempt_no }
 * หมายเหตุ:
 *   - ถ้า ttl <= 0 และ status ยังไม่ใช่ confirmed/success → ตีเป็น expired
 *   - attempt_no = MAX(attempt_no) ของ session_id (ถ้าไม่มีตาราง/ข้อมูล → 1)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'method_not_allowed'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Error log -> /tbfadmin/var/php_errors.log (Unified) ---------- */
$varDir = null;
foreach ([
  // โครงหลัก: .../tbfadmin/public/verify/status.php → ถอย 2 ชั้นไป .../tbfadmin/var
  dirname(__DIR__, 2) . '/var',
  // สำรอง
  dirname(__DIR__, 2) . '/tbfadmin/var',
  dirname(__DIR__, 3) . '/var',
  __DIR__ . '/../var',
] as $cand) {
  if (@is_dir($cand) || @mkdir($cand, 0775, true)) { $varDir = $cand; break; }
}
if ($varDir) {
  @ini_set('log_errors', '1');
  @ini_set('error_log', $varDir . '/php_errors.log');
}
function log_debug(string $msg, array $ctx=[]): void {
  $line = '[verify-status/sid] ' . $msg;
  if ($ctx) $line .= ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  error_log($line);
}

/* ---------- Bootstrap/DB (Unified) ---------- */
$basePathCandidates = [
  // โครงหลัก: .../tbfadmin/public/verify/status.php → ถอย 2 ชั้นไป .../tbfadmin/src
  dirname(__DIR__, 2) . '/src',
  // สำรอง
  dirname(__DIR__, 3) . '/tbfadmin/src',
  dirname(__DIR__, 3) . '/src',
  __DIR__ . '/../src',
];
$loaded = false;
foreach ($basePathCandidates as $base) {
  if (is_file($base . '/Bootstrap.php') && is_file($base . '/Database.php')) {
    require_once $base . '/Bootstrap.php';
    require_once $base . '/Database.php';
    $loaded = true; break;
  }
}
if (!$loaded) {
  log_debug('bootstrap_missing', ['candidates'=>$basePathCandidates]);
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'bootstrap_missing'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo = method_exists('Database', 'conn')
       ? Database::conn()
       : Database::getConnection();
} catch (Throwable $e) {
  log_debug('db_connect_error', ['error'=>$e->getMessage()]);
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_connect_error'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Read sid (session_id) ---------- */
$sid = (string)($_GET['sid'] ?? '');

/* รองรับกรณีแนบ sid ใน path เช่น /verify/status.php/xxxxx */
if ($sid === '' && !empty($_SERVER['REQUEST_URI'])) {
  if (preg_match('#/verify/status\.php/([A-Za-z0-9_-]{8,64})#', $_SERVER['REQUEST_URI'], $m)) {
    $sid = $m[1];
  }
}

/* ยอมรับรูปแบบ UUID/alnum + - _ ยาว 8–64 ตัวอักษร */
if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $sid)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_sid'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Query verify_sessions by session_id ---------- */
try {
  $sql = "SELECT vs.id, vs.status, vs.expires_at, vs.updated_at, vs.session_id
          FROM verify_sessions vs
          WHERE vs.session_id = ?
          ORDER BY vs.id DESC
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$sid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
  }
} catch (Throwable $e) {
  log_debug('db_read_vs_failed', ['error'=>$e->getMessage()]);
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_error'], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Compute status + ttl (Unified) ---------- */
$now   = time();
$expTs = !empty($row['expires_at']) ? strtotime((string)$row['expires_at']) : 0;
$ttl   = max(0, $expTs > 0 ? ($expTs - $now) : 0);

$status = strtolower((string)$row['status']);
if ($ttl <= 0 && $status !== 'confirmed' && $status !== 'success') {
  $status = 'expired';
}

/* ---------- Attempt no (MAX(attempt_no)) ---------- */
$attemptNo = 1; // ค่าพื้นฐานถ้ายังไม่เคยมี attempt
try {
  $sqlA = "SELECT COALESCE(MAX(va.attempt_no), 1) AS attempt_no
           FROM verify_attempts va
           WHERE va.session_id = ?";
  $stA = $pdo->prepare($sqlA);
  $stA->execute([$sid]);
  $a = $stA->fetch(PDO::FETCH_ASSOC);
  if ($a && isset($a['attempt_no']) && (int)$a['attempt_no'] > 0) {
    $attemptNo = (int)$a['attempt_no'];
  }
} catch (Throwable $e) {
  log_debug('attempt_lookup_failed', ['error'=>$e->getMessage()]);
}

/* ---------- Response (Unified) ---------- */
echo json_encode([
  'ok'           => true,
  'status'       => $status,
  'expires_at'   => (string)($row['expires_at'] ?? ''),
  'ttl_seconds'  => $ttl,
  'session_id'   => (string)($row['session_id'] ?? $sid),
  'attempt_no'   => $attemptNo,
], JSON_UNESCAPED_UNICODE);
