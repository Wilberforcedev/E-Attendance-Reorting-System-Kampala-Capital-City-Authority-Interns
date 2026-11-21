<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

function auditResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    auditResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

try {
    $db = (new Database())->getConnection();
    $auth = new Auth($db);
    $user = $auth->validateToken($auth->getBearerToken());
    if (!$user) {
        auditResponse(401, ['status' => 'error', 'message' => 'Authentication required.']);
    }
    if ($user['role'] !== 'admin') {
        auditResponse(403, ['status' => 'error', 'message' => 'Administrator access required.']);
    }

    $stmt = $db->query(
        'SELECT a.audit_id, a.action, a.description, a.ip_address, a.created_at,
                u.full_name AS actor_name, u.email AS actor_email
         FROM audit_logs a
         INNER JOIN users u ON u.id = a.user_id
         ORDER BY a.created_at DESC, a.audit_id DESC
         LIMIT 500'
    );
    $logs = array_map(static fn(array $row): array => [
        'id' => (int) $row['audit_id'],
        'timestamp' => $row['created_at'],
        'user' => $row['actor_name'],
        'email' => $row['actor_email'],
        'action' => $row['action'],
        'details' => $row['description'],
        'ipAddress' => $row['ip_address'] ?? '',
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    auditResponse(200, ['status' => 'success', 'count' => count($logs), 'logs' => $logs]);
} catch (Throwable $error) {
    error_log('Audit log request failed: ' . $error->getMessage());
    auditResponse(500, ['status' => 'error', 'message' => 'Request could not be completed.']);
}
