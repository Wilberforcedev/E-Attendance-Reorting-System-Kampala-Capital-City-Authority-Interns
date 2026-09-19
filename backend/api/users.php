<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . '/../config/db.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

// In your PHP backend
if (!empty($data['supervisor_id'])) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'supervisor'");
    $stmt->execute([$data['supervisor_id']]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid supervisor ID']);
        exit;
    }
}
switch ($method) {
    case 'GET':
        // Get all users
        $stmt = $db->query("SELECT * FROM users");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'status' => 'success',
            'count' => count($users),
            'users' => $users
        ]);
        break;
        
    case 'POST':
    // Add new user
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON input");
        }
        
        if (empty($data['full_name']) || empty($data['email']) || empty($data['password'])) {
            throw new Exception("Missing required fields");
        }
        
        $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $query = "INSERT INTO users (full_name, email, password, role, division_id, directorate_id, department_id, supervisor_id) 
                  VALUES (:full_name, :email, :password, :role, :division_id, :directorate_id, :department_id, :supervisor_id)";
        
        $stmt = $db->prepare($query);
        
        $params = [
            ':full_name' => $data['full_name'],
            ':email' => $data['email'],
            ':password' => $hashed_password,
            ':role' => $data['role'] ?? null,
            ':division_id' => $data['division_id'] ?? null,
            ':directorate_id' => $data['directorate_id'] ?? null,
            ':department_id' => $data['department_id'] ?? null,
            ':supervisor_id' => $data['supervisor_id'] ?? null
        ];
        
        
        if ($stmt->execute($params)) {
            http_response_code(201);
            echo json_encode([
                'status' => 'success', 
                'message' => 'User created successfully',
                'user_id' => $db->lastInsertId()
            ]);
        } else {
            throw new Exception("Failed to create user: " . implode(" ", $stmt->errorInfo()));
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    break;
    case 'DELETE':
        // Delete user
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        
        if ($id) {
            $query = "DELETE FROM users WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'User deleted successfully']);
            } else {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Failed to delete user']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'User ID is required']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        break;
}