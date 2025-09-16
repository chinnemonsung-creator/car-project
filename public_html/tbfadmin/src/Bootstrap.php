


<?php



// ---- Load local DB config ----
$cfg = __DIR__ . '/config.local.php';
if (file_exists($cfg)) { 
    require_once $cfg; 
}

/**
 * Bootstrap.php
 * โหลด config, ตั้งค่า timezone, autoload และ global helpers
 */

// โหลด config (ควรมีไฟล์ config.php ใน root โปรเจกต์)
$GLOBALS['config'] = require __DIR__ . '/../config.php';

// ตั้ง timezone
date_default_timezone_set($GLOBALS['config']['app']['timezone'] ?? 'Asia/Bangkok');

// ปิด header ที่ไม่จำเป็น
if (!headers_sent()) {
  header_remove('X-Powered-By');
}

// autoload class จากโฟลเดอร์ src (PSR-4 เบื้องต้น)
spl_autoload_register(function($class){
  $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
  if (is_file($file)) {
    require $file;
  }
});

/* ---------- Global Helper functions ---------- */

/**
 * Return base URL ของ public (ใช้กับ verify.html หรือ API อื่น ๆ)
 */
function app_public_base(): string {
  if (!empty($GLOBALS['config']['app']['public_base'])) {
    return rtrim($GLOBALS['config']['app']['public_base'], '/');
  }
  // เดาจาก Host + public path
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $publicPath = '/tbfadmin/public'; // ปรับตาม structure โปรเจกต์จริง
  return $scheme . '://' . $host . $publicPath;
}


/**
 * Return DLT entry base URL
 */
function dlt_entry_base(): string {
  return $GLOBALS['config']['dlt']['entry_base']
    ?? 'https://reserve.dlt.go.th/reserve/v2/?menu=resv_m&state=';
}


putenv('DLT_START_URL=https://httpbin.org/anything'); // mock
putenv('DLT_START_METHOD=GET');
putenv('DLT_CLIENT_ID=demo-client');
putenv('DLT_SCOPE=openid profile thaiid');
putenv('DLT_RETURN_BASE=https://bellafleur-benly.com/verify/api/callback.php');
putenv('DLT_CALLBACK_SECRET=demo-secret-123');  // เปลี่ยนเป็นค่าเดโม่ใดๆ
putenv('DLT_QR_TTL_SEC=90');                    // อายุ attempt 90s (ทดสอบง่าย)
putenv('DLT_HTTP_TIMEOUT=8');
