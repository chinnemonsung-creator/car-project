<?php
// /tbfadmin/public/api/v1/orders.php
declare(strict_types=1);

@ini_set('log_errors','1');
$__logDir = __DIR__ . '/../../../var';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }
@ini_set('error_log', $__logDir . '/php-error.log');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') { http_response_code(204); exit; }

/* ---------- GET ping ---------- */
if ($method === 'GET') {
  echo json_encode(['ok'=>true,'pong'=>date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- helpers (no DB) ---------- */
function jexit(array $arr, int $code=200): void {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}
function htrim($s): string { return trim((string)$s); }
function only_digits(string $s): string { return preg_replace('/\D+/', '', $s); }

/** รวมทุกแหล่ง plate -> array สูงสุด 6 รายการ */
function gather_plates(array $in): array {
  $out = [];

  // 1) มาจาก array "plates"
  if (isset($in['plates']) && is_array($in['plates'])) {
    foreach ($in['plates'] as $p) {
      $p = trim((string)$p);
      if ($p !== '') $out[] = $p;
      if (count($out) >= 6) break;
    }
  }

  // 2) มาจาก plate1..plate6
  for ($i=1; $i<=6 && count($out)<6; $i++) {
    $k = 'plate'.$i;
    if (!empty($in[$k])) {
      $p = trim((string)$in[$k]);
      if ($p!=='') $out[] = $p;
    }
  }

  // 3) มาจาก desired_plates (textarea string: คั่นด้วยบรรทัดหรือ comma)
  if (count($out) < 6 && !empty($in['desired_plates']) && is_string($in['desired_plates'])) {
    $parts = preg_split('/[\r\n,]+/u', (string)$in['desired_plates']);
    foreach ($parts as $p) {
      $p = trim((string)$p);
      if ($p!=='') $out[] = $p;
      if (count($out) >= 6) break;
    }
  }

  // คัดกรองอักขระพื้นฐานและจำกัดความยาว
  $clean = [];
  foreach ($out as $p) {
    if (!preg_match('/^[A-Za-z0-9ก-ฮ\-\/\s]+$/u', $p)) continue;
    $clean[] = mb_substr($p, 0, 32, 'UTF-8');
    if (count($clean) >= 6) break;
  }
  return $clean;
}

/** แปลง datetime จาก UI ("YYYY-MM-DDTHH:MM" หรือ "YYYY-MM-DD HH:MM[:SS]") -> "Y-m-d H:i:s" หรือ null */
function parse_dt(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  $s = str_replace('T', ' ', $s);
  // ถ้าไม่มีวินาที เติม :00
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $s)) $s .= ':00';
  // validate
  $ts = strtotime($s);
  if ($ts === false) return null;
  return date('Y-m-d H:i:s', $ts);
}

/* ---------- Bearer (optional) ---------- */
$EXPECTED = getenv('API_TOKEN') ?: (defined('EXPECTED_API_TOKEN') ? (string)constant('EXPECTED_API_TOKEN') : '');
if ($EXPECTED !== '') {
  $authHdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  $bearer = '';
  if (preg_match('/^Bearer\s+(.+)$/i', $authHdr, $m)) $bearer = trim($m[1]);
  else $bearer = $_GET['api_token'] ?? $_POST['api_token'] ?? '';
  if (!hash_equals($EXPECTED, (string)$bearer)) jexit(['ok'=>false,'error'=>'unauthorized'], 401);
}

/* ---------- read body ---------- */
$ct  = $_SERVER['CONTENT_TYPE'] ?? '';
$raw = file_get_contents('php://input') ?: '';
$in  = [];
if (stripos($ct, 'application/json') !== false) {
  $tmp = json_decode($raw, true);
  $in  = is_array($tmp) ? $tmp : [];
} else {
  $in = $_POST;
}
$action = htrim($in['action'] ?? '');

/* ---------- VALIDATE ONLY ---------- */
if ($action === 'validate_order') {
  $first_name  = htrim($in['first_name'] ?? '');
  $last_name   = htrim($in['last_name'] ?? '');
  $fullname_in = htrim($in['fullname'] ?? '');
  $fullname    = $fullname_in !== '' ? $fullname_in : trim($first_name.' '.$last_name);

  $citizen_id = htrim($in['citizen_id'] ?? '');
  $vin        = htrim($in['vin'] ?? '');

  $phone      = htrim($in['phone'] ?? ''); if ($phone!=='') $phone = only_digits($phone);
  $brand      = htrim($in['brand'] ?? '');
  $session_id = htrim($in['session_id'] ?? '');

  $scheduled  = parse_dt($in['scheduled_start_at'] ?? null);

  $plates = gather_plates($in);

  jexit([
    'ok'=>true,
    'preview'=>[
      'session_id'=>$session_id ?: null,
      'fullname'=>$fullname ?: null,
      'citizen_id'=>$citizen_id ?: null,
      'vin'=>$vin ?: null,
      'phone'=>$phone ?: null,
      'brand'=>$brand ?: null,
      'plates'=>$plates ?: [],
      'scheduled_start_at'=>$scheduled,
      'plate1'=>$plates[0] ?? null,
      'plate2'=>$plates[1] ?? null,
      'plate3'=>$plates[2] ?? null,
      'plate4'=>$plates[3] ?? null,
      'plate5'=>$plates[4] ?? null,
      'plate6'=>$plates[5] ?? null,
      'status'=>htrim($in['status'] ?? 'new')
    ]
  ]);
}

/* ===== DB Layer ===== */
$root = dirname(__DIR__, 3);
$bootstrap = $root . '/src/Bootstrap.php';
if (file_exists($bootstrap)) { require_once $bootstrap; }
require_once $root . '/src/Database.php';

try {
  // รองรับทั้ง Database::pdo() และวิธีอื่น
  if (method_exists('Database','pdo'))      { $pdo = Database::pdo(); }
  elseif (method_exists('Database','getPdo')) { $pdo = Database::getPdo(); }
  elseif (method_exists('Database','getConnection')) { $pdo = Database::getConnection(); }
  else { $pdo = (new Database())->getPdo(); }
} catch (\Throwable $e) { jexit(['ok'=>false,'error'=>'db_connect_fail'], 500); }

try {
  $cols = [];
  $q = $pdo->prepare("
    SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, COLUMN_DEFAULT
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='orders'
  ");
  $q->execute();
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $cols[(string)$c['COLUMN_NAME']] = [
      'nullable' => strtoupper((string)$c['IS_NULLABLE']) === 'YES',
      'type'     => strtolower((string)$c['DATA_TYPE']),
      'default'  => $c['COLUMN_DEFAULT'],
    ];
  }
  if (!$cols) jexit(['ok'=>false,'error'=>'orders_table_not_found'], 500);
} catch (\Throwable $e) {
  error_log('[orders.meta] '.$e->getMessage());
  jexit(['ok'=>false,'error'=>'meta_fail'], 500);
}

/* ---------- helpers for DB ---------- */
function default_for_type(string $t) {
  if (in_array($t, ['varchar','char','text','tinytext','mediumtext','longtext','json'])) return '';
  if (in_array($t, ['int','bigint','smallint','tinyint','mediumint','decimal','float','double'])) return 0;
  if (in_array($t, ['datetime','timestamp'])) return date('Y-m-d H:i:s');
  if (in_array($t, ['date'])) return '0000-00-00';
  if (in_array($t, ['time'])) return '00:00:00';
  return '';
}
function normalize_for_column(array $meta, string $col, $val) {
  if (is_array($val)) {
    $t = $meta[$col]['type'] ?? '';
    if ($t === 'json') return json_encode($val, JSON_UNESCAPED_UNICODE);
    return implode(',', array_map('strval',$val));
  }
  if (is_string($val)) { $val = trim($val); if ($val==='') $val = null; }
  if (($val === null) && isset($meta[$col]) && !$meta[$col]['nullable'] && $meta[$col]['default'] === null) {
    return default_for_type($meta[$col]['type']);
  }
  return $val;
}
function build_insert(array $meta, array $data, array $prefer): array {
  $cols = []; $params = [];
  foreach ($prefer as $k) {
    if (!array_key_exists($k, $data)) continue;
    if (!isset($meta[$k])) continue;
    $val = $data[$k];

    if ($k === 'session_id') {
      $v = is_string($val) ? trim($val) : (is_null($val) ? null : (string)$val);
      $nullable = $meta[$k]['nullable'] ?? true;
      $hasDefault = ($meta[$k]['default'] !== null);
      if ($v === '' || $v === null) {
        if ($nullable || $hasDefault) {
          continue;
        } else {
          $val = 'AUTO-'.date('YmdHis').'-'.bin2hex(random_bytes(3));
        }
      }
    }

    $val = normalize_for_column($meta, $k, $val);
    $cols[] = "`$k`";
    $params[":$k"] = $val;
  }
  if (!$cols) return ['', []];
  $place = implode(', ', array_map(fn($c)=>':'.trim($c,'`'), $cols));
  $sql   = "INSERT INTO `orders` (".implode(', ', $cols).") VALUES ($place)";
  return [$sql, $params];
}
function build_update(array $meta, array $data, int $id, array $allow): array {
  $sets=[]; $params=[];
  foreach ($allow as $k) {
    if (!array_key_exists($k, $data)) continue;
    if (!isset($meta[$k])) continue;
    $val = $data[$k];

    if ($k === 'session_id') {
      $vv = is_string($val) ? trim($val) : (is_null($val) ? null : (string)$val);
      if ($vv === '' || $vv === null) continue;
    }

    $val = normalize_for_column($meta, $k, $val);
    $sets[] = "`$k` = :$k";
    $params[":$k"] = $val;
  }
  if (!$sets) return ['', []];
  $sql = "UPDATE `orders` SET ".implode(', ', $sets).", `updated_at`=NOW() WHERE `id`=:id LIMIT 1";
  $params[':id'] = $id;
  return [$sql, $params];
}

/* =================== CREATE =================== */
$isCreateImplicit = ($action==='' && (isset($in['first_name'])||isset($in['last_name'])||isset($in['fullname'])));
if ($action === 'create_order' || $isCreateImplicit) {
  $first_name  = htrim($in['first_name'] ?? '');
  $last_name   = htrim($in['last_name'] ?? '');
  $fullname_in = htrim($in['fullname'] ?? '');
  $fullname    = $fullname_in !== '' ? $fullname_in : trim($first_name.' '.$last_name);

  $citizen_id = htrim($in['citizen_id'] ?? '');
  $vin        = htrim($in['vin'] ?? '');

  $phone      = htrim($in['phone'] ?? ''); if ($phone!=='') $phone = only_digits($phone);
  $brand      = htrim($in['brand'] ?? '');
  $session_id = htrim($in['session_id'] ?? '');

  $status     = htrim($in['status'] ?? 'new');
  $created_in = htrim($in['created_at'] ?? '');
  $created_at = parse_dt($created_in) ?? null;

  $scheduled  = parse_dt($in['scheduled_start_at'] ?? null);

  $plates = gather_plates($in);
  $desired_json = $plates ? json_encode($plates, JSON_UNESCAPED_UNICODE) : null;

  $plate1 = $plates[0] ?? '';
  $plate2 = $plates[1] ?? '';
  $plate3 = $plates[2] ?? '';
  $plate4 = $plates[3] ?? '';
  $plate5 = $plates[4] ?? '';
  $plate6 = $plates[5] ?? '';

  $payload = [
    'session_id'=>$session_id,
    'fullname'=>$fullname,
    'phone'=>$phone,
    'brand'=>$brand,
    'citizen_id'=>$citizen_id,
    'vin'=>$vin,
    'plate1'=>$plate1,'plate2'=>$plate2,'plate3'=>$plate3,'plate4'=>$plate4,'plate5'=>$plate5,'plate6'=>$plate6,
    'name'=>$fullname, 'first_name'=>$first_name, 'last_name'=>$last_name,
    'desired_plates'=>$desired_json,
    'status'=>$status,
    'scheduled_start_at'=>$scheduled,
  ];

  // อนุญาตให้ตั้ง created_at ถ้าตารางมีคอลัมน์ (ถ้าไม่ส่งมาจะใช้ DEFAULT/trigger เดิม)
  if ($created_at !== null) $payload['created_at'] = $created_at;

  $prefer = [
    'session_id','fullname','name','first_name','last_name',
    'citizen_id','vin','phone','brand',
    'plate1','plate2','plate3','plate4','plate5','plate6',
    'desired_plates','status','scheduled_start_at','created_at'
  ];

  error_log('[orders.create.payload] '.json_encode($payload, JSON_UNESCAPED_UNICODE));

  [$sql,$params] = build_insert($cols,$payload,$prefer);
  if ($sql==='') jexit(['ok'=>false,'error'=>'no_insertable_columns'],500);

  try {
    $st=$pdo->prepare($sql); $st->execute($params);
    $id=(int)$pdo->lastInsertId();
    jexit(['ok'=>true,'id'=>$id]);
  } catch (\Throwable $e) {
    error_log('[orders.create] '.$e->getMessage().' | SQL='.$sql.' | P='.json_encode($params,JSON_UNESCAPED_UNICODE));
    jexit(['ok'=>false,'error'=>'create_fail'], 500);
  }
}

/* =================== UPDATE =================== */
if ($action === 'update_order') {
  $id = (int)($in['id'] ?? 0); if ($id<=0) jexit(['ok'=>false,'error'=>'bad_id'],400);

  $plates = gather_plates($in);
  $desired_json = $plates ? json_encode($plates, JSON_UNESCAPED_UNICODE) : null;

  $data = [
    'session_id'=>htrim($in['session_id'] ?? ''),
    'fullname'  =>htrim($in['fullname'] ?? ''),
    'name'      =>htrim($in['fullname'] ?? ''),
    'first_name'=>htrim($in['first_name'] ?? ''),
    'last_name' =>htrim($in['last_name'] ?? ''),
    'phone'     =>htrim($in['phone'] ?? ''),
    'brand'     =>htrim($in['brand'] ?? ''),
    'citizen_id'=>htrim($in['citizen_id'] ?? ''),
    'vin'       =>htrim($in['vin'] ?? ''),
    'status'    =>htrim($in['status'] ?? ''),

    'plate1'    =>$plates[0] ?? '',
    'plate2'    =>$plates[1] ?? '',
    'plate3'    =>$plates[2] ?? '',
    'plate4'    =>$plates[3] ?? '',
    'plate5'    =>$plates[4] ?? '',
    'plate6'    =>$plates[5] ?? '',

    'desired_plates'=>$desired_json,

    // รองรับกำหนดเริ่ม
    'scheduled_start_at'=>parse_dt($in['scheduled_start_at'] ?? null),
  ];

  if ($data['phone']!=='') $data['phone']=only_digits($data['phone']);

  // ไม่บังคับใส่ค่า null ให้คอลัมน์ที่ไม่มีในตาราง: build_update จะข้ามให้เอง
  // log เพื่อดีบั๊ก
  error_log('[orders.update.data] '.json_encode(['id'=>$id,'data'=>$data], JSON_UNESCAPED_UNICODE));

  // อนุญาตให้อัปเดตเฉพาะ key ที่ส่งมา (และอาจเป็นค่าว่าง) — ให้คงคีย์ไว้ทั้งหมด เพื่อครอบคลุม scheduled_start_at/status
  $allow = array_keys($data);

  [$sql,$params]=build_update($cols,$data,$id,$allow);
  if ($sql==='') jexit(['ok'=>false,'error'=>'nothing_to_update'],400);

  try {
    $st=$pdo->prepare($sql); $st->execute($params);
    jexit(['ok'=>true,'id'=>$id]);
  } catch (\Throwable $e){
    error_log('[orders.update] '.$e->getMessage().' | SQL='.$sql.' | P='.json_encode($params,JSON_UNESCAPED_UNICODE));
    jexit(['ok'=>false,'error'=>'update_fail'],500);
  }
}

/* =================== DELETE =================== */
if ($action === 'delete_order') {
  $id=(int)($in['id'] ?? 0); if ($id<=0) jexit(['ok'=>false,'error'=>'bad_id'],400);
  try {
    $st=$pdo->prepare("DELETE FROM `orders` WHERE `id`=? LIMIT 1"); $st->execute([$id]);
    jexit(['ok'=>true,'id'=>$id]);
  } catch (\Throwable $e){
    error_log('[orders.delete] '.$e->getMessage());
    jexit(['ok'=>false,'error'=>'delete_fail'],500);
  }
}

/* =================== BULK DELETE (ถ้ามีการเรียกจาก UI) =================== */
if ($action === 'bulk_delete_orders') {
  $ids = $in['ids'] ?? [];
  if (!is_array($ids) || !$ids) jexit(['ok'=>false,'error'=>'bad_ids'],400);
  $ids = array_values(array_unique(array_map('intval',$ids)));
  $ids = array_filter($ids, fn($x)=>$x>0);
  if (!$ids) jexit(['ok'=>false,'error'=>'bad_ids'],400);
  $inQ = implode(',', array_fill(0, count($ids), '?'));
  try {
    $st=$pdo->prepare("DELETE FROM `orders` WHERE `id` IN ($inQ)");
    $st->execute($ids);
    jexit(['ok'=>true,'count'=>count($ids)]);
  } catch (\Throwable $e){
    error_log('[orders.bulk_delete] '.$e->getMessage());
    jexit(['ok'=>false,'error'=>'bulk_delete_fail'],500);
  }
}

jexit(['ok'=>false,'error'=>'unknown_action'],400);
