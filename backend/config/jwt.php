<?php
require_once 'core.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function generateToken($userData) {
    $issuedAt = time();
    $expirationTime = $issuedAt + 3600; // Token valid for 1 hour
    
    $payload = array(
        'iat' => $issuedAt,
        'exp' => $expirationTime,
        'data' => array(
            'id' => $userData['id'],
            'full_name' => $userData['full_name'],
            'email' => $userData['email'],
            'role' => $userData['role']
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