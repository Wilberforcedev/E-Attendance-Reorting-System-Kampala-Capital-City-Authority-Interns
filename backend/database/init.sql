-- Initial schema for a new EARS installation. Apply only to an empty database.
-- Existing installations need reviewed, versioned migrations; do not run this over production data.

CREATE TABLE divisions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(30) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE directorates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    division_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(30) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_directorates_division FOREIGN KEY (division_id) REFERENCES divisions(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_directorates_division (division_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    directorate_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(30) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_departments_directorate FOREIGN KEY (directorate_id) REFERENCES directorates(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_departments_directorate (directorate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    role ENUM('intern', 'supervisor', 'hr', 'admin') NOT NULL,
    division_id INT UNSIGNED NULL,
    directorate_id INT UNSIGNED NULL,
    department_id INT UNSIGNED NULL,
    supervisor_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_division FOREIGN KEY (division_id) REFERENCES divisions(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_users_directorate FOREIGN KEY (directorate_id) REFERENCES directorates(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_users_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_users_role_active (role, is_active),
    INDEX idx_users_supervisor (supervisor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reports (
    report_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    intern_id INT UNSIGNED NOT NULL,
    supervisor_id INT UNSIGNED NULL,
    week_number TINYINT UNSIGNED NOT NULL,
    month TINYINT UNSIGNED NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    status ENUM('draft', 'submitted', 'approved_by_supervisor', 'rejected', 'submitted_to_hr')
        NOT NULL DEFAULT 'draft',
    supervisor_comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_reports_intern FOREIGN KEY (intern_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reports_supervisor FOREIGN KEY (supervisor_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_reports_month CHECK (month BETWEEN 1 AND 12),
    CONSTRAINT chk_reports_week CHECK (week_number BETWEEN 1 AND 53),
    UNIQUE KEY uq_reports_intern_iso_week (intern_id, year, week_number),
    INDEX idx_reports_intern_period (intern_id, year, month, week_number),
    INDEX idx_reports_supervisor_status (supervisor_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_entries (
    entry_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday') NOT NULL,
    entry_date DATE NOT NULL,
    time_in TIME NULL,
    time_out TIME NULL,
    activities TEXT NOT NULL,
    challenges TEXT NULL,
    tasks_in_progress TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_daily_entries_report FOREIGN KEY (report_id) REFERENCES reports(report_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_daily_entry_report_day (report_id, day_of_week),
    INDEX idx_daily_entries_date (entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE weekly_summaries (
    summary_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL UNIQUE,
    key_achievements TEXT NULL,
    major_challenges TEXT NULL,
    plans_next_week TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_weekly_summaries_report FOREIGN KEY (report_id) REFERENCES reports(report_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comments (
    comment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_comments_report FOREIGN KEY (report_id) REFERENCES reports(report_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_comments_report_created (report_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE report_attachments (
    attachment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attachments_report FOREIGN KEY (report_id) REFERENCES reports(report_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_attachments_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    action VARCHAR(80) NOT NULL,
    description TEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_audit_logs_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One server-timestamped attendance session per intern per Kampala work date.
-- This table does not prove physical work location or replace a KCCA-approved verification method.
CREATE TABLE attendance_sessions (
    session_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    intern_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    clock_in_utc DATETIME(3) NOT NULL,
    clock_out_utc DATETIME(3) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_sessions_intern FOREIGN KEY (intern_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    UNIQUE KEY uq_attendance_intern_work_date (intern_id, work_date),
    INDEX idx_attendance_work_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
