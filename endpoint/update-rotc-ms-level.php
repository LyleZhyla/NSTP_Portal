<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Only the Super Admin can edit ROTC MS levels.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$studentIds = isset($_POST['student_ids']) && is_array($_POST['student_ids'])
    ? $_POST['student_ids']
    : [$_POST['student_id'] ?? 0];
$studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds), static fn($id) => $id > 0)));
$rotcMsLevel = normalizeRotcMsLevel($_POST['rotc_ms_level'] ?? null);

if (empty($studentIds) || !$rotcMsLevel) {
    echo json_encode(['success' => false, 'message' => 'Select at least one ROTC student and an MS level.']);
    exit;
}

try {
    ensureRotcAttendanceSchema($conn);
    $conn->beginTransaction();

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $conn->prepare("
        SELECT s.*, creator.role AS creator_role, creator.program AS creator_program
        FROM tbl_student s
        LEFT JOIN tbl_users creator ON creator.user_id = s.created_by
        WHERE s.tbl_student_id IN ({$placeholders})
        FOR UPDATE
    ");
    $stmt->execute($studentIds);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($students) !== count($studentIds)) {
        throw new RuntimeException('One or more selected students were not found.');
    }

    foreach ($students as $student) {
        $isRotcFacilitatorStudent = ($student['creator_role'] ?? '') === 'facilitator'
            && normalizeProgram($student['creator_program'] ?? null) === 'ROTC';
        if (!isRotcStudentRecord($conn, $student) && !$isRotcFacilitatorStudent) {
            throw new RuntimeException(($student['student_name'] ?? 'A selected student') . ' is not assigned to ROTC.');
        }
    }

    $overrideStmt = $conn->prepare("
        INSERT INTO tbl_student_rotc_levels (tbl_student_id, rotc_ms_level, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            rotc_ms_level = VALUES(rotc_ms_level),
            updated_by = VALUES(updated_by)
    ");
    $studentUserStmt = $conn->prepare("
        UPDATE tbl_users
        SET program = 'ROTC'
        WHERE role = 'student'
          AND (user_id = ? OR (? <> '' AND username = ?))
    ");

    foreach ($students as $student) {
        $studentId = (int) $student['tbl_student_id'];
        $studentUserId = (int) ($student['user_id'] ?? 0);
        $studentNumber = trim((string) ($student['student_number'] ?? ''));

        $overrideStmt->execute([$studentId, $rotcMsLevel, (int) $currentUser['user_id']]);
        $studentUserStmt->execute([$studentUserId, $studentNumber, $studentNumber]);

        $registrationIdentity = [];
        $registrationParams = [$rotcMsLevel];
        if ($studentUserId > 0) {
            $registrationIdentity[] = 'user_id = ?';
            $registrationParams[] = $studentUserId;
        }
        if ($studentNumber !== '') {
            $registrationIdentity[] = 'student_number = ?';
            $registrationParams[] = $studentNumber;
        }
        if ($registrationIdentity) {
            $registrationStmt = $conn->prepare("
                UPDATE tbl_public_student_registrations
                SET component = 'ROTC', rotc_ms_level = ?
                WHERE registration_id = (
                    SELECT registration_id
                    FROM (
                        SELECT registration_id
                        FROM tbl_public_student_registrations
                        WHERE registrant_role = 'student'
                          AND COALESCE(status, 'submitted') NOT IN ('attendance_only', 'account_deleted')
                          AND (" . implode(' OR ', $registrationIdentity) . ")
                        ORDER BY registration_id DESC
                        LIMIT 1
                    ) latest_registration
                )
            ");
            $registrationStmt->execute($registrationParams);
        }
    }

    $conn->commit();
    try {
        markSharedDataChanged($conn);
    } catch (Throwable $revisionError) {
        error_log('Unable to update shared data revision: ' . $revisionError->getMessage());
    }
    logSystemEvent(
        $conn,
        'rotc_ms_levels_updated',
        'Super Admin changed ' . count($students) . ' ROTC student(s) to ' . $rotcMsLevel . '.'
    );

    echo json_encode([
        'success' => true,
        'message' => count($students) . ' ROTC student(s) updated to ' . $rotcMsLevel . '.',
        'updated_count' => count($students),
        'rotc_ms_level' => $rotcMsLevel,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Update ROTC MS level failed: ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
