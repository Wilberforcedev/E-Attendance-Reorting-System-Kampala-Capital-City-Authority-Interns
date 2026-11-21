-- Adds hashed login-attempt storage used by the sign-in rate limiter.
-- Apply once to an existing EARS database after taking a verified backup.
-- Record this migration version in the deployment's migration ledger.

CREATE TABLE login_attempts (
    attempt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempt_identifier_time (identifier_hash, attempted_at),
    INDEX idx_login_attempt_ip_time (ip_hash, attempted_at),
    INDEX idx_login_attempt_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
