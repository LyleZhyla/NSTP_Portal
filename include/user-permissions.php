<?php

if (!function_exists('normalizeProgram')) {
function normalizeProgram($program) {
    $program = strtoupper(trim((string) $program));
    return in_array($program, ['CWTS', 'LTS', 'ROTC'], true) ? $program : null;
}
}

if (!function_exists('inferProgramFromText')) {
function inferProgramFromText($text) {
    $text = strtoupper((string) $text);

    if (strpos($text, 'LTS') !== false) {
        return 'LTS';
    }

    if (strpos($text, 'CWTS') !== false) {
        return 'CWTS';
    }

    $rotcCompanies = ['ALPHA', 'BRAVO', 'CHARLIE', 'DELTA', 'ECHO', 'FOXTROT', 'GOLF', 'HOTEL', 'INDIA', 'JULIET', 'KILO', 'LIMA', 'MIKE', 'NOVEMBER', 'OSCAR', 'PAPA', 'QUEBEC', 'ROMEO', 'SIERRA', 'TANGO', 'UNIFORM', 'VICTOR', 'WHISKEY', 'XRAY', 'YANKEE', 'ZULU'];
    foreach ($rotcCompanies as $company) {
        if (strpos($text, $company . ' COMPANY') !== false) {
            return 'ROTC';
        }
    }

    if (strpos($text, 'ROTC') !== false || strpos($text, 'PLATOON') !== false) {
        return 'ROTC';
    }

    return null;
}
}

function getCurrentUserRecord(PDO $conn) {
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $conn->prepare("SELECT user_id, role, program FROM tbl_users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function canAccessAdminManagement($role) {
    return in_array($role, ['super_admin', 'coordinator'], true);
}

function canAccessStaffTools($role) {
    return in_array($role, ['super_admin', 'coordinator', 'facilitator'], true);
}

function isFacilitatorScanRestrictionEnabled(PDO $conn) {
    return getSystemSetting($conn, 'facilitator_scan_restriction_enabled', '0') === '1';
}

function ensureRotcAttendanceSchema(PDO $conn) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tbl_public_student_registrations'
              AND COLUMN_NAME = 'rotc_ms_level'
        ");
        $stmt->execute();

        if ((int) $stmt->fetchColumn() === 0) {
            $conn->exec("ALTER TABLE tbl_public_student_registrations ADD COLUMN rotc_ms_level VARCHAR(20) NULL AFTER component");
        }
    } catch (Throwable $error) {
        // Older installs may not have the public registration table yet.
    }
}

function normalizeRotcMsLevel($value) {
    $text = strtoupper(trim((string) $value));
    $text = str_replace([' ', '_'], '-', $text);
    $text = preg_replace('/-+/', '-', $text);

    if (preg_match('/^MS-?(1|31|41)$/', $text, $matches)) {
        return 'MS-' . $matches[1];
    }

    if (preg_match('/\bMS[\s_-]?(1|31|41)\b/i', (string) $value, $matches)) {
        return 'MS-' . $matches[1];
    }

    return null;
}

function rotcStudentMsLevelSqlExpression($studentAlias = 's') {
    $studentAlias = preg_replace('/[^A-Za-z0-9_]/', '', (string) $studentAlias) ?: 's';

    return "COALESCE(
        (
            SELECT CASE
                WHEN UPPER(REPLACE(REPLACE(latest_rotc_registration.rotc_ms_level, ' ', '-'), '_', '-')) IN ('MS1', 'MS-1') THEN 'MS-1'
                WHEN UPPER(REPLACE(REPLACE(latest_rotc_registration.rotc_ms_level, ' ', '-'), '_', '-')) IN ('MS31', 'MS-31') THEN 'MS-31'
                WHEN UPPER(REPLACE(REPLACE(latest_rotc_registration.rotc_ms_level, ' ', '-'), '_', '-')) IN ('MS41', 'MS-41') THEN 'MS-41'
                ELSE NULL
            END
            FROM tbl_public_student_registrations latest_rotc_registration
            WHERE latest_rotc_registration.student_number = {$studentAlias}.student_number
              AND latest_rotc_registration.component = 'ROTC'
            ORDER BY latest_rotc_registration.registration_id DESC
            LIMIT 1
        ),
        CASE
            WHEN UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE '%MS-31%'
              OR UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE '%MS 31%'
              OR UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE '%MS31%' THEN 'MS-31'
            WHEN UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE '%MS-41%'
              OR UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE '%MS 41%'
              OR UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE '%MS41%' THEN 'MS-41'
            WHEN UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE '%MS-1%'
              OR UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE '%MS 1%'
              OR UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE '%MS1%' THEN 'MS-1'
            ELSE NULL
        END
    )";
}

function rotcMs1StudentSqlCondition($studentAlias = 's') {
    return "(" . rotcStudentSqlCondition($studentAlias) . " AND " . rotcStudentMsLevelSqlExpression($studentAlias) . " = 'MS-1')";
}

function rotcAdvancedStudentSqlCondition($studentAlias = 's') {
    return "(" . rotcStudentSqlCondition($studentAlias) . " AND " . rotcStudentMsLevelSqlExpression($studentAlias) . " IN ('MS-31', 'MS-41'))";
}

function rotcStudentSqlCondition($studentAlias = 's') {
    $studentAlias = preg_replace('/[^A-Za-z0-9_]/', '', (string) $studentAlias) ?: 's';

    return "(
        UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE '%ROTC%'
        OR UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE '%ALPHA%'
        OR UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE '%PLATOON%'
        OR EXISTS (
            SELECT 1
            FROM tbl_users student_user
            WHERE student_user.user_id = {$studentAlias}.user_id
              AND student_user.program = 'ROTC'
        )
        OR EXISTS (
            SELECT 1
            FROM tbl_public_student_registrations rotc_registration
            WHERE rotc_registration.student_number = {$studentAlias}.student_number
              AND rotc_registration.component = 'ROTC'
              AND rotc_registration.registration_id = (
                    SELECT MAX(latest_rotc_registration.registration_id)
                    FROM tbl_public_student_registrations latest_rotc_registration
                    WHERE latest_rotc_registration.student_number = {$studentAlias}.student_number
                      AND latest_rotc_registration.component = 'ROTC'
              )
        )
    )";
}

function isRotcStudentRecord(PDO $conn, array $student) {
    if (inferProgramFromText($student['course_section'] ?? '') === 'ROTC') {
        return true;
    }

    $studentId = (int) ($student['tbl_student_id'] ?? 0);
    if ($studentId <= 0) {
        return false;
    }

    try {
        $condition = rotcStudentSqlCondition('s');
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM tbl_student s
            WHERE s.tbl_student_id = ?
              AND {$condition}
        ");
        $stmt->execute([$studentId]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $error) {
        return false;
    }
}

function getRotcStudentMsLevel(PDO $conn, array $student) {
    $studentNumber = preg_replace('/\D/', '', (string) ($student['student_number'] ?? ''));
    if ($studentNumber !== '') {
        try {
            ensureRotcAttendanceSchema($conn);
            $stmt = $conn->prepare("
                SELECT rotc_ms_level
                FROM tbl_public_student_registrations
                WHERE student_number = ?
                  AND component = 'ROTC'
                ORDER BY registration_id DESC
                LIMIT 1
            ");
            $stmt->execute([$studentNumber]);
            $fromRegistration = normalizeRotcMsLevel($stmt->fetchColumn());
            if ($fromRegistration) {
                return $fromRegistration;
            }
        } catch (Throwable $error) {
            // Fall back to folder/section text below.
        }
    }

    return normalizeRotcMsLevel($student['course_section'] ?? '');
}

function getRotcAttendanceGroup(PDO $conn, array $student) {
    if (!isRotcStudentRecord($conn, $student)) {
        return null;
    }

    $msLevel = getRotcStudentMsLevel($conn, $student);
    return in_array($msLevel, ['MS-31', 'MS-41'], true) ? 'ROTC_MS31_MS41' : 'ROTC_MS1';
}

function canRecordStudentAttendance(PDO $conn, array $actor, array $student) {
    $role = $actor['role'] ?? '';

    if ($role === 'super_admin') {
        return true;
    }

    if ($role === 'coordinator') {
        $actorProgram = normalizeProgram($actor['program'] ?? null);
        $studentProgram = normalizeProgram($student['course_section'] ?? null)
            ?: inferProgramFromText($student['course_section'] ?? '');

        return $actorProgram && $actorProgram === $studentProgram;
    }

    if ($role !== 'facilitator') {
        return false;
    }

    if (!isFacilitatorScanRestrictionEnabled($conn)) {
        return true;
    }

    if (isRotcStudentRecord($conn, $student) && getRotcAttendanceGroup($conn, $student) === 'ROTC_MS31_MS41') {
        return false;
    }

    if (normalizeProgram($actor['program'] ?? null) === 'ROTC' && isRotcStudentRecord($conn, $student)) {
        return getRotcAttendanceGroup($conn, $student) === 'ROTC_MS1';
    }

    $actorId = (int) ($actor['user_id'] ?? 0);
    $studentCreatorId = (int) ($student['created_by'] ?? 0);

    if ($actorId <= 0 || $studentCreatorId !== $actorId) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_admin_sections
        WHERE user_id = ? AND course_section = ?
    ");
    $stmt->execute([$actorId, $student['course_section'] ?? '']);

    return (int) $stmt->fetchColumn() > 0;
}

function canManageUserRecord(array $actor, array $target) {
    if (($actor['role'] ?? '') === 'super_admin') {
        return true;
    }

    if (($actor['role'] ?? '') !== 'coordinator') {
        return false;
    }

    return ($target['role'] ?? '') === 'facilitator'
        && normalizeProgram($target['program'] ?? null) === normalizeProgram($actor['program'] ?? null);
}

function requestedManagedRole(array $actor, $requestedRole) {
    $requestedRole = (string) $requestedRole;

    if (($actor['role'] ?? '') === 'super_admin') {
        return in_array($requestedRole, ['super_admin', 'coordinator', 'facilitator', 'student'], true) ? $requestedRole : 'facilitator';
    }

    return 'facilitator';
}

function requestedManagedProgram(array $actor, $requestedProgram) {
    if (($actor['role'] ?? '') === 'super_admin') {
        return normalizeProgram($requestedProgram);
    }

    return normalizeProgram($actor['program'] ?? null);
}

function getSystemSetting(PDO $conn, $settingKey, $defaultValue = null) {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS tbl_system_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $stmt = $conn->prepare("SELECT setting_value FROM tbl_system_settings WHERE setting_key = ?");
        $stmt->execute([$settingKey]);
        $value = $stmt->fetchColumn();

        return $value === false ? $defaultValue : $value;
    } catch (Throwable $error) {
        return $defaultValue;
    }
}

function setSystemSetting(PDO $conn, $settingKey, $settingValue) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $conn->prepare("
        INSERT INTO tbl_system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    return $stmt->execute([$settingKey, $settingValue]);
}

function isComponentSelectionEnabled(PDO $conn) {
    return getSystemSetting($conn, 'component_selection_enabled', '1') === '1';
}

function ensureSystemLogsTable(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_system_logs (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            username VARCHAR(100) NULL,
            role VARCHAR(50) NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_created_at (created_at),
            INDEX idx_action (action),
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function logSystemEvent(PDO $conn, $action, $details = '') {
    try {
        ensureSystemLogsTable($conn);

        $stmt = $conn->prepare("
            INSERT INTO tbl_system_logs (user_id, username, role, action, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['username'] ?? null,
            $_SESSION['role'] ?? null,
            (string) $action,
            (string) $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $error) {
        return false;
    }
}
