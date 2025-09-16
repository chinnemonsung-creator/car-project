<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Database.php';
header('Content-Type: application/json; charset=utf-8');
try {
  $pdo = Database::pdo();
  $row = $pdo->query('SELECT 1 AS ok')->fetch();
  echo json_encode(['ok'=>true, 'driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                    'server_version'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                    'ping'=>$row['ok'] ?? null]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
