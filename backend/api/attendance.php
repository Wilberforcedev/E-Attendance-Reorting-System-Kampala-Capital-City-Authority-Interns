<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

function attendanceResponse(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function attendanceSession(array $row, DateTimeImmutable $nowUtc): array
{
    $clockIn = new DateTimeImmutable($row['clock_in_utc'], new DateTimeZone('UTC'));
    $clockOut = $row['clock_out_utc'] === null
        ? null
        : new DateTimeImmutable($row['clock_out_utc'], new DateTimeZone('UTC'));
    $end = $clockOut ?? $nowUtc;

    return [
        'id' => (int) $row['session_id'],
        'work_date' => $row['work_date'],
        'clock_in_utc' => $clockIn->format('Y-m-d\TH:i:s\Z'),
        'clock_out_utc' => $clockOut?->format('Y-m-d\TH:i:s\Z'),
        'duration_seconds' => max(0, $end->getTimestamp() - $clockIn->getTimestamp()),
        'status' => $clockOut === null ? 'in_progress' : 'completed',
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    attendanceResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($method === 'POST' && $contentLength > 4096) {
    attendanceResponse(413, ['success' => false, 'message' => 'Request is too large.']);
}

try {
    $db = (new Database())->getConnection();
    $auth = new Auth($db);
    $user = $auth->validateToken($auth->getBearerToken());
    if (!$user) {
        attendanceResponse(401, ['success' => false, 'message' => 'Authentication required.']);
    }
    if ($user['role'] !== 'intern') {
        attendanceResponse(403, ['success' => false, 'message' => 'Intern access required.']);
    }
    if (!$auth->hasValidCsrfToken()) {
        attendanceResponse(403, ['success' => false, 'message' => 'Request verification failed.']);
    }

    $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($method === 'GET') {
        $workDate = new DateTimeImmutable('now', new DateTimeZone('Africa/Kampala'));
        $since = $workDate->modify('-31 days')->format('Y-m-d');
        $stmt = $db->prepare(
            'SELECT session_id, work_date, clock_in_utc, clock_out_utc
             FROM attendance_sessions
             WHERE intern_id = :intern_id AND work_date >= :since
             ORDER BY work_date DESC, clock_in_utc DESC'
        );
        $stmt->execute([':intern_id' => $user['id'], ':since' => $since]);
        $sessions = array_map(fn(array $row): array => attendanceSession($row, $nowUtc), $stmt->fetchAll(PDO::FETCH_ASSOC));
        $active = null;
        foreach ($sessions as $session) {
            if ($session['status'] === 'in_progress') {
                $active = $session;
                break;
            }
        }
        attendanceResponse(200, ['success' => true, 'sessions' => $sessions, 'active_session' => $active]);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
        attendanceResponse(400, ['success' => false, 'message' => 'A valid JSON object is required.']);
    }
    $action = $payload['action'] ?? '';
    if (!in_array($action, ['clock_in', 'clock_out'], true)) {
        attendanceResponse(400, ['success' => false, 'message' => 'Choose clock_in or clock_out.']);
    }

    $db->beginTransaction();
    $lockUser = $db->prepare('SELECT id FROM users WHERE id = :intern_id FOR UPDATE');
    $lockUser->execute([':intern_id' => $user['id']]);
    if ($action === 'clock_in') {
        $openStmt = $db->prepare(
            'SELECT session_id FROM attendance_sessions
             WHERE intern_id = :intern_id AND clock_out_utc IS NULL LIMIT 1 FOR UPDATE'
        );
        $openStmt->execute([':intern_id' => $user['id']]);
        if ($openStmt->fetchColumn()) {
            $db->rollBack();
            attendanceResponse(409, ['success' => false, 'message' => 'Clock out of the active session before starting another.']);
        }
        $workDate = new DateTimeImmutable('now', new DateTimeZone('Africa/Kampala'));
        $stmt = $db->prepare(
            'SELECT session_id FROM attendance_sessions
             WHERE intern_id = :intern_id AND work_date = :work_date LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':intern_id' => $user['id'], ':work_date' => $workDate->format('Y-m-d')]);
        if ($stmt->fetchColumn()) {
            $db->rollBack();
            attendanceResponse(409, ['success' => false, 'message' => 'An attendance session already exists for today.']);
        }

        $insert = $db->prepare(
            'INSERT INTO attendance_sessions (intern_id, work_date, clock_in_utc)
             VALUES (:intern_id, :work_date, :clock_in_utc)'
        );
        $insert->execute([
            ':intern_id' => $user['id'],
            ':work_date' => $workDate->format('Y-m-d'),
            ':clock_in_utc' => $nowUtc->format('Y-m-d H:i:s.v'),
        ]);
        $sessionId = (int) $db->lastInsertId();
    } else {
        $stmt = $db->prepare(
            'SELECT session_id, work_date, clock_in_utc, clock_out_utc
             FROM attendance_sessions
             WHERE intern_id = :intern_id AND clock_out_utc IS NULL
             ORDER BY clock_in_utc DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':intern_id' => $user['id']]);
        $openSession = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$openSession) {
            $db->rollBack();
            attendanceResponse(409, ['success' => false, 'message' => 'There is no active attendance session to clock out.']);
        }

        $update = $db->prepare(
            'UPDATE attendance_sessions SET clock_out_utc = :clock_out_utc
             WHERE session_id = :session_id AND clock_out_utc IS NULL'
        );
        $update->execute([
            ':clock_out_utc' => $nowUtc->format('Y-m-d H:i:s.v'),
            ':session_id' => $openSession['session_id'],
        ]);
        $sessionId = (int) $openSession['session_id'];
    }

    $db->commit();
    $read = $db->prepare(
        'SELECT session_id, work_date, clock_in_utc, clock_out_utc
         FROM attendance_sessions WHERE session_id = :session_id'
    );
    $read->execute([':session_id' => $sessionId]);
    $row = $read->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        attendanceResponse(500, ['success' => false, 'message' => 'Attendance record could not be loaded.']);
    }
    attendanceResponse(200, ['success' => true, 'session' => attendanceSession($row, $nowUtc)]);
} catch (PDOException $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
        attendanceResponse(409, ['success' => false, 'message' => 'An attendance session already exists for today.']);
    }
    error_log('Attendance API database error: ' . $error->getMessage());
    attendanceResponse(500, ['success' => false, 'message' => 'Request could not be completed.']);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Attendance API request failed: ' . $error->getMessage());
    attendanceResponse(500, ['success' => false, 'message' => 'Request could not be completed.']);
}
