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
        if (!$claims || empty($claims->id) || !isset($claims->jti)
            || !is_string($claims->jti) || preg_match('/^[a-f0-9]{64}$/D', $claims->jti) !== 1
            || !isset($claims->session_version) || !is_numeric($claims->session_version)) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT u.id, u.full_name, u.email, u.role, u.supervisor_id, u.session_version
             FROM users u
             INNER JOIN auth_sessions s ON s.user_id = u.id
             WHERE u.id = :id AND u.is_active = 1 AND u.session_version = :session_version
               AND s.token_id = :token_id AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $claims->id,
            ':session_version' => (int) $claims->session_version,
            ':token_id' => $claims->jti,
        ]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['role'] !== ($claims->role ?? null)) {
            return false;
        }

        $user['token_id'] = $claims->jti;
        return $user;
    }
}
