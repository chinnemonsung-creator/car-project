<?php
error_reporting(E_ALL); ini_set('display_errors',1);
session_name($GLOBALS['config']['app']['admin_session_name'] ?? 'bb_admin');
session_start();

if (isset($_GET['set'])) {
  $_SESSION['admin_logged_in'] = true;
  $_SESSION['admin_username'] = 'tester';
  echo "SET OK\n";
  var_dump($_SESSION);
  exit;
}
if (isset($_GET['check'])) {
  echo "CHECK\n";
  var_dump($_SESSION);
  exit;
}
phpinfo();
