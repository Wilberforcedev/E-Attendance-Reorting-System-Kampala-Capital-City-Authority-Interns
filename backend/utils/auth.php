<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/jwt.php';

final class Auth
{
    public function __construct(private PDO $db)
    {
    }

    public function getBearerToken(): ?string
    {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($authorization === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    public function validateToken(?string $token): array|false
    {
        if ($token === null || $token === '') {
            return false;
        }

        $claims = verifyToken($token);
        if (!$claims || empty($claims->id)) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT id, full_name, email, role, supervisor_id
             FROM users WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $claims->id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['role'] !== ($claims->role ?? null)) {
            return false;
        }

        return $user;
    }
}
