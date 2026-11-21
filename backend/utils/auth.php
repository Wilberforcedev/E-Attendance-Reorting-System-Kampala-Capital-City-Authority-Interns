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

        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return trim($matches[1]);
        }

        $cookieToken = $_COOKIE['ears_token'] ?? null;
        return is_string($cookieToken) && $cookieToken !== '' ? $cookieToken : null;
    }

    public function hasValidCsrfToken(): bool
    {
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $cookieToken = $_COOKIE['ears_csrf'] ?? '';
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return is_string($cookieToken)
            && is_string($headerToken)
            && $cookieToken !== ''
            && hash_equals($cookieToken, $headerToken);
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
