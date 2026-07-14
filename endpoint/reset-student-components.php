<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

function resetComponentColumnExists(PDO $conn, $tableName, $columnName) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tableName, $columnName]);
    return (int) $stmt->fetchColumn() > 0;
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $conn->beginTransaction();

    $hasRotcMsLevel = resetComponentColumnExists($conn, 'tbl_public_student_registrations', 'rotc_ms_level');
    $excludedStudentNumbers = [];
    $excludedUserIds = [];
    if ($hasRotcMsLevel) {
        $excludedStmt = $conn->prepare("
            SELECT user_id, student_number
            FROM tbl_public_student_registrations
            WHERE registrant_role = 'student'
              AND UPPER(REPLACE(COALESCE(rotc_ms_level, ''), ' ', '-')) IN ('MS-31', 'MS31', 'MS-41', 'MS41')
        ");
        $excludedStmt->execute();
        foreach ($excludedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $studentNumber = preg_replace('/\D/', '', (string) ($row['student_number'] ?? ''));
            if ($userId > 0) {
                $excludedUserIds[] = $userId;
            }
            if (preg_match('/^\d{10}$/', $studentNumber)) {
                $excludedStudentNumbers[] = $studentNumber;
            }
        }
        $excludedUserIds = array_values(array_unique($excludedUserIds));
        $excludedStudentNumbers = array_values(array_unique($excludedStudentNumbers));
    }

    $studentUsersStmt = $conn->prepare("
        SELECT user_id, username
        FROM tbl_users
        WHERE role = 'student'
          AND program IS NOT NULL
    ");
    $studentUsersStmt->execute();
    $studentUsers = $studentUsersStmt->fetchAll(PDO::FETCH_ASSOC);
    $studentUsers = array_values(array_filter($studentUsers, static function ($row) use ($excludedUserIds, $excludedStudentNumbers) {
        $userId = (int) ($row['user_id'] ?? 0);
        $studentNumber = preg_replace('/\D/', '', (string) ($row['username'] ?? ''));
        return !in_array($userId, $excludedUserIds, true)
            && !in_array($studentNumber, $excludedStudentNumbers, true);
    }));
    $studentUserIds = array_map(static fn($row) => (int) $row['user_id'], $studentUsers);
    $studentNumbers = array_values(array_filter(array_map(
        static fn($row) => preg_match('/^\d{10}$/', (string) ($row['username'] ?? '')) ? (string) $row['username'] : null,
        $studentUsers
    )));

    $updatedUsers = 0;
    if (!empty($studentUserIds)) {
        $usersStmt = $conn->prepare("
            UPDATE tbl_users
            SET program = NULL
            WHERE role = 'student'
              AND program IS NOT NULL
              AND user_id IN (" . implode(',', array_fill(0, count($studentUserIds), '?')) . ")
        ");
        $usersStmt->execute($studentUserIds);
        $updatedUsers = $usersStmt->rowCount();
    }

    $registrationSetParts = ['component = NULL'];
    $registrationWhereParts = ['component IS NOT NULL'];
    foreach (['height', 'rotc_ms_level', 'rotc_completion_proof'] as $columnName) {
        if (resetComponentColumnExists($conn, 'tbl_public_student_registrations', $columnName)) {
            $registrationSetParts[] = "{$columnName} = NULL";
            $registrationWhereParts[] = "{$columnName} IS NOT NULL";
        }
    }

    $registrationStmt = $conn->prepare("
        UPDATE tbl_public_student_registrations
        SET " . implode(', ', $registrationSetParts) . "
        WHERE registrant_role = 'student'
          AND (" . implode(' OR ', $registrationWhereParts) . ")
          " . ($hasRotcMsLevel ? "AND UPPER(REPLACE(COALESCE(rotc_ms_level, ''), ' ', '-')) NOT IN ('MS-31', 'MS31', 'MS-41', 'MS41')" : "") . "
    ");
    $registrationStmt->execute();
    $updatedRegistrations = $registrationStmt->rowCount();

    $updatedStudents = 0;
    $studentWhere = [];
    $studentParams = [];

    if (!empty($studentUserIds)) {
        $studentWhere[] = 'user_id IN (' . implode(',', array_fill(0, count($studentUserIds), '?')) . ')';
        $studentParams = array_merge($studentParams, $studentUserIds);
    }

    if (!empty($studentNumbers)) {
        $studentWhere[] = 'student_number IN (' . implode(',', array_fill(0, count($studentNumbers), '?')) . ')';
        $studentParams = array_merge($studentParams, $studentNumbers);
    }

    if (!empty($studentWhere)) {
        $studentStmt = $conn->prepare("
            UPDATE tbl_student
            SET course_section = 'PENDING',
                created_by = NULL
            WHERE created_by IS NULL
              AND (" . implode(' OR ', $studentWhere) . ")
        ");
        $studentStmt->execute($studentParams);
        $updatedStudents = $studentStmt->rowCount();
    }

    setSystemSetting($conn, 'component_selection_enabled', '0');
    setSystemSetting($conn, 'component_selection_components_configured', '1');
    foreach (['CWTS', 'LTS', 'ROTC'] as $selectionComponent) {
        setSystemSetting($conn, 'component_selection_' . strtolower($selectionComponent) . '_enabled', '0');
    }
    setSystemSetting($conn, 'component_selection_rotc_ms_configured', '1');
    foreach (getRotcMsLevels() as $selectionMsLevel) {
        setSystemSetting($conn, rotcMsLevelSettingKey($selectionMsLevel), '0');
    }
    logSystemEvent(
        $conn,
        'student_components_reset',
        "Super Admin reset student component choices. Users: {$updatedUsers}; registrations: {$updatedRegistrations}; student records: {$updatedStudents}."
    );

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => "Component choices were reset and selection is now closed. Affected users: {$updatedUsers}.",
        'updated_users' => $updatedUsers,
        'updated_registrations' => $updatedRegistrations,
        'updated_students' => $updatedStudents,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log('Reset student components failed: ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to reset component choices: ' . $error->getMessage()]);
}
