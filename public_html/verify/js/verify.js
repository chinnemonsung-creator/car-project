/* ===== CONFIG & STATE (อัปเดตให้เข้ากับ status.php / start.php) ===== */
let ENTRY_BASE = null;  // อาจมาจาก /config, ถ้าไม่มี ใช้ DEFAULT_ENTRY_BASE
const DEFAULT_ENTRY_BASE = 'https://reserve.dlt.go.th/reserve/v2/?menu=resv_m&state=';

// ยังรองรับกรณีดึง /config จาก API ภายนอกได้ (ถ้าต้องการ)
const DEFAULT_API_BASE = 'https://api.bellafleur-benly.com';

let LIFF_ID = null;
let liffReady = false;

const POLL_BASE_MS   = 1500;
const POLL_JITTER_MS = 400;
const MAX_BACKOFF_MS = 7000;

// อ่าน token จาก <html data-token="..."> (แนะนำให้หน้า index.php ใส่ data-token)
const PAGE_TOKEN = document.documentElement.getAttribute('data-token') || '';

const state = {
  sid: null,               // จะได้มาจาก status.php (attempt.sid)
  entryUrl: null,          // URL ที่จะเปิด DLT (ENTRY_BASE + sid) หรือ entry_url จาก start.php
  pollTimer: null,
  polling: false,
  backoff: 0,
  started: false,
  ttl: 0,
  expiryAt: 0,
  ttlTimer: null,
  canOpenDLT: false,       // อ่านจาก status.php → can_open_dlt
  sessionStatus: '-',      // แสดงผลเฉย ๆ (หากต้องใช้)
};

/* ===== UTIL ===== */
function $(id){ return document.getElementById(id); }
function setStatusLabel(s){ $('statusPill') && ($('statusPill').textContent = s); }
function setSidLabel(s){ $('sidPill') && ($('sidPill').textContent = s); }
function setTTL(sec){
  const pill = $('ttlPill'); const slot = $('ttlSec');
  if (!pill || !slot) return;
  if (typeof sec === 'number' && sec > 0) { slot.textContent = sec; pill.classList.remove('hidden'); }
  else { slot.textContent = '—'; pill.classList.add('hidden'); }
}
function setAlert(msg, type="info"){
  const el = $('alert'); if(!el) return;
  el.className = "alert " + (type==="error" ? "alert-error" : "alert-info");
  el.textContent = msg;
  el.classList.remove('hidden');
}
function clearAlert(){ const el=$('alert'); if(el) el.classList.add('hidden'); }

function renderProgress(step){
  const fill=$('progressFill'); if (fill) {
    if(step===1) fill.style.width="10%";
    if(step===2) fill.style.width="50%";
    if(step===3) fill.style.width="100%";
  }
  document.querySelectorAll('.step-circle').forEach((el,idx)=> el.classList.toggle("active", step>=idx+1));
}
function revealTools(show){
  $('openAgain')?.classList.toggle('hidden', !show);
  $('copyLink')?.classList.toggle('hidden', !show);
  $('shareLink')?.classList.toggle('hidden', !show);
}

/* ===== IN-APP DETECT & OPEN-BEHAVIOR ===== */
// ตรวจ UA ว่าเป็น in-app ของ LINE / Facebook / Instagram หรือไม่
function isInAppBrowser() {
  const ua = (navigator.userAgent || navigator.vendor || "").toLowerCase();
  return ua.includes("fbav") || ua.includes("fban") || ua.includes("fb_iab") || ua.includes("facebook")
      || ua.includes(" line/") || ua.includes(" line ")
      || ua.includes("instagram");
}
function isAndroid(){ return /android/i.test(navigator.userAgent); }
function isIOS(){ return /iphone|ipad|ipod/i.test(navigator.userAgent); }

/** เปิดลิงก์ใน in-app แบบ overlay (_blank) เพื่อให้ผู้ใช้ "ปิด/Done" แล้วกลับมา LINE ได้ทันที */
function openInAppOverlay(url){
  const a = document.createElement('a');
  a.rel = 'noopener';
  a.target = '_blank';         // ทำให้เปิดเป็น overlay ภายใน LINE/FB/IG (SFSafariViewController/Custom Tab)
  a.href = url;                // ใช้ https ตรง ๆ — ไม่ใช้ intent:// เพื่อหลีกเลี่ยงการโยนออกแอป
  document.body.appendChild(a);
  a.click();
  a.remove();
}

/* ===== แบนเนอร์เตือน in-app + ปุ่มช่วยเปิดภายนอก (ออปชัน) ===== */
function ensureExternalBrowserBanner(options = {}) {
  if (!isInAppBrowser()) return;

  const { currentUrl = window.location.href, dltEntryUrl = null } = options;
  if (document.getElementById('iab-warning')) return;

  const bar = document.createElement('div');
  bar.id = 'iab-warning';
  bar.style.cssText = `
    position: fixed; z-index: 99999; left: 0; right: 0; bottom: 0;
    background: #111; color: #fff; padding: 12px 14px;
    display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between;
    box-shadow: 0 -4px 12px rgba(0,0,0,0.25); font-size: 14px;
  `;

  const msg = document.createElement('div');
  msg.innerHTML = `คุณกำลังเปิดจากแอปในตัว (LINE/FB/IG) — ระบบจะเปิดหน้า DLT แบบ <b>overlay</b> เพื่อให้คุณกด <b>ปิด</b> แล้วกลับมาหน้านี้ได้ทันที`;

  const btnRow = document.createElement('div');
  btnRow.style.cssText = 'display:flex; gap:8px; flex-wrap:wrap;';

  const copyBtn = document.createElement('button');
  copyBtn.textContent = 'คัดลอกลิงก์นี้';
  copyBtn.style.cssText = 'background:#424242;color:#fff;border:0;border-radius:6px;padding:8px 10px;cursor:pointer;';
  copyBtn.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(currentUrl);
      copyBtn.textContent = 'คัดลอกแล้ว ✓';
      setTimeout(() => (copyBtn.textContent = 'คัดลอกลิงก์นี้'), 1500);
    } catch {
      alert('คัดลอกไม่สำเร็จ กรุณากดค้างที่แอดเดรสบาร์เพื่อคัดลอกเอง');
    }
  });

  btnRow.appendChild(copyBtn);

  if (dltEntryUrl) {
    const openDLT = document.createElement('button');
    openDLT.textContent = 'เปิด DLT (overlay)';
    openDLT.style.cssText = 'background:#2962ff;color:#fff;border:0;border-radius:6px;padding:8px 10px;cursor:pointer;';
    openDLT.addEventListener('click', () => openInAppOverlay(dltEntryUrl));
    btnRow.appendChild(openDLT);
  }

  bar.appendChild(msg);
  bar.appendChild(btnRow);
  document.body.appendChild(bar);
}

/* ===== เปิดแบบ same-tab (เก็บไว้ใช้เฉพาะนอก in-app กรณี popup ถูกบล็อก) ===== */
function openSameTab(url){
  try { window.location.assign(url); }
  catch(e){ window.location.href = url; }
}

/* ===== LIFF (ถ้าใช้ใน LINE App) ===== */
async function initLiffOnce(){
  if (!window.liff || liffReady || !LIFF_ID) return;
  try{ await liff.init({ liffId: LIFF_ID }); liffReady = true; }catch(e){ /* ignore */ }
}

/* ===== API BASE (รองรับ ?api=... ใช้เฉพาะโหลด /config ภายนอก) ===== */
function getApiBase(){
  try {
    const u = new URL(location.href);
    const fromQS = u.searchParams.get('api');
    return fromQS || DEFAULT_API_BASE;
  } catch { return DEFAULT_API_BASE; }
}

/* ===== /config ภายนอก (ออปชัน) ===== */
async function loadConfig(){
  try{
    const api = getApiBase();
    const r = await fetch(`${api}/config`, { cache:'no-store' });
    const j = await r.json();
    if (j && j.ok) {
      ENTRY_BASE = j.entry_base || DEFAULT_ENTRY_BASE;
      LIFF_ID = j.liff_id || null;
      await initLiffOnce();
      return;
    }
  }catch(e){
    console.warn('load /config failed', e);
  }
  // fallback
  ENTRY_BASE = ENTRY_BASE || DEFAULT_ENTRY_BASE;
}

/* ===== สร้าง entryUrl จาก sid ===== */
function makeEntryUrlFromSid(sid){
  const base = ENTRY_BASE || DEFAULT_ENTRY_BASE;
  return base + encodeURIComponent(sid);
}

/* ===== แสดงผล entryUrl (เมื่อเริ่มแล้วเท่านั้น) ===== */
function renderEntryUI(url){
  const a = $('entryLinkA');
  if (a){
    a.textContent = url;
    a.href = url;
  }
  $('qrBox')?.classList.remove('hidden');
}

/* ===== TTL Countdown ===== */
function stopTTLTimer(){
  if (state.ttlTimer){ clearInterval(state.ttlTimer); state.ttlTimer = null; }
  state.ttl = 0; state.expiryAt = 0; setTTL(0);
}
function ensureTTLTimer(){
  if (state.ttlTimer) return;
  state.ttlTimer = setInterval(()=>{
    if (!state.expiryAt) return;
    const remain = Math.max(0, Math.ceil((state.expiryAt - Date.now()) / 1000));
    state.ttl = remain;
    setTTL(remain);
    if (remain <= 0){
      stopTTLTimer();
      $('renewButton')?.classList.remove('hidden');
      const btn = $('startButton'); if (btn){ btn.disabled = false; btn.classList.add('attention'); }
      setAlert('ลิงก์หมดอายุแล้ว', 'error');
    }
  }, 800);
}

/* ===== ฝั่งสถานะจริง: status.php / start.php (ภายใต้ /verify/api/) ===== */
function statusEndpoint(){
  // ใช้ relative เสมอ → /verify/api/status.php?token=...
  return '/verify/api/status.php?token=' + encodeURIComponent(PAGE_TOKEN || '');
}
function startEndpoint(){
  return '/verify/api/start.php';
}

/* ===== Polling /verify/api/status.php ===== */
function nextPollDelay(){ return POLL_BASE_MS + Math.floor(Math.random()*POLL_JITTER_MS) + (state.backoff||0); }

async function pollOnce(){
  if (!PAGE_TOKEN) {
    setAlert('ไม่พบ token สำหรับตรวจสอบสถานะ', 'error');
    return;
  }
  try{
    const r = await fetch(statusEndpoint(), { cache:'no-store' });
    const j = await r.json().catch(()=>null);
    if (!r.ok || !j || j.ok !== true) {
      throw new Error((j && j.error) ? j.error : 'status failed');
    }

    // โครง j จาก status.php:
    // { ok, token, session_status, session_expires_at,
    //   attempt: { sid, status, attempt_expires_at, ttl_seconds, age_seconds },
    //   can_open_dlt }
    state.backoff = 0;
    clearAlert();

    const attempt = j.attempt || {};
    const sid     = attempt.sid || null;
    const aStatus = (attempt.status || 'pending').toUpperCase();
    const ttlSec  = Number(attempt.ttl_seconds || 0);
    const attExp  = attempt.attempt_expires_at || null;

    state.sid = sid || state.sid;
    state.canOpenDLT = !!j.can_open_dlt;
    state.sessionStatus = j.session_status || '-';

    // UI
    setStatusLabel(aStatus);
    setSidLabel(state.sid || '—');

    if (Number.isFinite(ttlSec) && ttlSec > 0) {
      state.ttl = ttlSec;
      let expMs = 0;
      if (attExp) {
        const t = attExp.replace(' ', 'T');
        const d = new Date(t);
        expMs = isNaN(d) ? (Date.now() + ttlSec*1000) : d.getTime();
      } else {
        expMs = Date.now() + ttlSec*1000;
      }
      state.expiryAt = expMs;
      ensureTTLTimer();
    }

    // เปิด/ปิดปุ่มเริ่ม:
    const canStart = !!(state.sid && state.ttl > 0 && state.canOpenDLT);
    const startBtn = $('startButton');
    if (startBtn) {
      if (canStart) { startBtn.disabled = false; startBtn.classList.remove('attention'); }
      else          { startBtn.disabled = true;  startBtn.classList.remove('attention'); }
    }

    // ความคืบหน้า
    if (aStatus === 'SUCCESS' || aStatus === 'CONFIRMED') {
      renderProgress(3);
      stopPolling();
      stopTTLTimer();
      setAlert('ยืนยันเสร็จสิ้น', 'info');
    } else if (aStatus === 'EXPIRED' || aStatus === 'FAILED') {
      renderProgress(2);
      stopPolling();
      stopTTLTimer();
      $('renewButton')?.classList.remove('hidden');
      const btn = $('startButton'); if (btn){ btn.disabled = false; btn.classList.add('attention'); }
      setAlert(aStatus==='EXPIRED' ? 'ลิงก์หมดอายุแล้ว' : 'เกิดข้อผิดพลาด กรุณาลองใหม่', 'error');
    } else {
      renderProgress(2);
    }

  }catch(e){
    state.backoff = Math.min(MAX_BACKOFF_MS, (state.backoff||0) + 1000);
    setAlert('เครือข่ายไม่เสถียร กำลังเชื่อมต่ออีกครั้ง…', 'error');
  }
}
function startPolling(){
  if (state.polling) return;
  state.polling = true;
  const tick = async ()=>{ await pollOnce(); if (state.polling) state.pollTimer = setTimeout(tick, nextPollDelay()); };
  state.pollTimer = setTimeout(tick, 250);
}
function stopPolling(){
  state.polling = false;
  if (state.pollTimer){ clearTimeout(state.pollTimer); state.pollTimer = null; }
}

/* ===== Actions ===== */
async function startFlow(){
  if (state.started) return;
  state.started = true;

  const btn = $('startButton');
  if (btn){ btn.disabled=true; btn.classList.remove('attention'); btn.classList.add('is-loading'); }
  // แจ้งผู้ใช้ให้ทำต่อใน ThaiID แล้ว "ปิด (Done)" เพื่อกลับมาหน้านี้
  setAlert('กำลังเปิดหน้า DLT/ThaiID (overlay)… กรุณายืนยันตัวตนให้เสร็จ และกดปุ่มปิด (Done) เพื่อกลับมาหน้านี้', 'info');

  try{
    // ถ้าอนุญาตเปิด DLT ได้เลย (can_open_dlt=true) และมี sid
    if (state.canOpenDLT && state.sid) {
      state.entryUrl = makeEntryUrlFromSid(state.sid);
    } else {
      // มิฉะนั้น ให้ขอ entry_url จาก start.php (POST token)
      const body = new URLSearchParams({ token: PAGE_TOKEN || '' });
      const resp = await fetch(startEndpoint(), {
        method: 'POST',
        headers: { 'Content-Type':'application/x-www-form-urlencoded' },
        body,
        cache: 'no-store',
      });
      const data = await resp.json().catch(()=>null);
      if (!resp.ok || !data || data.ok !== true || !data.entry_url) {
        throw new Error((data && data.error) ? data.error : 'ไม่สามารถเริ่มกระบวนการได้');
      }
      state.entryUrl = data.entry_url;
      if (data.sid) { state.sid = data.sid; setSidLabel(state.sid); }
    }

    // แสดงแบนเนอร์อธิบาย overlay สำหรับ in-app
    ensureExternalBrowserBanner({ currentUrl: window.location.href, dltEntryUrl: state.entryUrl });

    // เริ่ม polling ทันที เพื่อให้ผู้ใช้กลับมาแล้วเห็นผลอัปเดตอัตโนมัติ
    startPolling();

    // *** จุดสำคัญตาม VDO: เปิดแบบ overlay ใน in-app เพื่อมีปุ่ม Close/Done ***
    if (isInAppBrowser()) {
      openInAppOverlay(state.entryUrl);
    } else {
      // นอก in-app → เปิดแท็บใหม่เพื่อไม่ทับหน้า (ถ้า popup โดนบล็อก ค่อย same-tab)
      const w = window.open(state.entryUrl, '_blank', 'noopener');
      if (!w) openSameTab(state.entryUrl);
    }

    renderProgress(2);
    renderEntryUI(state.entryUrl);
    revealTools(true);

  }catch(err){
    setAlert('เริ่มยืนยันไม่สำเร็จ: '+(err?.message||'unknown'), 'error');
    state.started = false;
    if (btn){ btn.disabled=false; btn.classList.add('attention'); }
  }finally{
    if (btn) btn.classList.remove('is-loading');
  }
}

async function renewEntry(){
  clearAlert();
  stopTTLTimer();

  if (state.entryUrl) {
    // ใน in-app ให้เปิด overlay อีกครั้ง (คงหน้าเดิมไว้)
    if (isInAppBrowser()) {
      openInAppOverlay(state.entryUrl);
    } else {
      const w = window.open(state.entryUrl, '_blank', 'noopener');
      if (!w) openSameTab(state.entryUrl);
    }
  }

  renderProgress(2);
  startPolling();
  $('renewButton')?.classList.add('hidden');
}

/* ===== Bind Events & Lifecycle ===== */
function bindEvents(){
  $('startButton')?.addEventListener('click', startFlow);
  $('renewButton')?.addEventListener('click', renewEntry);
  $('openAgain')?.addEventListener('click', ()=>{
    if(state.entryUrl){
      if (isInAppBrowser()) openInAppOverlay(state.entryUrl);
      else {
        const w = window.open(state.entryUrl, '_blank', 'noopener');
        if (!w) openSameTab(state.entryUrl);
      }
    }
  });
  $('copyLink')?.addEventListener('click', async ()=>{ if(state.entryUrl) await navigator.clipboard.writeText(state.entryUrl); });
  $('shareLink')?.addEventListener('click', async ()=>{ if(navigator.share && state.entryUrl) await navigator.share({ title:'DLT Entry', url: state.entryUrl }); });

  // เมื่อผู้ใช้ "ปิด/Done" overlay แล้วกลับมาหน้านี้ → โพลต่อ/อัปเดตผล
  document.addEventListener('visibilitychange', ()=>{
    if(document.hidden){ stopPolling(); }
    else if(state.started){ startPolling(); }
  });
  window.addEventListener('online', ()=>{ clearAlert(); if(state.started) startPolling(); });
  window.addEventListener('offline', ()=>{ setAlert('ออฟไลน์: ไม่มีการเชื่อมต่ออินเทอร์เน็ต', 'error'); stopPolling(); });
}

/* ===== Boot ===== */
(async function boot(){
  bindEvents();
  renderProgress(1);

  // 1) โหลด config ภายนอก (ถ้ามี) เพื่อได้ ENTRY_BASE/LIFF_ID
  await loadConfig();

  // 2) ยิง status ครั้งแรกเพื่อดึงข้อมูล sid/ttl/can_open_dlt
  await pollOnce();

  // 3) เริ่มโพลต่อเนื่อง + นาฬิกา TTL
  startPolling();
  ensureTTLTimer();

  // 4) แสดงแบนเนอร์แจ้งว่าระบบจะเปิด DLT แบบ overlay หากเป็น in-app
  ensureExternalBrowserBanner({ currentUrl: window.location.href });

  // 5) TIP: ถ้าหน้าใส่ data-token ไม่ได้ ให้แปะลง <html data-token="...">
})();
