<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Method not allowed");
    }

    if (empty($_POST['report_id'])) {
        throw new Exception("Report ID is required");
    }

    // Verify permission
    $permissionQuery = "SELECT report_id FROM reports 
                       WHERE report_id = :report_id 
                       AND intern_id = :user_id";
    $stmt = $db->prepare($permissionQuery);
    $stmt->execute([
        ':report_id' => $_POST['report_id'],
        ':user_id' => $userData['id']
    ]);
    
    if (!$stmt->fetch()) {
        throw new Exception("You can only attach files to your own reports");
    }

    if (empty($_FILES['attachment'])) {
        throw new Exception("No file uploaded");
    }

    $file = $_FILES['attachment'];
    
    // Validate file
    $maxSize = 5 * 1024 * 1024; // 5MB
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    
    if ($file['size'] > $maxSize) {
        throw new Exception("File size exceeds 5MB limit");
    }
    
    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception("Only PDF, JPG, PNG, and DOC/DOCX files are allowed");
    }

    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/../../uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '.' . $extension;
    $filepath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception("Failed to save file");
    }

    // Save to database
    $insertQuery = "INSERT INTO report_attachments 
                   (report_id, file_name, file_path, file_type, file_size)
                   VALUES 
                   (:report_id, :file_name, :file_path, :file_type, :file_size)";
    $insertStmt = $db->prepare($insertQuery);
    $insertStmt->execute([
        ':report_id' => $_POST['report_id'],
        ':file_name' => $file['name'],
        ':file_path' => '/uploads/' . $filename,
        ':file_type' => $file['type'],
        ':file_size' => $file['size']
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'File uploaded successfully',
        'file_path' => '/uploads/' . $filename
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}