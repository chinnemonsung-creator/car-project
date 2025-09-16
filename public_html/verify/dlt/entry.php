<?php
// /verify/dlt/entry.php
declare(strict_types=1);

/*
 * หน้าที่: รับ URL /verify/dlt/entry/{sid}/{n}
 * แล้ว 302 redirect ไปยังหน้า DLT ด้วย state={sid}
 * - ไม่แตะ Database.php
 * - มี log เพื่อ debug ที่ /tbfadmin/var/php_errors.log
 */

@header('Content-Type: text/html; charset=utf-8');

// ---- LOG ----
$varDir = __DIR__ . '/../../tbfadmin/var';
if (!is_dir($varDir)) { @mkdir($varDir, 0775, true); }
@ini_set('log_errors','1');
@ini_set('error_log', $varDir . '/php_errors.log');

$sid = $_GET['sid'] ?? '';
$n   = isset($_GET['n']) ? (int)$_GET['n'] : 0;

// ป้องกันค่าไม่ถูกต้องแบบง่าย ๆ
if ($sid === '' || $n <= 0) {
  error_log("[entry.php] invalid params sid={$sid} n={$n}");
  http_response_code(400);
  echo "Bad request: missing sid or attempt number.";
  exit;
}

// URL ของ DLT จริง
$dltBase = 'https://reserve.dlt.go.th/reserve/v2/?menu=resv_m&state=' . rawurlencode($sid);

// Log ไว้ดูย้อนหลัง
error_log("[entry.php] redirect sid={$sid} n={$n} -> {$dltBase}");

// 302 redirect ไป DLT
header('Location: ' . $dltBase, true, 302);
exit;
