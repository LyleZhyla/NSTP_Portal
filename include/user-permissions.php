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
