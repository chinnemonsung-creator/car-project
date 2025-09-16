<?php
// /tbfadmin/public/api/v1/db_check.php
declare(strict_types=1);

@ini_set('log_errors','1');
$__logDir = __DIR__ . '/../../../var';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }
@ini_set('error_log', $__logDir . '/php-error.log');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

$root = dirname(__DIR__, 3);
$bootstrap = $root . '/src/Bootstrap.php';
if (file_exists($bootstrap)) { require_once $bootstrap; }
require_once $root . '/src/Database.php';

try {
  $pdo = Database::pdo();            // แค่ลองต่อ
  echo json_encode(['ok'=>true, 'driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  // อย่าโชว์รหัสผ่าน แสดงเฉพาะข้อความสั้น ๆ พอ
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
