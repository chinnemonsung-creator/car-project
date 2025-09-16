<?php
require __DIR__ . '/src/Bootstrap.php';
require_once __DIR__ . '/src/Database.php';

$cfg = $GLOBALS['config']['db'];
$db  = new Database($cfg);
$pdo = $db->pdo();

// TODO: เปลี่ยน username/password จริง
$username = 'admin';
$password = '123456'; 

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT IGNORE INTO admin_users (username,password_hash) VALUES (?,?)");
$stmt->execute([$username, $hash]);

echo "Seed admin done: $username / $password\n";
