<?php


require __DIR__ . '/src/Bootstrap.php';
require_once __DIR__ . '/src/Database.php';

header('Content-Type: text/plain; charset=utf-8');

$cfg  = $GLOBALS['config']['db'] ?? null;
if (!$cfg) {
  http_response_code(500);
  echo "[ERROR] DB config not loaded.\n";
  exit;
}

$sqlFile = __DIR__ . '/schema_alter.sql';
if (!is_file($sqlFile)) {
  http_response_code(404);
  echo "[ERROR] SQL file not found: {$sqlFile}\n";
  exit;
}

$sql = file_get_contents($sqlFile);
if ($sql === false || $sql === '') {
  http_response_code(400);
  echo "[ERROR] SQL file is empty.\n";
  exit;
}

$dry = isset($_GET['dry']) && $_GET['dry'] == '1';

echo "=== tbfadmin migrate ===\n";
echo "File : " . basename($sqlFile) . "\n";
echo "Mode : " . ($dry ? "DRY-RUN (no execute)" : "EXECUTE") . "\n";
echo "Time : " . date('c') . "\n\n";

/**
 * Split SQL into statements; ignore semicolons inside quotes/comments.
 */
function splitSqlStatements(string $sql): array {
  $stmts = [];
  $current = '';
  $len = strlen($sql);
  $inSingle = false; // '
  $inDouble = false; // "
  $inBacktick = false; // `
  $inLineComment = false; // --
  $inBlockComment = false; // /* */

  for ($i = 0; $i < $len; $i++) {
    $ch = $sql[$i];
    $next = $i + 1 < $len ? $sql[$i + 1] : '';

    // handle end of line comment
    if ($inLineComment) {
      $current .= $ch;
      if ($ch === "\n") {
        $inLineComment = false;
      }
      continue;
    }

    // handle end of block comment
    if ($inBlockComment) {
      $current .= $ch;
      if ($ch === '*' && $next === '/') {
        $current .= $next;
        $i++;
        $inBlockComment = false;
      }
      continue;
    }

    // toggle quotes
    if (!$inDouble && !$inBacktick && $ch === "'" && ($i === 0 || $sql[$i-1] !== '\\')) {
      $inSingle = !$inSingle;
      $current .= $ch;
      continue;
    }
    if (!$inSingle && !$inBacktick && $ch === '"' && ($i === 0 || $sql[$i-1] !== '\\')) {
      $inDouble = !$inDouble;
      $current .= $ch;
      continue;
    }
    if (!$inSingle && !$inDouble && $ch === '`') {
      $inBacktick = !$inBacktick;
      $current .= $ch;
      continue;
    }

    // start comments if not inside quotes
    if (!$inSingle && !$inDouble && !$inBacktick) {
      // line comment: --
      if ($ch === '-' && $next === '-') {
        $inLineComment = true;
        $current .= $ch . $next;
        $i++;
        continue;
      }
      // block comment: /* ... */
      if ($ch === '/' && $next === '*') {
        $inBlockComment = true;
        $current .= $ch . $next;
        $i++;
        continue;
      }
    }

    // split on semicolon only when not in quotes/comments
    if (!$inSingle && !$inDouble && !$inBacktick && $ch === ';') {
      $trim = trim($current);
      if ($trim !== '') {
        $stmts[] = $trim;
      }
      $current = '';
      continue;
    }

    $current .= $ch;
  }

  $trim = trim($current);
  if ($trim !== '') {
    $stmts[] = $trim;
  }

  // remove BOM or stray delimiters
  $stmts = array_values(array_filter($stmts, fn($s) => $s !== '' && stripos($s, 'DELIMITER') !== 0));
  return $stmts;
}

$statements = splitSqlStatements($sql);
if (!$statements) {
  echo "[WARN] No SQL statements found.\n";
  exit;
}

echo "Found " . count($statements) . " statement(s).\n\n";

$db = null;
try {
  $db = new Database($cfg);
} catch (Throwable $e) {
  http_response_code(500);
  echo "[ERROR] Cannot connect DB: " . $e->getMessage() . "\n";
  exit;
}

$ok = 0; $fail = 0;

foreach ($statements as $idx => $stmt) {
  $n = $idx + 1;
  echo "--- Statement #{$n} ---\n";
  echo $stmt . ";\n";

  if ($dry) {
    echo "[DRY] skipped\n\n";
    continue;
  }

  try {
    $db->pdo()->exec($stmt);
    echo "[OK]\n\n";
    $ok++;
  } catch (PDOException $e) {
    // กรณี idempotent: ถ้าคอลัมน์/ดัชนีมีอยู่แล้ว ให้ถือว่า PASS แบบ soft
    $msg = $e->getMessage();
    $code = (int)$e->getCode();
    // MySQL error codes ที่มักเจอ
    // 1060: Duplicate column name
    // 1061: Duplicate key name
    // 1062: Duplicate entry (เช่น UNIQUE ซ้ำ)
    // 1091: Can't DROP; check that column/key exists (ถ้ามีคำสั่ง DROP)
    if (in_array($code, [1060,1061,1091], true)) {
      echo "[SOFT-OK] Ignored idempotent error (code {$code}): {$msg}\n\n";
      $ok++;
      continue;
    }
    echo "[ERROR] code {$code}: {$msg}\n\n";
    $fail++;
  } catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n\n";
    $fail++;
  }
}

echo "=== Summary ===\n";
echo "OK   : {$ok}\n";
echo "FAIL : {$fail}\n";
echo "Done at " . date('c') . "\n";
