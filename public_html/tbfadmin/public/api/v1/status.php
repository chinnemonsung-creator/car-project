<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$sid = $_GET['sid'] ?? '';
if (!$sid) { echo json_encode(['ok'=>false,'error'=>'missing_sid']); exit; }

$DB_HOST='localhost'; $DB_NAME='bellafle_dbfan'; $DB_USER='bellafle_admin'; $DB_PASS='V%oOSbU432lwiibw';
try{
  $pdo=new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
}catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_connect_error']); exit;
}

$stmt=$pdo->prepare("SELECT order_id,status,expires_at,updated_at FROM verify_sessions WHERE session_id=:sid LIMIT 1");
$stmt->execute([':sid'=>$sid]);
$row=$stmt->fetch();

if(!$row){ echo json_encode(['ok'=>false,'error'=>'sid_not_found']); exit; }

echo json_encode([
  'ok'=>true,
  'sid'=>$sid,
  'order_id'=>$row['order_id'],
  'status'=>$row['status'],
  'expires_at'=>$row['expires_at'],
  'updated_at'=>$row['updated_at']
], JSON_UNESCAPED_UNICODE);
