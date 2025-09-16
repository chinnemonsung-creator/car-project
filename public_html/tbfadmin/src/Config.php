<?php
// อย่า commit ลง git ถ้ามีรหัสจริง
const DB_HOST = 'localhost';
const DB_NAME = 'bellafle_dbfan';
const DB_USER = 'bellafle_admin';
const DB_PASS = 'V%oOSbU432lwiibw';

/* ---------- DLT CONFIG ---------- */
const DLT_ENTRY_BASE   = 'https://reserve.dlt.go.th/reserve/v2/?menu=resv_m&state=';
const DLT_CLIENT_ID    = 'YOUR_CLIENT_ID';        // เปลี่ยนเป็นค่าจริง
const DLT_REDIRECT_URI = 'https://bellafleur-benly.com/verify/callback'; 
const DLT_ATTEMPT_TTL  = 60; // วินาที (ค่า default อายุของ attempt)
