<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('X-Frame-Options: DENY');

function downloadError(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    downloadError(405, 'Method not allowed.');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

try {
    $attachmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$attachmentId) {
        downloadError(400, 'A valid attachment ID is required.');
    }

    $db = (new Database())->getConnection();
    $auth = new Auth($db);
    $user = $auth->validateToken($auth->getBearerToken());
    if (!$user) {
        downloadError(401, 'Authentication required.');
    }

    $stmt = $db->prepare(
        'SELECT a.attachment_id, a.report_id, a.file_name, a.file_path, a.file_type, a.file_size,
                r.intern_id, r.supervisor_id
         FROM report_attachments a
         INNER JOIN reports r ON r.report_id = a.report_id
         WHERE a.attachment_id = :attachment_id
           AND (r.intern_id = :user_id
                OR (:is_supervisor = 1 AND r.supervisor_id = :supervisor_id)
                OR :is_hr = 1 OR :is_admin = 1)
         LIMIT 1'
    );
    $stmt->execute([
        ':attachment_id' => $attachmentId,
        ':user_id' => $user['id'],
        ':is_supervisor' => $user['role'] === 'supervisor' ? 1 : 0,
        ':supervisor_id' => $user['id'],
        ':is_hr' => $user['role'] === 'hr' ? 1 : 0,
        ':is_admin' => $user['role'] === 'admin' ? 1 : 0,
    ]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$attachment) {
        downloadError(404, 'Attachment not found.');
    }

    $storageKey = (string) $attachment['file_path'];
    if (preg_match('/^[a-f0-9]{48}\.(pdf|jpg|jpeg|png|doc|docx)$/D', $storageKey) !== 1) {
        throw new RuntimeException('Stored attachment key is invalid.');
    }
    $configuredDir = getenv('UPLOAD_DIR');
    if (!is_string($configuredDir) || $configuredDir === '' || !str_starts_with($configuredDir, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Private upload storage is not configured.');
    }
    $root = realpath($configuredDir);
    if ($root === false || !is_dir($root) || $root === DIRECTORY_SEPARATOR) {
        throw new RuntimeException('Private upload storage is unavailable.');
    }
    $path = realpath($root . DIRECTORY_SEPARATOR . $storageKey);
    $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($path === false || !is_file($path) || !str_starts_with($path, $rootPrefix)) {
        downloadError(404, 'Attachment file is unavailable.');
    }

    $size = filesize($path);
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $allowedMimeTypes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    if (!is_int($size) || $size < 1 || $size > 5 * 1024 * 1024 || !is_string($mimeType)
        || !in_array($mimeType, $allowedMimeTypes, true) || $mimeType !== $attachment['file_type']) {
        throw new RuntimeException('Stored attachment failed integrity checks.');
    }

    $audit = $db->prepare(
        "INSERT INTO audit_logs (user_id, action, description, ip_address)
         VALUES (:user_id, 'attachment_downloaded', :description, :ip_address)"
    );
    $audit->execute([
        ':user_id' => $user['id'],
        ':description' => 'Downloaded attachment #' . $attachmentId . ' for report #' . $attachment['report_id'] . '.',
        ':ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
    ]);

    $name = basename(str_replace('\\', '/', (string) $attachment['file_name']));
    $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/u', '_', $name) ?? 'attachment';
    $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'attachment';
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . $size);
    header("Content-Disposition: attachment; filename=\"{$asciiName}\"; filename*=UTF-8''" . rawurlencode($name));
    header("Content-Security-Policy: sandbox; default-src 'none'");
    readfile($path);
    exit;
} catch (Throwable $error) {
    error_log('Attachment download request failed: ' . $error->getMessage());
    downloadError(500, 'Attachment could not be delivered.');
}
