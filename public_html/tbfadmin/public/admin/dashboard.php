<?php
// /tbfadmin/public/admin/dashboard.php
declare(strict_types=1);

/* ===== Boot & Error log ===== */
@error_reporting(E_ALL);
@ini_set('display_errors','0');
@ini_set('log_errors','1');
$__logDir = __DIR__ . '/../../var';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }
@ini_set('error_log', $__logDir . '/php-error.log');

require __DIR__ . '/../../src/Bootstrap.php';
require_once __DIR__ . '/../../src/Database.php';

/* ===== Auth ===== */
$sessionName = $GLOBALS['config']['app']['admin_session_name'] ?? 'bb_admin';
session_name($sessionName);
if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_set_cookie_params([
    'lifetime'=>0, 'path'=>'/', 'httponly'=>true, 'samesite'=>'Lax',
    'secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'),
  ]);
  @session_start();
}
if (empty($_SESSION['admin']) && empty($_SESSION['admin_logged_in'])) {
  header('Location: /tbfadmin/public/admin/login.php'); exit;
}
$uname = (string)($_SESSION['admin']['username'] ?? ($_SESSION['username'] ?? 'admin'));

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function dt_date(?string $s): string { if(!$s) return '-'; return strtoupper(date('j M Y', strtotime($s))); }
function dt_time(?string $s): string { if(!$s) return '';  return date('H:i', strtotime($s)); }
function badge_html(string $status): string {
  $s = strtolower(trim($status));
  $map = [
    'pending'   => ['#fff7ed','#f59e0b','#fed7aa'],
    'success'   => ['#ecfdf5','#16a34a','#bbf7d0'],
    'expired'   => ['#fef2f2','#e11d48','#fecaca'],
    'confirmed' => ['#ecfeff','#0891b2','#a5f3fc'],
    'failed'    => ['#fef2f2','#ef4444','#fecaca'],
  ];
  if (!isset($map[$s])) $s = 'pending';
  [$bg,$dot,$bd] = $map[$s];
  $label = ucfirst($s);
  return '<span class="st-badge" style="background:'.$bg.';border-color:'.$bd.'"><i style="background:'.$dot.'"></i>'.h($label).'</span>';
}

/* Robust PDO getter (ไม่แตะ Database.php) */
function get_pdo(): PDO {
  if (class_exists('Database')) {
    if (method_exists('Database','getConnection')) { $p = Database::getConnection(); if ($p instanceof PDO) return $p; }
    if (method_exists('Database','getPdo'))        { $p = Database::getPdo();        if ($p instanceof PDO) return $p; }
    try {
      $d = new Database();
      foreach (['getConnection','getPdo','pdo','connection'] as $m) {
        if (method_exists($d,$m)) { $p = $d->$m(); if ($p instanceof PDO) return $p; }
      }
    } catch (Throwable $e) {}
  }
  $cfg = $GLOBALS['config']['db'] ?? null;
  if (is_array($cfg) && !empty($cfg['dsn'])) {
    return new PDO(
      $cfg['dsn'],
      (string)($cfg['username'] ?? $cfg['user'] ?? ''),
      (string)($cfg['password'] ?? $cfg['pass'] ?? ''),
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
  }
  throw new RuntimeException('DB connection unavailable');
}

/* ===== Filters ===== */
$q        = trim((string)($_GET['q'] ?? ''));
$field    = (string)($_GET['field'] ?? 'all'); // all|id|customer|phone|brand|plate|vin
$from     = (string)($_GET['from'] ?? '');
$to       = (string)($_GET['to'] ?? '');
$pagesize = (int)($_GET['limit'] ?? 20);
if ($pagesize <= 0 || $pagesize > 200) $pagesize = 20;

/* Pre-normalize keyword */
$qnorm = $q !== '' ? preg_replace('/[^\p{L}\p{N}]+/u', '', $q) : '';

/* ===== Load from DB ===== */
$rows = []; $total = 0; $err = null; $meta = [];
try {
  $pdo = get_pdo();
  try {
    // เปิด emulate prepares เพื่อให้ใช้ชื่อพารามิเตอร์ซ้ำได้ (แก้ HY093)
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
  } catch (Throwable $e) { @error_log('[dashboard] set emulate prepares failed: '.$e->getMessage()); }

  // discover columns
  $stmt = $pdo->prepare("
    SELECT COLUMN_NAME, DATA_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
  ");
  $stmt->execute();
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $meta[$c['COLUMN_NAME']] = strtolower($c['DATA_TYPE']);
  }
  $has = fn(string $c)=>array_key_exists($c,$meta);

  // build SELECT
  $sel = ['`id`'];

  // customer name supports both (first_name,last_name) and (customer)
  if ($has('customer')) {
    $sel[] = '`customer` AS customer_name';
  } else {
    $f = $has('first_name') ? '`first_name`' : "''";
    $l = $has('last_name')  ? '`last_name`'  : "''";
    $sel[] = "TRIM(CONCAT_WS(' ', $f, $l)) AS customer_name";
  }

  $sel[] = $has('citizen_id') ? '`citizen_id`' : "NULL AS citizen_id";
  $sel[] = $has('phone') ? '`phone`' : "NULL AS phone";
  $sel[] = $has('brand') ? '`brand`' : "NULL AS brand";
  $sel[] = $has('vin') ? '`vin`' : "NULL AS vin";
  $sel[] = $has('status') ? '`status`' : "NULL AS status";
  for($i=1;$i<=6;$i++){ $sel[] = $has("plate{$i}") ? "`plate{$i}`" : "NULL AS `plate{$i}`"; }
  $sel[] = $has('desired_plates') ? '`desired_plates`' : "NULL AS desired_plates";
  $sel[] = $has('verify_token') ? '`verify_token`' : "NULL AS verify_token";
  $sel[] = $has('token') ? '`token`' : "NULL AS token";
  $sel[] = $has('created_at') ? '`created_at`' : "NULL AS created_at";
  // NEW: scheduled_start_at
  $sel[] = $has('scheduled_start_at') ? '`scheduled_start_at`' : "NULL AS scheduled_start_at";

  // WHERE
  $where=[]; $bind=[];

  if ($q !== '') {
    // อนุญาตผูกเงื่อนไข id เฉพาะเคสที่ตั้งใจ: field=id หรือพิมพ์แบบ #123
    $wantIdOnly = ($field === 'id');
    $matchId = null;

    if ($wantIdOnly && ctype_digit($q)) {
      $matchId = (int)$q;
    } elseif (preg_match('/^#(\d{1,12})$/', $q, $m)) {
      $matchId = (int)$m[1];
      $wantIdOnly = true;
    }

    if ($matchId !== null) {
      $where[] = "`id` = :id_eq";
      $bind[':id_eq'] = $matchId;
    }

    // คีย์เวิร์ดทั่วไป
    $bind[':q'] = '%'.$q.'%';
    if ($qnorm !== '') $bind[':qnorm'] = '%'.$qnorm.'%';

    if ($wantIdOnly) {
      // ไม่เพิ่ม where อื่นเพื่อกัน HY093 ซ้ำซ้อน
    } elseif ($field==='customer') {
      if ($has('customer')) {
        $where[]="`customer` LIKE :q";
      } else {
        $likes=[];
        if ($has('first_name')) $likes[]="`first_name` LIKE :q";
        if ($has('last_name'))  $likes[]="`last_name` LIKE :q";
        if($likes) $where[]='('.implode(' OR ',$likes).')';
      }

    } elseif ($field==='phone') {
      $tmp=[];
      if($has('phone')){
        if($qnorm!=='') $tmp[] = "REPLACE(REPLACE(REPLACE(`phone`,'-',''),' ',''),'.','') LIKE :qnorm";
        $tmp[] = "`phone` LIKE :q";
      }
      if($tmp) $where[]='('.implode(' OR ',$tmp).')';

    } elseif ($field==='brand') {
      if($has('brand')) $where[] = "`brand` LIKE :q";

    } elseif ($field==='vin') {
      $tmp=[];
      if($has('vin')){
        if($qnorm!=='') $tmp[] = "REPLACE(REPLACE(REPLACE(`vin`,'-',''),' ',''),'.','') LIKE :qnorm";
        $tmp[] = "`vin` LIKE :q";
      }
      if($tmp) $where[]='('.implode(' OR ',$tmp).')';

    } elseif ($field==='plate') {
      $likes=[];
      for($i=1;$i<=6;$i++){
        if($has("plate{$i}")){
          if($qnorm!=='') $likes[] = "REPLACE(REPLACE(REPLACE(`plate{$i}`,'-',''),' ',''),'.','') LIKE :qnorm";
          $likes[] = "`plate{$i}` LIKE :q";
        }
      }
      if($has('desired_plates')){
        if($qnorm!=='') $likes[] = "REPLACE(REPLACE(REPLACE(`desired_plates`,'-',''),' ',''),'.','') LIKE :qnorm";
        $likes[] = "`desired_plates` LIKE :q";
      }
      if($likes) $where[]='('.implode(' OR ',$likes).')';

    } else {
      // โหมด "ทั้งหมด"
      $likes=[];
      if ($has('customer')) { $likes[]="`customer` LIKE :q"; }
      else {
        if ($has('first_name')) $likes[]="`first_name` LIKE :q";
        if ($has('last_name'))  $likes[]="`last_name` LIKE :q";
      }
      if ($has('brand')) $likes[]="`brand` LIKE :q";

      if ($has('phone')){
        if($qnorm!=='') $likes[]="REPLACE(REPLACE(REPLACE(`phone`,'-',''),' ',''),'.','') LIKE :qnorm";
        $likes[]="`phone` LIKE :q";
      }
      if ($has('vin')){
        if($qnorm!=='') $likes[]="REPLACE(REPLACE(REPLACE(`vin`,'-',''),' ',''),'.','') LIKE :qnorm";
        $likes[]="`vin` LIKE :q";
      }
      if ($has('citizen_id')) $likes[]="`citizen_id` LIKE :q";

      for($i=1;$i<=6;$i++){
        if($has("plate{$i}")){
          if($qnorm!=='') $likes[]="REPLACE(REPLACE(REPLACE(`plate{$i}`,'-',''),' ',''),'.','') LIKE :qnorm";
          $likes[]="`plate{$i}` LIKE :q";
        }
      }
      if ($has('desired_plates')){
        if($qnorm!=='') $likes[]="REPLACE(REPLACE(REPLACE(`desired_plates`,'-',''),' ',''),'.','') LIKE :qnorm";
        $likes[]="`desired_plates` LIKE :q";
      }

      if($likes) $where[]='('.implode(' OR ',$likes).')';
    }
  }

  if ($from!=='' && $has('created_at')) { $where[]='`created_at` >= :from'; $bind[':from']=date('Y-m-d 00:00:00',strtotime($from)); }
  if ($to  !=='' && $has('created_at')) { $where[]='`created_at` <= :to';   $bind[':to']=date('Y-m-d 23:59:59',strtotime($to)); }

  $sqlWhere = $where ? 'WHERE '.implode(' AND ',$where) : '';

  if ($q !== '') {
    @error_log('[dashboard.where] ' . $sqlWhere);
    @error_log('[dashboard.bind] ' . json_encode($bind, JSON_UNESCAPED_UNICODE));
  }

  // count
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM `orders` $sqlWhere");
  foreach($bind as $k=>$v){ $stmt->bindValue($k,$v); }
  $stmt->execute(); $total = (int)$stmt->fetchColumn();

  // list
  $sql = "SELECT ".implode(', ',$sel)." FROM `orders` $sqlWhere ORDER BY `id` DESC LIMIT :lim";
  $stmt = $pdo->prepare($sql);
  foreach($bind as $k=>$v){ $stmt->bindValue($k,$v); }
  $stmt->bindValue(':lim',$pagesize,PDO::PARAM_INT);
  $stmt->execute();
  $rowsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // normalize plates for display
  foreach($rowsRaw as $r){
    $plates=[];
    for($i=1;$i<=6;$i++){ $v=trim((string)($r["plate{$i}"]??'')); if($v!=='') $plates[]=$v; }
    if(!$plates){
      $dp=$r['desired_plates']??null;
      if(is_string($dp)&&$dp!==''){
        if($dp[0]=='['||$dp[0]=='{'){
          $j=json_decode($dp,true); if(is_array($j)) foreach($j as $p){ $p=trim((string)$p); if($p!=='') $plates[]=$p; if(count($plates)>=6) break; }
        }else{
          foreach(preg_split('/,/', $dp) as $p){ $p=trim((string)$p); if($p!=='') $plates[]=$p; if(count($plates)>=6) break; }
        }
      }
    }
    $rows[] = [
      'id'                  => (int)$r['id'],
      'customer'            => trim((string)($r['customer_name'] ?? '')),
      'citizen_id'          => (string)($r['citizen_id'] ?? ''),
      'phone'               => (string)($r['phone'] ?? ''),
      'brand'               => (string)($r['brand'] ?? ''),
      'vin'                 => (string)($r['vin'] ?? ''),
      'status'              => (string)($r['status'] ?? 'Pending'),
      'plates'              => $plates,
      'created_at'          => (string)($r['created_at'] ?? ''),
      'verify_token'        => (string)($r['verify_token'] ?? $r['token'] ?? ''),
      // NEW: pass scheduled_start_at to UI
      'scheduled_start_at'  => (string)($r['scheduled_start_at'] ?? ''),
    ];
  }

} catch(Throwable $e){
  $err = 'โหลดข้อมูลไม่สำเร็จ: '.$e->getMessage();
  error_log('[dashboard] '.$e->getMessage());
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>Orders — Dashboard</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  :root{
    --bg:#f6f7fb; --card:#fff; --text:#1f2937; --muted:#6b7280; --bd:#e5e7eb;
    --primary:#2563eb; --primary-600:#1d4ed8; --danger:#e11d48;
    --green:#16a34a; --blue:#0369a1;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font:14px/1.6 system-ui,-apple-system,Segoe UI,Roboto}
  .wrap{max-width: clamp(1200px, 92vw, 1720px); margin: 24px auto; padding: 0 16px;}
  h1{margin:0 0 14px;font-size:24px}
  .muted{color:var(--muted)}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
  .toolbar{display:flex;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
  .toolbar .input,.toolbar .select{height:36px;border:1px solid var(--bd);border-radius:8px;padding:0 10px;background:#fff}
  .spacer{flex:1}
  .btn{height:36px;border:1px solid var(--bd);border-radius:10px;padding:0 12px;background:#fff;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
  .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}
  .btn-primary:hover{background:var(--primary-600)}
  .btn-ghost{background:#fff;border:1px solid var(--bd);color:#374151}
  .btn-danger{background:var(--danger);border-color:var(--danger);color:#fff}
  a.btn, .btn a, .toolbar a.btn, .row-actions a.btn { text-decoration:none !important; }
  a.btn:hover, a.btn:focus, a.btn:active, a.btn:visited { text-decoration:none !important; color:inherit; outline:none; }
  table{width:100%;border-collapse:separate;border-spacing:0;background:#fff;border:1px solid var(--bd);border-radius:12px;overflow:hidden}
  th,td{padding:10px 12px;border-bottom:1px solid var(--bd);text-align:left;vertical-align:middle}
  th{font-size:13px;color:var(--muted);font-weight:700}
  tr:hover td{background:#fafafa}
  .plates{display:grid;grid-template-columns:repeat(2,max-content);gap:6px 8px}
  .chip{background:#eef2ff;border:1px solid #e0e7ff;color:#3730a3;padding:2px 8px;border-radius:999px;font-size:12px}
  .st-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:999px;border:1px solid rgba(0,0,0,.06);font-weight:600;color:#334155}
  .st-badge i{display:inline-block;width:8px;height:8px;border-radius:999px}
  .row-actions{display:flex;align-items:center;justify-content:space-between;gap:16px}
  .actions-primary,.actions-mark,.actions-danger{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  @media (max-width:1100px){ .row-actions{flex-wrap:wrap} }
  .dropdown { position: relative; }
  .dropdown-toggle { cursor: pointer; }
  .dropdown-menu { position:absolute; top:100%; left:0; min-width:180px; background:#fff; border:1px solid var(--bd); border-radius:10px; padding:6px; margin-top:6px; box-shadow:0 6px 24px rgba(0,0,0,.08); display:none; z-index:30; }
  .dropdown-menu .dropdown-item{display:block;width:100%;text-align:left;background:transparent;border:0;padding:8px 10px;border-radius:8px;cursor:pointer;font:inherit;}
  .dropdown-menu .dropdown-item:hover{ background:#f3f4f6; }
  .bulkbar{display:none;position:sticky;top:0;z-index:2;background:#eef2ff;border:1px solid #c7d2fe;padding:8px 12px;border-radius:10px;margin:10px 0;align-items:center;gap:10px}
  .modal{position:fixed;inset:0;display:none;place-items:center;padding:18px;background:rgba(0,0,0,.34);z-index:50}
  .modal.open{display:grid}
  .dialog{width:min(1100px,96%);background:#fff;border:1px solid var(--bd);border-radius:18px;box-shadow:0 16px 40px rgba(0,0,0,.25);overflow:hidden}
  .dialog-header{padding:14px 16px;border-bottom:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center}
  .dialog-title{font-weight:800}
  .dialog-body{padding:16px;display:grid;grid-template-columns:1.4fr 1fr;gap:16px;align-items:start}
  .dialog-footer{padding:14px 16px;border-top:1px solid var(--bd);display:flex;gap:8px;justify-content:flex-end;background:#fafafa}
  .section{border:1px dashed var(--bd);border-radius:14px;padding:14px}
  .section h3{margin:0 0 10px;font-size:13px;color:#475569;text-transform:uppercase;letter-spacing:.6px}
  .field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
  .label{font-size:12px;color:var(--muted)}
  .control{height:36px;border:1px solid var(--bd);border-radius:10px;padding:0 10px;background:#fff;width:100%}
  .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
  @media (max-width:980px){ .dialog-body{grid-template-columns:1fr} .grid-3{grid-template-columns:1fr 1fr} }
  .compact-dialog{ width:min(520px,92%); }
  .compact-body{ padding:16px; display:flex; flex-direction:column; gap:12px; }
  .compact-section{ border:1px solid var(--bd); border-radius:10px; padding:12px; background:#f9fafb; }
  .toast-wrap{ position:fixed; right:16px; bottom:16px; display:flex; flex-direction:column; gap:8px; z-index:9999; }
  .toast{ min-width:220px; max-width:380px; background:#111827; color:#fff; border-radius:12px; padding:10px 12px; box-shadow:0 8px 30px rgba(0,0,0,.25); font-size:13px; display:flex; align-items:center; gap:10px; opacity:0; transform:translateY(8px); transition:.18s ease; }
  .toast.show{ opacity:1; transform:translateY(0); }
  .toast.success{ background:#065f46; }
  .toast.info{ background:#1f2937; }
  .toast.error{ background:#7f1d1d; }
  .btn-copy:active { transform: scale(.98); }
</style>
</head>
<body>
  <div class="wrap">
    <h1>Orders — Dashboard</h1>

    <!-- toolbar -->
    <form class="toolbar" method="get" action="">
      <input class="input" style="min-width:260px" type="text" name="q" placeholder="ค้นหา: ลูกค้า / เบอร์โทร / ยี่ห้อ / เลขทะเบียน / เลขตัวถัง" value="<?=h($q)?>">
      <select class="select" name="field">
        <?php
         $opts = [
            'all'      =>'ทั้งหมด',
            'id'       =>'รหัส (ID)',
            'customer' =>'ลูกค้า',
            'phone'    =>'เบอร์โทร',
            'brand'    =>'ยี่ห้อ',
            'plate'    =>'เลขทะเบียน',
            'vin'      =>'เลขตัวถัง'
          ];
          foreach($opts as $k=>$v){ $sel=$field===$k?'selected':''; echo "<option value=\"".h($k)."\" $sel>".h($v)."</option>"; }
        ?>
      </select>
      <input class="input" type="date" name="from" value="<?=h($from)?>">
      <input class="input" type="date" name="to"   value="<?=h($to)?>">
      <select class="select" name="limit">
        <?php foreach([10,20,50,100] as $n){$sel=$pagesize===$n?'selected':'';echo"<option $sel value=\"$n\">$n</option>";} ?>
      </select>
      <button class="btn btn-primary" type="submit">ค้นหา</button>
      <a class="btn btn-ghost" href="?">ล้างตัวกรอง</a>
      <div class="spacer"></div>
      <a class="btn btn-primary" id="btn-create" href="#">+ เพิ่มรายการ</a>
      <button type="button" class="btn btn-ghost" style="margin-left:8px" onclick="vm_openTestModal()">TEST</button>
      <a class="btn btn-ghost" href="/tbfadmin/public/admin/change_password.php">เปลี่ยนรหัสผ่าน</a>
      <a class="btn btn-ghost" href="/tbfadmin/public/admin/logout.php">ออกจากระบบ</a>
    </form>

    <!-- Legend -->
    <div style="margin:0 0 8px;color:var(--muted);font-size:12px">
      Legend:
      <?= badge_html('Pending') ?>
      <?= badge_html('Success') ?>
      <?= badge_html('Expired') ?>
      <span style="float:right">size: <?= (int)$total ?></span>
    </div>

    <!-- Bulk bar -->
    <div id="bulkbar" class="bulkbar">
      <div class="mono" id="bulkcount">เลือก 0 รายการ</div>
      <button id="bulkdel" class="btn btn-danger">ลบรายการที่เลือก</button>
      <button id="bulkselclear" class="btn">ยกเลิกการเลือก</button>
    </div>

    <!-- Table -->
    <table id="ordertable">
      <thead>
        <tr>
          <th style="width:36px"><input type="checkbox" id="chkAll"></th>
          <th style="width:64px">ID</th>
          <th>ลูกค้า</th>
          <th style="width:140px">เบอร์โทร</th>
          <th style="width:110px">ยี่ห้อ</th>
          <th>ป้ายที่ต้องการ</th>
          <th style="width:140px">สถานะ</th>
          <th style="width:160px">Scheduled start</th>
          <th style="width:130px">สร้างเมื่อ</th>
          <th style="min-width:520px">จัดการ</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="muted">ไม่มีข้อมูล</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr data-id="<?=$r['id']?>" data-row="order" data-sid="">
            <td><input type="checkbox" class="rowchk" value="<?=$r['id']?>"></td>
            <td class="mono"><?= (int)$r['id'] ?></td>
            <td><?= h($r['customer'] ?: '-') ?></td>
            <td class="mono"><?= h($r['phone'] ?: '-') ?></td>
            <td><?= h($r['brand'] ?: '-') ?></td>
            <td>
              <div class="plates">
                <?php if ($r['plates']): foreach ($r['plates'] as $p): ?>
                  <span class="chip"><?= h($p) ?></span>
                <?php endforeach; else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <div><?= badge_html((string)$r['status']) ?></div>
              <div class="mono" style="color:#64748b;margin-top:6px">
                <span>SID: <span data-role="attempt-sid">-</span></span>
                <span> · สถานะ: <span class="st-badge" style="padding:2px 8px;border-radius:999px;border:1px solid #e5e7eb;background:#f8fafc" data-role="attempt-status">-</span></span>
                <span> · TTL: <span data-role="attempt-ttl">-</span>s</span>
              </div>
            </td>
            <td class="mono">
              <?php if (!empty($r['scheduled_start_at'])): ?>
                <?= dt_date($r['scheduled_start_at']) ?><br><?= dt_time($r['scheduled_start_at']) ?>
              <?php else: ?>
                <span class="muted">-</span>
              <?php endif; ?>
            </td>
            <td class="mono">
              <?= dt_date($r['created_at']) ?><br><?= dt_time($r['created_at']) ?>
            </td>
            <td>
              <div class="row-actions" data-order="<?= (int)$r['id'] ?>">
                <div class="actions-primary">
                  <a href="#" class="btn btn-primary act-send"   data-order="<?= (int)$r['id'] ?>">ส่งให้ลูกค้ายืนยัน</a>
                  <a href="#" class="btn btn-ghost  act-status" data-order="<?= (int)$r['id'] ?>">ตรวจสถานะ</a>
                  <a href="#" class="btn btn-ghost  act-renew"  data-order="<?= (int)$r['id'] ?>">ต่ออายุ</a>
                  <a href="#" class="btn btn-ghost  act-edit"
                     data-token="<?=h($r['verify_token'] ?? '')?>"
                     data-citizen="<?=h($r['citizen_id'])?>"
                     data-customer="<?=h($r['customer'])?>"
                     data-phone="<?=h($r['phone'])?>"
                     data-brand="<?=h($r['brand'])?>"
                     data-vin="<?=h($r['vin'])?>"
                     data-scheduled="<?=h($r['scheduled_start_at'] ?? '')?>"
                  >ดู/แก้ไข</a>
                </div>

                <div class="actions-mark dropdown" data-role="dropdown">
                  <button type="button" class="btn btn-ghost dropdown-toggle" data-role="mark-menu-toggle">Mark ▼</button>
                  <div class="dropdown-menu">
                    <button class="dropdown-item" data-action="mark" data-status="confirmed">Mark Confirmed</button>
                    <button class="dropdown-item" data-action="mark" data-status="success">Mark Success</button>
                    <button class="dropdown-item" data-action="mark" data-status="fail">Mark Fail</button>
                  </div>
                </div>

                <div class="actions-danger">
                  <a href="#" class="btn btn-danger act-del">ลบ</a>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ===== Base Modal (reusable) ===== -->
  <div class="modal" id="modal">
    <div class="dialog" role="dialog" aria-modal="true">
      <div class="dialog-header">
        <div class="dialog-title" id="dialogTitle">เพิ่ม Order</div>
        <button class="btn btn-ghost" id="btnCloseModal">ปิด</button>
      </div>
      <div class="dialog-body" id="dialogBody"></div>
      <div class="dialog-footer" id="dialogFoot"></div>
    </div>
  </div>

  <div class="toast-wrap" id="toastWrap"></div>

<script>
const ORD_API    = '/tbfadmin/public/api/v1/orders.php';
const VERIFY_API = '/tbfadmin/public/api/v1/verify.php';

const $ = (sel, root=document) => root.querySelector(sel);
const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));
const modal = $('#modal'), mTitle = $('#dialogTitle'), mBody = $('#dialogBody'), mFoot = $('#dialogFoot');
const openModal  = () => modal.classList.add('open');
const closeModal = () => { modal.classList.remove('open'); mBody.innerHTML=''; mFoot.innerHTML=''; };

$('#btnCloseModal').addEventListener('click', closeModal);
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

async function postJSON(url, body){
  const res = await fetch(url, {
    method:'POST',
    headers:{'Content-Type':'application/json','Accept':'application/json'},
    credentials:'same-origin',
    body: JSON.stringify(body)
  });
  if(!res.ok){
    let msg = 'HTTP '+res.status;
    try{ const j = await res.json(); if(j && j.error) msg += ' — '+j.error; }catch(e){}
    throw new Error(msg);
  }
  return res.json();
}

async function copyWithFeedback(btnEl, text){
  try{
    if(navigator.clipboard && window.isSecureContext){
      await navigator.clipboard.writeText(text);
    }else{
      const ta=document.createElement('textarea'); ta.value=text;
      document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
    }
    const old = btnEl.textContent;
    btnEl.disabled = true; btnEl.textContent = 'คัดลอกแล้ว'; btnEl.style.opacity = .9;
    setTimeout(()=>{ btnEl.disabled=false; btnEl.textContent=old; btnEl.style.opacity=''; }, 900);
    showToast('คัดลอกลิงก์แล้ว', 'success');
  }catch(e){
    alert('คัดลอกไม่สำเร็จ: ' + e.message);
  }
}

function showToast(msg, type='info', ms=1800){
  const wrap = $('#toastWrap');
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.textContent = msg;
  wrap.appendChild(el);
  requestAnimationFrame(()=> el.classList.add('show'));
  setTimeout(()=>{ el.classList.remove('show'); setTimeout(()=>el.remove(),180); }, ms);
}

/* ===== Utils for datetime-local ===== */
function toInputDT(s){
  if(!s) return '';
  // รับรูปแบบ "YYYY-MM-DD HH:MM[:SS]" หรือ "YYYY-MM-DDTHH:MM[:SS]" -> "YYYY-MM-DDTHH:MM"
  const t = s.replace('T',' ').trim();
  const m = t.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/);
  return m ? `${m[1]}T${m[2]}` : '';
}

/* ===== Create ===== */
$('#btn-create').addEventListener('click', e=>{
  e.preventDefault();
  mTitle.textContent = 'เพิ่ม Order';
  mBody.innerHTML = `
    <div class="section">
      <h3>ข้อมูลลูกค้า</h3>
      <div class="field"><label class="label">ชื่อ</label><input class="control" id="c-first" placeholder="เช่น Somchai"></div>
      <div class="field"><label class="label">สกุล</label><input class="control" id="c-last" placeholder="เช่น Dee"></div>
      <div class="grid-2">
        <div class="field"><label class="label">เลขบัตรประชาชน</label><input class="control" id="c-citizen" placeholder="13 หลัก"></div>
        <div class="field"><label class="label">เบอร์โทร</label><input class="control" id="c-phone" placeholder="0891234567"></div>
      </div>
    </div>

    <div class="section">
      <h3>ข้อมูลรถ</h3>
      <div class="field"><label class="label">ยี่ห้อ</label><input class="control" id="c-brand" placeholder="Toyota / Honda ..."></div>
      <div class="field"><label class="label">เลขตัวถัง</label><input class="control" id="c-vin" placeholder="VIN"></div>
    </div>

    <div class="section">
      <h3>เลขทะเบียนที่ต้องการ</h3>
      <div class="grid-3">
        <input class="control" id="c-p1" placeholder="ป้าย 1">
        <input class="control" id="c-p2" placeholder="ป้าย 2">
        <input class="control" id="c-p3" placeholder="ป้าย 3">
        <input class="control" id="c-p4" placeholder="ป้าย 4">
        <input class="control" id="c-p5" placeholder="ป้าย 5">
        <input class="control" id="c-p6" placeholder="ป้าย 6">
      </div>
    </div>

    <div class="section">
      <h3>ระบบ</h3>
      <div class="grid-2">
        <div class="field"><label class="label">Session</label><input class="control" id="c-session" value="AUTO-${Date.now()}" readonly></div>
        <div class="field">
          <label class="label">สถานะ</label>
          <select class="control" id="c-status">
            <option value="Pending">Pending</option>
            <option value="Success">Success</option>
            <option value="Expired">Expired</option>
          </select>
        </div>
      </div>

      <div class="field">
        <label class="label">กำหนดวันนัดเริ่ม (Scheduled start)</label>
        <input class="control" id="c-scheduled" type="datetime-local" value="">
        <small class="hint">ระบบจะยังไม่เริ่ม session จนกว่าจะกด Start (หรือ Auto-start)</small>
      </div>

      <div class="field"><label class="label">วันที่สร้าง</label><input class="control" id="c-created" value="<?= date('Y-m-d H:i') ?>"></div>
    </div>
  `;
  mFoot.innerHTML = '';
  const btnSave = document.createElement('button');
  btnSave.className = 'btn btn-primary'; btnSave.textContent = 'บันทึก';
  btnSave.onclick = async ()=>{
    try{
      const payload = {
        action:'create_order',
        first_name: $('#c-first').value.trim(),
        last_name:  $('#c-last').value.trim(),
        citizen_id: $('#c-citizen').value.trim(),
        phone:      $('#c-phone').value.trim(),
        brand:      $('#c-brand').value.trim(),
        vin:        $('#c-vin').value.trim(),
        status:     $('#c-status').value,
        created_at: $('#c-created').value,
        session_id: $('#c-session').value,
        scheduled_start_at: ($('#c-scheduled').value || '').replace('T',' '),
        plates: ['#c-p1','#c-p2','#c-p3','#c-p4','#c-p5','#c-p6'].map(s=>$(s).value.trim()).filter(Boolean)
      };
      const r = await postJSON(ORD_API, payload);
      if(!r.ok) throw new Error(r.error||'create_fail');
      location.reload();
    }catch(err){ alert('เพิ่มไม่สำเร็จ: '+err.message); }
  };
  const btnCancel = document.createElement('button');
  btnCancel.className='btn'; btnCancel.textContent='ยกเลิก'; btnCancel.onclick=closeModal;
  mFoot.append(btnSave, btnCancel);
  openModal();
});

/* ===== Edit ===== */
function openEdit(tr){
  const id  = tr.dataset.id;
  const btn = tr.querySelector('.act-edit');
  const curr = {
    customer:   btn?.dataset.customer || '',
    citizen:    btn?.dataset.citizen  || '',
    phone:      btn?.dataset.phone    || '',
    brand:      btn?.dataset.brand    || '',
    vin:        btn?.dataset.vin      || '',
    scheduled:  btn?.dataset.scheduled || '',
    plates:     $$('.chip', tr).map(x=>x.textContent.trim())
  };
  const parts = (curr.customer||'').split(' ');
  const first = parts.shift() || '';
  const last  = parts.join(' ');

  mTitle.textContent = `ดู/แก้ไข Order #${id}`;
  mBody.innerHTML = `
    <div class="section">
      <h3>ข้อมูลลูกค้า</h3>
      <div class="field"><label class="label">ชื่อ</label><input class="control" id="e-first" value="${first}"></div>
      <div class="field"><label class="label">สกุล</label><input class="control" id="e-last" value="${last}"></div>
      <div class="grid-2">
        <div class="field"><label class="label">เลขบัตรประชาชน</label><input class="control" id="e-citizen" value="${curr.citizen}"></div>
        <div class="field"><label class="label">เบอร์โทร</label><input class="control" id="e-phone" value="${curr.phone}"></div>
      </div>
    </div>

    <div class="section">
      <h3>ข้อมูลรถ</h3>
      <div class="field"><label class="label">ยี่ห้อ</label><input class="control" id="e-brand" value="${curr.brand}"></div>
      <div class="field"><label class="label">เลขตัวถัง</label><input class="control" id="e-vin" value="${curr.vin}"></div>
    </div>

    <div class="section">
      <h3>เลขทะเบียนที่ต้องการ</h3>
      <div class="grid-3">
        <input class="control" id="e-p1" value="${curr.plates[0]||''}">
        <input class="control" id="e-p2" value="${curr.plates[1]||''}">
        <input class="control" id="e-p3" value="${curr.plates[2]||''}">
        <input class="control" id="e-p4" value="${curr.plates[3]||''}">
        <input class="control" id="e-p5" value="${curr.plates[4]||''}">
        <input class="control" id="e-p6" value="${curr.plates[5]||''}">
      </div>
    </div>

    <div class="section">
      <h3>ระบบ</h3>
      <div class="grid-2">
        <div class="field"><label class="label">Session</label><input class="control" id="e-session" value="AUTO-${Date.now()}" readonly></div>
        <div class="field">
          <label class="label">สถานะ</label>
          <select class="control" id="e-status">
            <option value="Pending">Pending</option>
            <option value="Success">Success</option>
            <option value="Expired">Expired</option>
          </select>
        </div>
      </div>

      <div class="field">
        <label class="label">กำหนดวันนัดเริ่ม (Scheduled start)</label>
        <input class="control" id="e-scheduled" type="datetime-local" value="${toInputDT(curr.scheduled)}">
        <small class="hint">ระบบจะยังไม่เริ่ม session จนกว่าจะกด Start (หรือ Auto-start)</small>
      </div>

      <div class="field"><label class="label">วันที่แก้ไข</label><input class="control" id="e-updated" value="<?= date('Y-m-d H:i') ?>"></div>
    </div>
  `;
  mFoot.innerHTML = '';
  const btnSave = document.createElement('button');
  btnSave.className='btn btn-primary'; btnSave.textContent='บันทึก';
  btnSave.onclick = async ()=>{
    try{
      const payload = {
        action:'update_order', id,
        first_name: $('#e-first').value.trim(),
        last_name:  $('#e-last').value.trim(),
        citizen_id: $('#e-citizen').value.trim(),
        phone:      $('#e-phone').value.trim(),
        brand:      $('#e-brand').value.trim(),
        vin:        $('#e-vin').value.trim(),
        status:     $('#e-status').value,
        updated_at: $('#e-updated').value,
        session_id: $('#e-session').value,
        scheduled_start_at: ($('#e-scheduled').value || '').replace('T',' '),
        plates: ['#e-p1','#e-p2','#e-p3','#e-p4','#e-p5','#e-p6'].map(s=>$(s).value.trim()).filter(Boolean)
      };
      const r = await postJSON(ORD_API, payload);
      if(!r.ok) throw new Error(r.error||'update_fail');
      location.reload();
    }catch(err){ alert('บันทึกไม่สำเร็จ: '+err.message); }
  };
  const btnCancel = document.createElement('button');
  btnCancel.className='btn'; btnCancel.textContent='ยกเลิก'; btnCancel.onclick=closeModal;
  mFoot.append(btnSave, btnCancel);
  openModal();
}

/* ===== Delete & Bulk ===== */
const chkAll = $('#chkAll');
const bulkbar = $('#bulkbar');
const bulkcount = $('#bulkcount');
const bulkdel = $('#bulkdel');
const bulkselclear = $('#bulkselclear');

const selectedIds = () => $$('.rowchk:checked').map(el=>el.value);
function updateBulkUI(){
  const n = selectedIds().length;
  bulkcount.textContent = `เลือก ${n} รายการ`;
  bulkbar.style.display = n>0 ? 'flex' : 'none';
}
document.addEventListener('change',(e)=>{
  if(e.target.id==='chkAll'){
    $$('.rowchk').forEach(el=>el.checked = e.target.checked);
    updateBulkUI();
  }
  if(e.target.classList.contains('rowchk')){
    const all = $$('.rowchk'), ch = $$('.rowchk:checked');
    chkAll.checked = (all.length>0 && ch.length===all.length);
    updateBulkUI();
  }
});

async function deleteIds(ids){
  try{
    const r = await postJSON(ORD_API, {action:'bulk_delete_orders', ids});
    if(r && r.ok) return true;
    throw new Error(r && r.error ? r.error : 'bulk_not_ok');
  }catch(e){
    for(const id of ids){
      const r = await postJSON(ORD_API, {action:'delete_order', id});
      if(!(r && r.ok)) throw new Error(r && r.error ? r.error : 'delete_fail_'+id);
    }
    return true;
  }
}
document.addEventListener('click',async e=>{
  const t=e.target;
  if(t.classList.contains('act-del')){
    e.preventDefault();
    const tr=t.closest('tr'); if(!tr) return;
    const id=tr.dataset.id;
    if(!confirm('ลบรายการนี้หรือไม่?')) return;
    try{
      await deleteIds([id]); tr.remove(); updateBulkUI();
    }catch(err){ alert('ลบไม่สำเร็จ: '+err.message); }
  }
  if(t.classList.contains('act-edit')){
    e.preventDefault();
    const tr=t.closest('tr'); if(tr) openEdit(tr);
  }
});

/* ===== Verify workflow (ส่งลิงก์/สถานะ/ต่ออายุ) ===== */
document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.act-send');
  if (!btn) return;
  ev.preventDefault();
  const oid = parseInt(btn.dataset.order || '0', 10);
  if (!oid) { alert('ไม่พบเลขออเดอร์'); return; }
  try{
    const res = await postJSON(VERIFY_API, { action:'ensure_session', order_id: oid });
    if (!res.ok) { alert('สร้างลิงก์ไม่สำเร็จ: ' + (res.error || 'unknown')); return; }
    const verifyPath = res.data?.verify_url;
    if (!verifyPath) { alert('สำเร็จ แต่ไม่พบ verify_url'); return; }
    const fullUrl = verifyPath.startsWith('http') ? verifyPath : (location.origin + verifyPath);
    openVerifyModal(fullUrl, res.data?.status, res.data?.expires_at);
  }catch(err){ alert('เกิดข้อผิดพลาด: ' + err.message); }
});

document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.act-status');
  if (!btn) return;
  ev.preventDefault();
  const oid = parseInt(btn.dataset.order || '0', 10);
  if (!oid) { alert('ไม่พบเลขออเดอร์'); return; }
  try{
    const res = await postJSON(VERIFY_API, { action:'ensure_session', order_id: oid });
    if (!res.ok) { alert('ดึงสถานะไม่ได้: ' + (res.error || 'unknown')); return; }
    const token = res.data?.token;
    if (!token) { alert('ไม่พบ token'); return; }
    const url = '/verify/api/status.php?token=' + encodeURIComponent(token) + '&view=html';
    window.open(url, '_blank', 'noopener');
  }catch(err){ alert('เกิดข้อผิดพลาด: ' + err.message); }
});

document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.act-renew');
  if (!btn) return;
  ev.preventDefault();
  const oid = parseInt(btn.dataset.order || '0', 10);
  if (!oid) { alert('ไม่พบเลขออเดอร์'); return; }
  try{
    const r1 = await postJSON(VERIFY_API, { action:'ensure_session', order_id: oid });
    if (!r1.ok) { alert('ขอ token ไม่ได้: ' + (r1.error || 'unknown')); return; }
    const token = r1.data?.token;
    if (!token) { alert('ไม่พบ token'); return; }
    const r2 = await postJSON(VERIFY_API, { action:'renew_attempt', token });
    if (!r2.ok) { alert('ต่ออายุไม่สำเร็จ: ' + (r2.error || 'unknown')); return; }
    const entryPath = r2.data?.entry_url || '';
    const fullEntry = entryPath && entryPath.startsWith('/') ? (location.origin + entryPath) : entryPath;
    openRenewModal(fullEntry, r2.data?.status, r2.data?.expires_at);
  }catch(err){ alert('เกิดข้อผิดพลาด: ' + err.message); }
});

/* ===== Mark dropdown ===== */
document.addEventListener('click', function (e) {
  const toggle = e.target.closest('[data-role="mark-menu-toggle"]');
  const allMenus = document.querySelectorAll('.dropdown-menu');
  if (!toggle) { allMenus.forEach(m => m.style.display = 'none'); return; }
  e.preventDefault();
  e.stopPropagation();
  const dd = toggle.closest('.dropdown');
  const menu = dd.querySelector('.dropdown-menu');
  allMenus.forEach(m => { if (m !== menu) m.style.display = 'none'; });
  menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
}, false);

async function fetchLatestSidForOrder(orderId){
  const r1 = await postJSON(VERIFY_API, { action:'ensure_session', order_id: orderId });
  if(!r1.ok) throw new Error(r1.error || 'ensure_failed');
  const token = r1.data?.token;
  if(!token) throw new Error('no_token');
  const res = await fetch('/verify/api/status.php?token=' + encodeURIComponent(token), { credentials:'same-origin' });
  const j = await res.json().catch(()=>null);
  const sid = j && j.attempt && j.attempt.sid ? j.attempt.sid : null;
  return { token, sid };
}
async function callMark(sid, status){
  const resp = await fetch('/verify/api/mark.php', {
    method:'POST', headers: { 'Content-Type':'application/json' },
    body: JSON.stringify({ sid, status })
  });
  const data = await resp.json().catch(()=>({}));
  if(!resp.ok || !data.ok){
    throw new Error((data && data.error) ? data.error : ('HTTP_'+resp.status));
  }
  return data;
}
document.addEventListener('click', async (e)=>{
  const item = e.target.closest('.dropdown-item[data-action="mark"]');
  if(!item) return;
  e.preventDefault();
  const row = item.closest('.row-actions');
  const orderId = parseInt(row?.getAttribute('data-order') || '0', 10);
  const status = item.getAttribute('data-status');
  if(!orderId || !status){ alert('Missing orderId or status'); return; }
  try{
    const { sid } = await fetchLatestSidForOrder(orderId);
    if(!sid){
      alert('ยังไม่มี attempt (sid) ให้ไปกด "เริ่มยืนยัน" ในหน้าตรวจสถานะก่อน');
      return;
    }
    const data = await callMark(sid, status);
    showToast(`Marked "${data.status}" (sid: ${String(sid).substring(0,8)}…)`, 'success');
  }catch(err){
    console.error('mark failed:', err);
    showToast('Mark failed: '+err.message, 'error', 2400);
  }finally{
    const menu = item.closest('.dropdown-menu'); if(menu) menu.style.display='none';
  }
});

/* ===== Renew countdown & Modals ===== */
let renewTimer  = null;
function fmtRemain(ms){
  if(ms <= 0) return 'หมดอายุแล้ว';
  const s = Math.floor(ms/1000);
  const d = Math.floor(s/86400);
  const h = Math.floor((s%86400)/3600);
  const m = Math.floor((s%3600)/60);
  const sec = s%60;
  if(d>0) return `เหลือ ${d} วัน ${h} ชม.`;
  if(h>0) return `เหลือ ${h} ชม. ${m} นาที`;
  if(m>0) return `เหลือ ${m} นาที ${sec} วิ`;
  return `เหลือ ${sec} วิ`;
}
function startCountdown(targetISO, elRemain){
  if(!targetISO){ elRemain.textContent=''; return null; }
  const target = new Date(targetISO.replace(' ', 'T'));
  if(isNaN(target)) { elRemain.textContent=''; return null; }
  function tick(){ elRemain.textContent = ' · ' + fmtRemain(target.getTime() - Date.now()); }
  tick();
  return setInterval(tick, 1000);
}
function openVerifyModal(url, status, expires){
  $('#verifyUrlBox').value = url || '';
  $('#verifyStatus').textContent = status || '-';
  $('#verifyExpire').textContent = expires || '-';
  $('#verifyRemain').textContent = '';
  $('#verifyModal').classList.add('open');
}
function closeVerifyModal(){ $('#verifyModal').classList.remove('open'); }
async function copyVerifyUrl(btn){ const url = $('#verifyUrlBox').value; if(!url) return; await copyWithFeedback(btn, url); }
function openRenewModal(url, status, expires){
  $('#renewUrlBox').value = url || '';
  $('#renewStatus').textContent = status || '-';
  $('#renewExpire').textContent = expires || '-';
  $('#renewRemain').textContent = '';
  $('#renewModal').classList.add('open');
  if(renewTimer) clearInterval(renewTimer);
  const target = FORCE_TTL_SEC ? new Date(Date.now() + FORCE_TTL_SEC*1000).toISOString() : expires;
  renewTimer = startCountdown(target, $('#renewRemain'));
}
function closeRenewModal(){ $('#renewModal').classList.remove('open'); if(renewTimer){ clearInterval(renewTimer); renewTimer=null; } }
async function copyRenewUrl(btn){ const url = $('#renewUrlBox').value; if(!url) return; await copyWithFeedback(btn, url); }
</script>

<!-- ===== Compact Verify Modal ===== -->
<div class="modal" id="verifyModal">
  <div class="dialog compact-dialog" role="dialog" aria-modal="true" aria-labelledby="verifyTitle">
    <div class="dialog-header">
      <div class="dialog-title" id="verifyTitle">ลิงก์ยืนยันตัวตน</div>
      <button class="btn btn-ghost" onclick="closeVerifyModal()">ปิด</button>
    </div>
    <div class="compact-body">
      <div class="compact-section" style="background:#ecfdf5;border-color:#bbf7d0">
        <div id="verifyMsg" style="color:var(--green);font-weight:600">
          สร้างลิงก์เรียบร้อย — ส่งให้ลูกค้าเพื่อเริ่มยืนยันตัวตน
        </div>
      </div>
      <div class="compact-section">
        <label class="label">Verify URL</label>
        <div style="display:flex;gap:6px">
          <input id="verifyUrlBox" class="control mono" readonly>
          <button class="btn btn-primary btn-copy" onclick="copyVerifyUrl(this)">คัดลอก</button>
        </div>
        <div style="margin-top:8px;font-size:13px;color:#555">
          สถานะ: <span id="verifyStatus" class="mono">-</span> ·
          หมดอายุ: <span id="verifyExpire" class="mono">-</span>
          <span id="verifyRemain" class="mono" style="color:#334155"></span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ===== Compact Renew Modal ===== -->
<div class="modal" id="renewModal">
  <div class="dialog compact-dialog" role="dialog" aria-modal="true" aria-labelledby="renewTitle">
    <div class="dialog-header">
      <div class="dialog-title" id="renewTitle">ออก <b>Attempt</b> ใหม่</div>
    <button class="btn btn-ghost" onclick="closeRenewModal()">ปิด</button>
    </div>
    <div class="compact-body">
      <div class="compact-section" style="background:#f0f9ff;border-color:#bae6fd">
        <div id="renewMsg" style="color:var(--blue);font-weight:600">
          ต่ออายุสำเร็จ — ระบบออก Attempt ใหม่ให้แล้ว
        </div>
      </div>
      <div class="compact-section">
        <label class="label">Entry URL</label>
        <div style="display:flex;gap:6px">
          <input id="renewUrlBox" class="control mono" readonly>
          <button class="btn btn-primary btn-copy" onclick="copyRenewUrl(this)">คัดลอก</button>
        </div>
        <div style="margin-top:8px;font-size:13px;color:#555">
          สถานะ: <span id="renewStatus" class="mono">-</span> ·
          หมดอายุ: <span id="renewExpire" class="mono">-</span>
          <span id="renewRemain" class="mono" style="color:#334155"></span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ========== TEST MODAL ========== -->
<style>
  .test-modal-backdrop{ position:fixed; inset:0; background:rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; z-index:9998; }
  .test-modal{ width: min(1000px, 96vw); height: min(680px, 92vh); background:#fff; border-radius:14px; box-shadow:0 12px 36px rgba(0,0,0,.2); display:flex; flex-direction:column; overflow:hidden; position:relative; }
  .test-modal header{ padding:10px 14px; border-bottom:1px solid #eef1f4; display:flex; align-items:center; justify-content:space-between; font-weight:600; }
  .test-modal header .close{ background:#ef4444; color:#fff; border:0; border-radius:10px; padding:6px 10px; cursor:pointer; }
  .test-modal iframe{ flex:1; width:100%; border:0; background:#f7f7f9; }
</style>

<div id="testModalBackdrop" class="test-modal-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="test-modal">
    <header>
      <div>Test POST <code>/tbfadmin/public/api/v1/orders.php</code></div>
      <button class="close" onclick="vm_closeTestModal()">ปิด</button>
    </header>
    <iframe id="testModalFrame" src="/tbfadmin/public/api/v1/test.html" loading="lazy"></iframe>
  </div>
</div>

<script>
  const FORCE_TTL_SEC = 180;
  function vm_openTestModal(){
    var el = document.getElementById('testModalBackdrop'); if(!el) return;
    el.style.display = 'flex'; el.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden';
  }
  function vm_closeTestModal(){
    var el = document.getElementById('testModalBackdrop'); if(!el) return;
    el.style.display = 'none'; el.setAttribute('aria-hidden', 'true'); document.body.style.overflow = '';
  }
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape'){ vm_closeTestModal(); }});
  document.getElementById('testModalBackdrop')?.addEventListener('click', function(e){ if(e.target === this){ vm_closeTestModal(); }});
</script>
<!-- ========== /TEST MODAL ========== -->

</body>
</html>
