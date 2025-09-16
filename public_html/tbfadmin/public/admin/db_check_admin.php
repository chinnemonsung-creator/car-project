<?php
// /tbfadmin/public/admin/db_check_admin.php
declare(strict_types=1);
require __DIR__ . '/../../src/Database.php';
header('Content-Type: text/plain; charset=utf-8');

try {
  $pdo = Database::pdo(); // ใช้ pdo() ตามที่มีจริง
  echo "OK: DB connected\n";

  $exists = $pdo->query("SHOW TABLES LIKE 'admin_users'")->fetchColumn();
  echo "admin_users table: ".($exists ? "FOUND" : "NOT FOUND")."\n";

  if ($exists) {
    $row = $pdo->query("SELECT id, username, password_hash, is_active FROM admin_users WHERE username='iceadmin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    echo "iceadmin row: ".($row ? "FOUND" : "NOT FOUND")."\n";
    if ($row) { print_r($row); }
  }
} catch (Throwable $e) {
  echo "ERR: ".$e->getMessage()."\n";
}
