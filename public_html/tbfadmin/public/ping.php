<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok' => true,
  'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
  'uri' => $_SERVER['REQUEST_URI'] ?? '',
]);
