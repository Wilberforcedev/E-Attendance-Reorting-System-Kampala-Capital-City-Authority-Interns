<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reportDetailsResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    reportDetailsResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

try {
    $reportId = filter_input(INPUT_GET, 'report_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$reportId) {
        reportDetailsResponse(400, ['status' => 'error', 'message' => 'A valid report ID is required.']);
    }

    $db = (new Database())->getConnection();
    $auth = new Auth($db);
    $user = $auth->validateToken($auth->getBearerToken());
    if (!$user) {
        reportDetailsResponse(401, ['status' => 'error', 'message' => 'Authentication required.']);
    }

    $reportStmt = $db->prepare(
        'SELECT r.* FROM reports r
         WHERE r.report_id = :report_id
           AND (r.intern_id = :intern_id
                OR (:is_supervisor = 1 AND r.supervisor_id = :supervisor_id)
                OR :is_hr = 1 OR :is_admin = 1)
         LIMIT 1'
    );
    $reportStmt->execute([
        ':report_id' => $reportId,
        ':intern_id' => $user['id'],
        ':is_supervisor' => $user['role'] === 'supervisor' ? 1 : 0,
        ':supervisor_id' => $user['id'],
        ':is_hr' => $user['role'] === 'hr' ? 1 : 0,
        ':is_admin' => $user['role'] === 'admin' ? 1 : 0,
    ]);
    $report = $reportStmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        reportDetailsResponse(404, ['status' => 'error', 'message' => 'Report not found or access denied.']);
    }

    $entries = $db->prepare(
        'SELECT day_of_week, entry_date, time_in, time_out, activities, challenges, tasks_in_progress
         FROM daily_entries WHERE report_id = :report_id ORDER BY entry_date ASC'
    );
    $entries->execute([':report_id' => $reportId]);
    $dailyEntries = $entries->fetchAll(PDO::FETCH_ASSOC);

    $summary = $db->prepare(
        'SELECT key_achievements, major_challenges, plans_next_week
         FROM weekly_summaries WHERE report_id = :report_id LIMIT 1'
    );
    $summary->execute([':report_id' => $reportId]);
    $weeklySummary = $summary->fetch(PDO::FETCH_ASSOC) ?: null;

    $commentsStmt = $db->prepare(
        'SELECT c.comment_id, c.user_id, c.comment, c.created_at, u.full_name
         FROM comments c INNER JOIN users u ON u.id = c.user_id
         WHERE c.report_id = :report_id ORDER BY c.created_at ASC, c.comment_id ASC'
    );
    $commentsStmt->execute([':report_id' => $reportId]);
    $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

    $attachmentsStmt = $db->prepare(
        'SELECT attachment_id, file_name, file_type, file_size, created_at
         FROM report_attachments WHERE report_id = :report_id
         ORDER BY created_at ASC, attachment_id ASC'
    );
    $attachmentsStmt->execute([':report_id' => $reportId]);
    $attachments = array_map(static fn(array $attachment): array => [
        'id' => (int) $attachment['attachment_id'],
        'file_name' => $attachment['file_name'],
        'file_type' => $attachment['file_type'],
        'file_size' => (int) $attachment['file_size'],
        'created_at' => $attachment['created_at'],
        'download_url' => '/backend/api/download_attachment.php?id=' . (int) $attachment['attachment_id'],
    ], $attachmentsStmt->fetchAll(PDO::FETCH_ASSOC));

    reportDetailsResponse(200, ['status' => 'success', 'data' => [
        'report' => $report,
        'daily_entries' => $dailyEntries,
        'weekly_summary' => $weeklySummary,
        'comments' => $comments,
        'attachments' => $attachments,
    ]]);
} catch (Throwable $error) {
    error_log('Report details request failed: ' . $error->getMessage());
    reportDetailsResponse(500, ['status' => 'error', 'message' => 'Request could not be completed.']);
}
