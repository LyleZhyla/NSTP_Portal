CREATE TABLE IF NOT EXISTS tbl_student_cor_submissions (
    cor_submission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    semester_key VARCHAR(64) NOT NULL,
    semester_label VARCHAR(150) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    file_hash CHAR(64) NOT NULL,
    file_content MEDIUMBLOB NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (cor_submission_id),
    UNIQUE KEY uq_cor_user_semester (user_id, semester_key),
    KEY idx_cor_semester (semester_key, submitted_at),
    KEY idx_cor_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tbl_system_settings (setting_key, setting_value)
VALUES
    ('current_semester_key', 'semester_2026_2027_current'),
    ('current_semester_label', 'Current Semester AY 2026-2027'),
    ('student_cor_requirement_enabled', '1');
