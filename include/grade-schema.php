<?php

function ltsDefaultGradeColumns() {
    return [
        ['lts_quiz_1', 'Quiz 1: Philippine Constitution', 'lts_quiz', 'Quiz', 15, 20, 10],
        ['lts_quiz_2', 'Quiz 2: Philippine Constitution', 'lts_quiz', 'Quiz', 15, 20, 20],
        ['lts_midterm_exam', 'Midterm Exam', 'lts_term_test', 'Term Test', 100, 20, 40],
        ['lts_final_exam_video_lesson', 'Final Exam (Video Lesson)', 'lts_term_test', 'Term Test', 100, 20, 50],
        ['lts_requirement_gad', 'GAD', 'lts_requirements', 'Requirements', 100, 30, 70],
        ['lts_requirement_bomb_threat', 'Bomb Threat', 'lts_requirements', 'Requirements', 100, 30, 80],
        ['lts_requirement_drug_education', 'Drug Education', 'lts_requirements', 'Requirements', 100, 30, 90],
        ['lts_requirement_lesson_plan', 'Lesson Plan', 'lts_requirements', 'Requirements', 24, 30, 100],
        ['lts_requirement_im', 'Instructional Material', 'lts_requirements', 'Requirements', 20, 30, 110],
        ['lts_requirement_video_lesson', 'Video Lesson', 'lts_requirements', 'Requirements', 32, 30, 120],
        ['lts_participation', 'Participation', 'class_standing_attendance', 'Class Standing/Attendance', 30, 30, 140],
    ];
}

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

    $ltsColumnCountStmt = $conn->query("
        SELECT COUNT(*)
        FROM tbl_grade_columns
        WHERE UPPER(COALESCE(program_scope, '')) = 'LTS'
          AND column_key NOT LIKE 'system_attendance_%'
          AND is_active = 1
    ");
    if ((int) $ltsColumnCountStmt->fetchColumn() > 0) {
        return;
    }

    $ltsDefaults = ltsDefaultGradeColumns();

    $ltsStmt = $conn->prepare("
        INSERT IGNORE INTO tbl_grade_columns
            (column_key, program_scope, label, group_code, group_label, max_score, weight_percent, sort_order, is_default, is_active)
        VALUES (?, 'LTS', ?, ?, ?, ?, ?, ?, 1, 1)
    ");

    foreach ($ltsDefaults as $column) {
        $ltsStmt->execute($column);
    }
}

/**
 * Load the component grading template shared by its coordinators, the super
 * admin, and facilitators. Facilitator-created columns remain private to the
 * selected facilitator, while every coordinator-created column in the same
 * component is part of the shared template.
 */
function loadComponentGradeColumns(
    PDO $conn,
    $program,
    $sheetOwnerId,
    $facilitatorColumnOwnerId,
    $visibilityScope
) {
    $normalizedProgram = strtoupper(trim((string) $program));
    $stmt = $conn->prepare("
        SELECT c.*
        FROM tbl_grade_columns c
        WHERE c.is_active = 1
          AND (
            (? = 'LTS' AND UPPER(COALESCE(c.program_scope, '')) = 'LTS')
            OR
            (? <> 'LTS' AND (c.program_scope IS NULL OR UPPER(c.program_scope) = ?))
          )
          AND (
            c.is_default = 1
            OR c.created_by IS NULL
            OR c.created_by = ?
            OR c.created_by = ?
            OR EXISTS (
                SELECT 1
                FROM tbl_users creator
                WHERE creator.user_id = c.created_by
                  AND creator.role = 'coordinator'
                  AND UPPER(creator.program) = ?
            )
          )
          AND NOT EXISTS (
            SELECT 1
            FROM tbl_grade_column_visibility v
            LEFT JOIN tbl_users visibility_owner ON visibility_owner.user_id = v.user_id
            WHERE v.grade_column_id = c.grade_column_id
              AND v.program_scope = ?
              AND v.is_hidden = 1
              AND (
                v.user_id = ?
                OR (
                    visibility_owner.role = 'coordinator'
                    AND UPPER(visibility_owner.program) = ?
                )
              )
          )
        ORDER BY c.sort_order ASC, c.grade_column_id ASC
    ");
    $stmt->execute([
        $normalizedProgram,
        $normalizedProgram,
        $normalizedProgram,
        (int) $sheetOwnerId,
        (int) $facilitatorColumnOwnerId,
        $normalizedProgram,
        (string) $visibilityScope,
        (int) $sheetOwnerId,
        $normalizedProgram,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

