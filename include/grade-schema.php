<?php
function ensureGradeTables(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_grade_columns (
            grade_column_id INT AUTO_INCREMENT PRIMARY KEY,
            column_key VARCHAR(80) NOT NULL UNIQUE,
            label VARCHAR(160) NOT NULL,
            group_code VARCHAR(60) NOT NULL,
            group_label VARCHAR(120) NOT NULL,
            max_score DECIMAL(8,2) NOT NULL DEFAULT 0,
            weight_percent DECIMAL(8,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_grade_scores (
            grade_score_id INT AUTO_INCREMENT PRIMARY KEY,
            grade_column_id INT NOT NULL,
            tbl_student_id INT NOT NULL,
            score DECIMAL(8,2) NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_grade_score (grade_column_id, tbl_student_id),
            INDEX idx_grade_student (tbl_student_id),
            CONSTRAINT fk_grade_score_column FOREIGN KEY (grade_column_id)
                REFERENCES tbl_grade_columns (grade_column_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_grade_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_grade_column_visibility (
            grade_column_visibility_id INT AUTO_INCREMENT PRIMARY KEY,
            grade_column_id INT NOT NULL,
            user_id INT NOT NULL,
            program_scope VARCHAR(20) NOT NULL DEFAULT 'global',
            is_hidden TINYINT(1) NOT NULL DEFAULT 0,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_grade_column_visibility (grade_column_id, user_id, program_scope),
            INDEX idx_grade_column_visibility_user (user_id, program_scope),
            CONSTRAINT fk_grade_column_visibility_column FOREIGN KEY (grade_column_id)
                REFERENCES tbl_grade_columns (grade_column_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = $conn->query("SHOW COLUMNS FROM tbl_grade_columns")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('program_scope', $columns, true)) {
        $conn->exec("ALTER TABLE tbl_grade_columns ADD COLUMN program_scope VARCHAR(20) NULL AFTER column_key");
    }
    if (!in_array('updated_by', $columns, true)) {
        $conn->exec("ALTER TABLE tbl_grade_columns ADD COLUMN updated_by INT NULL AFTER created_by");
    }
    if (!in_array('updated_at', $columns, true)) {
        $conn->exec("ALTER TABLE tbl_grade_columns ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }
}

function seedDefaultGradeColumns(PDO $conn) {
    $defaults = [
        ['bandage_head', 'Top of the head', 'bandaging', 'Bandaging Evaluation', 16, 15, 10],
        ['bandage_chest', 'Chest/Back', 'bandaging', 'Bandaging Evaluation', 16, 15, 20],
        ['bandage_hand_foot', 'Hand/Foot', 'bandaging', 'Bandaging Evaluation', 16, 15, 30],
        ['bandage_shoulder_hips', 'Shoulder/Hips (SEMI)', 'bandaging', 'Bandaging Evaluation', 16, 15, 40],
        ['bandage_elbow_knee', 'Elbow/Knee (SEMI)', 'bandaging', 'Bandaging Evaluation', 16, 15, 50],
        ['bandage_forehead', 'Forehead (narrow)', 'bandaging', 'Bandaging Evaluation', 16, 15, 60],
        ['bandage_ear_cheek_jaw', 'Ear/Cheek/Jaw (narrow)', 'bandaging', 'Bandaging Evaluation', 16, 15, 70],
        ['bandage_palm', 'Palm (narrow)', 'bandaging', 'Bandaging Evaluation', 16, 15, 80],
        ['bandage_forearm_leg', 'Forearm/Leg (narrow)', 'bandaging', 'Bandaging Evaluation', 16, 15, 90],
        ['carry_walking_assist', 'Walking assist', 'carrying', 'Carrying Evaluation', 24, 15, 110],
        ['carry_cradle', 'Cradle carry', 'carrying', 'Carrying Evaluation', 24, 15, 120],
        ['carry_pack_strap', 'Pack strap', 'carrying', 'Carrying Evaluation', 24, 15, 130],
        ['carry_firefighter', 'Firefighter', 'carrying', 'Carrying Evaluation', 24, 15, 140],
        ['carry_extremity', 'Extremity carry', 'carrying', 'Carrying Evaluation', 28, 15, 150],
        ['carry_swing', 'Swing carry', 'carrying', 'Carrying Evaluation', 28, 15, 160],
        ['carry_chair', 'Chair carry', 'carrying', 'Carrying Evaluation', 28, 15, 170],
        ['carry_hammock', 'Hammock carry', 'three_man_carry', '3-4 Man Carry', 28, 15, 190],
        ['carry_bearers', "Bearer's along side", 'three_man_carry', '3-4 Man Carry', 28, 15, 200],
        ['carry_blanket', 'Blanket carry', 'three_man_carry', '3-4 Man Carry', 28, 15, 210],
        ['carry_stretcher', 'Improvised stretcher', 'three_man_carry', '3-4 Man Carry', 28, 15, 220],
        ['spine_board', 'Spine Board Management', 'spine_board', 'Spine Board Equivalent', 32, 15, 240],
        ['cpr', 'CPR', 'cpr', 'CPR Equivalent', 40, 20, 260],
        ['proposal', 'Proposal', 'community', 'Community Immersion', 35, 40, 300],
        ['implementation', 'MRF and Beautification / Implementation', 'community', 'Community Immersion', 55, 60, 310],
    ];

    $stmt = $conn->prepare("
        INSERT IGNORE INTO tbl_grade_columns
            (column_key, label, group_code, group_label, max_score, weight_percent, sort_order, is_default, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)
    ");

    foreach ($defaults as $column) {
        $stmt->execute($column);
    }
}

