<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__, 2);
require_once $root . '/backend/config/db.php';
require_once $root . '/backend/models/user.php';
require_once $root . '/backend/config/jwt.php';

function loginResponse(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    loginResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 16384) {
    loginResponse(413, ['success' => false, 'message' => 'Request is too large.']);
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
        loginResponse(400, ['success' => false, 'message' => 'A valid JSON object is required.']);
    }

    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    $password = $payload['password'] ?? null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '' || strlen($password) > 1024) {
        loginResponse(400, ['success' => false, 'message' => 'Enter a valid email and password.']);
    }

    $db = (new Database())->getConnection();
    $user = new User($db);
    $user->email = $email;

    if (!$user->emailExists() || !$user->is_active || !password_verify($password, $user->password)) {
        loginResponse(401, ['success' => false, 'message' => 'Invalid email or password.']);
    }

    $token = generateToken([
        'id' => $user->id,
        'full_name' => $user->full_name,
        'email' => $user->email,
        'role' => $user->role,
    ]);
    $csrfToken = bin2hex(random_bytes(32));
    $secureCookies = strtolower((string) (getenv('COOKIE_SECURE') ?: 'true')) !== 'false';
    $cookieOptions = [
        'expires' => time() + 3600,
        'path' => '/',
        'secure' => $secureCookies,
        'httponly' => true,
        'samesite' => 'Strict',
    ];
    setcookie('ears_token', $token, $cookieOptions);
    setcookie('ears_csrf', $csrfToken, [...$cookieOptions, 'httponly' => false]);

    loginResponse(200, [
        'success' => true,
        'message' => 'Login successful.',
        'user' => [
            'id' => (int) $user->id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role,
        ],
    ]);
} catch (PDOException $error) {
    error_log('Login database error: ' . $error->getMessage());
    loginResponse(500, ['success' => false, 'message' => 'Sign-in is temporarily unavailable.']);
} catch (Throwable $error) {
    error_log('Login request failed: ' . $error->getMessage());
    loginResponse(500, ['success' => false, 'message' => 'Sign-in is temporarily unavailable.']);
}
