-- EARS attendance foundation (MySQL 8.0+)
-- Apply to a dedicated database after reviewing deployment credentials and access controls.
-- Store only a SHA-256 hash of each QR token; never store the raw token.

CREATE TABLE IF NOT EXISTS interns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    intern_number VARCHAR(50) NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL,
    department VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('intern', 'supervisor', 'admin') NOT NULL DEFAULT 'intern',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_interns_number (intern_number),
    UNIQUE KEY uq_interns_email (email),
    KEY idx_interns_department_active (department, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A supervisor/admin issues a short-lived QR check-in session for a location.
-- token_hash should be SHA-256(raw_token); expires_at should be short (for example, 2-5 minutes).
CREATE TABLE IF NOT EXISTS attendance_qr_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) NOT NULL,
    location VARCHAR(160) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_qr_token_hash (token_hash),
    KEY idx_qr_expiry (expires_at, revoked_at),
    CONSTRAINT fk_qr_created_by FOREIGN KEY (created_by) REFERENCES interns(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    intern_id BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    check_in_at DATETIME NOT NULL,
    check_out_at DATETIME NULL,
    check_in_method ENUM('qr', 'manual', 'web') NOT NULL DEFAULT 'qr',
    location VARCHAR(160) NOT NULL,
    qr_session_id BIGINT UNSIGNED NULL,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Prevent duplicate check-ins for the same intern on the same local attendance date.
    UNIQUE KEY uq_attendance_intern_day (intern_id, attendance_date),
    KEY idx_attendance_date (attendance_date),
    KEY idx_attendance_department_report (attendance_date, intern_id),
    CONSTRAINT fk_attendance_intern FOREIGN KEY (intern_id) REFERENCES interns(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_qr_session FOREIGN KEY (qr_session_id) REFERENCES attendance_qr_sessions(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_attendance_checkout CHECK (check_out_at IS NULL OR check_out_at >= check_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attendance_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attendance_record_id BIGINT UNSIGNED NULL,
    actor_intern_id BIGINT UNSIGNED NULL,
    action VARCHAR(60) NOT NULL,
    details JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attendance_audit_created (created_at),
    KEY idx_attendance_audit_record (attendance_record_id),
    CONSTRAINT fk_audit_attendance_record FOREIGN KEY (attendance_record_id) REFERENCES attendance_records(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_intern_id) REFERENCES interns(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
