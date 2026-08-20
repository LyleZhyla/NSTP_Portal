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

function ensureAttendancePerformanceIndexes(PDO $conn) {
    $indexes = [
        'tbl_attendance' => [
            'idx_attendance_student_time' => "ALTER TABLE tbl_attendance ADD INDEX idx_attendance_student_time (tbl_student_id, time_in)",
            'idx_attendance_time_status' => "ALTER TABLE tbl_attendance ADD INDEX idx_attendance_time_status (time_in, status)",
        ],
        'tbl_student' => [
            'idx_student_generated_code' => "ALTER TABLE tbl_student ADD INDEX idx_student_generated_code (generated_code)",
        ],
    ];

    foreach ($indexes as $tableName => $tableIndexes) {
        foreach ($tableIndexes as $indexName => $sql) {
            try {
                $stmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = ?
                      AND INDEX_NAME = ?
                ");
                $stmt->execute([$tableName, $indexName]);

                if ((int) $stmt->fetchColumn() === 0) {
                    $conn->exec($sql);
                }
            } catch (Throwable $error) {
                // Keep scanner requests working even if the database user cannot alter schema.
            }
        }
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
                WHEN UPPER(REPLACE(REPLACE(REPLACE(TRIM(latest_rotc_registration.rotc_ms_level), ' ', ''), '-', ''), '_', '')) = 'MS1' THEN 'MS-1'
                WHEN UPPER(REPLACE(REPLACE(REPLACE(TRIM(latest_rotc_registration.rotc_ms_level), ' ', ''), '-', ''), '_', '')) = 'MS31' THEN 'MS-31'
                WHEN UPPER(REPLACE(REPLACE(REPLACE(TRIM(latest_rotc_registration.rotc_ms_level), ' ', ''), '-', ''), '_', '')) = 'MS41' THEN 'MS-41'
                ELSE NULL
            END
            FROM tbl_public_student_registrations latest_rotc_registration
            WHERE latest_rotc_registration.component = 'ROTC'
              AND (
                    ({$studentAlias}.user_id IS NOT NULL AND latest_rotc_registration.user_id = {$studentAlias}.user_id)
                    OR (
                        {$studentAlias}.student_number IS NOT NULL
                        AND {$studentAlias}.student_number <> ''
                        AND latest_rotc_registration.student_number = {$studentAlias}.student_number
                    )
              )
            ORDER BY latest_rotc_registration.registration_id DESC
            LIMIT 1
        ),
        CASE
            WHEN UPPER(REPLACE(REPLACE(REPLACE(COALESCE({$studentAlias}.course_section, ''), ' ', ''), '-', ''), '_', '')) LIKE '%MS31%' THEN 'MS-31'
            WHEN UPPER(REPLACE(REPLACE(REPLACE(COALESCE({$studentAlias}.course_section, ''), ' ', ''), '-', ''), '_', '')) LIKE '%MS41%' THEN 'MS-41'
            WHEN UPPER(REPLACE(REPLACE(REPLACE(COALESCE({$studentAlias}.course_section, ''), ' ', ''), '-', ''), '_', '')) LIKE '%MS1%' THEN 'MS-1'
            WHEN UPPER(REPLACE(REPLACE(REPLACE(COALESCE({$studentAlias}.original_section, ''), ' ', ''), '-', ''), '_', '')) LIKE '%MS31%' THEN 'MS-31'
            WHEN UPPER(REPLACE(REPLACE(REPLACE(COALESCE({$studentAlias}.original_section, ''), ' ', ''), '-', ''), '_', '')) LIKE '%MS41%' THEN 'MS-41'
            WHEN UPPER(REPLACE(REPLACE(REPLACE(COALESCE({$studentAlias}.original_section, ''), ' ', ''), '-', ''), '_', '')) LIKE '%MS1%' THEN 'MS-1'
            ELSE NULL
        END,
        'MS-1'
    )";
}

function rotcMs1StudentSqlCondition($studentAlias = 's') {
    return "(" . rotcStudentSqlCondition($studentAlias) . " AND " . rotcStudentMsLevelSqlExpression($studentAlias) . " = 'MS-1')";
}

function rotcMs31StudentSqlCondition($studentAlias = 's') {
    return "(" . rotcStudentSqlCondition($studentAlias) . " AND " . rotcStudentMsLevelSqlExpression($studentAlias) . " = 'MS-31')";
}

function rotcMs41StudentSqlCondition($studentAlias = 's') {
    return "(" . rotcStudentSqlCondition($studentAlias) . " AND " . rotcStudentMsLevelSqlExpression($studentAlias) . " = 'MS-41')";
}

function rotcAdvancedStudentSqlCondition($studentAlias = 's') {
    return "(" . rotcStudentSqlCondition($studentAlias) . " AND " . rotcStudentMsLevelSqlExpression($studentAlias) . " IN ('MS-31', 'MS-41'))";
}

function rotcMsLevelStudentSqlCondition($msLevel, $studentAlias = 's') {
    $msLevel = normalizeRotcMsLevel($msLevel);
    if ($msLevel === 'MS-31') {
        return rotcMs31StudentSqlCondition($studentAlias);
    }
    if ($msLevel === 'MS-41') {
        return rotcMs41StudentSqlCondition($studentAlias);
    }

    return rotcMs1StudentSqlCondition($studentAlias);
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
    $studentUserId = (int) ($student['user_id'] ?? 0);
    if ($studentNumber !== '' || $studentUserId > 0) {
        try {
            ensureRotcAttendanceSchema($conn);
            $stmt = $conn->prepare("
                SELECT rotc_ms_level
                FROM tbl_public_student_registrations
                WHERE component = 'ROTC'
                  AND (
                        (? > 0 AND user_id = ?)
                        OR (? <> '' AND student_number = ?)
                  )
                ORDER BY registration_id DESC
                LIMIT 1
            ");
            $stmt->execute([$studentUserId, $studentUserId, $studentNumber, $studentNumber]);
            $fromRegistration = normalizeRotcMsLevel($stmt->fetchColumn());
            if ($fromRegistration) {
                return $fromRegistration;
            }
        } catch (Throwable $error) {
            // Fall back to folder/section text below.
        }
    }

    return normalizeRotcMsLevel($student['course_section'] ?? '')
        ?: normalizeRotcMsLevel($student['original_section'] ?? '');
}

function getRotcAttendanceGroup(PDO $conn, array $student) {
    if (!isRotcStudentRecord($conn, $student)) {
        return null;
    }

    $msLevel = getRotcStudentMsLevel($conn, $student) ?: 'MS-1';
    return 'ROTC_' . str_replace('-', '', $msLevel);
}

function studentProgramForAttendance(PDO $conn, array $student) {
    $courseProgram = normalizeProgram($student['course_section'] ?? null)
        ?: inferProgramFromText($student['course_section'] ?? '');
    if ($courseProgram) {
        return $courseProgram;
    }

    if (isRotcStudentRecord($conn, $student)) {
        return 'ROTC';
    }

    $studentId = (int) ($student['tbl_student_id'] ?? 0);
    if ($studentId <= 0) {
        return null;
    }

    try {
        $stmt = $conn->prepare("
            SELECT COALESCE(student_user.program, creator_user.program, registration.component)
            FROM tbl_student s
            LEFT JOIN tbl_users student_user ON student_user.user_id = s.user_id
            LEFT JOIN tbl_users creator_user ON creator_user.user_id = s.created_by
            LEFT JOIN tbl_public_student_registrations registration
              ON registration.student_number = s.student_number
             AND registration.registration_id = (
                    SELECT MAX(latest_registration.registration_id)
                    FROM tbl_public_student_registrations latest_registration
                    WHERE latest_registration.student_number = s.student_number
                )
            WHERE s.tbl_student_id = ?
            LIMIT 1
        ");
        $stmt->execute([$studentId]);

        return normalizeProgram($stmt->fetchColumn());
    } catch (Throwable $error) {
        return null;
    }
}

function studentAttendanceAccessSqlForUser(array $actor, $studentAlias = 's') {
    $studentAlias = preg_replace('/[^A-Za-z0-9_]/', '', (string) $studentAlias) ?: 's';
    $role = $actor['role'] ?? '';
    $actorId = (int) ($actor['user_id'] ?? 0);

    if ($role === 'super_admin') {
        return ['condition' => '1=1', 'params' => []];
    }

    if ($role === 'coordinator') {
        $program = normalizeProgram($actor['program'] ?? null);
        if (!$program) {
            return ['condition' => '1=0', 'params' => []];
        }

        if ($program === 'ROTC') {
            return [
                'condition' => '(' . rotcStudentSqlCondition($studentAlias) . " OR EXISTS (
                    SELECT 1 FROM tbl_users creator_user
                    WHERE creator_user.user_id = {$studentAlias}.created_by
                      AND creator_user.program = ?
                ))",
                'params' => [$program],
            ];
        }

        return [
            'condition' => "(
                UPPER(COALESCE({$studentAlias}.course_section, '')) LIKE ?
                OR EXISTS (
                    SELECT 1 FROM tbl_users student_user
                    WHERE student_user.user_id = {$studentAlias}.user_id
                      AND student_user.program = ?
                )
                OR EXISTS (
                    SELECT 1 FROM tbl_users creator_user
                    WHERE creator_user.user_id = {$studentAlias}.created_by
                      AND creator_user.program = ?
                )
                OR EXISTS (
                    SELECT 1 FROM tbl_public_student_registrations registration
                    WHERE registration.student_number = {$studentAlias}.student_number
                      AND registration.component = ?
                      AND registration.registration_id = (
                            SELECT MAX(latest_registration.registration_id)
                            FROM tbl_public_student_registrations latest_registration
                            WHERE latest_registration.student_number = {$studentAlias}.student_number
                        )
                )
            )",
            'params' => ['%' . $program . '%', $program, $program, $program],
        ];
    }

    if ($role === 'facilitator') {
        $condition = "({$studentAlias}.created_by = ? OR ads.user_id = ?";
        if (normalizeProgram($actor['program'] ?? null) === 'ROTC') {
            $condition .= " OR " . rotcMs1StudentSqlCondition($studentAlias);
        }
        $condition .= ")";

        return ['condition' => $condition, 'params' => [$actorId, $actorId]];
    }

    return ['condition' => '1=0', 'params' => []];
}

function canRecordStudentAttendance(PDO $conn, array $actor, array $student) {
    $role = $actor['role'] ?? '';

    if ($role === 'super_admin') {
        return true;
    }

    // When the restriction is off, every authenticated staff scanner may
    // record any student. Apply this before coordinator/facilitator scoping.
    if (in_array($role, ['coordinator', 'facilitator'], true) && !isFacilitatorScanRestrictionEnabled($conn)) {
        return true;
    }

    if ($role === 'coordinator') {
        $actorProgram = normalizeProgram($actor['program'] ?? null);
        $studentProgram = studentProgramForAttendance($conn, $student);

        return $actorProgram && $actorProgram === $studentProgram;
    }

    if ($role !== 'facilitator') {
        return false;
    }

    if (isRotcStudentRecord($conn, $student) && getRotcAttendanceGroup($conn, $student) !== 'ROTC_MS1') {
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

function ensureSharedDataRevision(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_shared_data_revision (
            revision_key VARCHAR(50) PRIMARY KEY,
            revision_value BIGINT UNSIGNED NOT NULL DEFAULT 1,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $conn->prepare("
        INSERT IGNORE INTO tbl_shared_data_revision (revision_key, revision_value)
        VALUES ('management', 1)
    ");
    $stmt->execute();
}

function getSharedDataRevision(PDO $conn) {
    try {
        $stmt = $conn->prepare("
            SELECT revision_value
            FROM tbl_shared_data_revision
            WHERE revision_key = 'management'
            LIMIT 1
        ");
        $stmt->execute();
        $revision = $stmt->fetchColumn();

        if ($revision !== false) {
            return (int) $revision;
        }
    } catch (Throwable $error) {
        // The table is initialized below on first use.
    }

    ensureSharedDataRevision($conn);

    return 1;
}

function markSharedDataChanged(PDO $conn) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO tbl_shared_data_revision (revision_key, revision_value)
            VALUES ('management', 2)
            ON DUPLICATE KEY UPDATE revision_value = revision_value + 1
        ");

        return $stmt->execute();
    } catch (Throwable $error) {
        ensureSharedDataRevision($conn);

        $stmt = $conn->prepare("
            UPDATE tbl_shared_data_revision
            SET revision_value = revision_value + 1
            WHERE revision_key = 'management'
        ");

        return $stmt->execute();
    }
}

function isComponentSelectionEnabled(PDO $conn) {
    return getSystemSetting($conn, 'component_selection_enabled', '1') === '1';
}

function isStudentComponentChangeEnabled(PDO $conn) {
    return getSystemSetting($conn, 'student_component_change_enabled', '0') === '1';
}

function getStudentComponentChangeRound(PDO $conn) {
    return max(1, (int) getSystemSetting($conn, 'student_component_change_round', '1'));
}

function ensureStudentComponentChangeUsageTable(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_student_component_change_usage (
            user_id INT PRIMARY KEY,
            last_change_round INT NOT NULL DEFAULT 0,
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function hasStudentUsedComponentChange(PDO $conn, $userId) {
    ensureStudentComponentChangeUsageTable($conn);
    $stmt = $conn->prepare("SELECT last_change_round FROM tbl_student_component_change_usage WHERE user_id = ? LIMIT 1");
    $stmt->execute([(int) $userId]);
    return (int) ($stmt->fetchColumn() ?: 0) >= getStudentComponentChangeRound($conn);
}

function markStudentComponentChangeUsed(PDO $conn, $userId, $changeRound) {
    $stmt = $conn->prepare("
        INSERT INTO tbl_student_component_change_usage (user_id, last_change_round)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE last_change_round = VALUES(last_change_round)
    ");
    return $stmt->execute([(int) $userId, max(1, (int) $changeRound)]);
}

function getOpenStudentComponents(PDO $conn) {
    if (!isComponentSelectionEnabled($conn)) {
        return [];
    }

    $components = ['CWTS', 'LTS', 'ROTC'];
    if (getSystemSetting($conn, 'component_selection_components_configured', '0') !== '1') {
        return $components;
    }

    $openComponents = array_values(array_filter($components, static function ($component) use ($conn) {
        return getSystemSetting($conn, 'component_selection_' . strtolower($component) . '_enabled', '0') === '1';
    }));

    return array_values(array_filter($openComponents, static function ($component) use ($conn) {
        return $component !== 'ROTC' || !empty(getOpenRotcMsLevels($conn));
    }));
}

function isStudentComponentOpen(PDO $conn, $component) {
    $component = normalizeProgram($component);
    return $component && in_array($component, getOpenStudentComponents($conn), true);
}

function getRotcMsLevels() {
    return ['MS-1', 'MS-31', 'MS-41'];
}

function rotcMsLevelSettingKey($msLevel) {
    $msLevel = normalizeRotcMsLevel($msLevel);
    return $msLevel ? 'component_selection_rotc_' . strtolower(str_replace('-', '_', $msLevel)) . '_enabled' : null;
}

function getOpenRotcMsLevels(PDO $conn) {
    if (!isComponentSelectionEnabled($conn)) {
        return [];
    }

    $componentsConfigured = getSystemSetting($conn, 'component_selection_components_configured', '0') === '1';
    $rotcOpen = !$componentsConfigured
        || getSystemSetting($conn, 'component_selection_rotc_enabled', '0') === '1';
    if (!$rotcOpen) {
        return [];
    }

    $levels = getRotcMsLevels();
    if (getSystemSetting($conn, 'component_selection_rotc_ms_configured', '0') !== '1') {
        return $levels;
    }

    return array_values(array_filter($levels, static function ($msLevel) use ($conn) {
        return getSystemSetting($conn, rotcMsLevelSettingKey($msLevel), '0') === '1';
    }));
}

function isStudentRotcMsLevelOpen(PDO $conn, $msLevel) {
    $msLevel = normalizeRotcMsLevel($msLevel);
    return $msLevel && in_array($msLevel, getOpenRotcMsLevels($conn), true);
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
