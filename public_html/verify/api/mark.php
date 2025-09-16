<?php
// /verify/api/mark.php  (Option A: ใช้ sid เสมือน session_id#NNN)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'OPTIONS') { http_response_code(204); exit; }

/* ---- Logging ---- */
$varDir = __DIR__ . '/../../tbfadmin/var';
if (!is_dir($varDir)) { @mkdir($varDir, 0775, true); }
@ini_set('log_errors', '1');
@ini_set('error_log', $varDir . '/php-error.log');

$bootstrap = __DIR__ . '/../../tbfadmin/src/Bootstrap.php';
$dbfile    = __DIR__ . '/../../tbfadmin/src/Database.php';
if (is_file($bootstrap)) require $bootstrap;
if (is_file($dbfile))    require_once $dbfile;

function jexit(array $d, int $code=200){ http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function get_pdo(): PDO {
  if (class_exists('Database')) {
    if (method_exists('Database','getConnection')) { $p = Database::getConnection(); if ($p instanceof PDO) return $p; }
    if (method_exists('Database','getPdo'))        { $p = Database::getPdo();        if ($p instanceof PDO) return $p; }
    try { $d = new Database(); foreach (['getConnection','getPdo','pdo','connection'] as $m){ if (method_exists($d,$m)){ $p=$d->$m(); if ($p instanceof PDO) return $p; } } } catch(Throwable $e){}
  }
  $cfg = $GLOBALS['config']['db'] ?? null;
  if (is_array($cfg) && !empty($cfg['dsn'])) {
    return new PDO($cfg['dsn'], (string)($cfg['username'] ?? $cfg['user'] ?? ''), (string)($cfg['password'] ?? $cfg['pass'] ?? ''), [
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);
  }
  throw new RuntimeException('DB connection unavailable');
}

$raw = file_get_contents('php://input'); $in = $raw ? json_decode($raw, true) : null;
$sid = is_array($in) ? trim((string)($in['sid'] ?? '')) : '';
$reqStatus = is_array($in) ? trim((string)($in['status'] ?? '')) : '';

if ($sid === '' || $reqStatus === '') jexit(['ok'=>false,'error'=>'missing_parameters','hint'=>'sid,status required'], 400);

// map status ที่ UI อาจส่งมา ให้เข้ากับ ENUM จริง
$map = [
  'success' => 'confirmed',
  'fail'    => 'failed',
];
$st = strtolower($reqStatus);
$st = $map[$st] ?? $st;

// อนุญาตเฉพาะชุด ENUM ที่ตารางรองรับ
$allowed = ['pending','confirmed','expired','failed'];
if (!in_array($st, $allowed, true)) {
  jexit(['ok'=>false,'error'=>'invalid_status','allowed'=>$allowed], 400);
}

// parse sid virtual → session_id + attempt_no
// รูปแบบ: <session_id>#<NNN>
$parts = explode('#', $sid);
if (count($parts) !== 2 || $parts[0]==='' || !ctype_digit($parts[1])) {
  jexit(['ok'=>false,'error'=>'invalid_sid_format','example'=>'SESSIONID#001'], 400);
}
$sessionId = $parts[0];
$attemptNo = (int)$parts[1];

try {
  $pdo = get_pdo();

  // อัปเดตแถวเป้าหมาย
  $u = $pdo->prepare("
    UPDATE verify_attempts
    SET status = :st, updated_at = NOW()
    WHERE session_id = :sid AND attempt_no = :no
    LIMIT 1
  ");
  $u->execute([':st'=>$st, ':sid'=>$sessionId, ':no'=>$attemptNo]);

  if ($u->rowCount() < 1) {
    jexit(['ok'=>false,'error'=>'sid_not_found'], 404);
  }

  jexit(['ok'=>true, 'sid'=>$sid, 'status'=>$st], 200);

} catch (Throwable $e) {
  error_log('[mark.php] error: '.$e->getMessage());
  jexit(['ok'=>false,'error'=>'server_error'], 500);
}
