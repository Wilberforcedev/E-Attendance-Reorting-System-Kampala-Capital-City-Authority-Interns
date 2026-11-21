<?php
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
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }
    if ($method === 'POST' && $userData['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator access required']);
        exit;
    }
    if (!$auth->hasValidCsrfToken()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Request verification failed']);
        exit;
    }

    if ($method === 'GET') {
        $stmt = $db->query("SELECT * FROM divisions");
        $divisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($divisions);
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['name']) || empty($input['code'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Name and code are required']);
            exit;
        }

        $name = $input['name'];
        $code = $input['code'];

        $query = "INSERT INTO divisions (name, code) VALUES (:name, :code)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':code', $code);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Division added successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to add division']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }

} catch (PDOException $e) {
    error_log('Divisions API database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Request could not be completed'
    ]);
}
