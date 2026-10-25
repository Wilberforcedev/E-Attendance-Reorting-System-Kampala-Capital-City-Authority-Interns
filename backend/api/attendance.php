<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Kampala');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt.php';

function respond(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function requestData(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) respond(400, ['status' => 'error', 'message' => 'Request body must be valid JSON.']);
    return $data;
}

function bearerToken(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    return preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches) ? trim($matches[1]) : null;
}

$token = bearerToken();
$user = $token ? verifyToken($token) : false;
if (!$user || empty($user->id) || empty($user->role)) {
    respond(401, ['status' => 'error', 'message' => 'A valid bearer token is required.']);
}

$database = new Database();
$db = $database->getConnection();
if (!$db instanceof PDO) {
    error_log('EARS attendance API: database connection unavailable.');
    respond(503, ['status' => 'error', 'message' => 'Attendance service is temporarily unavailable.']);
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$role = (string) $user->role;
$userId = (int) $user->id;
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $params = [];
        $where = '';
        if ($role === 'intern') {
            $where = 'WHERE a.intern_id = :intern_id';
            $params[':intern_id'] = $userId;
        } elseif (!in_array($role, ['supervisor', 'hr', 'admin'], true)) {
            respond(403, ['status' => 'error', 'message' => 'You do not have permission to view attendance.']);
        } elseif (!empty($_GET['intern_id'])) {
            $where = 'WHERE a.intern_id = :intern_id';
            $params[':intern_id'] = (int) $_GET['intern_id'];
        }

        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
        $limit = max(1, min($limit, 200));
        $stmt = $db->prepare(
            "SELECT a.id, a.intern_id, u.full_name, u.email, u.department, a.attendance_date,
                    a.check_in_at, a.check_out_at, a.location, a.notes
             FROM attendance_records a
             JOIN users u ON u.id = a.intern_id
             $where
             ORDER BY a.attendance_date DESC, a.check_in_at DESC
             LIMIT $limit"
        );
        $stmt->execute($params);
        respond(200, ['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST, OPTIONS');
        respond(405, ['status' => 'error', 'message' => 'Method not allowed.']);
    }

    $data = requestData();
    $action = (string) ($data['action'] ?? '');

    if ($action === 'create_qr') {
        if (!in_array($role, ['supervisor', 'hr', 'admin'], true)) {
            respond(403, ['status' => 'error', 'message' => 'Only supervisors or administrators can issue check-in QR codes.']);
        }
        $location = trim((string) ($data['location'] ?? ''));
        if ($location === '' || mb_strlen($location) > 160) {
            respond(422, ['status' => 'error', 'message' => 'Provide a location of 1–160 characters.']);
        }

        $rawQrToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawQrToken);
        $expiresAt = (new DateTimeImmutable('+2 minutes'))->format('Y-m-d H:i:s');
        $stmt = $db->prepare(
            'INSERT INTO attendance_qr_sessions (token_hash, location, created_by, expires_at)
             VALUES (:token_hash, :location, :created_by, :expires_at)'
        );
        $stmt->execute([
            ':token_hash' => $tokenHash,
            ':location' => $location,
            ':created_by' => $userId,
            ':expires_at' => $expiresAt
        ]);

        $audit = $db->prepare(
            'INSERT INTO attendance_audit_logs (user_id, action, description, ip_address)
             VALUES (:user_id, :action, :description, :ip_address)'
        );
        $audit->execute([
            ':user_id' => $userId,
            ':action' => 'qr_created',
            ':description' => 'Issued a short-lived attendance QR session for ' . $location,
            ':ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)
        ]);

        // Return the raw token once only. The QR display should encode this token and expiry.
        respond(201, [
            'status' => 'success',
            'message' => 'QR check-in token created. It expires in two minutes.',
            'data' => ['token' => $rawQrToken, 'location' => $location, 'expires_at' => $expiresAt]
        ]);
    }

    if ($action === 'check_in') {
        if ($role !== 'intern') respond(403, ['status' => 'error', 'message' => 'Only intern accounts can check in.']);
        $rawQrToken = trim((string) ($data['qr_token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/i', $rawQrToken)) {
            respond(422, ['status' => 'error', 'message' => 'A valid QR token is required.']);
        }

        $now = date('Y-m-d H:i:s');
        $today = date('Y-m-d');
        $db->beginTransaction();

        $qr = $db->prepare(
            'SELECT id, location FROM attendance_qr_sessions
             WHERE token_hash = :token_hash AND expires_at > :now AND revoked_at IS NULL
             LIMIT 1 FOR UPDATE'
        );
        $qr->execute([':token_hash' => hash('sha256', $rawQrToken), ':now' => $now]);
        $session = $qr->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            $db->rollBack();
            respond(422, ['status' => 'error', 'message' => 'This QR code is invalid, expired, or revoked.']);
        }

        $existing = $db->prepare(
            'SELECT id FROM attendance_records WHERE intern_id = :intern_id AND attendance_date = :attendance_date LIMIT 1'
        );
        $existing->execute([':intern_id' => $userId, ':attendance_date' => $today]);
        if ($existing->fetch()) {
            $db->rollBack();
            respond(409, ['status' => 'error', 'message' => 'Attendance has already been recorded for today.']);
        }

        $insert = $db->prepare(
            'INSERT INTO attendance_records (intern_id, attendance_date, check_in_at, location, qr_session_id)
             VALUES (:intern_id, :attendance_date, :check_in_at, :location, :qr_session_id)'
        );
        $insert->execute([
            ':intern_id' => $userId,
            ':attendance_date' => $today,
            ':check_in_at' => $now,
            ':location' => $session['location'],
            ':qr_session_id' => $session['id']
        ]);
        $recordId = (int) $db->lastInsertId();

        $audit = $db->prepare(
            'INSERT INTO attendance_audit_logs (user_id, attendance_record_id, action, description, ip_address)
             VALUES (:user_id, :record_id, :action, :description, :ip_address)'
        );
        $audit->execute([
            ':user_id' => $userId,
            ':record_id' => $recordId,
            ':action' => 'check_in',
            ':description' => 'Intern checked in via QR at ' . $session['location'],
            ':ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)
        ]);

        $db->commit();
        respond(201, [
            'status' => 'success',
            'message' => 'Check-in recorded successfully.',
            'data' => ['id' => $recordId, 'attendance_date' => $today, 'check_in_at' => $now, 'location' => $session['location']]
        ]);
    }

    if ($action === 'check_out') {
        if ($role !== 'intern') respond(403, ['status' => 'error', 'message' => 'Only intern accounts can check out.']);
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $db->beginTransaction();
        $stmt = $db->prepare(
            'SELECT id, check_out_at FROM attendance_records
             WHERE intern_id = :intern_id AND attendance_date = :attendance_date
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':intern_id' => $userId, ':attendance_date' => $today]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            $db->rollBack();
            respond(404, ['status' => 'error', 'message' => 'No check-in was found for today.']);
        }
        if ($record['check_out_at'] !== null) {
            $db->rollBack();
            respond(409, ['status' => 'error', 'message' => 'You have already checked out today.']);
        }

        $update = $db->prepare('UPDATE attendance_records SET check_out_at = :check_out_at WHERE id = :id');
        $update->execute([':check_out_at' => $now, ':id' => $record['id']]);
        $audit = $db->prepare(
            'INSERT INTO attendance_audit_logs (user_id, attendance_record_id, action, description, ip_address)
             VALUES (:user_id, :record_id, :action, :description, :ip_address)'
        );
        $audit->execute([
            ':user_id' => $userId,
            ':record_id' => $record['id'],
            ':action' => 'check_out',
            ':description' => 'Intern checked out for ' . $today,
            ':ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)
        ]);
        $db->commit();
        respond(200, ['status' => 'success', 'message' => 'Check-out recorded successfully.', 'data' => ['id' => (int) $record['id'], 'check_out_at' => $now]]);
    }

    respond(422, ['status' => 'error', 'message' => 'Unknown action. Use create_qr, check_in, or check_out.']);
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    if ($e->getCode() === '23000') {
        respond(409, ['status' => 'error', 'message' => 'Attendance already exists for today or a related record is invalid.']);
    }
    error_log('EARS attendance API database error: ' . $e->getMessage());
    respond(500, ['status' => 'error', 'message' => 'Unable to process attendance right now.']);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('EARS attendance API error: ' . $e->getMessage());
    respond(500, ['status' => 'error', 'message' => 'Unable to process attendance right now.']);
}
