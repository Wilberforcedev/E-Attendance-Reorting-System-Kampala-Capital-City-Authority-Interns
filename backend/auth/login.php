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
    $rawBody = file_get_contents('php://input', false, null, 0, 16385);
    if (!is_string($rawBody) || strlen($rawBody) > 16384) {
        loginResponse(413, ['success' => false, 'message' => 'Request is too large.']);
    }
    $payload = json_decode($rawBody, true);
    if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
        loginResponse(400, ['success' => false, 'message' => 'A valid JSON object is required.']);
    }

    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    $password = $payload['password'] ?? null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '' || strlen($password) > 1024) {
        loginResponse(400, ['success' => false, 'message' => 'Enter a valid email and password.']);
    }

    $db = (new Database())->getConnection();
    $identifierHash = hash('sha256', $email);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ipHash = hash('sha256', $ip);

    if (random_int(1, 100) === 1) {
        $db->exec('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
        $db->exec('DELETE FROM auth_sessions WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
    }

    $emailAttempts = $db->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE identifier_hash = :identifier_hash AND ip_hash = :ip_hash
           AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE'
    );
    $emailAttempts->execute([':identifier_hash' => $identifierHash, ':ip_hash' => $ipHash]);
    $ipAttempts = $db->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_hash = :ip_hash AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE'
    );
    $ipAttempts->execute([':ip_hash' => $ipHash]);
    if ((int) $emailAttempts->fetchColumn() >= 5 || (int) $ipAttempts->fetchColumn() >= 100) {
        header('Retry-After: 900');
        loginResponse(429, ['success' => false, 'message' => 'Too many sign-in attempts. Try again later.']);
    }

    $user = new User($db);
    $user->email = $email;

    if (!$user->emailExists() || !$user->is_active || !password_verify($password, $user->password)) {
        $attempt = $db->prepare('INSERT INTO login_attempts (identifier_hash, ip_hash) VALUES (:identifier_hash, :ip_hash)');
        $attempt->execute([':identifier_hash' => $identifierHash, ':ip_hash' => $ipHash]);
        loginResponse(401, ['success' => false, 'message' => 'Invalid email or password.']);
    }

    $tokenId = bin2hex(random_bytes(32));
    $expiresAt = time() + 3600;
    $token = generateToken([
        'id' => $user->id,
        'full_name' => $user->full_name,
        'email' => $user->email,
        'role' => $user->role,
        'session_version' => $user->session_version,
        'jti' => $tokenId,
    ], $expiresAt);

    $db->beginTransaction();
    $audit = $db->prepare(
        "INSERT INTO audit_logs (user_id, action, description, ip_address)
         VALUES (:user_id, 'login', 'Successful sign-in.', :ip_address)"
    );
    $audit->execute([
        ':user_id' => $user->id,
        ':ip_address' => substr($ip, 0, 45),
    ]);
    $session = $db->prepare(
        'INSERT INTO auth_sessions (token_id, user_id, expires_at)
         VALUES (:token_id, :user_id, :expires_at)'
    );
    $session->execute([
        ':token_id' => $tokenId,
        ':user_id' => $user->id,
        ':expires_at' => gmdate('Y-m-d H:i:s', $expiresAt),
    ]);
    $db->commit();

    $csrfToken = bin2hex(random_bytes(32));
    $secureCookies = strtolower((string) (getenv('COOKIE_SECURE') ?: 'true')) !== 'false';
    $cookieOptions = [
        'expires' => $expiresAt,
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
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Login database error: ' . $error->getMessage());
    loginResponse(500, ['success' => false, 'message' => 'Sign-in is temporarily unavailable.']);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Login request failed: ' . $error->getMessage());
    loginResponse(500, ['success' => false, 'message' => 'Sign-in is temporarily unavailable.']);
}
