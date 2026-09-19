// api/reports/details.php
<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

$token = $auth->getBearerToken();
$userData = $auth->validateToken($token);

if (!$userData) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

try {
    if (empty($_GET['report_id'])) {
        throw new Exception("Report ID is required");
    }

    $reportId = $_GET['report_id'];

    // Verify user has permission to view this report
    $permissionQuery = "SELECT r.* FROM reports r 
                       WHERE r.report_id = :report_id 
                       AND (r.intern_id = :user_id 
                           OR r.supervisor_id = :user_id 
                           OR :is_hr = 1)";
    
    $stmt = $db->prepare($permissionQuery);
    $stmt->execute([
        ':report_id' => $reportId,
        ':user_id' => $userData['id'],
        ':is_hr' => ($userData['role'] === 'hr') ? 1 : 0
    ]);
    
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        throw new Exception("Report not found or you don't have permission to view it");
    }

    // Get daily entries
    $entriesQuery = "SELECT * FROM daily_entries 
                    WHERE report_id = :report_id 
                    ORDER BY entry_date ASC";
    $entriesStmt = $db->prepare($entriesQuery);
    $entriesStmt->execute([':report_id' => $reportId]);
    $dailyEntries = $entriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get weekly summary
    $summaryQuery = "SELECT * FROM weekly_summaries 
                    WHERE report_id = :report_id";
    $summaryStmt = $db->prepare($summaryQuery);
    $summaryStmt->execute([':report_id' => $reportId]);
    $weeklySummary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    // Get comments if any
    $commentsQuery = "SELECT c.*, u.full_name FROM comments c
                     JOIN users u ON c.user_id = u.id
                     WHERE c.report_id = :report_id
                     ORDER BY c.created_at DESC";
    $commentsStmt = $db->prepare($commentsQuery);
    $commentsStmt->execute([':report_id' => $reportId]);
    $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get attachments if any
    $attachmentsQuery = "SELECT * FROM report_attachments
                        WHERE report_id = :report_id";
    $attachmentsStmt = $db->prepare($attachmentsQuery);
    $attachmentsStmt->execute([':report_id' => $reportId]);
    $attachments = $attachmentsStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => [
            'report' => $report,
            'daily_entries' => $dailyEntries,
            'weekly_summary' => $weeklySummary,
            'comments' => $comments,
            'attachments' => $attachments
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}