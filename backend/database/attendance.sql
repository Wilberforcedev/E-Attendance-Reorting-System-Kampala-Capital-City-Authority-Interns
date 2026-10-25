-- EARS attendance + QR migration for the existing users table.
-- Apply once to the ears_db database before deploying backend/api/attendance.php.

CREATE TABLE IF NOT EXISTS attendance_qr_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) NOT NULL,
    location VARCHAR(160) NOT NULL,
    created_by INT NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_qr_token_hash (token_hash),
    KEY idx_attendance_qr_validity (expires_at, revoked_at),
    CONSTRAINT fk_attendance_qr_creator FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    intern_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    check_in_at DATETIME NOT NULL,
    check_out_at DATETIME NULL,
    location VARCHAR(160) NOT NULL,
    notes VARCHAR(500) NULL,
    qr_session_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_user_date (intern_id, attendance_date),
    KEY idx_attendance_date (attendance_date),
    CONSTRAINT fk_attendance_user FOREIGN KEY (intern_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_qr_session FOREIGN KEY (qr_session_id) REFERENCES attendance_qr_sessions(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_attendance_checkout CHECK (check_out_at IS NULL OR check_out_at >= check_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NULL,
    attendance_record_id BIGINT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    description VARCHAR(500) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attendance_audit_created (created_at),
    CONSTRAINT fk_attendance_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_attendance_audit_record FOREIGN KEY (attendance_record_id) REFERENCES attendance_records(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
