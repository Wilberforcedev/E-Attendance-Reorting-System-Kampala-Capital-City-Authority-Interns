<?php
require_once __DIR__ . '/core.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function generateToken($userData, ?int $expirationTime = null) {
    $issuedAt = time();
    $expirationTime = $expirationTime ?? ($issuedAt + 3600);
    
    $payload = array(
        'iat' => $issuedAt,
        'exp' => $expirationTime,
        'jti' => $userData['jti'],
        'data' => array(
            'id' => $userData['id'],
            'full_name' => $userData['full_name'],
            'email' => $userData['email'],
            'role' => $userData['role'],
            'jti' => $userData['jti'],
            'session_version' => (int) $userData['session_version']
        )
    );
    
    return JWT::encode($payload, SECRET_KEY, 'HS256');
}

function verifyToken($token) {
    try {
        $decoded = JWT::decode($token, new Key(SECRET_KEY, 'HS256'));
        return $decoded->data;
    } catch (Exception $e) {
        return false;
    }
}
?>
