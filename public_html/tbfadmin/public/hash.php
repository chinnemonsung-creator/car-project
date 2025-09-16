<?php
header('Content-Type: text/plain; charset=utf-8');
echo password_hash($_GET['p'] ?? 'ChangeMe!123', PASSWORD_DEFAULT), PHP_EOL;
