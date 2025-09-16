<?php
require __DIR__ . '/../src/Bootstrap.php';
require_once __DIR__ . '/../src/Database.php';

header('Content-Type: text/plain; charset=utf-8');

try {
  $db  = new Database($GLOBALS['config']['db']);
  $pdo = $db->pdo();
  echo "OK: Connected\n";

  // ลอง query เบา ๆ
  $stmt = $pdo->query('SELECT 1 AS ok');
  print_r($stmt->fetch());
} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: " . $e->getMessage() . "\n";
}
