<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function statusResponse(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'PUT') {
    header('Allow: PUT');
    statusResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    statusResponse(413, ['status' => 'error', 'message' => 'Request is too large.']);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

try {
    $db = (new Database())->getConnection();
    $auth = new Auth($db);
    $actor = $auth->validateToken($auth->getBearerToken());
    if (!$actor) {
        statusResponse(401, ['status' => 'error', 'message' => 'Authentication required.']);
    }
    if (!$auth->hasValidCsrfToken()) {
        statusResponse(403, ['status' => 'error', 'message' => 'Request verification failed.']);
    }

    $rawBody = file_get_contents('php://input', false, null, 0, 16385);
    if (!is_string($rawBody) || strlen($rawBody) > 16384) {
        statusResponse(413, ['status' => 'error', 'message' => 'Request is too large.']);
    }
    $data = json_decode($rawBody, true);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
        statusResponse(400, ['status' => 'error', 'message' => 'A valid JSON object is required.']);
    }

    $reportId = filter_var($data['report_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $nextStatus = $data['status'] ?? null;
    $comment = $data['comment'] ?? '';
    if (!$reportId || !is_string($nextStatus) || !is_string($comment) || strlen($comment) > 2000) {
        statusResponse(400, ['status' => 'error', 'message' => 'Provide a valid report, decision, and comment.']);
    }
    $comment = trim($comment);

    if ($actor['role'] === 'supervisor') {
        if (!in_array($nextStatus, ['approved_by_supervisor', 'rejected'], true)) {
            statusResponse(400, ['status' => 'error', 'message' => 'Select approve or request revisions.']);
        }
        if ($nextStatus === 'rejected' && $comment === '') {
            statusResponse(400, ['status' => 'error', 'message' => 'A comment is required when requesting revisions.']);
        }
        $action = $nextStatus === 'rejected' ? 'report_rejected' : 'report_approved';
    } elseif ($actor['role'] === 'hr') {
        if ($nextStatus !== 'submitted_to_hr') {
            statusResponse(400, ['status' => 'error', 'message' => 'HR may only complete review of supervisor-approved reports.']);
        }
        $action = 'report_forwarded_to_hr';
    } else {
        statusResponse(403, ['status' => 'error', 'message' => 'Supervisor or HR access is required.']);
    }

    $db->beginTransaction();
    $reportStmt = $db->prepare('SELECT report_id, supervisor_id, status FROM reports WHERE report_id = :report_id FOR UPDATE');
    $reportStmt->execute([':report_id' => $reportId]);
    $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        $db->rollBack();
        statusResponse(404, ['status' => 'error', 'message' => 'Report not found.']);
    }

    if ($actor['role'] === 'supervisor') {
        if ((int) $report['supervisor_id'] !== (int) $actor['id']) {
            $db->rollBack();
            statusResponse(403, ['status' => 'error', 'message' => 'This report is not assigned to you.']);
        }
        if ($report['status'] !== 'submitted') {
            $db->rollBack();
            statusResponse(409, ['status' => 'error', 'message' => 'Only submitted reports can be reviewed.']);
        }
        $update = $db->prepare('UPDATE reports SET status = :status, supervisor_comment = :comment WHERE report_id = :report_id AND status = :current_status');
        $update->execute([
            ':status' => $nextStatus,
            ':comment' => $comment === '' ? null : $comment,
            ':report_id' => $reportId,
            ':current_status' => 'submitted',
        ]);
    } else {
        if ($report['status'] !== 'approved_by_supervisor') {
            $db->rollBack();
            statusResponse(409, ['status' => 'error', 'message' => 'Only supervisor-approved reports can be completed by HR.']);
        }
        $update = $db->prepare('UPDATE reports SET status = :status WHERE report_id = :report_id AND status = :current_status');
        $update->execute([
            ':status' => 'submitted_to_hr',
            ':report_id' => $reportId,
            ':current_status' => 'approved_by_supervisor',
        ]);
    }

    if ($update->rowCount() !== 1) {
        $db->rollBack();
        statusResponse(409, ['status' => 'error', 'message' => 'The report changed before your decision was saved. Refresh and try again.']);
    }

    $audit = $db->prepare(
        'INSERT INTO audit_logs (user_id, action, description, ip_address)
         VALUES (:user_id, :action, :description, :ip_address)'
    );
    $audit->execute([
        ':user_id' => $actor['id'],
        ':action' => $action,
        ':description' => 'Changed report #' . $reportId . ' from ' . $report['status'] . ' to ' . $nextStatus . ($comment === '' ? '' : '. Comment: ' . $comment),
        ':ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
    ]);
    $db->commit();

    statusResponse(200, ['status' => 'success', 'message' => 'Report decision saved.', 'report_id' => (int) $reportId, 'report_status' => $nextStatus]);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Report status API request failed: ' . $error->getMessage());
    statusResponse(500, ['status' => 'error', 'message' => 'Request could not be completed.']);
}
