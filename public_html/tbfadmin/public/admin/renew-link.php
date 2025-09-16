<?php
// /tbfadmin/public/admin/renew-link.php
declare(strict_types=1);

/*
 * Safe/Debug-friendly admin tool for renewing/extend verify link TTL
 * - ไม่โชว์รายละเอียด error บนหน้า (กัน 500)
 * - log ลง /tbfadmin/var/php_errors.log
 * - ?diag=1 มีหน้า diagnostics เบื้องต้น
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
$varDir = dirname(__DIR__, 2) . '/var';
if (!is_dir($varDir)) { @mkdir($varDir, 0775, true); }
ini_set('log_errors', '1');
ini_set('error_log', $varDir . '/php_errors.log');

function log_dbg(string $msg, array $ctx = []): void {
  $line = '[renew-link] ' . $msg;
  if ($ctx) $line .= ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  error_log($line);
}

// ---- Bootstrap (ห่อ try/catch กัน fatal) ----
$bootOk = true;
try {
  require dirname(__DIR__, 2) . '/src/Bootstrap.php';
  require_once dirname(__DIR__, 2) . '/src/Database.php';
  require_once dirname(__DIR__, 2) . '/src/Security.php';
} catch (Throwable $e) {
  $bootOk = false;
  log_dbg('bootstrap_failed', ['err'=>$e->getMessage()]);
}

// ---- Session (กันกรณี config ไม่มี) ----
$sn = $GLOBALS['config']['app']['admin_session_name'] ?? 'bb_admin';
if (!is_string($sn) || $sn === '') { $sn = 'bb_admin'; }
@session_name($sn);
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

// ---- Gate: ต้องล็อกอินก่อน ----
if (empty($_SESSION['admin_logged_in'])) {
  // อย่าปล่อย 500; redirect ไป login ปกติ
  header("Location: login.php");
  exit;
}

// ---- CSRF (fallback ถ้า Security ไม่มี) ----
$csrf = 'NA';
try {
  if (class_exists('Security')) {
    $csrf = Security::csrfToken();
  } else {
    $csrf = bin2hex(random_bytes(16)); // fallback เท่านั้น (ไม่ใช้จริงฝั่ง API)
  }
} catch (Throwable $e) {
  $csrf = bin2hex(random_bytes(16));
  log_dbg('csrf_gen_failed', ['err'=>$e->getMessage()]);
}

// ---- Diagnostics page (optional) ----
if (isset($_GET['diag'])) {
  $paths = [
    'var_dir'    => $varDir,
    'bootstrap'  => dirname(__DIR__, 2) . '/src/Bootstrap.php',
    'database'   => dirname(__DIR__, 2) . '/src/Database.php',
    'security'   => dirname(__DIR__, 2) . '/src/Security.php',
    'api_target' => dirname(__DIR__) . '/api/orders-renew.php',
  ];
  $checks = [
    'boot_included'  => $bootOk,
    'pdo_mysql'      => extension_loaded('pdo_mysql'),
    'session_id'     => session_id(),
    'admin_user'     => $_SESSION['admin_username'] ?? null,
    'api_exists'     => file_exists($paths['api_target']),
    'api_readable'   => is_readable($paths['api_target']),
  ];
  header('Content-Type: text/plain; charset=utf-8');
  echo "RENEW-LINK DIAG\n";
  echo "---------------\n";
  echo "PHP: " . PHP_VERSION . "\n";
  echo "Boot OK: " . ($bootOk?'yes':'no') . "\n";
  echo "pdo_mysql: " . ($checks['pdo_mysql']?'yes':'no') . "\n";
  echo "Session: " . ($checks['session_id'] ?: '-') . "\n";
  echo "Admin: " . ($checks['admin_user'] ?: '-') . "\n";
  echo "API exists: " . ($checks['api_exists']?'yes':'no') . "\n";
  echo "API readable: " . ($checks['api_readable']?'yes':'no') . "\n";
  echo "\nPaths:\n" . json_encode($paths, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n";
  exit;
}

// ---- Prefill form ----
$prefillOrderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$prefillMinutes = isset($_GET['minutes']) ? max(1, min(30, (int)$_GET['minutes'])) : 5;
$adminUser      = $_SESSION['admin_username'] ?? '-';

?><!doctype html>
<html lang="th" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <title>Renew/Extend Verify Link</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; }
    .small-dim { color:#6c757d; font-size:.9rem; }
    .result pre { background:#f8f9fa; padding:.75rem; border-radius:.5rem; }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom">
  <div class="container-fluid">
    <span class="navbar-brand">tbfadmin</span>
    <div class="ms-auto small-dim">ผู้ใช้: <?= htmlspecialchars($adminUser) ?></div>
  </div>
</nav>

<div class="container py-4">
  <h1 class="h4 mb-3">ต่ออายุลิงก์ยืนยันตัวตน (Renew / Extend TTL)</h1>

  <?php if (!$bootOk): ?>
    <div class="alert alert-danger">
      ระบบยังไม่พร้อม (Bootstrap ล้มเหลว) — ได้บันทึกลง log แล้ว
      <div class="small-dim mt-2">ลองเปิด <span class="mono">renew-link.php?diag=1</span> เพื่อเช็คไฟล์ที่ขาด/สิทธิ์</div>
    </div>
  <?php endif; ?>

  <div class="alert alert-info">
    เครื่องมือนี้เรียก API <span class="mono">/tbfadmin/public/api/orders-renew.php</span> ให้คุณ โดยไม่ต้องใช้ <span class="mono">curl</span>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <form id="f" class="row g-3">
        <input type="hidden" name="csrf" id="csrf" value="<?= htmlspecialchars($csrf) ?>">

        <div class="col-md-4">
          <label class="form-label">Order ID</label>
          <input type="number" class="form-control" name="order_id" id="order_id" value="<?= (int)$prefillOrderId ?>" required>
        </div>

        <div class="col-md-4">
          <label class="form-label">Minutes (1–30)</label>
          <input type="number" class="form-control" name="minutes" id="minutes" min="1" max="30" value="<?= (int)$prefillMinutes ?>" required>
        </div>

        <div class="col-md-4">
          <label class="form-label">โหมด</label>
          <select class="form-select" name="mode" id="mode">
            <option value="">Auto (แนะนำ)</option>
            <option value="extend">Extend (ใช้ token เดิมถ้ายังไม่หมดอายุ)</option>
            <option value="renew">Renew (ออก token ใหม่)</option>
          </select>
        </div>

        <div class="col-12 d-flex gap-2">
          <button type="submit" class="btn btn-primary" <?= $bootOk ? '' : 'disabled' ?>>ส่งคำขอ</button>
          <a class="btn btn-outline-secondary" href="?diag=1" target="_blank">เปิด Diagnostics</a>
        </div>
      </form>

      <hr>

      <div id="msg" class="alert d-none" role="alert"></div>

      <div class="result d-none" id="resultBox">
        <h6 class="mb-2">ผลลัพธ์</h6>
        <div class="mb-2">
          <div><strong>Status:</strong> <span id="r_status">—</span></div>
          <div><strong>Action:</strong> <span id="r_action">—</span></div>
          <div><strong>Expires at:</strong> <span id="r_exp">—</span></div>
          <div><strong>TTL:</strong> <span id="r_ttl">—</span> วินาที</div>
          <div><strong>Verify URL:</strong> <a id="r_url" href="#" target="_blank">—</a></div>
        </div>
        <details>
          <summary>Raw JSON</summary>
          <pre id="rawJson"></pre>
        </details>
      </div>
    </div>
  </div>

  <div class="small-dim mt-3">
    TIP: เปิดหน้า Diagnostics ถ้าหน้าฟอร์มยังมีปัญหา
  </div>
</div>

<script>
(function(){
  const form   = document.getElementById('f');
  const msg    = document.getElementById('msg');
  const box    = document.getElementById('resultBox');

  const rStatus= document.getElementById('r_status');
  const rAction= document.getElementById('r_action');
  const rExp   = document.getElementById('r_exp');
  const rTTL   = document.getElementById('r_ttl');
  const rURL   = document.getElementById('r_url');
  const raw    = document.getElementById('rawJson');

  function showMsg(kind, text) {
    msg.className = 'alert alert-' + kind;
    msg.textContent = text;
    msg.classList.remove('d-none');
  }
  function hideMsg(){ msg.classList.add('d-none'); }
  function showResult(obj) {
    rStatus.textContent = obj.status ?? '—';
    rAction.textContent = obj.action ?? '—';
    rExp.textContent    = obj.expires_at ?? '—';
    rTTL.textContent    = (obj.ttl_seconds ?? 0);
    rURL.textContent    = obj.verify_url ?? '—';
    rURL.href           = obj.verify_url ?? '#';
    raw.textContent     = JSON.stringify(obj, null, 2);
    box.classList.remove('d-none');
  }
  function clamp(val, min, max){ return Math.max(min, Math.min(max, val)); }

  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideMsg();
    box.classList.add('d-none');

    const orderId = parseInt(document.getElementById('order_id').value || '0', 10);
    let minutes   = parseInt(document.getElementById('minutes').value || '5', 10);
    minutes = clamp(minutes, 1, 30);
    const mode    = document.getElementById('mode').value || '';
    const csrf    = document.getElementById('csrf').value || '';

    if (!orderId) { showMsg('warning', 'กรุณาระบุ Order ID'); return; }

    const fd = new FormData();
    fd.append('order_id', String(orderId));
    fd.append('minutes', String(minutes));
    if (mode) fd.append('mode', mode);
    fd.append('csrf', csrf);

    try {
      const res = await fetch('../api/orders-renew.php', {
        method: 'POST',
        body: fd,
        headers: { 'Accept': 'application/json' },
        cache: 'no-store',
      });
      const data = await res.json().catch(()=>null);
      if (!res.ok || !data || data.ok !== true) {
        const msg = data && data.error ? (data.error + (data.error_code? ' ['+data.error_code+']':'') ) : 'ไม่สามารถต่ออายุได้';
        showMsg('danger', 'เกิดข้อผิดพลาด: ' + msg);
        return;
      }
      showMsg('success', 'สำเร็จ');
      showResult(data);
    } catch (err) {
      showMsg('danger', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
    }
  });
})();
</script>
</body>
</html>
