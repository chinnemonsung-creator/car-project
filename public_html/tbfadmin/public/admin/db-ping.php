<?php
declare(strict_types=1);
require __DIR__ . '/../../src/Database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = Database::getConnection();
echo "DB: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . PHP_EOL;
echo "Now: " . $pdo->query("SELECT NOW()")->fetchColumn() . PHP_EOL;
