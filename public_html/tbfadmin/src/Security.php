<?php
class Security {
  /* -------- Session & CSRF -------- */
  public static function ensureSession() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      $name = $GLOBALS['config']['app']['admin_session_name'] ?? 'bb_admin';
      session_name($name);
      session_start();
    }
  }

  public static function csrfToken(): string {
    self::ensureSession();
    if (empty($_SESSION['csrf'])) {
      $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
  }

  public static function verifyCsrf(string $t): bool {
    self::ensureSession();
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
  }

  /* -------- Normalizers -------- */
  /** คืนค่าเฉพาะตัวเลข (สำหรับ phone/citizen) */
  public static function normalizePhone(string $s): string {
    return preg_replace('/\D+/', '', $s);
  }
  public static function normalizeCitizen(string $s): string {
    return preg_replace('/\D+/', '', $s);
  }
  /** VIN: ตัดช่องว่าง + เปลี่ยนเป็นตัวพิมพ์ใหญ่ */
  public static function normalizeVIN(string $s): string {
    $s = strtoupper($s);
    return preg_replace('/\s+/', '', $s);
  }

  /* -------- Date Range Parser -------- */
  /**
   * รับ $from, $to (YYYY-MM-DD) แล้วคืน [fromDT,toDT] ในรูป 'Y-m-d H:i:s'
   * ถ้า parse ไม่ได้ → คืนค่า null ตำแหน่งนั้น
   */
  public static function parseDateRange(?string $from, ?string $to): array {
    $outFrom = null; $outTo = null;
    if ($from) {
      $dt = date_create($from);
      if ($dt) $outFrom = $dt->format('Y-m-d 00:00:00');
    }
    if ($to) {
      $dt = date_create($to);
      if ($dt) $outTo = $dt->format('Y-m-d 23:59:59');
    }
    return [$outFrom, $outTo];
  }

  /* -------- Safe ORDER BY Builder -------- */
  /**
   * สร้าง ORDER BY อย่างปลอดภัยด้วย whitelist
   * @param string|null $sort  ชื่อ key ที่มาจากผู้ใช้ (เช่น 'created_at')
   * @param string|null $dir   'asc'|'desc' (ไม่สนตัวพิมพ์)
   * @param array $whitelist   ['created_at' => 'created_at', 'name' => 'last_name, first_name', ...]
   * @param string $defaultSql ORDER BY เริ่มต้น ถ้า $sort ไม่ผ่าน
   * @return string            เช่น "ORDER BY created_at DESC"
   */
  public static function safeOrderBy(?string $sort, ?string $dir, array $whitelist, string $defaultSql): string {
    $sort = $sort ?? '';
    $dir  = strtolower($dir ?? 'desc');
    $dir  = in_array($dir, ['asc','desc'], true) ? $dir : 'desc';

    if ($sort !== '' && isset($whitelist[$sort])) {
      // value ใน whitelist ควรเป็น SQL expression/column ที่เราควบคุมเอง เช่น "created_at"
      $expr = $whitelist[$sort];
      return " ORDER BY {$expr} ".strtoupper($dir)." ";
    }
    // ถ้าไม่ผ่าน whitelist ให้ใช้ค่าดีฟอลต์
    return " ".$defaultSql." ";
  }
}
