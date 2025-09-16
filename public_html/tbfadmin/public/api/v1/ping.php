<?php
// /tbfadmin/public/api/v1/ping.php
declare(strict_types=1);

@ini_set('log_errors','1');
$__logDir = __DIR__ . '/../../../var';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }
@ini_set('error_log', $__logDir . '/php-error.log');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

echo json_encode([
  'ok'   => true,
  'pong' => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
