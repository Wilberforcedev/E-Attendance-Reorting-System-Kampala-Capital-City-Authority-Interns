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
