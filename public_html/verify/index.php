<?php
// /verify/index.php
declare(strict_types=1);

// รับ token ได้ทั้งจาก query (?token=xxx) และจาก path (/verify/xxx) ที่ .htaccess rewrite มา
$token = (string)($_GET['token'] ?? '');
if ($token === '' && !empty($_SERVER['REQUEST_URI'])) {
  if (preg_match('#/verify/([A-Za-z0-9]{16,64})#', $_SERVER['REQUEST_URI'], $m)) {
    $token = $m[1];
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>Verify</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap CDN สำหรับหน้า verify -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; }
    .dim { color:#6c757d; }
    .btn.is-disabled, .btn[aria-disabled="true"] { pointer-events:none; opacity:.6; }
  </style>
</head>
<body class="bg-light">
  <div class="container py-4">
    <div class="row justify-content-center">
      <div class="col-lg-7">
        <div class="card shadow-sm">
          <div class="card-body">
            <h1 class="h4 mb-3">ยืนยันตัวตนเพื่อจองทะเบียน</h1>

            <?php if ($token === ''): ?>
              <div class="alert alert-danger">ไม่พบโทเคนในลิงก์ กรุณาตรวจสอบ URL</div>
            <?php else: ?>
              <div class="mb-2 small dim">Token: <span class="mono"><?= htmlspecialchars($token) ?></span></div>

              <div id="alertBox" class="alert alert-info" role="alert" style="display:none;"></div>

              <dl class="row mb-0">
                <dt class="col-sm-4">สถานะ (attempt)</dt>
                <dd class="col-sm-8"><span id="st">กำลังโหลด…</span></dd>

                <dt class="col-sm-4">หมดอายุ (attempt)</dt>
                <dd class="col-sm-8">
                  <span id="exp">—</span>
                  <span class="dim">(เหลือ <span id="ttl">—</span>)</span>
                </dd>

                <dt class="col-sm-4">SID</dt>
                <dd class="col-sm-8"><span id="sid" class="mono">—</span></dd>

                <dt class="col-sm-4">สถานะ session</dt>
                <dd class="col-sm-8">
                  <span id="sessSt">—</span>
                  <span class="dim"> · หมดอายุ: <span id="sessExp">—</span></span>
                </dd>
              </dl>

              <hr>

              <div class="d-grid d-sm-flex gap-2">
                <a id="btnStart" class="btn btn-primary is-disabled" href="#" aria-disabled="true" rel="noopener">
                  <span class="label">เริ่มยืนยันตัวตน</span>
                  <span class="spinner-border spinner-border-sm ms-2 d-none" role="status" aria-hidden="true"></span>
                </a>
                <button id="btnRefresh" class="btn btn-outline-secondary">
                  รีเฟรชสถานะ
                </button>
              </div>

              <div class="mt-3 small dim">
                หน้านี้จะอัปเดตสถานะอัตโนมัติทุก ~2 วินาที
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="text-center mt-3 small dim">
          v0.3 • verify flow (status poll + start handoff + DLT state)
        </div>
      </div>
    </div>
  </div>

  <?php if ($token !== ''): ?>
  <script>
  (function(){
    const token = <?= json_encode($token, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

    // Elements
    const elSt     = document.getElementById('st');
    const elExp    = document.getElementById('exp');
    const elTtl    = document.getElementById('ttl');
    const elSid    = document.getElementById('sid');
    const elSessSt = document.getElementById('sessSt');
    const elSessExp= document.getElementById('sessExp');
    const elBox    = document.getElementById('alertBox');
    const btnStart   = document.getElementById('btnStart');
    const btnRefresh = document.getElementById('btnRefresh');
    const startSpin  = btnStart?.querySelector('.spinner-border');

    // API endpoints (ภายใต้ /verify)
    const statusUrl = 'api/status.php?token=' + encodeURIComponent(token);
    const startUrl  = 'api/start.php';

    // ถ้าอยากให้เปิด DLT เองทันทีเมื่อ can_open_dlt=true ก็ใช้ BASE นี้
    const DLT_BASE  = 'https://reserve.dlt.go.th/reserve/v2/?menu=resv_m&state=';

    let lastTTL = 0, ttlTimer = null, poller = null;

    /* ---------- helpers ---------- */
    function setAlert(kind, text) {
      elBox.className = 'alert alert-' + kind;
      elBox.textContent = text;
      elBox.style.display = 'block';
    }
    function hideAlert() { elBox.style.display = 'none'; }

    function formatTTL(sec) {
      sec = Math.max(0, Math.floor(sec || 0));
      const m = Math.floor(sec/60);
      const s = sec%60;
      return (m>0? (m+' นาที ') : '') + s + ' วิ';
    }

    function tickTTL() {
      if (lastTTL > 0) { lastTTL--; }
      elTtl.textContent = formatTTL(lastTTL);
      if (lastTTL <= 0) {
        // หมดอายุ → ปิดปุ่ม
        disableBtn('ลิงก์หมดเวลา กรุณาติดต่อเจ้าหน้าที่เพื่อออกลิงก์ใหม่');
      }
    }

    function disableBtn(msg) {
      if (!btnStart) return;
      btnStart.setAttribute('aria-disabled','true');
      btnStart.classList.add('is-disabled');
      btnStart.href = '#';
      if (msg) setAlert('warning', msg);
    }
    function enableBtn() {
      if (!btnStart) return;
      btnStart.removeAttribute('aria-disabled');
      btnStart.classList.remove('is-disabled');
      hideAlert();
    }
    function setStartLoading(on) {
      if (!btnStart || !startSpin) return;
      if (on) {
        startSpin.classList.remove('d-none');
        disableBtn(); // ปิดชั่วคราวกันคลิกซ้ำ
      } else {
        startSpin.classList.add('d-none');
        // ไม่ auto-enable ที่นี่ ให้ enable ตามสถานะจริงจาก status
      }
    }

    /* ---------- load status ---------- */
    async function loadStatus() {
      const res = await fetch(statusUrl, { cache:'no-store' });
      const j = await res.json().catch(()=>null);

      if (!res.ok || !j || j.ok !== true) {
        const msg = (j && j.error) ? j.error : 'ไม่สามารถดึงสถานะได้';
        setAlert('danger', 'เกิดข้อผิดพลาด: ' + msg);
        elSt.textContent  = 'error';
        elExp.textContent = '—';
        elSid.textContent = '—';
        elSessSt.textContent = '—';
        elSessExp.textContent= '—';
        disableBtn();
        return null;
      }

      // โครง JSON จริงจาก status.php:
      // {
      //   ok, token, session_status, session_expires_at,
      //   attempt: { sid, status, attempt_expires_at, ttl_seconds, age_seconds },
      //   can_open_dlt
      // }

      const attempt   = j.attempt || {};
      const sid       = attempt.sid || null;
      const st        = (attempt.status || '-').toLowerCase();
      const ttl       = Number(attempt.ttl_seconds ?? 0);
      const attExp    = attempt.attempt_expires_at || '-';
      const sessSt    = j.session_status || '-';
      const sessExp   = j.session_expires_at || '-';
      const canOpen   = !!j.can_open_dlt;

      // เขียน UI
      elSt.textContent   = st;
      elExp.textContent  = attExp;
      elSid.textContent  = sid || '—';
      elSessSt.textContent = sessSt;
      elSessExp.textContent= sessExp;

      lastTTL = ttl > 0 ? ttl : 0;
      elTtl.textContent  = formatTTL(lastTTL);

      // เปิดปุ่มเมื่อ: มี sid + TTL > 0 + can_open_dlt = true
      // (ถ้าอยากบังคับให้กดผ่าน start.php เสมอ ให้ enableBtn() เฉพาะตอนกด start)
      if (sid && ttl > 0 && canOpen) {
        // ตั้ง href ไป DLT ทันที (ปลอดภัย/รวดเร็ว)
        btnStart.href = DLT_BASE + encodeURIComponent(sid);
        enableBtn();
      } else if (sid && ttl > 0 && !canOpen) {
        disableBtn('ระบบกำลังเตรียมข้อมูล โปรดลองอีกครั้งในไม่กี่วินาที');
      } else if (sid && ttl <= 0) {
        disableBtn('ลิงก์หมดอายุแล้ว กรุณาติดต่อเจ้าหน้าที่เพื่อออกลิงก์ใหม่');
      } else {
        disableBtn('ยังไม่มีรหัสสำหรับเริ่มยืนยัน โปรดรอเจ้าหน้าที่เริ่มกระบวนการ');
      }

      return j;
    }

    /* ---------- start (ขอ entry_url แล้ว redirect) ---------- */
    async function doStartViaAPI() {
      // ใช้ในกรณีที่อยากให้ผ่าน start.php เพื่อรับ entry_url (เผื่อในอนาคตเปลี่ยนปลายทาง)
      try {
        setStartLoading(true);
        const body = new URLSearchParams({ token });
        const res = await fetch(startUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body,
          cache: 'no-store',
        });
        const data = await res.json().catch(()=>null);
        setStartLoading(false);

        if (!res.ok || !data || data.ok !== true || !data.entry_url) {
          const msg = (data && data.error) ? data.error : 'ไม่สามารถเริ่มกระบวนการได้';
          setAlert('danger', 'เกิดข้อผิดพลาด: ' + msg);
          return;
        }

        // ไปหน้า DLT ตาม entry_url ที่ backend สร้างให้
        window.location.href = data.entry_url;

      } catch (e) {
        setStartLoading(false);
        setAlert('danger', 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
      }
    }

    // ปุ่มรีเฟรช
    btnRefresh?.addEventListener('click', (e) => {
      e.preventDefault();
      loadStatus().catch(err=>{
        setAlert('danger','โหลดสถานะไม่สำเร็จ: '+(err?.message||'unknown'));
      });
    });

    // ปุ่มเริ่มยืนยัน
    btnStart?.addEventListener('click', async (e) => {
      // ถ้าปุ่มพร้อม (ไม่ disabled) และตั้ง href ไป DLT แล้ว ให้ปล่อยให้เบราว์เซอร์ไปตามลิงก์
      if (btnStart.getAttribute('aria-disabled') === 'true' || btnStart.classList.contains('is-disabled')) {
        e.preventDefault();
        return false;
      }
      // ถ้า href ปัจจุบันยังเป็น '#', ให้บังคับผ่าน start.php
      if (btnStart.getAttribute('href') === '#') {
        e.preventDefault();
        await doStartViaAPI();
        return false;
      }
      // else: ปล่อยผ่าน (ไป DLT ด้วย state=sid)
    }, true);

    // เริ่มทำงาน
    (async function boot(){
      try {
        await loadStatus();
      } catch(e) {
        setAlert('danger', 'โหลดสถานะไม่สำเร็จ: ' + (e?.message||'unknown'));
      }
      // โพลสถานะทุก ~2s
      poller = setInterval(loadStatus, 2000);
      // นับถอยหลัง TTL ทุก 1s
      ttlTimer = setInterval(tickTTL, 1000);
    })();

    // เคลียร์ timer เมื่อออกจากหน้า
    window.addEventListener('beforeunload', () => {
      if (poller) clearInterval(poller);
      if (ttlTimer) clearInterval(ttlTimer);
    });
  })();
  </script>
  <?php endif; ?>
</body>
</html>
