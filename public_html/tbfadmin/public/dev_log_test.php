<?php
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
if (!is_dir(__DIR__ . '/var')) { @mkdir(__DIR__ . '/var', 0775, true); }
ini_set('error_log', __DIR__ . '/var/php_errors.log');

error_log('hello-from-log-test');
trigger_error('force php warning for log test', E_USER_WARNING);

echo 'OK';
