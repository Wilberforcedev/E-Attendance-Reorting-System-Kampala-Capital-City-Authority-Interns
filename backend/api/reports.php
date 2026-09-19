<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);

// Authenticate user
$token = $auth->getBearerToken();
$userData = $auth->validateToken($token);

if (!$userData || $userData['role'] !== 'intern') {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Basic validation
        $requiredFields = ['week_number', 'month', 'year'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }

        // Start transaction
        $db->beginTransaction();

        // Insert main report
        $reportQuery = "INSERT INTO reports 
                       (intern_id, supervisor_id, week_number, month, year, status) 
                       VALUES 
                       (:intern_id, :supervisor_id, :week_number, :month, :year, 'submitted')";
        
        $stmt = $db->prepare($reportQuery);
        $stmt->execute([
            ':intern_id' => $userData['id'],
            ':supervisor_id' => $userData['supervisor_id'],
            ':week_number' => $data['week_number'],
            ':month' => $data['month'],
            ':year' => $data['year']
        ]);
        
        $reportId = $db->lastInsertId();
        

        // Insert daily entries
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
       // Inside your try block in reports.php
foreach ($days as $day) {
    if (!empty($data[$day])) {
        // Validate activities length
        if (strlen($data[$day]['activities']) < 200) {
            throw new Exception("Activities for " . ucfirst($day) . " must be at least 200 characters");
        }
        
        $dayQuery = "INSERT INTO daily_entries 
                    (report_id, day_of_week, entry_date, time_in, time_out, activities, challenges, tasks_in_progress) 
                    VALUES 
                    (:report_id, :day_of_week, :entry_date, :time_in, :time_out, :activities, :challenges, :tasks)";
        
        $dayStmt = $db->prepare($dayQuery);
        $dayStmt->execute([
            ':report_id' => $reportId,
            ':day_of_week' => ucfirst($day),
            ':entry_date' => $data[$day]['date'],
            ':time_in' => $data[$day]['time_in'],
            ':time_out' => $data[$day]['time_out'],
            ':activities' => $data[$day]['activities'],
            ':challenges' => $data[$day]['challenges'] ?? null,
            ':tasks' => $data[$day]['tasks'] ?? null
        ]);
    }
}
        // Insert weekly summary
        if (!empty($data['weekly_summary'])) {
            $summaryQuery = "INSERT INTO weekly_summaries 
                           (report_id, key_achievements, major_challenges, plans_next_week) 
                           VALUES 
                           (:report_id, :key_achievements, :major_challenges, :plans_next_week)";
            
            $summaryStmt = $db->prepare($summaryQuery);
            $summaryStmt->execute([
                ':report_id' => $reportId,
                ':key_achievements' => $data['weekly_summary']['key_achievements'] ?? null,
                ':major_challenges' => $data['weekly_summary']['major_challenges'] ?? null,
                ':plans_next_week' => $data['weekly_summary']['plans_next_week'] ?? null
            ]);
        }

        // Commit transaction
        $db->commit();

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Report submitted successfully',
            'report_id' => $reportId
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}