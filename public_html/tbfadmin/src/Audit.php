<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Security.php';

class Audit {
  /**
   * บันทึกเหตุการณ์ลงตาราง audit_logs
   *
   * @param string      $event        ชื่อเหตุการณ์ เช่น 'orders.update', 'orders.delete', 'orders.export', 'orders.search'
   * @param array       $meta         ข้อมูลประกอบ (จะถูกเก็บเป็น JSON)
   * @param string|null $entityType   ประเภท entity เช่น 'order'
   * @param int|null    $entityId     ไอดีของ entity
   */
  public static function log(string $event, array $meta = [], ?string $entityType = null, ?int $entityId = null): void {
    try {
      // เตรียมบริบทผู้ใช้/ไคลเอนต์
      Security::ensureSession();
      $adminUserId = isset($_SESSION['admin_user_id']) ? (int)$_SESSION['admin_user_id'] : null;
      $adminRole   = $_SESSION['admin_role'] ?? null;
      $ip          = $_SERVER['REMOTE_ADDR'] ?? null;
      $ua          = $_SERVER['HTTP_USER_AGENT'] ?? null;

      // รวม meta เพิ่มเติม
      $meta = array_merge([
        '_ip'        => $ip,
        '_userAgent' => $ua,
      ], $meta);

      // เตรียม DB
      $db  = new Database($GLOBALS['config']['db']);
      $pdo = $db->pdo();

      // เตรียม JSON
      $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      // Insert
      $sql = "INSERT INTO audit_logs
              (event, entity_type, entity_id, admin_user_id, role, meta_json, ip, user_agent, created_at)
              VALUES (:event, :etype, :eid, :uid, :role, :meta, :ip, :ua, NOW())";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        ':event' => $event,
        ':etype' => $entityType,
        ':eid'   => $entityId,
        ':uid'   => $adminUserId,
        ':role'  => $adminRole,
        ':meta'  => $metaJson,
        ':ip'    => $ip,
        ':ua'    => $ua,
      ]);
    } catch (\Throwable $e) {
      // ถ้า insert ไม่ได้ (เช่นยังไม่มีตาราง) ให้ log ลงไฟล์แทน เพื่อไม่ให้หน้าเว็บล้ม
      error_log('[audit] '.$e->getMessage().' | event='.$event.' | meta='.json_encode($meta, JSON_UNESCAPED_UNICODE));
    }
  }
}
