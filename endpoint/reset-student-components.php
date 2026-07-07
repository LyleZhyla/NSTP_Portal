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

    $studentUsersStmt = $conn->prepare("
        SELECT user_id, username
        FROM tbl_users
        WHERE role = 'student'
          AND program IS NOT NULL
    ");
    $studentUsersStmt->execute();
    $studentUsers = $studentUsersStmt->fetchAll(PDO::FETCH_ASSOC);
    $studentUserIds = array_map(static fn($row) => (int) $row['user_id'], $studentUsers);
    $studentNumbers = array_values(array_filter(array_map(
        static fn($row) => preg_match('/^\d{10}$/', (string) ($row['username'] ?? '')) ? (string) $row['username'] : null,
        $studentUsers
    )));

    $usersStmt = $conn->prepare("UPDATE tbl_users SET program = NULL WHERE role = 'student' AND program IS NOT NULL");
    $usersStmt->execute();
    $updatedUsers = $usersStmt->rowCount();

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
