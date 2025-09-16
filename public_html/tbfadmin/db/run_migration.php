<?php
// tbfadmin/db/run_migration.php
// Usage: php db/run_migration.php db/migrations/20250903_create_verify_sessions.sql

declare(strict_types=1);

$root = dirname(__DIR__); // tbfadmin/
require $root . '/src/Bootstrap.php';

$config = $GLOBALS['config']['db'] ?? null;
if (!$config) {
  fwrite(STDERR, "❌ DB config not found in src/Bootstrap.php\n");
  exit(1);
}

$host = $config['host'] ?? '127.0.0.1';
$user = $config['user'] ?? $config['username'] ?? '';
$pass = $config['pass'] ?? $config['password'] ?? '';
$name = $config['name'] ?? $config['database'] ?? '';
$port = (int)($config['port'] ?? 3306);
$charset = $config['charset'] ?? 'utf8mb4';

if ($argc < 2) {
  fwrite(STDERR, "Usage: php db/run_migration.php <path/to/file.sql>\n");
  exit(2);
}
$sqlFile = $argv[1];
if (!is_file($sqlFile)) {
  fwrite(STDERR, "❌ SQL file not found: $sqlFile\n");
  exit(3);
}

$sql = file_get_contents($sqlFile);
if ($sql === false || $sql === '') {
  fwrite(STDERR, "❌ Cannot read SQL file or file is empty.\n");
  exit(4);
}

$mysqli = new mysqli($host, $user, $pass, $name, $port);
if ($mysqli->connect_errno) {
  fwrite(STDERR, "❌ DB connect error: {$mysqli->connect_error}\n");
  exit(5);
}

// ตั้ง charset
if (!$mysqli->set_charset($charset)) {
  fwrite(STDERR, "⚠️  Cannot set charset to $charset, continuing...\n");
}

echo "➡️  Running SQL: $sqlFile\n";
if (!$mysqli->multi_query($sql)) {
  fwrite(STDERR, "❌ Query error: {$mysqli->error}\n");
  $mysqli->close();
  exit(6);
}
// เดินหน้าเคลียร์ผลลัพธ์ทั้งหมด
do {
  if ($res = $mysqli->store_result()) { $res->free(); }
} while ($mysqli->more_results() && $mysqli->next_result());

if ($mysqli->errno) {
  fwrite(STDERR, "❌ Execution error: {$mysqli->error}\n");
  $mysqli->close();
  exit(7);
}

$mysqli->close();
echo "✅ Done.\n";
