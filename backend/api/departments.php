<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/../config/db.php';

try {
    $database = new Database();
    $db = $database->getConnection();

    $method = $_SERVER['REQUEST_METHOD'];

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
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error',
        'error' => $e->getMessage()
    ]);
}
