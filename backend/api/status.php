<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PUT");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Authenticate
$token = $auth->getBearerToken();
$userData = $auth->validateToken($token);

if (!$userData) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    try {
        if (empty($data['report_id']) || empty($data['status'])) {
            throw new Exception("Missing report ID or status");
        }

        // Verify user has permission to update status
        $reportQuery = "SELECT intern_id, supervisor_id, status FROM reports WHERE report_id = :report_id";
        $stmt = $db->prepare($reportQuery);
        $stmt->execute([':report_id' => $data['report_id']]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            throw new Exception("Report not found");
        }

        // Authorization logic
        $allowed = false;
        if ($userData['role'] === 'supervisor' && $report['supervisor_id'] == $userData['id']) {
            $allowed = in_array($data['status'], ['approved_by_supervisor', 'rejected']);
        } elseif ($userData['role'] === 'hr') {
            $allowed = in_array($data['status'], ['submitted_to_hr']);
        }

        if (!$allowed) {
            throw new Exception("You don't have permission to perform this action");
        }

        // Update status
        $updateQuery = "UPDATE reports SET status = :status WHERE report_id = :report_id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->execute([
            ':status' => $data['status'],
            ':report_id' => $data['report_id']
        ]);

        // Log the action
        $logQuery = "INSERT INTO audit_logs (user_id, action, description) 
                    VALUES (:user_id, :action, :description)";
        $logStmt = $db->prepare($logQuery);
        $logStmt->execute([
            ':user_id' => $userData['id'],
            ':action' => 'status_update',
            ':description' => "Changed report {$data['report_id']} to {$data['status']}"
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Report status updated successfully'
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}