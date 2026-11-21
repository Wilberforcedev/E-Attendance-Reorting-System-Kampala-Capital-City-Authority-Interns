<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header("Content-Type: application/json");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/auth.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $method = $_SERVER['REQUEST_METHOD'];
    $auth = new Auth($db);
    $userData = $auth->validateToken($auth->getBearerToken());
    if (!$userData) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }
    if ($method === 'POST' && $userData['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Administrator access required']);
        exit;
    }
    if (!$auth->hasValidCsrfToken()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Request verification failed']);
        exit;
    }

    if ($method === 'GET') {
        $stmt = $db->query("SELECT * FROM departments");
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'count' => count($departments),
            'departments' => $departments
        ]);
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        // Validate required fields (example: name, code, directorateId)
        if (empty($input['name']) || empty($input['code']) || empty($input['directorateId'])) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Name, code, and directorateId are required'
            ]);
            exit;
        }

        $name = $input['name'];
        $code = $input['code'];
        $directorateId = $input['directorateId'];

        $query = "INSERT INTO departments (name, code, directorate_id) VALUES (:name, :code, :directorate_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':code', $code);
        $stmt->bindParam(':directorate_id', $directorateId);

        if ($stmt->execute()) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Department added successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to add department'
            ]);
        }
    } else {
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'message' => 'Method not allowed'
        ]);
    }

} catch (PDOException $e) {
    error_log('Departments API database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Request could not be completed'
    ]);
}
