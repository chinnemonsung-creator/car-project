<?php
// /verify/api/status.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }
if ($method !== 'GET') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'method_not_allowed']); exit; }

// --- read token ---
$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'missing_token'], JSON_UNESCAPED_UNICODE);
  exit;
}

// --- include config/db from tbfadmin ---
$base = dirname(__DIR__, 1);                // /verify
$root = dirname($base, 1);                  // /
$tbf  = $root . '/tbfadmin';
require_once $tbf . '/src/Bootstrap.php';
require_once $tbf . '/src/Database.php';

function jexit(array $arr, int $code=200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

try {
  $pdo = Database::getConnection();
} catch (Throwable $e) {
  jexit(['ok'=>false,'error'=>'db_connect_error'], 500);
}

// --- lookup latest verify_session by token ---
try {
  $sql = "SELECT id, order_id, token, session_id, status, expires_at, created_at, updated_at
          FROM verify_sessions
          WHERE token = ?
          ORDER BY id DESC
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$token]);
  $vs = $st->fetch(PDO::FETCH_ASSOC);
  if (!$vs) {
    jexit(['ok'=>false,'error'=>'token_not_found'], 404);
  }
} catch (Throwable $e) {
  jexit(['ok'=>false,'error'=>'db_error'], 500);
}

// --- compute TTL & effective status ---
$nowTs = time();
$expTs = $vs['expires_at'] ? strtotime((string)$vs['expires_at']) : null;
$ttl   = ($expTs && $expTs > $nowTs) ? ($expTs - $nowTs) : 0;

$dbStatus = strtolower((string)$vs['status']);
$effective = $dbStatus;
if ($expTs !== null && $nowTs >= $expTs && $dbStatus !== 'success' && $dbStatus !== 'confirmed') {
  $effective = 'expired';
}

// NOTE: ยังไม่ปล่อย entry_url ที่ไป DLT ในชั้นนี้ (จะแยกเป็น Attempt ภายหลัง)
// ใส่เป็น null ไปก่อน
$entryUrl = null;

// --- response ---
jexit([
  'ok'          => true,
  'token'       => (string)$vs['token'],
  'status'      => $effective,                  // pending | success/confirmed | expired | (others future)
  'expires_at'  => (string)($vs['expires_at'] ?? ''),
  'ttl_seconds' => (int)$ttl,
  'session_id'  => (string)($vs['session_id'] ?? ''),
  'entry_url'   => $entryUrl,                   // จะมีค่าเมื่อแอดมินออกลิงก์ DLT ใหม่ในอนาคต
]);
