// /assets/js/dashboard.js

// ==============================
// Utilities พื้นฐาน + Toast
// ==============================
const $  = (sel, root=document) => root.querySelector(sel);
const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));

function showToast(msg, type='info', ms=1800){
  let wrap = $('#toastWrap');
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.id = 'toastWrap';
    wrap.className = 'toast-wrap';
    document.body.appendChild(wrap);
  }
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.textContent = msg;
  wrap.appendChild(el);
  requestAnimationFrame(()=> el.classList.add('show'));
  setTimeout(()=>{ el.classList.remove('show'); setTimeout(()=>el.remove(),180); }, ms);
}

function getRow(el) {
  return el.closest('[data-row="order"]');
}

// ==============================
// Verify session helpers
// ==============================

// ขอ token ล่าสุดของ order ผ่าน API ฝั่งคุณ
async function ensureSessionAndGetToken(orderId) {
  const resp = await fetch('/tbfadmin/public/api/v1/verify.php', {
    method: 'POST',
    headers: { 'Content-Type':'application/json', 'Accept':'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ action:'ensure_session', order_id: Number(orderId) })
  });
  const data = await resp.json().catch(()=> ({}));
  if (!resp.ok || !data.ok) {
    throw new Error(data?.error || ('HTTP_' + resp.status));
  }
  const token = data?.data?.token;
  if (!token) throw new Error('no_token');
  return token;
}

// ดึงสถานะล่าสุดจาก status.php ด้วย token แล้วอัปเดต UI ของแถว
async function refreshRowByToken(row, token) {
  const url = '/verify/api/status.php?token=' + encodeURIComponent(token);
  const resp = await fetch(url, { method: 'GET', credentials: 'same-origin' });
  const json = await resp.json().catch(()=> ({}));
  if (!resp.ok || !json.ok) {
    const msg = (json && json.error) ? json.error : ('HTTP_' + resp.status);
    throw new Error(msg);
  }
  updateUIStatusRowFromPayload(row, json);
  return json;
}

// เขียนค่า attempt.* กลับสู่ DOM (sid/status/ttl)
function updateUIStatusRowFromPayload(row, payload) {
  const attempt = payload?.attempt || {};
  const sid = attempt.sid || '-';
  const st  = (attempt.status || '').toLowerCase() || '-';
  const ttl = (attempt.ttl_seconds ?? '-') + '';

  // เก็บ sid ล่าสุดไว้บนแถวด้วย (ให้ปุ่มหาค่าได้)
  if (sid && sid !== '-') row.setAttribute('data-sid', sid);

  const badgeEl = row.querySelector('[data-role="attempt-status"]');
  const sidEl   = row.querySelector('[data-role="attempt-sid"]');
  const ttlEl   = row.querySelector('[data-role="attempt-ttl"]');

  if (badgeEl) {
    badgeEl.classList.remove('pending','confirmed','failed','expired');
    if (['pending','confirmed','failed','expired'].includes(st)) {
      badgeEl.classList.add(st);
    }
    badgeEl.textContent = st;
  }
  if (sidEl) sidEl.textContent = sid;
  if (ttlEl) ttlEl.textContent = ttl;
}

// ==============================
// Mark attempt
// ==============================

function findSid(el) {
  // 1) sid บนปุ่มเอง
  const sidOnBtn = el.getAttribute('data-sid');
  if (sidOnBtn) return sidOnBtn;
  // 2) sid บน row ครอบ
  const row = getRow(el) || el.closest('[data-sid]');
  if (row) return row.getAttribute('data-sid');
  return null;
}

async function callMark(sid, status) {
  const resp = await fetch('/verify/api/mark.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ sid, status })
  });
  const data = await resp.json().catch(() => ({}));
  if (!resp.ok || !data.ok) {
    const msg = (data && data.error) ? data.error : ('HTTP_'+resp.status);
    throw new Error(msg);
  }
  return data; // { ok:true, sid:'...', status:'confirmed|failed|...' }
}

async function handleMarkClick(btn) {
  const rowActions = btn.closest('.row-actions');
  const row = getRow(btn) || rowActions?.closest('[data-row="order"]');
  const orderId = parseInt(rowActions?.getAttribute('data-order') || '0', 10);
  const status = btn.getAttribute('data-status'); // confirmed | success | fail
  if (!row || !orderId || !status) {
    alert('Missing row/orderId/status');
    return;
  }

  // ขอ token (ensure_session) เพื่อใช้ refresh สถานะหลัง mark
  let token = null;
  try {
    token = await ensureSessionAndGetToken(orderId);
  } catch (err) {
    console.error('ensure_session failed:', err);
    alert('ensure_session ล้มเหลว: ' + err.message);
    return;
  }

  // หา sid ล่าสุด ถ้าไม่มี ลอง refresh ก่อน 1 ครั้ง
  let sid = findSid(btn);
  if (!sid) {
    try {
      const st = await refreshRowByToken(row, token);
      sid = st?.attempt?.sid || '';
    } catch (err) {
      console.warn('pre-refresh failed:', err);
    }
  }
  if (!sid) {
    alert('ยังไม่มี attempt (sid) — กรุณากด "เริ่มยืนยัน (Verify)" ก่อน');
    return;
  }

  // ยิง mark
  btn.disabled = true;
  try {
    const mk = await callMark(sid, status);
    // แจ้งผลแบบ toast
    showToast(`Marked "${mk.status}" (sid: ${sid.slice(0, 10)}…)`, 'success');
  } catch (err) {
    console.error('mark failed:', err);
    showToast('Mark failed: ' + err.message, 'error', 2400);
    return;
  } finally {
    btn.disabled = false;
  }

  // ดึงสถานะล่าสุดมาปรับ UI ให้ตรง DB (กันความคลาดเคลื่อน)
  try {
    await refreshRowByToken(row, token);
  } catch (err) {
    console.error('refresh after mark failed:', err);
  }

  // ปิดเมนู dropdown ถ้ามี
  const menu = btn.closest('.dropdown-menu');
  if (menu) menu.style.display = 'none';
}

// ==============================
// Refresh status ด้วยปุ่ม
// ==============================
async function handleRefreshClick(btn) {
  const rowActions = btn.closest('.row-actions');
  const row = getRow(btn) || rowActions?.closest('[data-row="order"]');
  const orderId = parseInt(rowActions?.getAttribute('data-order') || '0', 10);
  if (!row || !orderId) {
    alert('Missing row/orderId');
    return;
  }
  btn.disabled = true;
  try {
    const token = await ensureSessionAndGetToken(orderId);
    await refreshRowByToken(row, token);
  } catch (err) {
    console.error('refresh failed:', err);
    showToast('Refresh failed: ' + err.message, 'error', 2400);
  } finally {
    btn.disabled = false;
  }
}

// ==============================
// Dropdown Mark
// ==============================
function toggleDropdown(el, open) {
  const dd = el.closest('[data-role="dropdown"]') || el.closest('.dropdown');
  if (!dd) return;
  if (typeof open === 'boolean') {
    dd.classList.toggle('open', open);
  } else {
    dd.classList.toggle('open');
  }
  const menu = dd.querySelector('.dropdown-menu');
  if (menu) {
    if (dd.classList.contains('open')) menu.style.display = 'block';
    else menu.style.display = 'none';
  }
}

// ปิด dropdown เมื่อคลิกรอบนอก
document.addEventListener('click', (e) => {
  if (!e.target.closest('[data-role="dropdown"]') && !e.target.closest('[data-role="mark-menu-toggle"]')) {
    document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
    document.querySelectorAll('[data-role="dropdown"].open, .dropdown.open').forEach(dd => dd.classList.remove('open'));
  }
}, false);

// ==============================
// Global click delegation
// ==============================
document.addEventListener('click', async (e) => {
  // toggle dropdown
  const tgl = e.target.closest('[data-role="mark-menu-toggle"]');
  if (tgl) {
    e.preventDefault();
    toggleDropdown(tgl);
    return;
  }

  // click mark
  const mk = e.target.closest('[data-action="mark"]');
  if (mk) {
    e.preventDefault();
    handleMarkClick(mk);
    return;
  }

  // click refresh status
  const rf = e.target.closest('[data-action="refresh-status"]');
  if (rf) {
    e.preventDefault();
    handleRefreshClick(rf);
    return;
  }
}, false);

// ==============================
// (ออปชัน) เริ่มยืนยันแบบรวดเร็วจากปุ่ม .js-verify-flow
// เปิด status.php ในแท็บใหม่หลัง start attempt
// ==============================
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.js-verify-flow');
  if (!btn) return;
  ev.preventDefault();

  const orderId = parseInt(btn.dataset.orderId || '0', 10);
  if (!orderId) { alert('ไม่พบเลขออเดอร์'); return; }

  try{
    // 1) ensure_session → ได้ token
    const r1 = await fetch('/tbfadmin/public/api/v1/verify.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      credentials:'same-origin',
      body: JSON.stringify({ action:'ensure_session', order_id: orderId })
    });
    const j1 = await r1.json().catch(()=> ({}));
    if(!r1.ok || !j1.ok) throw new Error(j1.error || ('HTTP_'+r1.status));
    const token = j1?.data?.token;
    if(!token) throw new Error('no_token');

    // 2) start_attempt (ถ้ามี endpoint นี้ใน verify.php ของคุณ)
    const r2 = await fetch('/tbfadmin/public/api/v1/verify.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      credentials:'same-origin',
      body: JSON.stringify({ action:'start_attempt', token })
    });
    const j2 = await r2.json().catch(()=> ({}));
    if(!r2.ok || !j2.ok) throw new Error(j2.error || ('HTTP_'+r2.status));

    // 3) เปิดหน้า status.php ให้ตรวจแบบเร็ว
    const statusUrl = '/verify/api/status.php?token=' + encodeURIComponent(token);
    window.open(statusUrl, '_blank');
  }catch(err){
    console.error('verify-flow failed:', err);
    alert('เกิดข้อผิดพลาด: ' + err.message);
  }
});

// ==============================
// Auto Refresh (ทั้งตาราง)
// ==============================

// ปรับค่าได้ตามต้องการ
const AUTO_REFRESH_ENABLED = true;
const REFRESH_EVERY_SEC    = 15;   // ⏱️ รีเฟรชทุกกี่วินาที
const REFRESH_MAX_ROWS     = 200;  // ป้องกันโหลดหนัก ถ้ามีแถวเยอะเกิน ตัดที่จำนวนนี้

// cache token ต่อ order เพื่อลดการเรียก ensure_session ซ้ำ ๆ
// เก็บ { token, ts } แล้วถือว่ามีอายุ ~5 นาที
const _tokenCache = new Map();
const TOKEN_TTL_MS = 5 * 60 * 1000;

function _getOrderIdFromRow(row) {
  const ra = row.querySelector('.row-actions[data-order]');
  if (!ra) return null;
  const id = parseInt(ra.getAttribute('data-order') || '0', 10);
  return Number.isFinite(id) && id > 0 ? id : null;
}

async function _getTokenWithCache(orderId) {
  const now = Date.now();
  const hit = _tokenCache.get(orderId);
  if (hit && (now - hit.ts) < TOKEN_TTL_MS && hit.token) {
    return hit.token;
  }
  const token = await ensureSessionAndGetToken(orderId);
  _tokenCache.set(orderId, { token, ts: now });
  return token;
}

let _autoRefreshTimer = null;
let _autoRefreshRunning = false;

async function refreshAllRowsSequential() {
  if (_autoRefreshRunning) return;       // กัน overlap
  if (document.hidden) return;           // แท็บไม่โฟกัส → ข้ามรอบนี้

  _autoRefreshRunning = true;
  try {
    const rows = Array.from(document.querySelectorAll('tr[data-row="order"]'))
      .slice(0, REFRESH_MAX_ROWS);

    // ไล่ทีละแถวแบบค่อยเป็นค่อยไป เพื่อลดโหลด
    for (const row of rows) {
      const orderId = _getOrderIdFromRow(row);
      if (!orderId) continue;

      try {
        const token = await _getTokenWithCache(orderId);
        await refreshRowByToken(row, token);
        // เว้นจังหวะสั้น ๆ กัน request ถี่เกิน
        await new Promise(r => setTimeout(r, 120));
      } catch (e) {
        // ถ้าเจอ error เฉพาะแถว ให้ข้ามต่อไป
        console.warn('[auto-refresh] row', orderId, e?.message || e);
      }
    }
  } finally {
    _autoRefreshRunning = false;
  }
}

function startAutoRefresh() {
  stopAutoRefresh();
  if (!AUTO_REFRESH_ENABLED) return;
  _autoRefreshTimer = setInterval(refreshAllRowsSequential, REFRESH_EVERY_SEC * 1000);
  // ยิงรอบแรกทันที
  refreshAllRowsSequential().catch(()=>{});
}

function stopAutoRefresh() {
  if (_autoRefreshTimer) {
    clearInterval(_autoRefreshTimer);
    _autoRefreshTimer = null;
  }
}

// หยุด/เริ่มตาม visibility (ถ้าแท็บถูกซ่อนไว้จะไม่ยิง)
document.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    // ไม่ต้อง stop timer เพื่อความเรียบง่าย แค่รอบหน้ามันจะเช็ค hidden แล้วข้ามเอง
    return;
  } else {
    // เพิ่งกลับมาโฟกัส → ยิงหนึ่งรอบทันที
    refreshAllRowsSequential().catch(()=>{});
  }
});

// บูต
startAutoRefresh();
