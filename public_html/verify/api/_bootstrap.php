<?php
// /verify/api/_bootstrap.php
declare(strict_types=1);

@error_reporting(E_ALL);
@ini_set('display_errors','0');
@ini_set('log_errors','1');

// หา path ไปยัง tbfadmin (สมมติว่า verify และ tbfadmin อยู่คนละโฟลเดอร์ในเว็บรูทเดียวกัน)
$verifyRoot = realpath(__DIR__ . '/..');          // /verify/api -> /verify
$adminRoot  = realpath($verifyRoot . '/../tbfadmin'); // /tbfadmin

if (!$adminRoot || !is_dir($adminRoot)) {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'tbfadmin_path_missing']);
  exit;
}

// ใช้ Bootstrap/Database ของฝั่งแอดมินร่วมกัน
require $adminRoot . '/src/Bootstrap.php';
require $adminRoot . '/src/Database.php';

// helpers เล็กน้อย
function json_out($a){
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function db(): PDO { return Database::pdo(); }
