<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

function reportsResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function reportStatusLabel(string $status): string
{
    return match ($status) {
        'approved_by_supervisor', 'submitted_to_hr' => 'Approved',
        'rejected' => 'Revision',
        default => 'Pending',
    };
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    reportsResponse(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($method === 'POST' && $contentLength > 65536) {
    reportsResponse(413, ['status' => 'error', 'message' => 'Request is too large.']);
}

try {
    $db = (new Database())->getConnection();
    $auth = new Auth($db);
    $user = $auth->validateToken($auth->getBearerToken());
    if (!$user) {
        reportsResponse(401, ['status' => 'error', 'message' => 'Authentication required.']);
    }
    if ($user['role'] !== 'intern') {
        reportsResponse(403, ['status' => 'error', 'message' => 'Intern access required.']);
    }

    if ($method === 'GET') {
        $reportStmt = $db->prepare(
            'SELECT r.report_id, r.week_number, r.month, r.year, r.status,
                    r.supervisor_comment, r.created_at,
                    s.full_name AS supervisor_name,
                    MIN(d.entry_date) AS week_start, MAX(d.entry_date) AS week_end
             FROM reports r
             LEFT JOIN daily_entries d ON d.report_id = r.report_id
             LEFT JOIN users s ON s.id = r.supervisor_id
             WHERE r.intern_id = :intern_id
             GROUP BY r.report_id
             ORDER BY r.year DESC, r.week_number DESC
             LIMIT 100'
        );
        $reportStmt->execute([':intern_id' => $user['id']]);
        $reports = [];
        $entryStmt = $db->prepare(
            'SELECT day_of_week, entry_date, time_in, time_out, activities, challenges, tasks_in_progress
             FROM daily_entries WHERE report_id = :report_id
             ORDER BY entry_date ASC'
        );
        foreach ($reportStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entryStmt->execute([':report_id' => $row['report_id']]);
            $entries = $entryStmt->fetchAll(PDO::FETCH_ASSOC);
            $totalSeconds = 0;
            $tasks = [];
            foreach ($entries as $entry) {
                $dayHours = 0.0;
                if ($entry['time_in'] !== null && $entry['time_out'] !== null) {
                    $in = new DateTimeImmutable($entry['entry_date'] . ' ' . $entry['time_in'], new DateTimeZone('Africa/Kampala'));
                    $out = new DateTimeImmutable($entry['entry_date'] . ' ' . $entry['time_out'], new DateTimeZone('Africa/Kampala'));
                    $dayHours = max(0, $out->getTimestamp() - $in->getTimestamp()) / 3600;
                    $totalSeconds += (int) round($dayHours * 3600);
                }
                $tasks[] = [
                    'day' => $entry['day_of_week'],
                    'date' => $entry['entry_date'],
                    'hours' => round($dayHours, 1),
                    'task' => $entry['activities'],
                    'challenges' => $entry['challenges'],
                    'tasksInProgress' => $entry['tasks_in_progress'],
                ];
            }
            $start = $row['week_start'] ? new DateTimeImmutable($row['week_start']) : null;
            $end = $row['week_end'] ? new DateTimeImmutable($row['week_end']) : null;
            $reports[] = [
                'id' => (int) $row['report_id'],
                'weekNumber' => (int) $row['week_number'],
                'weekRange' => $start && $end ? $start->format('d M Y') . ' - ' . $end->format('d M Y') : '',
                'submittedDate' => (new DateTimeImmutable($row['created_at']))->format('d M Y'),
                'hoursLogged' => round($totalSeconds / 3600, 1),
                'status' => reportStatusLabel($row['status']),
                'supervisor' => $row['supervisor_name'] ?? 'Not assigned',
                'feedback' => $row['supervisor_comment'] ?? '',
                'tasks' => $tasks,
            ];
        }
        reportsResponse(200, ['status' => 'success', 'reports' => $reports]);
    }

    if (!$auth->hasValidCsrfToken()) {
        reportsResponse(403, ['status' => 'error', 'message' => 'Request verification failed.']);
    }

    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
        reportsResponse(400, ['status' => 'error', 'message' => 'A valid JSON object is required.']);
    }

    $weekStartValue = $payload['week_start_date'] ?? '';
    if (!is_string($weekStartValue)) {
        reportsResponse(400, ['status' => 'error', 'message' => 'A Monday week start date is required.']);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $weekStartValue) !== 1) {
        reportsResponse(400, ['status' => 'error', 'message' => 'The week start date must use YYYY-MM-DD format.']);
    }
    $weekStart = DateTimeImmutable::createFromFormat('!Y-m-d', $weekStartValue, new DateTimeZone('Africa/Kampala'));
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$weekStart || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
        || $weekStart->format('Y-m-d') !== $weekStartValue || $weekStart->format('N') !== '1') {
        reportsResponse(400, ['status' => 'error', 'message' => 'The week start date must be a valid Monday.']);
    }

    $days = $payload['days'] ?? null;
    $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    if (!is_array($days)) {
        reportsResponse(400, ['status' => 'error', 'message' => 'Daily report entries are required.']);
    }
    foreach ($dayNames as $dayName) {
        $day = $days[$dayName] ?? null;
        $activity = is_array($day) ? ($day['activities'] ?? null) : null;
        if (!is_string($activity) || trim($activity) === '' || strlen($activity) > 5000) {
            reportsResponse(400, ['status' => 'error', 'message' => 'Enter a daily activity for each weekday (maximum 5,000 characters each).']);
        }
        foreach (['challenges', 'tasks_in_progress'] as $optionalField) {
            $value = $day[$optionalField] ?? '';
            if (!is_string($value) || strlen($value) > 5000) {
                reportsResponse(400, ['status' => 'error', 'message' => 'Optional daily report fields must be text of at most 5,000 characters.']);
            }
        }
    }

    $weekNumber = (int) $weekStart->format('W');
    $isoYear = (int) $weekStart->format('o');
    $month = (int) $weekStart->format('n');
    $db->beginTransaction();
    $reportInsert = $db->prepare(
        "INSERT INTO reports (intern_id, supervisor_id, week_number, month, year, status)
         VALUES (:intern_id, :supervisor_id, :week_number, :month, :year, 'submitted')"
    );
    $reportInsert->execute([
        ':intern_id' => $user['id'],
        ':supervisor_id' => $user['supervisor_id'],
        ':week_number' => $weekNumber,
        ':month' => $month,
        ':year' => $isoYear,
    ]);
    $reportId = (int) $db->lastInsertId();

    $attendanceStmt = $db->prepare(
        'SELECT clock_in_utc, clock_out_utc FROM attendance_sessions
         WHERE intern_id = :intern_id AND work_date = :work_date AND clock_out_utc IS NOT NULL LIMIT 1'
    );
    $entryInsert = $db->prepare(
        'INSERT INTO daily_entries
            (report_id, day_of_week, entry_date, time_in, time_out, activities, challenges, tasks_in_progress)
         VALUES
            (:report_id, :day_of_week, :entry_date, :time_in, :time_out, :activities, :challenges, :tasks_in_progress)'
    );
    foreach ($dayNames as $offset => $dayName) {
        $entryDate = $weekStart->modify('+' . $offset . ' days');
        $workDate = $entryDate->format('Y-m-d');
        $attendanceStmt->execute([':intern_id' => $user['id'], ':work_date' => $workDate]);
        $attendance = $attendanceStmt->fetch(PDO::FETCH_ASSOC);
        $timeIn = $attendance
            ? (new DateTimeImmutable($attendance['clock_in_utc'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Kampala'))->format('H:i:s')
            : null;
        $timeOut = $attendance
            ? (new DateTimeImmutable($attendance['clock_out_utc'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Africa/Kampala'))->format('H:i:s')
            : null;
        $entryInsert->execute([
            ':report_id' => $reportId,
            ':day_of_week' => $dayName,
            ':entry_date' => $workDate,
            ':time_in' => $timeIn,
            ':time_out' => $timeOut,
            ':activities' => trim($days[$dayName]['activities']),
            ':challenges' => trim((string) ($days[$dayName]['challenges'] ?? '')) ?: null,
            ':tasks_in_progress' => trim((string) ($days[$dayName]['tasks_in_progress'] ?? '')) ?: null,
        ]);
    }

    $summary = $payload['weekly_summary'] ?? [];
    if (!is_array($summary)) {
        reportsResponse(400, ['status' => 'error', 'message' => 'Weekly summary must be an object.']);
    }
    foreach (['key_achievements', 'major_challenges', 'plans_next_week'] as $summaryField) {
        $value = $summary[$summaryField] ?? '';
        if (!is_string($value) || strlen($value) > 5000) {
            reportsResponse(400, ['status' => 'error', 'message' => 'Weekly summary fields must be text of at most 5,000 characters.']);
        }
    }
    $summaryInsert = $db->prepare(
        'INSERT INTO weekly_summaries (report_id, key_achievements, major_challenges, plans_next_week)
         VALUES (:report_id, :key_achievements, :major_challenges, :plans_next_week)'
    );
    $summaryInsert->execute([
        ':report_id' => $reportId,
        ':key_achievements' => trim((string) ($summary['key_achievements'] ?? '')) ?: null,
        ':major_challenges' => trim((string) ($summary['major_challenges'] ?? '')) ?: null,
        ':plans_next_week' => trim((string) ($summary['plans_next_week'] ?? '')) ?: null,
    ]);
    $db->commit();
    reportsResponse(201, ['status' => 'success', 'message' => 'Weekly report submitted.', 'report_id' => $reportId]);
} catch (PDOException $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
        reportsResponse(409, ['status' => 'error', 'message' => 'A report for this week already exists.']);
    }
    error_log('Reports API database error: ' . $error->getMessage());
    reportsResponse(500, ['status' => 'error', 'message' => 'Request could not be completed.']);
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Reports API request failed: ' . $error->getMessage());
    reportsResponse(500, ['status' => 'error', 'message' => 'Request could not be completed.']);
}
