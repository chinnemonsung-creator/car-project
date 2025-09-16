<?php
return [

  // -------------------------------
  // การตั้งค่าแอป
  // -------------------------------
  'app' => [
    'base_path' => '/tbfadmin', // ปล่อยไว้ตามนี้
    'base_url'  => 'https://bellafleur-benly.com/tbfadmin',
    'timezone'  => 'Asia/Bangkok',

    // ชื่อ session สำหรับ admin
    'admin_session_name' => 'bb_admin',

    // base URL ของ public/ (ใช้สร้างลิงก์ verify.html อัตโนมัติ)
    'public_base' => 'https://bellafleur-benly.com/tbfadmin/public',
  ],

  // -------------------------------
  // การตั้งค่า Database
  // -------------------------------
  'db' => [
    'host'    => '127.0.0.1',
    'port'    => 3306,
    'name'    => 'bellafle_dbfan',
    'user'    => 'bellafle_admin',
    'pass'    => 'V%oOSbU432lwiibw',
    'charset' => 'utf8mb4',
  ],

  // -------------------------------
  // การตั้งค่า DLT
  // -------------------------------
  'dlt' => [
    'entry_base' => 'https://reserve.dlt.go.th/reserve/v2/?menu=resv_m&state=',
  ],

  // -------------------------------
  // การตั้งค่า Verify / Share link
  // -------------------------------
  'verify' => [
  'public_base'  => 'https://bellafleur-benly.com/verify', // URL หน้า verify ฝั่งลูกค้า
  'ttl_seconds'  => 180,                                    // อายุลิงก์
],

];
