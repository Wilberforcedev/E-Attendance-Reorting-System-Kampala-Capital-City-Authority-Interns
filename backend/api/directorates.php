<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
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

    if ($method === 'GET') {
        // Check for division_id filter
        $divisionId = isset($_GET['division_id']) ? $_GET['division_id'] : null;
        
        if ($divisionId) {
            $stmt = $db->prepare("SELECT * FROM directorates WHERE division_id = :division_id");
            $stmt->bindParam(':division_id', $divisionId);
            $stmt->execute();
        } else {
            $stmt = $db->query("SELECT * FROM directorates");
        }
        
        $directorates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'count' => count($directorates),
            'directorates' => $directorates
        ]);
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        // Validation
        if (empty($input['name']) || empty($input['code']) || empty($input['divisionId'])) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Name, code, and divisionId are required'
            ]);
            exit;
        }

        $query = "INSERT INTO directorates (name, code, division_id) VALUES (:name, :code, :division_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $input['name']);
        $stmt->bindParam(':code', $input['code']);
        $stmt->bindParam(':division_id', $input['divisionId']); // Note the conversion here

        if ($stmt->execute()) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Directorate added successfully',
                'id' => $db->lastInsertId()
            ]);
        } else {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Failed to add directorate'
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
    error_log('Directorates API database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Request could not be completed'
    ]);
}
