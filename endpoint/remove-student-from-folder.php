<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/section-folders.php';

function removeStudentFromFolderResponse($success, $message, array $extra = []) {
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'message' => (string) $message,
    ], $extra));
    exit();
}

$currentUser = getCurrentUserRecord($conn);
$role = $currentUser['role'] ?? '';

if (!$currentUser || !in_array($role, ['super_admin', 'coordinator', 'facilitator'], true)) {
    removeStudentFromFolderResponse(false, 'You are not allowed to remove students from this folder.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    removeStudentFromFolderResponse(false, 'Invalid request method.');
}

$studentId = (int) ($_POST['student_id'] ?? 0);
$folder = trim((string) ($_POST['folder'] ?? ''));
if ($studentId <= 0 || $folder === '') {
    removeStudentFromFolderResponse(false, 'Student and folder are required.');
}

try {
    ensureSectionFoldersTable($conn);
    ensureRotcAttendanceSchema($conn);

    $stmt = $conn->prepare("
        SELECT
            s.tbl_student_id,
            s.student_name,
            s.student_number,
            s.user_id,
            s.course_section,
            s.created_by,
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
            ) AS registration_component,
            (
                SELECT f.program
                FROM tbl_section_folders f
                WHERE f.course_section = s.course_section
                ORDER BY f.folder_id ASC
                LIMIT 1
            ) AS folder_program
        FROM tbl_student s
        LEFT JOIN tbl_users student_user ON student_user.user_id = s.user_id AND student_user.role = 'student'
        LEFT JOIN tbl_users creator ON creator.user_id = s.created_by
        WHERE s.tbl_student_id = ?
        LIMIT 1
    ");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student || trim((string) $student['course_section']) !== $folder) {
        removeStudentFromFolderResponse(false, 'The student is no longer assigned to this folder.');
    }

    $component = resolveStudentComponentFromSources(
        $student['account_program'] ?? null,
        $student['registration_component'] ?? null,
        $student['course_section'] ?? null,
        $student['creator_role'] ?? null,
        $student['creator_program'] ?? null
    ) ?: normalizeProgram($student['folder_program'] ?? null);

    if (!$component) {
        removeStudentFromFolderResponse(false, 'The student component could not be determined.');
    }

    $actorId = (int) $currentUser['user_id'];
    if ($role === 'facilitator') {
        if ((int) $student['created_by'] !== $actorId) {
            removeStudentFromFolderResponse(false, 'You can only remove students from your own folder.');
        }

        $assignmentStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM tbl_admin_sections
            WHERE user_id = ? AND course_section = ?
        ");
        $assignmentStmt->execute([$actorId, $folder]);
        if ((int) $assignmentStmt->fetchColumn() === 0) {
            removeStudentFromFolderResponse(false, 'This folder is not assigned to your account.');
        }
    } elseif ($role === 'coordinator') {
        $coordinatorProgram = normalizeProgram($currentUser['program'] ?? null);
        if (!$coordinatorProgram || $coordinatorProgram !== $component) {
            removeStudentFromFolderResponse(false, 'You can only remove students under your component.');
        }
    }

    if ($folder === $component && empty($student['created_by'])) {
        removeStudentFromFolderResponse(false, 'This student is already in the component pending list.');
    }

    assertSectionFolderUnlocked($conn, $component, $folder);

    $conn->beginTransaction();

    $updateStmt = $conn->prepare("
        UPDATE tbl_student
        SET course_section = ?, created_by = NULL
        WHERE tbl_student_id = ? AND course_section = ?
    ");
    $updateStmt->execute([$component, $studentId, $folder]);

    if ($updateStmt->rowCount() !== 1) {
        throw new RuntimeException('The folder assignment changed before it could be removed. Please refresh and try again.');
    }

    $conn->commit();
    try {
        markSharedDataChanged($conn);
        logSystemEvent(
            $conn,
            'student_removed_from_folder',
            'Removed student record ID ' . $studentId . ' (' . ($student['student_name'] ?: 'Unknown') . ') from folder ' . $folder . ' and moved it to the ' . $component . ' pending list.'
        );
    } catch (Throwable $auditError) {
        error_log('Unable to record student folder removal audit: ' . $auditError->getMessage());
    }

    removeStudentFromFolderResponse(true, 'Student removed from the folder and moved to the ' . $component . ' pending list.', [
        'student_id' => $studentId,
        'component' => $component,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Remove student from folder failed: ' . $error->getMessage());
    removeStudentFromFolderResponse(false, $error->getMessage());
}
