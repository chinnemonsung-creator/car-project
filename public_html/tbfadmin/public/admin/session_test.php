<?php
require __DIR__ . '/../../src/Bootstrap.php';

session_name($GLOBALS['config']['app']['admin_session_name'] ?? 'bb_admin');
session_start();

if (empty($_SESSION['t'])) {
  $_SESSION['t'] = bin2hex(random_bytes(4));
}
header('Content-Type: text/plain; charset=utf-8');
echo "session_name=" . session_name() . "\n";
echo "session_id=" . session_id() . "\n";
echo "value=" . $_SESSION['t'] . "\n";
