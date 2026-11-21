<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function uploadResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    uploadResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 6 * 1024 * 1024) {
    uploadResponse(413, ['status' => 'error', 'message' => 'The upload request is too large.']);
}
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === 0
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && empty($_POST) && empty($_FILES)) {
    uploadResponse(413, ['status' => 'error', 'message' => 'The PHP upload request limit was exceeded.']);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

$filepath = null;
$db = null;
try {
    $db = (new Database())->getConnection();
    $auth = new Auth($db);
    $user = $auth->validateToken($auth->getBearerToken());
    if (!$user) {
        uploadResponse(401, ['status' => 'error', 'message' => 'Authentication required.']);
    }
    if ($user['role'] !== 'intern') {
        uploadResponse(403, ['status' => 'error', 'message' => 'Only interns may upload report attachments.']);
    }
    if (!$auth->hasValidCsrfToken()) {
        uploadResponse(403, ['status' => 'error', 'message' => 'Request verification failed.']);
    }

    $reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$reportId) {
        uploadResponse(400, ['status' => 'error', 'message' => 'A valid report ID is required.']);
    }
    $file = $_FILES['attachment'] ?? null;
    if (!is_array($file)) {
        uploadResponse(400, ['status' => 'error', 'message' => 'Choose a file to upload.']);
    }
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        if (in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            uploadResponse(413, ['status' => 'error', 'message' => 'File size exceeds the 5 MiB limit.']);
        }
        uploadResponse(400, ['status' => 'error', 'message' => 'The uploaded file could not be read.']);
    }
    $tmpName = $file['tmp_name'] ?? '';
    if (!is_string($tmpName) || !is_uploaded_file($tmpName)) {
        uploadResponse(400, ['status' => 'error', 'message' => 'The uploaded file could not be read.']);
    }
    $fileSize = filesize($tmpName);
    if (!is_int($fileSize) || $fileSize < 1 || $fileSize > 5 * 1024 * 1024) {
        uploadResponse(413, ['status' => 'error', 'message' => 'File size must be between 1 byte and 5 MiB.']);
    }

    $originalName = str_replace('\\', '/', (string) ($file['name'] ?? 'attachment'));
    $originalName = basename($originalName);
    $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?? '';
    $originalName = trim($originalName);
    if ($originalName === '' || strlen($originalName) > 255) {
        uploadResponse(400, ['status' => 'error', 'message' => 'File name is invalid or too long.']);
    }

    $allowedTypes = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
    ];
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!is_string($mimeType) || !isset($allowedTypes[$mimeType]) || !in_array($extension, $allowedTypes[$mimeType], true)) {
        uploadResponse(400, ['status' => 'error', 'message' => 'Only matching PDF, JPG, PNG, DOC, or DOCX files are allowed.']);
    }

    $configuredDir = getenv('UPLOAD_DIR');
    if (!is_string($configuredDir) || $configuredDir === '' || !str_starts_with($configuredDir, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('A private absolute UPLOAD_DIR must be configured.');
    }
    if (!is_dir($configuredDir) && !mkdir($configuredDir, 0750, true) && !is_dir($configuredDir)) {
        throw new RuntimeException('Upload storage could not be created.');
    }
    $uploadDir = realpath($configuredDir);
    if ($uploadDir === false || !is_dir($uploadDir) || !is_writable($uploadDir)) {
        throw new RuntimeException('Upload storage is unavailable.');
    }
    $directoryMode = fileperms($uploadDir);
    if (!is_int($directoryMode) || ($directoryMode & 0007) !== 0 || $uploadDir === DIRECTORY_SEPARATOR) {
        throw new RuntimeException('Upload storage must be private and cannot be the filesystem root.');
    }

    $db->beginTransaction();
    $userLock = $db->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1 FOR UPDATE');
    $userLock->execute([':id' => $user['id']]);
    if (!$userLock->fetchColumn()) {
        $db->rollBack();
        uploadResponse(401, ['status' => 'error', 'message' => 'Your account is no longer active.']);
    }

    $reportLock = $db->prepare(
        "SELECT report_id FROM reports WHERE report_id = :report_id AND intern_id = :user_id
         AND status IN ('submitted', 'rejected') FOR UPDATE"
    );
    $reportLock->execute([':report_id' => $reportId, ':user_id' => $user['id']]);
    if (!$reportLock->fetchColumn()) {
        $db->rollBack();
        uploadResponse(404, ['status' => 'error', 'message' => 'An attachment can only be added to your submitted or revision-requested report.']);
    }
    $attachmentCount = $db->prepare('SELECT COUNT(*) FROM report_attachments WHERE report_id = :report_id');
    $attachmentCount->execute([':report_id' => $reportId]);
    if ((int) $attachmentCount->fetchColumn() >= 10) {
        $db->rollBack();
        uploadResponse(409, ['status' => 'error', 'message' => 'A report can have up to 10 attachments.']);
    }

    if (random_int(1, 100) === 1) {
        $db->exec('DELETE FROM upload_events WHERE created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
    }

    $usage = $db->prepare(
        'SELECT COUNT(CASE WHEN created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR THEN 1 END) AS hourly_count,
                COALESCE(SUM(CASE WHEN created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY THEN file_size ELSE 0 END), 0) AS daily_bytes
         FROM upload_events
         WHERE user_id = :user_id AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY'
    );
    $usage->execute([':user_id' => $user['id']]);
    $limits = $usage->fetch(PDO::FETCH_ASSOC);
    if ((int) $limits['hourly_count'] >= 10 || (int) $limits['daily_bytes'] + $fileSize > 50 * 1024 * 1024) {
        $db->rollBack();
        header('Retry-After: 3600');
        uploadResponse(429, ['status' => 'error', 'message' => 'Upload limit reached. Try again later.']);
    }

    $storageKey = bin2hex(random_bytes(24)) . '.' . $extension;
    $filepath = $uploadDir . DIRECTORY_SEPARATOR . $storageKey;
    if (!move_uploaded_file($tmpName, $filepath)) {
        throw new RuntimeException('The file could not be stored.');
    }
    chmod($filepath, 0640);

    $insert = $db->prepare(
        'INSERT INTO report_attachments (report_id, file_name, file_path, file_type, file_size)
         VALUES (:report_id, :file_name, :file_path, :file_type, :file_size)'
    );
    $insert->execute([
        ':report_id' => $reportId,
        ':file_name' => $originalName,
        ':file_path' => $storageKey,
        ':file_type' => $mimeType,
        ':file_size' => $fileSize,
    ]);
    $attachmentId = (int) $db->lastInsertId();

    $event = $db->prepare('INSERT INTO upload_events (user_id, file_size) VALUES (:user_id, :file_size)');
    $event->execute([':user_id' => $user['id'], ':file_size' => $fileSize]);
    $audit = $db->prepare(
        "INSERT INTO audit_logs (user_id, action, description, ip_address)
         VALUES (:user_id, 'attachment_uploaded', :description, :ip_address)"
    );
    $audit->execute([
        ':user_id' => $user['id'],
        ':description' => 'Uploaded attachment #' . $attachmentId . ' for report #' . $reportId . '.',
        ':ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
    ]);

    $db->commit();
    uploadResponse(201, [
        'status' => 'success',
        'message' => 'File uploaded successfully.',
        'attachment_id' => $attachmentId,
        'file_name' => $originalName,
    ]);
} catch (PDOException $error) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    if (is_string($filepath) && is_file($filepath)) {
        unlink($filepath);
    }
    error_log('Upload API database error: ' . $error->getMessage());
    uploadResponse(500, ['status' => 'error', 'message' => 'Upload could not be completed.']);
} catch (Throwable $error) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    if (is_string($filepath) && is_file($filepath)) {
        unlink($filepath);
    }
    error_log('Upload API request failed: ' . $error->getMessage());
    uploadResponse(500, ['status' => 'error', 'message' => 'Upload service is unavailable.']);
}
