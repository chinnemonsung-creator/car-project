<?php
class Util {
  public static function bodyJson(): array {
    $raw  = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
  }
  public static function newSessionId(): string {
    return bin2hex(random_bytes(16)); // 32 hex chars
  }
}
