<?php
// /verify/api/start.php  (Option A: schema ปัจจุบัน ไม่มีคอลัมน์ sid จริง)
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Start-Mode', 'schema:attempt_no + sid_virtual');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

/* ---- Logging ---- */
$varDir = __DIR__ . '/../../tbfadmin/var';
if (!is_dir($varDir)) { @mkdir($varDir, 0775, true); }
@ini_set('log_errors', '1');
@ini_set('error_log', $varDir . '/php-error.log');

$ATTEMPT_TTL_SECONDS = 600;

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
function read_token(): ?string {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input'); if ($raw){ $j=json_decode($raw,true); if (is_array($j)&&!empty($j['token'])) return trim((string)$j['token']); }
  }
  if (!empty($_GET['token'])) return trim((string)$_GET['token']);
  return null;
}

$token = read_token();
if (!$token) jexit(['ok'=>false,'error'=>'missing_token'], 400);

try {
  $pdo = get_pdo();
  // 1) หา session ด้วย token
  $st = $pdo->prepare("SELECT id, session_id, token, status, expires_at FROM verify_sessions WHERE token=:t LIMIT 1");
  $st->execute([':t'=>$token]);
  $sess = $st->fetch();
  if (!$sess) jexit(['ok'=>false,'error'=>'session_not_found','token'=>$token], 404);

  // 2) คำนวณ attempt_no ล่าสุด +1 สำหรับ session นี้ (ใช้ session_id (string) ตามสคีมาปัจจุบัน)
  $st = $pdo->prepare("SELECT COALESCE(MAX(attempt_no),0) AS mx FROM verify_attempts WHERE session_id=:sid");
  $st->execute([':sid'=>$sess['session_id']]);
  $row = $st->fetch();
  $nextAttempt = (int)($row['mx'] ?? 0) + 1;

  // 3) expires
  $now = new DateTimeImmutable('now');
  $exp = $now->add(new DateInterval('PT'.$ATTEMPT_TTL_SECONDS.'S'))->format('Y-m-d H:i:s');

  // 4) INSERT แถว attempt ใหม่ (status เริ่มต้นใช้ ENUM เดิม: pending)
  $ins = $pdo->prepare("
    INSERT INTO verify_attempts
      (session_id, attempt_no, status, expires_at, created_at, updated_at)
    VALUES
      (:sid, :attempt_no, 'pending', :exp, NOW(), NOW())
  ");
  $ins->execute([
    ':sid' => $sess['session_id'],
    ':attempt_no' => $nextAttempt,
    ':exp' => $exp,
  ]);

  // 5) sid (virtual) = session_id#NNN
  $sidVirtual = $sess['session_id'] . '#' . str_pad((string)$nextAttempt, 3, '0', STR_PAD_LEFT);

  jexit([
    'ok' => true,
    'status' => 'pending',
    'expires_at' => $exp,
    'token' => $token,
    'sid' => $sidVirtual,     // virtual
    'entry_url' => null,
    'ttl_seconds' => $ATTEMPT_TTL_SECONDS
  ], 200);

} catch (Throwable $e) {
  error_log('[start.php] error: '.$e->getMessage());
  jexit(['ok'=>false,'error'=>'attempt_insert_failed'], 500);
}
