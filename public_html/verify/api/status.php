<?php
// /verify/api/status.php  (HTML status viewer + JSON status)
declare(strict_types=1);

/* ---- Logging ---- */
$varDir = __DIR__ . '/../../tbfadmin/var';
if (!is_dir($varDir)) { @mkdir($varDir, 0775, true); }
@ini_set('log_errors', '1');
@ini_set('error_log', $varDir . '/php-error.log');

$bootstrap = __DIR__ . '/../../tbfadmin/src/Bootstrap.php';
$dbfile    = __DIR__ . '/../../tbfadmin/src/Database.php';
if (is_file($bootstrap)) require $bootstrap;
if (is_file($dbfile))    require_once $dbfile;

/* ---- Helpers ---- */
function jexit(array $d, int $code=200){
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
  }
  http_response_code($code);
  echo json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
function get_pdo(): PDO {
  if (class_exists('Database')) {
    if (method_exists('Database','getConnection')) { $p = Database::getConnection(); if ($p instanceof PDO) return $p; }
    if (method_exists('Database','getPdo'))        { $p = Database::getPdo();        if ($p instanceof PDO) return $p; }
    try {
      $d = new Database();
      foreach (['getConnection','getPdo','pdo','connection'] as $m){
        if (method_exists($d,$m)){ $p=$d->$m(); if ($p instanceof PDO) return $p; }
      }
    } catch(Throwable $e){}
  }
  $cfg = $GLOBALS['config']['db'] ?? null;
  if (is_array($cfg) && !empty($cfg['dsn'])) {
    return new PDO(
      $cfg['dsn'],
      (string)($cfg['username'] ?? $cfg['user'] ?? ''),
      (string)($cfg['password'] ?? $cfg['pass'] ?? ''),
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
  }
  throw new RuntimeException('DB connection unavailable');
}

/* ------------------ HTML view (no DB here) ------------------ */
if (isset($_GET['view']) && $_GET['view'] === 'html') {
  if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
  $token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
  ?>
  <!doctype html>
  <html lang="th">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>ตรวจสถานะการยืนยัน</title>
    <style>
      :root { --bg:#0b1020; --card:#121a2e; --muted:#7f8ba6; --ok:#16a34a; --warn:#f59e0b; --err:#ef4444; --pending:#3b82f6; }
      *{box-sizing:border-box} body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Helvetica,Arial,sans-serif;background:linear-gradient(180deg,#0b1020 0%,#0e1730 100%);color:#e6edf7}
      .wrap{max-width:1140px;margin:24px auto;padding:16px}
      h1{margin:0 0 6px}
      .topbar{display:flex;gap:8px;align-items:center;margin:8px 0 16px}
      .topbar .sp{flex:1}
      .btn{border:1px solid #33426a;background:#18223d;color:#e6edf7;border-radius:10px;padding:8px 12px;cursor:pointer}
      .btn:disabled{opacity:.45;cursor:not-allowed}
      .danger{background:#7f1d1d;border-color:#7f1d1d}
      .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
      @media(max-width:900px){.grid{grid-template-columns:1fr}}
      .card{background:var(--card);border:1px solid #223055;border-radius:16px;padding:16px}
      .row{display:flex;justify-content:space-between;gap:12px;margin:6px 0}
      .k{color:var(--muted)} .v{font-weight:600}
      .badge{display:inline-block;padding:4px 10px;border-radius:999px;border:1px solid #33426a;background:#111a31}
      .b-pending{border-color:#3b82f6;color:#cfe3ff}
      .b-confirmed,.b-success{border-color:#16a34a;color:#c8facc}
      .b-fail{border-color:#ef4444;color:#ffd4d4}
      .b-expired{border-color:#f59e0b;color:#ffe5b3}
      .muted{color:var(--muted)}
      .json{white-space:pre-wrap;background:#0b1328;border:1px solid #223055;border-radius:12px;padding:12px;max-height:300px;overflow:auto}
      .hint{margin-top:6px;color:#9aa7c6;font-size:13px}
      .alert{color:#ffb4b4;margin:4px 0 10px;min-height:20px}
      .cluster{display:flex;gap:8px;flex-wrap:wrap}
    </style>
  </head>
  <body>
    <div class="wrap">
      <h1>ตรวจสถานะการยืนยัน</h1>
      <div id="alert" class="alert"></div>

      <div class="topbar">
        <div class="cluster">
          <button id="btnStart" class="btn">เริ่มยืนยัน</button>
          <button id="btnRenew" class="btn">ออก Attempt ใหม่</button>
          <button id="btnEntry" class="btn" disabled>เปิด Entry URL</button>
          <button id="btnRefresh" class="btn">รีเฟรช</button>
          <button id="btnAuto" class="btn">เริ่มออโต้โพล</button>
          <button id="btnUpload" class="btn">เริ่มอัปโหลด</button>
        </div>
        <div class="sp"></div>
      </div>

      <div class="grid">
        <div class="card">
          <div class="row"><div class="k">Token</div><div class="v" id="vToken">-</div></div>
          <div class="cluster" style="margin-top:8px">
            <button id="btnCopyToken" class="btn">คัดลอก Token</button>
            <button id="btnCopyVerify" class="btn">คัดลอกลิงก์ /verify/{token}</button>
          </div>
          <div class="hint">ลิงก์ลูกค้า: <span id="vVerifyLink" class="muted">-</span></div>
        </div>

        <div class="card">
          <div class="row"><div class="k">Session</div><div class="v"><span id="vSession" class="badge b-pending">-</span></div></div>
          <div class="row"><div class="k">หมดอายุรอบงาน</div><div class="v" id="vSessExp">-</div></div>
          <div class="row"><div class="k">นับถอยหลัง</div><div class="v" id="vSessTTL">-</div></div>
        </div>

        <div class="card">
          <div class="row"><div class="k">SID</div><div class="v" id="vSid">-</div></div>
          <div class="row"><div class="k">สถานะ Attempt</div><div class="v"><span id="vAStatus" class="badge">-</span></div></div>
          <div class="row"><div class="k">หมดอายุ Attempt</div><div class="v" id="vAExp">-</div></div>
          <div class="row"><div class="k">นับถอยหลัง</div><div class="v" id="vATTL">-</div></div>
          <div class="cluster" style="margin-top:8px">
            <button id="btnOpenDLT" class="btn" disabled>เปิดตรวจสิทธิ์ DLT</button>
            <button id="btnCopyDLT" class="btn" disabled>คัดลอกลิงก์ DLT</button>
          </div>
          <div class="hint">ลิงก์ DLT: <span id="vDLT" class="muted">-</span></div>
        </div>

        <div class="card">
          <div class="row"><div class="k">ข้อมูลดิบ (JSON)</div><div class="v muted">อ่านอย่างเดียว</div></div>
          <div id="json" class="json">-</div>
        </div>
      </div>

      <div class="hint" style="margin-top:12px">โพลทุก 1.5 วินาทีเมื่อเปิดโหมดออโต้</div>
    </div>

    <script>
      const token = new URLSearchParams(location.search).get('token') || '';
      const statusURL = location.pathname + '?token=' + encodeURIComponent(token);
      const VERIFY_API = '/tbfadmin/public/api/v1/verify.php';

      const el = id => document.getElementById(id);
      const alertBox = el('alert');

      const vToken = el('vToken'), vVerify = el('vVerifyLink'), vSession = el('vSession'),
            vSessExp = el('vSessExp'), vSessTTL = el('vSessTTL'),
            vSid = el('vSid'), vAStatus = el('vAStatus'), vAExp = el('vAExp'), vATTL = el('vATTL'),
            vDLT = el('vDLT'), jsonBox = el('json');

      const btnStart = el('btnStart'), btnRenew = el('btnRenew'), btnEntry = el('btnEntry'),
            btnRefresh = el('btnRefresh'), btnAuto = el('btnAuto'),
            btnCopyToken = el('btnCopyToken'), btnCopyVerify = el('btnCopyVerify'),
            btnOpenDLT = el('btnOpenDLT'), btnCopyDLT = el('btnCopyDLT'),
            btnUpload = el('btnUpload');

      let autoTimer = null;
      let sessEndAt = null, attEndAt = null;
      let lastEntryUrl = ''; // เก็บ entry_url หลัง start/renew รอบล่าสุด

      async function postJSON(url, body){
        const res = await fetch(url, {
          method:'POST',
          headers:{'Content-Type':'application/json','Accept':'application/json'},
          credentials:'same-origin',
          body: JSON.stringify(body)
        });
        if (!res.ok) {
          let msg = 'HTTP_'+res.status;
          try{ const j=await res.json(); if(j && j.error) msg = j.error; }catch(e){}
          throw new Error(msg);
        }
        return res.json();
      }

      function badgeClass(status){
        switch((status||'').toLowerCase()){
          case 'confirmed': return 'badge b-confirmed';
          case 'success': return 'badge b-success';
          case 'fail': return 'badge b-fail';
          case 'expired': return 'badge b-expired';
          default: return 'badge b-pending';
        }
      }

      function fmtCountdown(ms){
        if (ms == null) return '-';
        if (ms <= 0) return 'หมดอายุ';
        const s = Math.floor(ms/1000);
        const m = Math.floor(s/60), sec = s%60;
        const h = Math.floor(m/60), min = m%60;
        return (h? (h+':'):'') + String(min).padStart(2,'0') + ':' + String(sec).padStart(2,'0');
      }

      function updateCountdown(){
        const now = Date.now();
        if (sessEndAt) vSessTTL.textContent = fmtCountdown(sessEndAt - now);
        if (attEndAt)  vATTL.textContent  = fmtCountdown(attEndAt - now);
      }
      setInterval(updateCountdown, 500);

      async function load(){
        alertBox.textContent = '';
        try{
          const res = await fetch(statusURL, { headers: { 'Accept':'application/json' }});
          const data = await res.json();
          render(data);
        }catch(e){
          alertBox.textContent = 'โหลดข้อมูลไม่สำเร็จ: ' + e;
        }
      }

      function render(d){
        jsonBox.textContent = JSON.stringify(d, null, 2);

        vToken.textContent = d.token || '-';
        const verifyLink = location.origin + '/verify/' + (d.token || '');
        vVerify.textContent = verifyLink;

        vSession.textContent = (d.session_status || '-');
        vSession.className = badgeClass(d.session_status);
        vSessExp.textContent = d.session_expires_at || '-';
        sessEndAt = d.session_expires_at ? Date.parse(d.session_expires_at.replace(' ','T')+'+07:00') : null;

        const a = d.attempt || {};
        vSid.textContent = a.sid || '-';
        vAStatus.textContent = (a.status || '-');
        vAStatus.className = badgeClass(a.status);
        vAExp.textContent = a.attempt_expires_at || '-';
        attEndAt = a.attempt_expires_at ? Date.parse(a.attempt_expires_at.replace(' ','T')+'+07:00') : null;

        const dlt = a.sid ? ('https://reserve.dlt.go.th/reserve/v2/?menu=resv_m&state=' + encodeURIComponent(a.sid)) : '';
        vDLT.textContent = dlt || '-';
        btnOpenDLT.disabled = !a.sid || ['fail','expired'].includes(String(a.status||'').toLowerCase());
        btnCopyDLT.disabled = btnOpenDLT.disabled;

        // ปุ่ม Entry URL จะ enable เฉพาะเมื่อเพิ่ง start/renew แล้วได้ url เก็บไว้
        btnEntry.disabled = !lastEntryUrl;
      }

      // ====== Button handlers ======
      btnRefresh.onclick = load;

      btnAuto.onclick = () => {
        if (autoTimer){
          clearInterval(autoTimer); autoTimer = null;
          btnAuto.textContent = 'เริ่มออโต้โพล';
        } else {
          autoTimer = setInterval(load, 1500);
          btnAuto.textContent = 'หยุดออโต้โพล';
        }
      };

      btnCopyToken.onclick = () => navigator.clipboard.writeText(vToken.textContent||'');
      btnCopyVerify.onclick = () => navigator.clipboard.writeText(vVerify.textContent||'');

      btnOpenDLT.onclick = () => { if (vDLT.textContent && vDLT.textContent !== '-') window.open(vDLT.textContent,'_blank'); };
      btnCopyDLT.onclick = () => navigator.clipboard.writeText(vDLT.textContent||'');

      btnEntry.onclick = () => {
        if (!lastEntryUrl){ alert('ยังไม่มี Entry URL (กรุณาเริ่มยืนยันหรือออก Attempt ใหม่ก่อน)'); return; }
        const url = lastEntryUrl.startsWith('/') ? (location.origin + lastEntryUrl) : lastEntryUrl;
        window.open(url, '_blank', 'noopener');
      };

      btnStart.onclick = async () => {
        if (!token){ alert('ขาด token'); return; }
        try{
          const r = await postJSON(VERIFY_API, { action:'start_attempt', token });
          if (!r.ok) throw new Error(r.error || 'start_failed');
          lastEntryUrl = r.data?.entry_url || '';
          btnEntry.disabled = !lastEntryUrl;
          await load();
        }catch(e){
          alertBox.textContent = 'เริ่มยืนยันไม่สำเร็จ: ' + e.message;
          setTimeout(()=> alertBox.textContent='', 4000);
          alert('เริ่มยืนยันไม่สำเร็จ: ' + e.message);
        }
      };

      btnRenew.onclick = async () => {
        if (!token){ alert('ขาด token'); return; }
        try{
          const r = await postJSON(VERIFY_API, { action:'renew_attempt', token });
          if (!r.ok) throw new Error(r.error || 'renew_failed');
          lastEntryUrl = r.data?.entry_url || '';
          btnEntry.disabled = !lastEntryUrl;
          await load();
        }catch(e){
          alertBox.textContent = 'ต่ออายุไม่สำเร็จ: ' + e.message;
          setTimeout(()=> alertBox.textContent='', 4000);
          alert('ต่ออายุไม่สำเร็จ: ' + e.message);
        }
      };

      btnUpload.onclick = () => {
        // โชว์ปุ่มไว้ก่อนสำหรับ flow อัปโหลดภายหลัง
        alert('Coming soon: อัปโหลดเอกสาร/รูปผ่านโฟลว์เดียวกัน');
      };

      if (!token){
        alertBox.textContent = 'ขาดพารามิเตอร์ token';
      } else {
        load();
      }
    </script>
  </body>
  </html>
  <?php
  exit;
}

/* ------------------ JSON status (ใช้โดยหน้า HTML ด้านบน) ------------------ */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : null;
if (!$token) jexit(['ok'=>false,'error'=>'missing_token'], 400);

try {
  $pdo = get_pdo();

  // 1) session by token
  $st = $pdo->prepare("SELECT id, session_id, token, status, expires_at, created_at, updated_at FROM verify_sessions WHERE token=:t LIMIT 1");
  $st->execute([':t'=>$token]);
  $sess = $st->fetch();
  if (!$sess) jexit(['ok'=>false,'error'=>'session_not_found','token'=>$token], 404);

  $out = [
    'ok' => true,
    'token' => $token,
    'session_status' => $sess['status'] ?? null,
    'session_expires_at' => $sess['expires_at'] ?? null,
    'attempt' => [
      'sid' => null,
      'status' => null,
      'attempt_expires_at' => null,
      'ttl_seconds' => null,
      'age_seconds' => null,
    ],
    'can_open_dlt' => false,
  ];

  // 2) latest attempt by session_id
  $q = $pdo->prepare("
    SELECT session_id, attempt_no, status, expires_at, created_at, updated_at
    FROM verify_attempts
    WHERE session_id = :sid
    ORDER BY attempt_no DESC, updated_at DESC, created_at DESC
    LIMIT 1
  ");
  $q->execute([':sid'=>$sess['session_id']]);
  $a = $q->fetch();

  if ($a) {
    $sidVirtual = $a['session_id'] . '#' . str_pad((string)$a['attempt_no'], 3, '0', STR_PAD_LEFT);
    $out['attempt']['sid']   = $sidVirtual;
    $out['attempt']['status'] = $a['status'] ?? null;
    $out['attempt']['attempt_expires_at'] = $a['expires_at'] ?? null;

    $now = new DateTimeImmutable('now');
    if (!empty($a['expires_at'])) {
      $exp = new DateTimeImmutable($a['expires_at']);
      $ttl = $exp->getTimestamp() - $now->getTimestamp();
      $out['attempt']['ttl_seconds'] = max($ttl, 0);
    }
    $ref = $a['created_at'] ?? $a['updated_at'] ?? null;
    if ($ref) {
      $c = new DateTimeImmutable($ref);
      $out['attempt']['age_seconds'] = max($now->getTimestamp() - $c->getTimestamp(), 0);
    }

    $stt = strtolower((string)($out['attempt']['status'] ?? ''));
    $out['can_open_dlt'] = ($out['attempt']['ttl_seconds'] ?? 0) > 0 && !in_array($stt, ['fail','expired'], true);
  }

  jexit($out, 200);

} catch (Throwable $e) {
  error_log('[status.php] error: '.$e->getMessage());
  jexit(['ok'=>false,'error'=>'server_error'], 500);
}
