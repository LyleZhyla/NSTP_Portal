<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Only the Super Admin can assign a student component.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$studentId = (int) ($_POST['student_id'] ?? 0);
$component = normalizeProgram($_POST['component'] ?? null);
$rotcMsLevel = normalizeRotcMsLevel($_POST['rotc_ms_level'] ?? null);

if ($studentId <= 0 || !$component) {
    echo json_encode(['success' => false, 'message' => 'Student and component are required.']);
    exit;
}
if ($component === 'ROTC' && !$rotcMsLevel) {
    echo json_encode(['success' => false, 'message' => 'Please select the ROTC MS level.']);
    exit;
}

try {
    ensureRotcAttendanceSchema($conn);
    $conn->beginTransaction();

    $stmt = $conn->prepare("
        SELECT
            s.*,
            student_user.program AS account_program,
            creator.role AS creator_role,
            creator.program AS creator_program,
            (
                SELECT r.component
                FROM tbl_public_student_registrations r
                WHERE r.registrant_role = 'student'
                  AND COALESCE(r.status, 'submitted') NOT IN ('attendance_only', 'account_deleted')
                  AND (
                        (s.user_id IS NOT NULL AND r.user_id = s.user_id)
                        OR (NULLIF(TRIM(s.student_number), '') IS NOT NULL AND r.student_number = s.student_number)
                  )
                ORDER BY r.registration_id DESC
                LIMIT 1
            ) AS registration_component
        FROM tbl_student s
        LEFT JOIN tbl_users student_user ON student_user.user_id = s.user_id AND student_user.role = 'student'
        LEFT JOIN tbl_users creator ON creator.user_id = s.created_by
        WHERE s.tbl_student_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        throw new RuntimeException('Student record was not found.');
    }

    $currentComponent = resolveStudentComponentFromSources(
        $student['account_program'] ?? null,
        $student['registration_component'] ?? null,
        $student['course_section'] ?? null,
        $student['creator_role'] ?? null,
        $student['creator_program'] ?? null
    );
    if ($currentComponent) {
        throw new RuntimeException('This student already belongs to ' . $currentComponent . '.');
    }

    $studentNumber = trim((string) ($student['student_number'] ?? ''));
    $studentUserId = (int) ($student['user_id'] ?? 0);
    if ($studentUserId > 0 || $studentNumber !== '') {
        $userConditions = [];
        $userParams = [$component];
        if ($studentUserId > 0) {
            $userConditions[] = 'user_id = ?';
            $userParams[] = $studentUserId;
        }
        if ($studentNumber !== '') {
            $userConditions[] = 'username = ?';
            $userParams[] = $studentNumber;
        }
        $userStmt = $conn->prepare("
            UPDATE tbl_users
            SET program = ?
            WHERE role = 'student'
              AND (" . implode(' OR ', $userConditions) . ")
        ");
        $userStmt->execute($userParams);
    }

    $registrationSet = ['component = ?'];
    $registrationParams = [$component];
    if ($component === 'ROTC') {
        $registrationSet[] = 'rotc_ms_level = ?';
        $registrationParams[] = $rotcMsLevel;
    } else {
        $registrationSet[] = 'rotc_ms_level = NULL';
    }

    $registrationIdentity = [];
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
            SET " . implode(', ', $registrationSet) . "
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

    $studentStmt = $conn->prepare("
        UPDATE tbl_student
        SET course_section = ?, created_by = NULL
        WHERE tbl_student_id = ?
    ");
    $studentStmt->execute([$component, $studentId]);

    $conn->commit();
    try {
        markSharedDataChanged($conn);
    } catch (Throwable $revisionError) {
        error_log('Unable to update shared data revision: ' . $revisionError->getMessage());
    }
    logSystemEvent(
        $conn,
        'student_component_assigned',
        'Super Admin assigned ' . $component . ($component === 'ROTC' ? ' ' . $rotcMsLevel : '')
            . ' to ' . ($student['student_name'] ?? ('student #' . $studentId)) . '.'
    );

    echo json_encode([
        'success' => true,
        'message' => 'Component assigned successfully.',
        'component' => $component,
        'rotc_ms_level' => $component === 'ROTC' ? $rotcMsLevel : null,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Assign student component failed: ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
