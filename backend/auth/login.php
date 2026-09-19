<?php
// Set response headers first to prevent any output issues
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Error reporting for development (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// --------------------------------------------------
// 1. PATH CONFIGURATION AND DEPENDENCY LOADING
// --------------------------------------------------

// Calculate base directory (two levels up from backend/auth/)
$baseDir = dirname(__DIR__, 2);

// Verify and load Composer autoloader
$autoloadPath = $baseDir . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    die(json_encode([
        "success" => false,
        "message" => "System configuration error",
        "error" => "Composer dependencies not installed",
        "solution" => "Run 'composer install' in the project root directory"
    ]));
}
require_once $autoloadPath;

// Define and verify all required system files
$requiredFiles = [
    'core.php' => $baseDir . '/backend/config/core.php',
    'db.php'   => $baseDir . '/backend/config/db.php',
    'user.php' => $baseDir . '/backend/models/user.php',
    'jwt.php'  => $baseDir . '/backend/config/jwt.php'
];

foreach ($requiredFiles as $label => $path) {
    if (!file_exists($path)) {
        http_response_code(500);
        die(json_encode([
            "success" => false,
            "message" => "System configuration error",
            "error" => "Required file '$label' not found",
            "path" => str_replace('\\', '/', $path), // Normalize path for readability
            "solution" => "Verify the file exists at this location"
        ]));
    }
    require_once $path;
}

// --------------------------------------------------
// 2. REQUEST PROCESSING AND VALIDATION
// --------------------------------------------------

try {
    // Get and validate raw input
    $rawInput = file_get_contents("php://input");
    if (empty($rawInput)) {
        throw new Exception("No input data received");
    }

    $data = json_decode($rawInput);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON format: " . json_last_error_msg());
    }

    // Validate required fields
    $requiredFields = ['email', 'password'];
    foreach ($requiredFields as $field) {
        if (!isset($data->$field) || empty(trim($data->$field))) {
            throw new Exception("Field '$field' is required");
        }
    }

    // Sanitize input
    $email = filter_var($data->email, FILTER_SANITIZE_EMAIL);
    $password = $data->password; // Password will be verified, not sanitized

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid email format");
    }

    // --------------------------------------------------
    // 3. DATABASE OPERATIONS
    // --------------------------------------------------

    $database = new Database();
    $db = $database->getConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }

    $user = new User($db);
    $user->email = $email;

    // Check if user exists
    if (!$user->emailExists()) {
        throw new Exception("Invalid credentials", 401);
    }

    // Verify password
    if (!password_verify($password, $user->password)) {
        throw new Exception("Invalid credentials", 401);
    }

    // --------------------------------------------------
    // 4. TOKEN GENERATION AND RESPONSE
    // --------------------------------------------------

    $tokenPayload = [
        'id' => $user->id,
        'full_name' => $user->full_name,
        'email' => $user->email,
        'role' => $user->role,
        'iat' => time(),
        'exp' => time() + (60 * 60) // 1 hour expiration
    ];

    $token = generateToken($tokenPayload);

    // Successful response
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Login successful",
        "token" => $token,
        "user" => [
            "id" => $user->id,
            "full_name" => $user->full_name,
            "email" => $user->email,
            "role" => $user->role
        ]
    ]);

} catch (Exception $e) {
    // Error handling
    $statusCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($statusCode);
    
    $response = [
        "success" => false,
        "message" => $e->getMessage()
    ];

    // Add debug info only in development
    if (ini_get('display_errors')) {
        $response['debug'] = [
            "file" => $e->getFile(),
            "line" => $e->getLine(),
            "trace" => $e->getTrace()
        ];
    }

    echo json_encode($response);
}