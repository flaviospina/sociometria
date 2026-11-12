<?php
// demo/app/config.php — lê o .env e define as configurações.

declare(strict_types=1);

$envFile = dirname(__DIR__).'/.env';
$env = [];
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
}

define('DB_HOST', $env['DB_HOST'] ?? 'localhost');
define('DB_NAME', $env['DB_NAME'] ?? '');
define('DB_USER', $env['DB_USER'] ?? '');
define('DB_PASS', $env['DB_PASS'] ?? '');
define('APP_URL', rtrim($env['APP_URL'] ?? '', '/'));
define('APP_DEBUG', ($env['APP_DEBUG'] ?? 'false') === 'true');
define('APP_NAME', 'Sociometria Escolar');

ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');
