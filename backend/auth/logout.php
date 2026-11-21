<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrfCookie = $_COOKIE['ears_csrf'] ?? '';
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($csrfCookie) || !is_string($csrfHeader) || $csrfCookie === '' || !hash_equals($csrfCookie, $csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Request verification failed.']);
    exit;
}

try {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../utils/auth.php';
    $db = (new Database())->getConnection();
    $auth = new Auth($db);
    $user = $auth->validateToken($auth->getBearerToken());
    if ($user) {
        $db->beginTransaction();
        $revoke = $db->prepare(
            'UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP()
             WHERE token_id = :token_id AND user_id = :user_id AND revoked_at IS NULL'
        );
        $revoke->execute([':token_id' => $user['token_id'], ':user_id' => $user['id']]);
        $audit = $db->prepare(
            "INSERT INTO audit_logs (user_id, action, description, ip_address)
             VALUES (:user_id, 'logout', 'Signed out.', :ip_address)"
        );
        $audit->execute([
            ':user_id' => $user['id'],
            ':ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
        $db->commit();
    }
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Logout audit event could not be recorded: ' . $error->getMessage());
}

$secureCookies = strtolower((string) (getenv('COOKIE_SECURE') ?: 'true')) !== 'false';
$expired = [
    'expires' => time() - 3600,
    'path' => '/',
    'secure' => $secureCookies,
    'samesite' => 'Strict',
];
setcookie('ears_token', '', [...$expired, 'httponly' => true]);
setcookie('ears_csrf', '', [...$expired, 'httponly' => false]);

echo json_encode(['success' => true, 'message' => 'Signed out.']);
