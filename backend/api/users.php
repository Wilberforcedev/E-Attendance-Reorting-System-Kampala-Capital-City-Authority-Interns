<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

function usersResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function usersLogAction(PDO $db, int $actorId, string $action, string $description): void
{
    $stmt = $db->prepare(
        'INSERT INTO audit_logs (user_id, action, description, ip_address)
         VALUES (:user_id, :action, :description, :ip_address)'
    );
    $stmt->execute([
        ':user_id' => $actorId,
        ':action' => $action,
        ':description' => $description,
        ':ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
    ]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    usersResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}
if ($method === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    usersResponse(413, ['status' => 'error', 'message' => 'Request is too large.']);
}

try {
    $db = (new Database())->getConnection();
    $auth = new Auth($db);
    $actor = $auth->validateToken($auth->getBearerToken());
    if (!$actor) {
        usersResponse(401, ['status' => 'error', 'message' => 'Authentication required.']);
    }
    if ($actor['role'] !== 'admin') {
        usersResponse(403, ['status' => 'error', 'message' => 'Administrator access required.']);
    }
    if (!$auth->hasValidCsrfToken()) {
        usersResponse(403, ['status' => 'error', 'message' => 'Request verification failed. Refresh and try again.']);
    }

    if ($method === 'GET') {
        $stmt = $db->query(
            'SELECT u.id, u.full_name, u.email, u.role, u.division_id, u.directorate_id,
                    u.department_id, u.supervisor_id, u.is_active, u.created_at,
                    d.name AS department_name
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT 500'
        );
        $users = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'full_name' => $row['full_name'],
                'email' => $row['email'],
                'role' => $row['role'],
                'division_id' => $row['division_id'] === null ? null : (int) $row['division_id'],
                'directorate_id' => $row['directorate_id'] === null ? null : (int) $row['directorate_id'],
                'department_id' => $row['department_id'] === null ? null : (int) $row['department_id'],
                'supervisor_id' => $row['supervisor_id'] === null ? null : (int) $row['supervisor_id'],
                'department' => $row['department_name'] ?? '',
                'status' => (bool) $row['is_active'] ? 'active' : 'inactive',
                'created_at' => $row['created_at'],
                'last_login' => null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        usersResponse(200, ['status' => 'success', 'count' => count($users), 'users' => $users]);
    }

    if ($method === 'POST') {
        $rawBody = file_get_contents('php://input', false, null, 0, 16385);
        if (!is_string($rawBody) || strlen($rawBody) > 16384) {
            usersResponse(413, ['status' => 'error', 'message' => 'Request is too large.']);
        }
        $data = json_decode($rawBody, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            usersResponse(400, ['status' => 'error', 'message' => 'A valid JSON object is required.']);
        }

        $fullName = trim((string) ($data['full_name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $password = $data['password'] ?? null;
        $role = (string) ($data['role'] ?? '');
        if ($fullName === '' || strlen($fullName) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            usersResponse(400, ['status' => 'error', 'message' => 'Provide a valid name and email address.']);
        }
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 1024) {
            usersResponse(400, ['status' => 'error', 'message' => 'Password must be 12 to 1,024 characters.']);
        }
        if (!in_array($role, ['intern', 'supervisor', 'hr', 'admin'], true)) {
            usersResponse(400, ['status' => 'error', 'message' => 'Select a valid account role.']);
        }

        $departmentId = $data['department_id'] ?? null;
        if ($departmentId !== null && $departmentId !== '') {
            if (!filter_var($departmentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                usersResponse(400, ['status' => 'error', 'message' => 'Select a valid department.']);
            }
            $departmentCheck = $db->prepare('SELECT directorate_id FROM departments WHERE id = :id');
            $departmentCheck->execute([':id' => $departmentId]);
            $directorateId = $departmentCheck->fetchColumn();
            if ($directorateId === false) {
                usersResponse(400, ['status' => 'error', 'message' => 'The selected department does not exist.']);
            }
            $directorateCheck = $db->prepare('SELECT division_id FROM directorates WHERE id = :id');
            $directorateCheck->execute([':id' => $directorateId]);
            $divisionId = $directorateCheck->fetchColumn();
        } else {
            $departmentId = $directorateId = $divisionId = null;
        }

        $supervisorId = $data['supervisor_id'] ?? null;
        if ($supervisorId !== null && $supervisorId !== '') {
            if ($role !== 'intern' || !filter_var($supervisorId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
                usersResponse(400, ['status' => 'error', 'message' => 'Only interns may be assigned a valid supervisor.']);
            }
            $supervisorCheck = $db->prepare("SELECT id FROM users WHERE id = :id AND role = 'supervisor' AND is_active = 1");
            $supervisorCheck->execute([':id' => $supervisorId]);
            if (!$supervisorCheck->fetchColumn()) {
                usersResponse(400, ['status' => 'error', 'message' => 'The selected supervisor is not active.']);
            }
        } else {
            $supervisorId = null;
        }

        $db->beginTransaction();
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
            ':division_id' => $divisionId,
            ':directorate_id' => $directorateId,
            ':department_id' => $departmentId,
            ':supervisor_id' => $supervisorId,
        ]);
        $newUserId = (int) $db->lastInsertId();
        usersLogAction($db, (int) $actor['id'], 'create', 'Created ' . $role . ' account #' . $newUserId . ' (' . $email . ').');
        $db->commit();
        usersResponse(201, ['status' => 'success', 'message' => 'User created successfully.', 'user_id' => $newUserId]);
    }

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        usersResponse(400, ['status' => 'error', 'message' => 'A valid user ID is required.']);
    }
    if ((int) $actor['id'] === $id) {
        usersResponse(400, ['status' => 'error', 'message' => 'You cannot deactivate your own account.']);
    }

    $db->beginTransaction();
    $target = $db->prepare('SELECT id, role, is_active, email FROM users WHERE id = :id FOR UPDATE');
    $target->execute([':id' => $id]);
    $user = $target->fetch(PDO::FETCH_ASSOC);
    if (!$user || !(bool) $user['is_active']) {
        $db->rollBack();
        usersResponse(404, ['status' => 'error', 'message' => 'Active user not found.']);
    }
    if ($user['role'] === 'admin') {
        $activeAdmins = $db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
        if (count($activeAdmins) <= 1) {
            $db->rollBack();
            usersResponse(409, ['status' => 'error', 'message' => 'The final active administrator cannot be deactivated.']);
        }
    }
    $deactivate = $db->prepare('UPDATE users SET is_active = 0, session_version = session_version + 1 WHERE id = :id');
    $deactivate->execute([':id' => $id]);
    $revokeSessions = $db->prepare('UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :id AND revoked_at IS NULL');
    $revokeSessions->execute([':id' => $id]);
    usersLogAction($db, (int) $actor['id'], 'deactivate', 'Deactivated account #' . $id . ' (' . $user['email'] . ').');
    $db->commit();
    usersResponse(200, ['status' => 'success', 'message' => 'User account deactivated.']);
} catch (PDOException $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
        usersResponse(409, ['status' => 'error', 'message' => 'An account already uses that email address.']);
    }
    error_log('Users API database error: ' . $error->getMessage());
    usersResponse(500, ['status' => 'error', 'message' => 'Request could not be completed.']);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Users API request failed: ' . $error->getMessage());
    usersResponse(500, ['status' => 'error', 'message' => 'Request could not be completed.']);
}
