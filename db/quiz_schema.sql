-- Run once when deploying the quiz module to a new database.
-- Quiz HTTP requests intentionally do not execute schema DDL at runtime.

CREATE TABLE IF NOT EXISTS tbl_quiz_focus_events (
    response_id INT NOT NULL,
    event_id VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (response_id, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_quiz_grade_links (
    quiz_id INT PRIMARY KEY,
    grade_column_id INT NOT NULL,
    INDEX idx_quiz_grade_column (grade_column_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_quiz_grade_destinations (
    quiz_id INT NOT NULL,
    grade_column_id INT NOT NULL,
    PRIMARY KEY (quiz_id, grade_column_id),
    INDEX idx_quiz_destination_column (grade_column_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_quizzes (
    quiz_id INT AUTO_INCREMENT PRIMARY KEY,
    uploaded_by INT NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    audience_components VARCHAR(20) NOT NULL,
    audience_rotc_levels VARCHAR(30) NOT NULL,
    definition_json LONGTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    revision INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quiz_owner (uploaded_by),
    INDEX idx_quiz_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_quiz_responses (
    response_id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    user_id INT NOT NULL,
    answers_json LONGTEXT NOT NULL,
    grades_json LONGTEXT NOT NULL,
    state VARCHAR(20) NOT NULL DEFAULT 'draft',
    score DECIMAL(10,2) NOT NULL DEFAULT 0,
    total_points DECIMAL(10,2) NOT NULL DEFAULT 0,
    needs_review TINYINT NOT NULL DEFAULT 0,
    released TINYINT NOT NULL DEFAULT 0,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_quiz_student (quiz_id, user_id),
    INDEX idx_quiz_response (quiz_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_quiz_files (
    file_id INT AUTO_INCREMENT PRIMARY KEY,
    response_id INT NOT NULL,
    question_id VARCHAR(48) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_name VARCHAR(68) NOT NULL,
    file_size INT NOT NULL,
    INDEX idx_response_file (response_id, question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO tbl_quiz_grade_destinations (quiz_id, grade_column_id)
SELECT quiz_id, grade_column_id FROM tbl_quiz_grade_links;
