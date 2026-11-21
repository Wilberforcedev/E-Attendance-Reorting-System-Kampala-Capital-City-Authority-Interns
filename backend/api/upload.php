<?php
header("Content-Type: application/json");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

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

    if (!isset($_FILES['attachment']) || !is_array($_FILES['attachment'])) {
        throw new Exception("No file uploaded");
    }

    $file = $_FILES['attachment'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new Exception("The uploaded file could not be read");
    }

    $maxSize = 5 * 1024 * 1024;
    if ($file['size'] < 1 || $file['size'] > $maxSize) {
        throw new Exception("File size exceeds 5MB limit");
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($file['tmp_name']);
    $allowedTypes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    if (!isset($allowedTypes[$mimeType])) {
        throw new Exception("Only PDF, JPG, PNG, and DOC/DOCX files are allowed");
    }

    $uploadDir = getenv('UPLOAD_DIR');
    if (!is_string($uploadDir) || $uploadDir === '' || !str_starts_with($uploadDir, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException("A private absolute UPLOAD_DIR must be configured");
    }
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
        throw new RuntimeException("Upload storage could not be created");
    }

    $storageKey = bin2hex(random_bytes(24)) . '.' . $allowedTypes[$mimeType];
    $filepath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storageKey;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        throw new Exception("Failed to save file");
    }
    chmod($filepath, 0640);

    // Save to database
    $insertQuery = "INSERT INTO report_attachments 
                   (report_id, file_name, file_path, file_type, file_size)
                   VALUES 
                   (:report_id, :file_name, :file_path, :file_type, :file_size)";
    $insertStmt = $db->prepare($insertQuery);
    $insertStmt->execute([
        ':report_id' => $_POST['report_id'],
        ':file_name' => basename((string) $file['name']),
        ':file_path' => $storageKey,
        ':file_type' => $mimeType,
        ':file_size' => $file['size']
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'File uploaded successfully',
        'attachment_id' => (int) $db->lastInsertId()
    ]);

} catch (PDOException $e) {
    error_log('Upload API database error: ' . $e->getMessage());
    if (isset($filepath) && is_file($filepath)) {
        unlink($filepath);
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Request could not be completed']);
} catch (RuntimeException $e) {
    error_log('Upload API configuration error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Upload service is not configured']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
