<?php
header('Content-Type: application/json; charset=utf-8');
try {
  require_once __DIR__.'/../../src/Database.php';
  $pdo = Database::getConnection();
  $row = $pdo->query("SELECT 1 AS ok")->fetch();
  echo json_encode(['ok'=>true, 'db'=>($row['ok']??0)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'db_connect_error', 'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
