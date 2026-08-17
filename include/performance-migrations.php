<?php

require_once __DIR__ . '/user-permissions.php';

function performanceMigrationColumnExists(PDO $conn, $tableName, $columnName) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tableName, $columnName]);
    return (int) $stmt->fetchColumn() > 0;
}

function performanceMigrationIndexExists(PDO $conn, $tableName, $indexName) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ");
    $stmt->execute([$tableName, $indexName]);
    return (int) $stmt->fetchColumn() > 0;
}

function runPublicRegistrationPerformanceMigration(PDO $conn) {
    $migrationKey = 'migration_public_registration_performance_20260817';
    if (getSystemSetting($conn, $migrationKey, '0') === '1') {
        return true;
    }

    try {
        $columns = [
            'first_login_at' => "ALTER TABLE tbl_users ADD COLUMN first_login_at DATETIME NULL AFTER last_password_change",
            'last_login_at' => "ALTER TABLE tbl_users ADD COLUMN last_login_at DATETIME NULL AFTER first_login_at",
            'login_count' => "ALTER TABLE tbl_users ADD COLUMN login_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_login_at",
        ];
        foreach ($columns as $columnName => $sql) {
            if (!performanceMigrationColumnExists($conn, 'tbl_users', $columnName)) {
                $conn->exec($sql);
            }
        }

        $indexes = [
            ['tbl_users', 'idx_users_role_program', "ALTER TABLE tbl_users ADD INDEX idx_users_role_program (role, program)"],
            ['tbl_users', 'idx_users_last_login', "ALTER TABLE tbl_users ADD INDEX idx_users_last_login (role, last_login_at)"],
            ['tbl_public_student_registrations', 'idx_public_reg_student_status', "ALTER TABLE tbl_public_student_registrations ADD INDEX idx_public_reg_student_status (student_number, registrant_role, status)"],
            ['tbl_public_student_registrations', 'idx_public_reg_list', "ALTER TABLE tbl_public_student_registrations ADD INDEX idx_public_reg_list (registrant_role, status, component, created_at)"],
            ['tbl_public_student_registrations', 'idx_public_reg_user', "ALTER TABLE tbl_public_student_registrations ADD INDEX idx_public_reg_user (user_id)"],
            ['tbl_system_logs', 'idx_logs_login_user_date', "ALTER TABLE tbl_system_logs ADD INDEX idx_logs_login_user_date (action, user_id, created_at)"],
        ];
        foreach ($indexes as [$tableName, $indexName, $sql]) {
            if (!performanceMigrationIndexExists($conn, $tableName, $indexName)) {
                $conn->exec($sql);
            }
        }

        $conn->exec("
            UPDATE tbl_users u
            INNER JOIN (
                SELECT user_id, MIN(created_at) AS first_login_at,
                       MAX(created_at) AS last_login_at, COUNT(*) AS login_count
                FROM tbl_system_logs
                WHERE action = 'user_login' AND user_id IS NOT NULL
                GROUP BY user_id
            ) activity ON activity.user_id = u.user_id
            SET u.first_login_at = COALESCE(u.first_login_at, activity.first_login_at),
                u.last_login_at = COALESCE(u.last_login_at, activity.last_login_at),
                u.login_count = GREATEST(u.login_count, activity.login_count)
        ");

        setSystemSetting($conn, $migrationKey, '1');
        return true;
    } catch (Throwable $error) {
        error_log('Public registration performance migration failed: ' . $error->getMessage());
        return false;
    }
}

