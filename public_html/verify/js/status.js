<script>
let pollTimer, aborter;

function renderStatus(s) {
  // TODO: อัปเดต UI ตามสไตล์ของไอซ์
  // ตัวอย่าง:
  const ttl = s.ttl_seconds ?? 0;
  document.querySelector('#status').textContent = s.status;
  document.querySelector('#ttl').textContent = ttl + 's';
  document.querySelector('#attempt').textContent = s.attempt_no;
}

async function fetchStatus(sid) {
  if (aborter) aborter.abort();
  aborter = new AbortController();
  const r = await fetch(`/verify/status.php?sid=${encodeURIComponent(sid)}`, {
    signal: aborter.signal,
    cache: 'no-store',
  });
  return r.json();
}

function startStatusPoll(sid, intervalMs = 1500) {
  clearInterval(pollTimer);
  pollTimer = setInterval(async () => {
    try {
      const resp = await fetchStatus(sid);
      if (!resp.ok) return;           // ยังไม่พร้อม/ผิดพลาดเล็กน้อยก็ปล่อยผ่าน
      renderStatus(resp);

      // เงื่อนไขหยุด
      if (resp.status === 'confirmed' || resp.status === 'success') {
        clearInterval(pollTimer);
        // TODO: แจ้งผู้ให้บริการ/เปลี่ยนปุ่ม/ไปขั้นถัดไป
      } else if (resp.status === 'expired' || resp.status === 'failed') {
        clearInterval(pollTimer);
        // TODO: แสดงปุ่ม "ขอลิงก์ใหม่" → ไปเรียก /verify/api/start.php
      }
    } catch(e) {
      // เงียบๆ แล้วลองใหม่รอบหน้า
    }
  }, intervalMs);
}
</script>
