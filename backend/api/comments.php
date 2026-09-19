<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, DELETE");
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

$data = json_decode(file_get_contents("php://input"), true);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Add comment
        if (empty($data['report_id']) || empty($data['comment'])) {
            throw new Exception("Report ID and comment are required");
        }

        // Verify permission
        $permissionQuery = "SELECT report_id FROM reports 
                           WHERE report_id = :report_id 
                           AND (intern_id = :user_id 
                               OR supervisor_id = :user_id 
                               OR :is_hr = 1)";
        $stmt = $db->prepare($permissionQuery);
        $stmt->execute([
            ':report_id' => $data['report_id'],
            ':user_id' => $userData['id'],
            ':is_hr' => ($userData['role'] === 'hr') ? 1 : 0
        ]);
        
        if (!$stmt->fetch()) {
            throw new Exception("You don't have permission to comment on this report");
        }

        $insertQuery = "INSERT INTO comments 
                       (report_id, user_id, comment)
                       VALUES 
                       (:report_id, :user_id, :comment)";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->execute([
            ':report_id' => $data['report_id'],
            ':user_id' => $userData['id'],
            ':comment' => $data['comment']
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Comment added successfully'
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        // Delete comment
        if (empty($data['comment_id'])) {
            throw new Exception("Comment ID is required");
        }

        // Verify ownership
        $ownershipQuery = "SELECT user_id FROM comments 
                          WHERE comment_id = :comment_id";
        $stmt = $db->prepare($ownershipQuery);
        $stmt->execute([':comment_id' => $data['comment_id']]);
        $comment = $stmt->fetch();

        if (!$comment || $comment['user_id'] != $userData['id']) {
            throw new Exception("You can only delete your own comments");
        }

        $deleteQuery = "DELETE FROM comments 
                       WHERE comment_id = :comment_id";
        $deleteStmt = $db->prepare($deleteQuery);
        $deleteStmt->execute([':comment_id' => $data['comment_id']]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Comment deleted successfully'
        ]);

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get comments for a report
        if (empty($_GET['report_id'])) {
            throw new Exception("Report ID is required");
        }

        $commentsQuery = "SELECT c.*, u.full_name, u.role 
                         FROM comments c
                         JOIN users u ON c.user_id = u.id
                         WHERE c.report_id = :report_id
                         ORDER BY c.created_at DESC";
        $commentsStmt = $db->prepare($commentsQuery);
        $commentsStmt->execute([':report_id' => $_GET['report_id']]);
        $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => $comments
        ]);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}