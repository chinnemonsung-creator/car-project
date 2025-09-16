<?php
// /tbfadmin/src/Database.php
declare(strict_types=1);

final class Database {
  private static ?\PDO $pdo = null;

  // --- ปรับค่าตามจริงของโฮสต์คุณ ---
    private const DB_HOST    = '127.0.0.1';       // เปลี่ยนเป็น 127.0.0.1 เพื่อให้ตรงกับ phpMyAdmin
  private const DB_NAME    = 'bellafle_dbfan';
  private const DB_USER    = 'bellafle_admin';
  private const DB_PASS    = 'V%oOSbU432lwiibw';
  private const DB_PORT    = 3306;
  private const DB_CHARSET = 'utf8mb4';

  // ถ้าโฮสต์ต้องการ UNIX socket (ให้ใส่ path; ถ้าไม่รู้ให้เว้นเป็น '' ไว้ก่อน)
  private const DB_SOCKET  = ''; // เช่น '/var/lib/mysql/mysql.sock'

  public static function pdo(): \PDO {
    if (self::$pdo instanceof \PDO) return self::$pdo;

    $opts = [
      \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
      \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
      \PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
      if (self::DB_SOCKET !== '') {
        $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s',
          self::DB_SOCKET, self::DB_NAME, self::DB_CHARSET
        );
      } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
          self::DB_HOST, self::DB_PORT, self::DB_NAME, self::DB_CHARSET
        );
      }

      self::$pdo = new \PDO($dsn, self::DB_USER, self::DB_PASS, $opts);
      // ตั้ง timezone ให้ตรงไทย (ถ้าต้องการ)
      self::$pdo->exec("SET time_zone = '+07:00'");
      return self::$pdo;

    } catch (\Throwable $e) {
      // log ให้ละเอียด
      $logDir = __DIR__ . '/../var';
      if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
      @ini_set('log_errors', '1');
      @ini_set('error_log', $logDir . '/php_errors.log');
      error_log('[DB_CONNECT_FAIL] ' . $e->getMessage());
      throw $e; // โยนต่อให้ชั้นบนจับแล้วแปลงเป็น JSON {"ok":false,"error":"db_connect_error"}
    }
  }
}
