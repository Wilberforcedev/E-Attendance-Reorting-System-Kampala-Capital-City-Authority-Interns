<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    throw new RuntimeException('Server dependencies are not installed.');
}
require_once $autoloadPath;

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

$jwtSecret = getenv('JWT_SECRET');
if (!is_string($jwtSecret) || strlen($jwtSecret) < 32) {
    throw new RuntimeException('JWT_SECRET must be configured with at least 32 characters.');
}
if (!defined('SECRET_KEY')) {
    define('SECRET_KEY', $jwtSecret);
}

// Auto-load classes
spl_autoload_register(function ($class_name) {
    $modelPath = __DIR__ . '/../models/' . basename($class_name) . '.php';
    if (is_file($modelPath)) {
        require_once $modelPath;
    }
});
