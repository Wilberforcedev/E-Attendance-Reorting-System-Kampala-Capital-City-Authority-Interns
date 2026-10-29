<?php
$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoloadPath)) {
    error_log('EARS Composer autoloader is missing. Run composer install.');
    throw new RuntimeException('Application dependencies are not installed.');
}
require_once $autoloadPath;

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Max-Age: 3600');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

$allowedOrigin = getenv('EARS_CORS_ORIGIN');
if ($allowedOrigin !== false && trim($allowedOrigin) !== '') {
    header('Access-Control-Allow-Origin: ' . trim($allowedOrigin));
}

spl_autoload_register(function ($className) {
    $modelPath = dirname(__DIR__) . '/models/' . $className . '.php';
    if (is_file($modelPath)) {
        require_once $modelPath;
    }
});

$jwtSecret = getenv('EARS_JWT_SECRET');
if ($jwtSecret === false || strlen($jwtSecret) < 32) {
    error_log('EARS_JWT_SECRET is missing or shorter than 32 characters.');
    throw new RuntimeException('Authentication service is not configured.');
}
if (!defined('SECRET_KEY')) {
    define('SECRET_KEY', $jwtSecret);
}
