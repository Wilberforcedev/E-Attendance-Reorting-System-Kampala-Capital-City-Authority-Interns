<?php
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Auto-load classes
spl_autoload_register(function ($class_name) {
    include '../models/' . $class_name . '.php';
});

// JWT secret key
define('SECRET_KEY', 'your-secret-key-here');
?>