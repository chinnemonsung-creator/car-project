<?php
/**
 * /tbfadmin/reset_admin.php
 *
 * รีเซ็ตรหัสผู้ใช้แอดมินให้ล็อกอินได้ทันที
 * - รองรับ Database.php ที่ใช้ static methods: getConnection() / pdo()
 * - ไม่ทำลาย schema เดิม: อัปเดตแถวเดิม; ถ้าไม่มีค่อย insert แบบพยายามครอบคลุมคอลัมน์ที่มี
 * - พารามิเตอร์: ?u=admin&p=Admin@1234
 */
declare(strict_types=1);

@error_reporting(E_ALL);
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

/* ---------- BOOT: หา Database.php ให้เจอไม่ว่าจะวางไว้ตรงไหน ---------- */
$paths = [
  __DIR__ . '/src/Database.php',            // /tbfadmin/src/Database.php
  __DIR__ . '/tbfadmin/src/Database.php',   // เผื่อเรียกจากรากเว็บ
  dirname(__DIR__) . '/src/Database.php',
];
$loaded = false;
foreach ($paths as $p) {
  if (is_file($p)) { require_once $p; $loaded = true; break; }
}
if (!$loaded) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'why'=>'Database.php not found']); exit;
}

/* ---------- GET PDO (รองรับทั้ง getConnection และ pdo) ---------- */
try {
  if (class_exists('Database') && method_exists('Database','getConnection')) {
    /** @var PDO $pdo */
    $pdo = Database::getConnection();
  } elseif (class_exists('Database') && method_exists('Database','pdo')) {
    /** @var PDO $pdo */
    $pdo = Database::pdo();
  } else {
    throw new RuntimeException('Database class has no getConnection()/pdo()');
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'why'=>'pdo_failed','error'=>$e->getMessage()]); exit;
}

/* ---------- INPUTS ---------- */
$username = isset($_GET['u']) && $_GET['u'] !== '' ? (string)$_GET['u'] : 'admin';
$password = isset($_GET['p']) && $_GET['p'] !== '' ? (string)$_GET['p'] : 'Admin@1234';

/* ---------- UTIL ---------- */
function tableExists(PDO $pdo, string $table): bool {
  try {
    $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
    return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function columns(PDO $pdo, string $table): array {
  try {
    $cols = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $cols[strtolower((string)$r['Field'])] = true; }
    return $cols;
  } catch (Throwable $e) { return []; }
}

/* ---------- สร้างตารางขั้นต่ำ ถ้ายังไม่มี (พยายาม non-destructive) ---------- */
$created = false;
if (!tableExists($pdo, 'admin_users')) {
  $sql = "CREATE TABLE `admin_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(190) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name` VARCHAR(190) DEFAULT NULL,
    `role` VARCHAR(50) DEFAULT 'admin',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  $pdo->exec($sql);
  $created = true;
}

/* ---------- ยืนยัน/แก้คอลัมน์สำคัญ (แบบปลอดภัย) ---------- */
$cols = columns($pdo, 'admin_users');
try {
  if (!isset($cols['password_hash'])) {
    $pdo->exec("ALTER TABLE admin_users ADD COLUMN `password_hash` VARCHAR(255) NOT NULL AFTER `username`");
    $cols['password_hash'] = true;
  } else {
    // กันแฮชถูกตัดท่อน
    $pdo->exec("ALTER TABLE admin_users MODIFY `password_hash` VARCHAR(255) NOT NULL");
  }
  if (!isset($cols['is_active'])) {
    $pdo->exec("ALTER TABLE admin_users ADD COLUMN `is_active` TINYINT(1) DEFAULT 1");
    $cols['is_active'] = true;
  }
  if (!isset($cols['role'])) {
    $pdo->exec("ALTER TABLE admin_users ADD COLUMN `role` VARCHAR(50) DEFAULT 'admin'");
    $cols['role'] = true;
  }
  if (!isset($cols['display_name'])) {
    $pdo->exec("ALTER TABLE admin_users ADD COLUMN `display_name` VARCHAR(190) DEFAULT NULL");
    $cols['display_name'] = true;
  }
  if (!isset($cols['updated_at'])) {
    $pdo->exec("ALTER TABLE admin_users ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    $cols['updated_at'] = true;
  }
  if (!isset($cols['created_at'])) {
    $pdo->exec("ALTER TABLE admin_users ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    $cols['created_at'] = true;
  }
} catch (Throwable $e) {
  // ถ้า ALTER ไม่ผ่าน ให้ไปต่อ (อย่าล้มสคริปต์)
}

/* ---------- สร้างแฮชและอัปเดต/แทรก ---------- */
$out = ['ok'=>false,'created_table'=>$created];
try {
  $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>10]);
  if (!$hash) { throw new RuntimeException('password_hash failed'); }

  // UPDATE ก่อน
  $updated = 0;
  try {
    $sqlUpd = "UPDATE admin_users
               SET password_hash = :hash"
               . (isset($cols['is_active']) ? ", is_active = 1" : "")
               . (isset($cols['role']) ? ", role = COALESCE(role,'admin')" : "")
               . (isset($cols['display_name']) ? ", display_name = COALESCE(display_name,'Administrator')" : "")
               . (isset($cols['updated_at']) ? ", updated_at = NOW()" : "")
               . " WHERE BINARY TRIM(username) = :u
               LIMIT 1";
    $stmt = $pdo->prepare($sqlUpd);
    $stmt->execute([':hash'=>$hash, ':u'=>$username]);
    $updated = $stmt->rowCount();
  } catch (Throwable $e) {
    // ignore, try insert
  }

  if ($updated < 1) {
    // INSERT ครอบคลุมคอลัมน์ที่มี
    if (isset($cols['display_name']) && isset($cols['role']) && isset($cols['is_active']) && isset($cols['created_at']) && isset($cols['updated_at'])) {
      $stmt = $pdo->prepare("INSERT INTO admin_users
        (username, password_hash, display_name, role, is_active, created_at, updated_at)
        VALUES (?, ?, 'Administrator', 'admin', 1, NOW(), NOW())");
      $stmt->execute([$username, $hash]);
      $out['inserted'] = true;
    } else {
      // ขั้นต่ำสุด
      $stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)");
      $stmt->execute([$username, $hash]);
      $out['inserted'] = true;
    }
  } else {
    $out['updated'] = true;
  }

  // ตรวจทวนด้วย password_verify
  $stmt = $pdo->prepare("SELECT password_hash FROM admin_users WHERE BINARY TRIM(username)=? LIMIT 1");
  $stmt->execute([$username]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $ok  = $row && password_verify($password, (string)$row['password_hash']);

  $out['ok']      = (bool)$ok;
  $out['why']     = $ok ? 'match' : 'verify_failed';
  $out['user']    = $username;
  $out['hashlen'] = isset($row['password_hash']) ? strlen((string)$row['password_hash']) : 0;

  echo json_encode($out);
} catch (Throwable $e) {
  http_response_code(500);
  $out['ok'] = false;
  $out['why'] = 'fatal';
  $out['error'] = $e->getMessage();
  echo json_encode($out);
}
