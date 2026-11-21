<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}

if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
    respond(401, ['status' => 'error', 'message' => 'Authentication required.']);
}

try {
    $db = (new Database())->getConnection();
    $actor = (new Auth($db))->validateToken(trim($matches[1]));
} catch (Throwable $error) {
    error_log('Users API authentication failed: ' . $error->getMessage());
    respond(500, ['status' => 'error', 'message' => 'Request could not be completed.']);
}

if (!$actor) {
    respond(401, ['status' => 'error', 'message' => 'Authentication required.']);
}
if ($actor['role'] !== 'admin') {
    respond(403, ['status' => 'error', 'message' => 'Administrator access required.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    respond(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

try {
    if ($method === 'GET') {
        $stmt = $db->query(
            'SELECT id, full_name, email, role, division_id, directorate_id,
                    department_id, supervisor_id, is_active, created_at
             FROM users ORDER BY created_at DESC'
        );
        $users = $stmt->fetchAll();
        respond(200, ['status' => 'success', 'count' => count($users), 'users' => $users]);
    }

    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            respond(400, ['status' => 'error', 'message' => 'A valid JSON object is required.']);
        }

        $fullName = trim((string) ($data['full_name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $role = (string) ($data['role'] ?? '');
        $allowedRoles = ['intern', 'supervisor', 'hr', 'admin'];

        if ($fullName === '' || strlen($fullName) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            respond(400, ['status' => 'error', 'message' => 'Provide a valid name and email address.']);
        }
        if (strlen($password) < 12) {
            respond(400, ['status' => 'error', 'message' => 'Password must be at least 12 characters.']);
        }
        if (!in_array($role, $allowedRoles, true)) {
            respond(400, ['status' => 'error', 'message' => 'Select a valid user role.']);
        }

        $supervisorId = $data['supervisor_id'] ?? null;
        if ($supervisorId !== null) {
            if (!filter_var($supervisorId, FILTER_VALIDATE_INT)) {
                respond(400, ['status' => 'error', 'message' => 'Supervisor ID must be a valid integer.']);
            }
            $supervisorCheck = $db->prepare("SELECT id FROM users WHERE id = :id AND role = 'supervisor'");
            $supervisorCheck->execute([':id' => $supervisorId]);
            if (!$supervisorCheck->fetchColumn()) {
                respond(400, ['status' => 'error', 'message' => 'Supervisor ID is invalid.']);
            }
        }

        $insert = $db->prepare(
            'INSERT INTO users
                (full_name, email, password, role, division_id, directorate_id, department_id, supervisor_id)
             VALUES
                (:full_name, :email, :password, :role, :division_id, :directorate_id, :department_id, :supervisor_id)'
        );
        $insert->execute([
            ':full_name' => $fullName,
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => $role,
            ':division_id' => $data['division_id'] ?? null,
            ':directorate_id' => $data['directorate_id'] ?? null,
            ':department_id' => $data['department_id'] ?? null,
            ':supervisor_id' => $supervisorId,
        ]);

        respond(201, ['status' => 'success', 'message' => 'User created successfully.', 'user_id' => (int) $db->lastInsertId()]);
    }

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        respond(400, ['status' => 'error', 'message' => 'A valid user ID is required.']);
    }
    if ((string) $id === (string) $actor['id']) {
        respond(400, ['status' => 'error', 'message' => 'You cannot delete your own account.']);
    }

    $delete = $db->prepare('UPDATE users SET is_active = 0 WHERE id = :id AND is_active = 1');
    $delete->execute([':id' => $id]);
    if ($delete->rowCount() === 0) {
        respond(404, ['status' => 'error', 'message' => 'User not found.']);
    }
    respond(200, ['status' => 'success', 'message' => 'User account deactivated successfully.']);
} catch (Throwable $error) {
    error_log('Users API request failed: ' . $error->getMessage());
    respond(500, ['status' => 'error', 'message' => 'Request could not be completed.']);
}
