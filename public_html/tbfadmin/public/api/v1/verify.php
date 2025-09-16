<?php
// /tbfadmin/public/api/v1/verify.php
declare(strict_types=1);

/* ---------- Error / Log ---------- */
@error_reporting(E_ALL);
@ini_set('display_errors','0');
@ini_set('log_errors','1');
$__logDir = __DIR__ . '/../../../var';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }
@ini_set('error_log', $__logDir . '/php-error.log');

/* ---------- CORS / Headers ---------- */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$__method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($__method === 'OPTIONS') { http_response_code(204); exit; }

/* ---------- Boot ---------- */
require __DIR__ . '/../../../src/Bootstrap.php';
require __DIR__ . '/../../../src/Database.php';

/* ---------- Auth (แชร์เซสชันกับแอดมิน) ---------- */
$sessionName = $GLOBALS['config']['app']['admin_session_name'] ?? 'bb_admin';
session_name($sessionName);
if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_set_cookie_params([
    'lifetime'=>0, 'path'=>'/', 'httponly'=>true, 'samesite'=>'Lax',
    'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'),
  ]);
  @session_start();
}
if (empty($_SESSION['admin']) && empty($_SESSION['admin_logged_in'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

/* ---------- Helpers ---------- */
function json_out($a, int $code=200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
function now(): string { return date('Y-m-d H:i:s'); }
function uuid_token(): string { return bin2hex(random_bytes(16)); } // 32 hex
function new_session_id(): string { return 'VS-'.date('YmdHis').'-'.substr(bin2hex(random_bytes(4)),0,8); }
function is_truthy($v): bool { return $v===true || $v==='1' || $v===1 || $v==='true' || $v==='on'; }

function has_col(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = $table.'|'.$col;
  if(isset($cache[$key])) return $cache[$key];
  $stmt = $pdo->prepare("
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
    LIMIT 1
  ");
  $stmt->execute([':t'=>$table, ':c'=>$col]);
  return $cache[$key] = (bool)$stmt->fetchColumn();
}

function get_json_body(): array {
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

/* ---------- Read input ---------- */
$body = ($__method === 'POST') ? get_json_body() : $_GET;
$action = strtolower(trim((string)($body['action'] ?? '')));

/* ---------- DB ---------- */
$pdo = Database::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ---------- Utilities for this API ---------- */
/** หา session ล่าสุดของ order */
function find_latest_session(PDO $pdo, int $orderId): ?array {
  $sql = "SELECT * FROM verify_sessions
          WHERE order_id = :oid
          ORDER BY 
            CASE WHEN status IN ('success','confirmed') THEN 1
                 WHEN status IN ('pending','authing','retrying','ready') THEN 2
                 WHEN status IN ('expired','failed') THEN 3
                 ELSE 9 END,
            id DESC
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([':oid'=>$orderId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/** สร้าง session ใหม่ให้ order */
function create_new_session(PDO $pdo, int $orderId, int $ttlHours = 48): array {
  $token = uuid_token();
  $sid   = new_session_id();
  $now   = now();
  $exp   = date('Y-m-d H:i:s', time() + $ttlHours*3600);

  $cols = ['order_id','token','session_id','status','created_at','expires_at'];
  $vals = [':oid',    ':tok', ':sid',     ':st',   ':ca',      ':ex'];
  $bind = [':oid'=>$orderId, ':tok'=>$token, ':sid'=>$sid, ':st'=>'pending', ':ca'=>$now, ':ex'=>$exp];

  if (has_col($pdo,'verify_sessions','verify_url')) {
    $cols[]='verify_url'; $vals[]=':vu'; $bind[':vu']='/verify/'.$token;
  }

  $sql = "INSERT INTO verify_sessions (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
  $st = $pdo->prepare($sql);
  $st->execute($bind);

  return [
    'id'=>(int)$pdo->lastInsertId(),
    'order_id'=>$orderId,
    'token'=>$token,
    'session_id'=>$sid,
    'status'=>'pending',
    'verify_url'=>'/verify/'.$token,
    'expires_at'=>$exp,
    'created_at'=>$now
  ];
}

/** ดึง session จาก token */
function get_session_by_token(PDO $pdo, string $token): ?array {
  $st = $pdo->prepare("SELECT * FROM verify_sessions WHERE token=:t LIMIT 1");
  $st->execute([':t'=>$token]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/** หา attempt ล่าสุดของ session */
function find_latest_attempt(PDO $pdo, string $sessionId): ?array {
  $st = $pdo->prepare("SELECT * FROM verify_attempts WHERE session_id=:sid ORDER BY id DESC LIMIT 1");
  $st->execute([':sid'=>$sessionId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/** ปิด attempt ที่ active ให้เป็นสถานะเป้าหมาย (default: expired) */
function close_active_attempts(PDO $pdo, string $sessionId, string $toStatus='expired'): int {
  $st = $pdo->prepare("UPDATE verify_attempts SET status=:s, updated_at=:u
                       WHERE session_id=:sid AND status IN ('pending','authing','retrying')");
  $st->execute([':s'=>$toStatus, ':u'=>now(), ':sid'=>$sessionId]);
  return $st->rowCount();
}

/** สร้าง attempt ใหม่ (จุดเสียบ DLT entry_url จริงให้อยู่ที่นี่ในอนาคต) */
function create_new_attempt(PDO $pdo, string $sessionId, int $ttlSeconds = 300, ?string $entryUrl = null): array {
  $st = $pdo->prepare("SELECT COALESCE(MAX(attempt_no),0) FROM verify_attempts WHERE session_id=:sid");
  $st->execute([':sid'=>$sessionId]);
  $no = (int)$st->fetchColumn() + 1;

  $nowTs = time();
  $exp   = date('Y-m-d H:i:s', $nowTs + max(60,$ttlSeconds)); // อย่างน้อย 60 วิ
  if ($entryUrl === null) {
    // mock จนกว่าจะต่อ DLT จริง
    $entryUrl = '/verify/dlt/entry/'.rawurlencode($sessionId).'/'.$no;
  }

  $sql = "INSERT INTO verify_attempts
          (session_id,attempt_no,status,entry_url,expires_at,created_at,updated_at)
          VALUES (:sid,:no,:st,:eu,:ex,:ca,:ua)";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':sid'=>$sessionId, ':no'=>$no, ':st'=>'pending',
    ':eu'=>$entryUrl, ':ex'=>$exp, ':ca'=>date('Y-m-d H:i:s',$nowTs), ':ua'=>date('Y-m-d H:i:s',$nowTs)
  ]);

  return [
    'id'=>(int)$pdo->lastInsertId(),
    'session_id'=>$sessionId,
    'attempt_no'=>$no,
    'status'=>'pending',
    'entry_url'=>$entryUrl,
    'expires_at'=>$exp
  ];
}

/** ทำให้ session ก่อนหน้าเป็น expired (ถ้าจะออกใหม่) */
function expire_session(PDO $pdo, int $sessionPk): void {
  $st = $pdo->prepare("UPDATE verify_sessions SET status='expired', updated_at=:u WHERE id=:id AND status NOT IN ('success')");
  $st->execute([':u'=>now(),':id'=>$sessionPk]);
}

/* ---------- Router ---------- */
try {
  switch ($action) {

    case 'ensure_session': {
      // input: order_id (int)
      $oid = (int)($body['order_id'] ?? 0);
      if ($oid <= 0) json_out(['ok'=>false,'error'=>'order_id_required'], 400);

      $pdo->beginTransaction();
      $sess = find_latest_session($pdo, $oid);
      $use  = null;

      $nowTs = time();
      if ($sess) {
        $expOk = empty($sess['expires_at']) ? true : (strtotime($sess['expires_at']) > $nowTs);
        if ($expOk && !in_array(strtolower((string)$sess['status']), ['success','expired'], true)) {
          $use = $sess;
        }
      }
      if (!$use) {
        $use = create_new_session($pdo, $oid, 48); // อายุ 48 ชม.
      }
      $pdo->commit();

      json_out([
        'ok'=>true,
        'data'=>[
          'order_id'=>(int)$use['order_id'],
          'token'=>$use['token'],
          'session_id'=>$use['session_id'],
          'verify_url'=>('/verify/'.$use['token']),
          'status'=>$use['status'],
          'expires_at'=>$use['expires_at'] ?? null,
        ]
      ]);
      break;
    }

    case 'start_attempt': {
      // input: token (string), force(optional bool)
      $token = trim((string)($body['token'] ?? ''));
      $force = is_truthy($body['force'] ?? false);
      if ($token === '') json_out(['ok'=>false,'error'=>'token_required'], 400);

      $sess = get_session_by_token($pdo, $token);
      if (!$sess) json_out(['ok'=>false,'error'=>'session_not_found'], 404);

      $sid   = (string)$sess['session_id'];
      $nowTs = time();
      $expOk = empty($sess['expires_at']) ? true : (strtotime($sess['expires_at']) > $nowTs);
      if (!$expOk || strtolower((string)$sess['status'])==='expired') {
        json_out(['ok'=>false,'error'=>'session_expired_need_new_token'], 409);
      }

      $pdo->beginTransaction();
      $latest = find_latest_attempt($pdo, $sid);

      if ($latest && in_array(strtolower((string)$latest['status']), ['pending','authing','retrying'], true) && !$force) {
        $pdo->commit();
        json_out(['ok'=>true,'data'=>[
          'session_id'=>$sid,
          'attempt_no'=>(int)$latest['attempt_no'],
          'status'=>$latest['status'],
          'entry_url'=>$latest['entry_url'],
          'expires_at'=>$latest['expires_at'],
          'token'=>$token,
        ]]);
      }

      if ($latest && in_array(strtolower((string)$latest['status']), ['pending','authing','retrying'], true) && $force) {
        close_active_attempts($pdo, $sid, 'expired');
      }

      // TODO: จุดต่อ DLT จริง (ขอ entry_url + TTL)
      $attempt = create_new_attempt($pdo, $sid, 300, null);
      $pdo->commit();

      json_out(['ok'=>true,'data'=>[
        'session_id'=>$sid,
        'attempt_no'=>$attempt['attempt_no'],
        'status'=>$attempt['status'],
        'entry_url'=>$attempt['entry_url'],
        'expires_at'=>$attempt['expires_at'],
        'token'=>$token,
      ]]);
      break;
    }

    case 'renew_attempt': {
      // input: token (string)
      $token = trim((string)($body['token'] ?? ''));
      if ($token === '') json_out(['ok'=>false,'error'=>'token_required'], 400);

      $sess = get_session_by_token($pdo, $token);
      if (!$sess) json_out(['ok'=>false,'error'=>'session_not_found'], 404);

      $sid   = (string)$sess['session_id'];
      $nowTs = time();
      $expOk = empty($sess['expires_at']) ? true : (strtotime($sess['expires_at']) > $nowTs);
      if (!$expOk || strtolower((string)$sess['status'])==='expired') {
        json_out(['ok'=>false,'error'=>'session_expired_need_new_token'], 409);
      }

      $pdo->beginTransaction();
      close_active_attempts($pdo, $sid, 'expired');

      // TODO: จุดต่อ DLT จริง (entry_url ใหม่)
      $attempt = create_new_attempt($pdo, $sid, 300, null);
      $pdo->commit();

      json_out(['ok'=>true,'data'=>[
        'session_id'=>$sid,
        'attempt_no'=>$attempt['attempt_no'],
        'status'=>$attempt['status'],
        'entry_url'=>$attempt['entry_url'],
        'expires_at'=>$attempt['expires_at'],
        'token'=>$token,
      ]]);
      break;
    }

    case 'renew_session': {
      // input: order_id (int)
      $oid = (int)($body['order_id'] ?? 0);
      if ($oid <= 0) json_out(['ok'=>false,'error'=>'order_id_required'], 400);

      $pdo->beginTransaction();
      $prev = find_latest_session($pdo, $oid);
      if ($prev) {
        if (strtolower((string)$prev['status']) !== 'success') {
          expire_session($pdo, (int)$prev['id']);
        }
      }
      $new = create_new_session($pdo, $oid, 48);
      $pdo->commit();

      json_out(['ok'=>true,'data'=>[
        'order_id'=>$oid,
        'token'=>$new['token'],
        'session_id'=>$new['session_id'],
        'verify_url'=>'/verify/'.$new['token'],
        'status'=>$new['status'],
        'expires_at'=>$new['expires_at'],
      ]]);
      break;
    }

    default:
      json_out(['ok'=>false,'error'=>'unknown_action'], 400);
  }
} catch (Throwable $e) {
  error_log('[verify_api] '.$e->getMessage());
  if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
  json_out(['ok'=>false,'error'=>'server_error','detail'=>$e->getMessage()], 500);
}
