<?php
declare(strict_types=1);

/**
 * GET /api/v1/verify-link.php?token=XXXX
 * Response (success): { ok:true, entryUrl, status, expires_at }
 * Response (error):   { ok:false, error }
 */

require __DIR__ . '/../../src/Bootstrap.php';
require_once __DIR__ . '/../../src/Database.php';
if (file_exists(__DIR__ . '/../../src/Audit.php')) {
  require_once __DIR__ . '/../../src/Audit.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
header('Pragma: no-cache');

// อนุญาตเฉพาะ GET
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
  http_response_code(405);
  echo json_encode(['ok'=>false, 'error'=>'method_not_allowed']);
  exit;
}

// อ่าน token
$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
  echo json_encode(['ok'=>false, 'error'=>'missing_token']);
  exit;
}
// validate รูปแบบ token (ยอม a-zA-Z0-9_- ความยาว 16-256)
if (!preg_match('/^[A-Za-z0-9\-_]{16,256}$/', $token)) {
  echo json_encode(['ok'=>false, 'error'=>'invalid_token_format']);
  exit;
}

$singleUse = (bool)($GLOBALS['config']['verify']['single_use'] ?? true);
$entryBase = dlt_entry_base();

try {
  $db  = new Database($GLOBALS['config']['db']);
  $pdo = $db->pdo();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // หา verify session ตาม token
  $stmt = $pdo->prepare("SELECT id, order_id, sid, share_token, status, expires_at, created_at, used_at
                         FROM verify_sessions
                         WHERE share_token = ?
                         LIMIT 1");
  $stmt->execute([$token]);
  $vs = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$vs) {
    echo json_encode(['ok'=>false, 'error'=>'not_found']);
    exit;
  }

  // เช็คหมดอายุ
  $now = new DateTimeImmutable('now');
  $exp = new DateTimeImmutable($vs['expires_at']);
  $isExpired = $now > $exp;

  // ถ้าหมดอายุ → mark expired (ครั้งแรก) แล้วตอบ error
  if ($isExpired) {
    if ($vs['status'] !== 'expired') {
      $upd = $pdo->prepare("UPDATE verify_sessions SET status='expired' WHERE id=? AND status<>'expired'");
      $upd->execute([(int)$vs['id']]);
    }
    // Audit
    if (class_exists('Audit')) {
      Audit::log('verify_link.fetch', [
        'verify_session_id'=>(int)$vs['id'],
        'result'=>'expired',
      ], 'verify_session', (int)$vs['id']);
    }
    echo json_encode(['ok'=>false, 'error'=>'expired']);
    exit;
  }

  // ถ้าถูกยกเลิก
  if ($vs['status'] === 'cancelled') {
    if (class_exists('Audit')) {
      Audit::log('verify_link.fetch', [
        'verify_session_id'=>(int)$vs['id'],
        'result'=>'cancelled',
      ], 'verify_session', (int)$vs['id']);
    }
    echo json_encode(['ok'=>false, 'error'=>'cancelled']);
    exit;
  }

  // โหมด single-use: ถ้าเคยใช้แล้วให้ปฏิเสธ
  if ($singleUse && $vs['status'] === 'used') {
    if (class_exists('Audit')) {
      Audit::log('verify_link.fetch', [
        'verify_session_id'=>(int)$vs['id'],
        'result'=>'used_already',
      ], 'verify_session', (int)$vs['id']);
    }
    echo json_encode(['ok'=>false, 'error'=>'used']);
    exit;
  }

  $sid = (string)$vs['sid'];
  if ($sid === '') {
    if (class_exists('Audit')) {
      Audit::log('verify_link.fetch', [
        'verify_session_id'=>(int)$vs['id'],
        'result'=>'sid_missing',
      ], 'verify_session', (int)$vs['id']);
    }
    echo json_encode(['ok'=>false, 'error'=>'sid_missing']);
    exit;
  }

  $entryUrl = $entryBase . rawurlencode($sid);

  // อัปเดตสถานะตาม policy
  if ($singleUse) {
    // ใช้ครั้งเดียว → mark used + used_at
    $upd = $pdo->prepare("UPDATE verify_sessions SET status='used', used_at=NOW() WHERE id=? AND status='waiting'");
    $upd->execute([(int)$vs['id']]);
    $statusReturn = 'used';
  } else {
    // ใช้ได้หลายครั้งจนหมดอายุ → ถ้ายังไม่เคยใช้ ให้บันทึก used_at ครั้งแรก
    if (empty($vs['used_at'])) {
      $upd = $pdo->prepare("UPDATE verify_sessions SET used_at=NOW() WHERE id=? AND used_at IS NULL");
      $upd->execute([(int)$vs['id']]);
    }
    $statusReturn = 'waiting';
  }

  // Audit
  if (class_exists('Audit')) {
    Audit::log('verify_link.fetch', [
      'verify_session_id'=>(int)$vs['id'],
      'result'=>'ok',
      'single_use'=>$singleUse,
      'status_return'=>$statusReturn,
    ], 'verify_session', (int)$vs['id']);
  }

  echo json_encode([
    'ok'         => true,
    'entryUrl'   => $entryUrl,
    'status'     => $statusReturn,
    'expires_at' => $exp->format(DateTime::ATOM),
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log('[verify-link] ' . $e->getMessage());
  echo json_encode(['ok'=>false, 'error'=>'server_error']);
  exit;
}
