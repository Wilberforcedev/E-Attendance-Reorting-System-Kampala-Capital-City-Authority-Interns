<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reviewQueueResponse(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    reviewQueueResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

try {
    $db = (new Database())->getConnection();
    $auth = new Auth($db);
    $actor = $auth->validateToken($auth->getBearerToken());
    if (!$actor) {
        reviewQueueResponse(401, ['status' => 'error', 'message' => 'Authentication required.']);
    }

    if ($actor['role'] === 'supervisor') {
        $stmt = $db->prepare(
            "SELECT r.report_id, r.week_number, r.month, r.year, r.status, r.created_at,
                    r.supervisor_comment, u.id AS intern_id, u.full_name AS intern_name,
                    d.name AS department_name, MIN(e.entry_date) AS week_start,
                    MAX(e.entry_date) AS week_end, COUNT(DISTINCT e.entry_id) AS entry_count
             FROM reports r
             INNER JOIN users u ON u.id = r.intern_id
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN daily_entries e ON e.report_id = r.report_id
             WHERE r.supervisor_id = :supervisor_id AND r.status = 'submitted'
             GROUP BY r.report_id, u.id, u.full_name, d.name
             ORDER BY r.created_at ASC, r.report_id ASC
             LIMIT 200"
        );
        $stmt->execute([':supervisor_id' => $actor['id']]);
    } elseif ($actor['role'] === 'hr') {
        $stmt = $db->query(
            "SELECT r.report_id, r.week_number, r.month, r.year, r.status, r.created_at,
                    r.supervisor_comment, u.id AS intern_id, u.full_name AS intern_name,
                    d.name AS department_name, s.full_name AS supervisor_name,
                    MIN(e.entry_date) AS week_start, MAX(e.entry_date) AS week_end,
                    COUNT(DISTINCT e.entry_id) AS entry_count
             FROM reports r
             INNER JOIN users u ON u.id = r.intern_id
             LEFT JOIN users s ON s.id = r.supervisor_id
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN daily_entries e ON e.report_id = r.report_id
             WHERE r.status = 'approved_by_supervisor'
             GROUP BY r.report_id, u.id, u.full_name, d.name, s.full_name
             ORDER BY r.created_at ASC, r.report_id ASC
             LIMIT 200"
        );
    } else {
        reviewQueueResponse(403, ['status' => 'error', 'message' => 'Supervisor or HR access is required.']);
    }

    $reports = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['report_id'],
            'intern_id' => (int) $row['intern_id'],
            'intern_name' => $row['intern_name'],
            'department' => $row['department_name'] ?? '',
            'supervisor' => $row['supervisor_name'] ?? null,
            'week_number' => (int) $row['week_number'],
            'month' => (int) $row['month'],
            'year' => (int) $row['year'],
            'week_start' => $row['week_start'],
            'week_end' => $row['week_end'],
            'status' => $row['status'],
            'entry_count' => (int) $row['entry_count'],
            'submitted_at' => $row['created_at'],
            'supervisor_comment' => $row['supervisor_comment'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    reviewQueueResponse(200, ['status' => 'success', 'count' => count($reports), 'reports' => $reports]);
} catch (Throwable $error) {
    error_log('Report review queue request failed: ' . $error->getMessage());
    reviewQueueResponse(500, ['status' => 'error', 'message' => 'Request could not be completed.']);
}
