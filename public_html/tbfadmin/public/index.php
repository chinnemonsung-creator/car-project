<?php
// public_html/verify/index.php
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if (!is_dir(__DIR__ . '/../tbfadmin/var')) { @mkdir(__DIR__ . '/../tbfadmin/var', 0775, true); }
ini_set('error_log', __DIR__ . '/../tbfadmin/var/php_errors.log');

// ==== โหลด bootstrap/prod DB จากโปรเจกต์ tbfadmin ====
require __DIR__ . '/../tbfadmin/src/Bootstrap.php';
require __DIR__ . '/../tbfadmin/src/Database.php';
if (file_exists(__DIR__ . '/../tbfadmin/src/Audit.php')) { require_once __DIR__ . '/../tbfadmin/src/Audit.php'; }

$db  = new Database($GLOBALS['config']['db']);
$pdo = $db->pdo();

// ==== รับ token จาก /verify/<token> หรือ ?token= ====
$token = trim($_GET['token'] ?? '', " \t\n\r\0\x0B");
if ($token === '') {
  $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
  $parts = explode('/', $path);
  $token = $parts[count($parts)-1] ?? '';
}
if ($token === '') { http_response_code(400); echo 'Bad Request'; exit; }

// หมดอายุรายการที่ค้าง
$pdo->exec("UPDATE verify_sessions
            SET status='expired', updated_at=NOW()
            WHERE status='pending' AND expires_at IS NOT NULL AND expires_at <= NOW()");

// ดึงแถวของ token
$stmt = $pdo->prepare("SELECT id, order_id, session_id, status, expires_at, created_at
                       FROM verify_sessions
                       WHERE token = :t
                       LIMIT 1");
$stmt->execute(['t'=>$token]);
$row = $stmt->fetch();

if (!$row) {
  http_response_code(410);
  render_page('ไม่พบท็อคเค็นนี้ในระบบ', null, null, true, false, 'not_found');
  exit;
}

// เมื่อกด “ยืนยัน”
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $row['status']==='pending') {
  $u = $pdo->prepare("UPDATE verify_sessions
                      SET status='confirmed', updated_at=NOW()
                      WHERE id=:id AND status='pending'");
  $u->execute(['id'=>$row['id']]);

  if (class_exists('Audit')) {
    Audit::log('verify.confirm', ['order_id'=>(int)$row['order_id']], 'order', (int)$row['order_id']);
  }

  render_page('ยืนยันเรียบร้อย ขอบคุณค่ะ/ครับ', $row['session_id'], $row['expires_at'], false, true, 'confirmed');
  exit;
}

// ตรวจสถานะ/หมดอายุ
$status = $row['status']; // pending | confirmed | expired | error
if ($status !== 'pending') {
  $msg = $status==='confirmed' ? 'ลิงก์นี้ถูกยืนยันไปแล้ว' : 'ลิงก์นี้หมดอายุหรือใช้ไม่ได้';
  render_page($msg, $row['session_id'], $row['expires_at'], true, $status==='confirmed', $status);
  exit;
}
if (!empty($row['expires_at']) && strtotime($row['expires_at']) <= time()) {
  render_page('ลิงก์หมดอายุแล้ว', $row['session_id'], $row['expires_at'], true, false, 'expired');
  exit;
}

// แสดงปุ่มให้ยืนยัน (pending)
render_page(null, $row['session_id'], $row['expires_at'], false, false, 'pending');


// ===== view function (HTML + UI เก่าปรับใช้) =====
function render_page($message, $sessionId, $expiresAt, $disabled, $done=false, $status='pending'){
  $expiredAt = $expiresAt ? date('Y-m-d H:i', strtotime($expiresAt)) : '-';
  $ttlSeconds = $expiresAt ? max(0, strtotime($expiresAt) - time()) : 0;

  // แปลงสถานะเป็นข้อความย่อ
  $statusLabel = 'WAITING';
  if ($status === 'pending')   $statusLabel = 'WAITING';
  if ($status === 'confirmed') $statusLabel = 'SUCCESS';
  if ($status === 'expired')   $statusLabel = 'EXPIRED';
  if ($status === 'error')     $statusLabel = 'ERROR';
  if ($status === 'not_found') $statusLabel = 'NOT FOUND';

  // คำนวณ step สำหรับ progress (1 รอ | 2 ดำเนินการ/พร้อม | 3 เสร็จ)
  $step = 1;
  if (in_array($status, ['pending','error','expired'], true)) $step = 2;
  if ($done || $status==='confirmed') $step = 3;
  ?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ระบบจองทะเบียน – ยืนยันตัวตน</title>

  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@800&family=Mitr:wght@300;400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>

  <style>
    :root{ --lime:#d3ff00; --dark:#333333; --gray:#999999; --success:#3ddc84; --blue:#1f6fbf; --red:#ef2f3c; --bg:#0f2a54; }
    * { box-sizing: border-box; }
    body{font-family:'Mitr',sans-serif;margin:0;padding:0;background:#f2f6fb;color:#333;}
    .container{max-width:520px;margin:auto;padding:20px;}

    .status-text{font-size:22px;font-weight:bold;text-align:center;margin-bottom:10px;}
    .progress-bar-wrapper{background:var(--dark);height:80px;border-radius:40px;position:relative;padding:0 20px;display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
    .progress-fill{position:absolute;left:20px;top:50%;transform:translateY(-50%);height:38px;border-radius:19px;background:var(--lime);width:0%;transition:.4s;}
    .step-circle{width:56px;height:56px;border-radius:28px;background:var(--gray);display:flex;align-items:center;justify-content:center;z-index:2;color:#fff;transition:.4s;}
    .step-circle.active{background:var(--lime);color:#000;}

    .row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .pill { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; background:#f3f4f6; font-size:14px; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .muted{ color:#666; }
    .box{background:#ffffff;border-radius:12px;padding:16px;margin-bottom:16px;font-size:15px;line-height:1.6;}

    .btn { border:1px solid #e5e7eb; background:white; padding:10px 12px; border-radius:10px; cursor:pointer; }
    .btn:disabled{ opacity:.5; cursor:not-allowed; }

    .button-click{
      display:block;background-color:var(--success);color:white;
      font-family:'Mitr',sans-serif;padding:16px;font-size:18px;border-radius:12px;
      border:none;width:100%;cursor:pointer;margin-top:16px;
      transition:transform .15s ease, box-shadow .15s ease;
      position:relative; outline:none; font-weight:bold; text-align:center;
    }
    .button-click:active{transform:scale(.98);}
    .button-click.is-loading{ opacity:.9; pointer-events:none; }
    .button-click.is-loading .btn-text{ visibility:hidden; }
    .button-click.is-loading::after{
      content:""; position:absolute; inset:0; margin:auto;
      width:22px; height:22px; border:3px solid rgba(255,255,255,.6);
      border-top-color:#fff; border-radius:50%; animation:spin .8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    @keyframes pulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.03); } }
    @keyframes glow { 0%{ box-shadow:0 0 0 0 rgba(61,220,132,.55);} 70%{ box-shadow:0 0 0 18px rgba(61,220,132,0);} 100%{ box-shadow:0 0 0 0 rgba(61,220,132,0);} }
    @keyframes arrowBlink { 0%{opacity:0;transform:translate(-50%,-6px);} 50%{opacity:1;transform:translate(-50%,0);} 100%{opacity:0;transform:translate(-50%,-6px);} }
    .button-click.attention{ animation:pulse 1.1s ease-in-out infinite, glow 1.6s ease-out infinite; }
    .button-click.attention::before{
      content:"👇"; position:absolute; top:-28px; left:50%; transform:translateX(-50%);
      font-size:26px; line-height:1; animation:arrowBlink 1.2s ease-in-out infinite;
    }
    @media (prefers-reduced-motion: reduce) { .button-click.attention, .button-click.attention::before { animation: none; } }

    .toolbar { display:flex; gap:8px; flex-wrap:wrap; }
    .alert { padding:10px 12px; border-radius:10px; font-size:14px; }
    .alert-error{ background:#fff1f2; color:#b91c1c; border:1px solid #fecdd3; }
    .alert-info{ background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
    .hidden{ display:none !important; }
  </style>
</head>

<body>
<div class="container" aria-live="polite">
  <div id="statusText" class="status-text">ขั้นตอนถัดไป</div>

  <div class="progress-bar-wrapper" aria-label="ความคืบหน้า">
    <div class="progress-fill" id="progressFill"></div>
    <div class="step-circle"><i class="fa-solid fa-clock-rotate-left"></i></div>
    <div class="step-circle"><i class="fa-solid fa-id-badge"></i></div>
    <div class="step-circle"><i class="fa-solid fa-circle-check"></i></div>
  </div>

  <div class="row" style="margin-bottom:12px;">
    <div class="pill">สถานะ: <b id="statusPill"><?php echo htmlspecialchars($statusLabel) ?></b></div>
    <?php if ($sessionId): ?>
      <div class="pill">SESSION: <span id="sidPill" class="mono"><?php echo htmlspecialchars($sessionId) ?></span></div>
    <?php endif; ?>
    <?php if ($expiresAt): ?>
      <div id="ttlPill" class="pill">ลิงก์หมดอายุใน <span id="ttlSec" class="mono"><?php echo (int)$ttlSeconds ?></span>s</div>
    <?php endif; ?>
  </div>

  <div id="alert" class="alert <?php echo $message ? ($done ? 'alert-info':'alert-error') : 'hidden'; ?>" role="status">
    <?php echo $message ? htmlspecialchars($message) : '' ?>
  </div>

  <div class="box">
    📱 วิธีการยืนยันตัวตน<br>
    1️⃣ ตรวจสอบข้อมูล แล้วกดปุ่ม “ยืนยันตัวตน”<br>
    2️⃣ ระบบจะบันทึกว่าได้รับการยืนยันแล้ว<br>
    3️⃣ หากลิงก์หมดอายุ/ใช้งานไปแล้ว จะไม่สามารถยืนยันซ้ำได้
    <div class="muted" style="margin-top:8px;">
      ลิงก์หมดอายุ: <b><?php echo htmlspecialchars($expiredAt) ?></b>
    </div>
  </div>

  <?php if (!$done && !$message && !$disabled): ?>
    <form method="post" id="confirmForm">
      <button id="startButton" class="button-click attention" type="submit">
        <span class="btn-text">ยืนยันตัวตน</span>
      </button>
    </form>
  <?php else: ?>
    <div class="toolbar" style="margin-top:12px;">
      <button class="btn" disabled>ไม่สามารถยืนยันได้</button>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  // render progress
  var step = <?php echo (int)$step ?>;
  var fill = document.getElementById('progressFill');
  function setStep(s){
    if(s===1) fill.style.width="10%";
    if(s===2) fill.style.width="50%";
    if(s===3) fill.style.width="100%";
    document.querySelectorAll('.step-circle').forEach(function(el,idx){
      el.classList.toggle('active', s >= (idx+1));
    });
  }
  setStep(step);

  // countdown
  var remain = <?php echo (int)$ttlSeconds ?>;
  if (remain > 0){
    var pill = document.getElementById('ttlPill');
    var slot = document.getElementById('ttlSec');
    setInterval(function(){
      if (remain <= 0) return;
      remain -= 1;
      if (slot) slot.textContent = Math.max(0, remain);
    }, 1000);
  }

  // button loading
  var btn = document.getElementById('startButton');
  var form = document.getElementById('confirmForm');
  if (form && btn){
    form.addEventListener('submit', function(){
      btn.classList.add('is-loading');
    });
  }
})();
</script>
</body>
</html>
<?php
}
