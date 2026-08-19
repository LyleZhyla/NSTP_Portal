<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/section-folders.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'coordinator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$studentIds = [];
if (isset($_POST['student_ids']) && is_array($_POST['student_ids'])) {
    $studentIds = array_values(array_unique(array_filter(array_map('intval', $_POST['student_ids']), fn($id) => $id > 0)));
} else {
    $studentId = (int) ($_POST['student_id'] ?? 0);
    if ($studentId > 0) {
        $studentIds = [$studentId];
    }
}
$facilitatorId = (int) ($_POST['facilitator_id'] ?? 0);
$courseSection = trim((string) ($_POST['course_section'] ?? ''));
$program = normalizeProgram($currentUser['program'] ?? null);

if (empty($studentIds) || $facilitatorId <= 0 || $courseSection === '' || !$program) {
    echo json_encode(['success' => false, 'message' => 'Student, facilitator, and folder are required']);
    exit();
}

try {
    ensureSectionFoldersTable($conn);

    $stmt = $conn->prepare("
        SELECT user_id
        FROM tbl_users
        WHERE user_id = ? AND role = 'facilitator' AND program = ?
    ");
    $stmt->execute([$facilitatorId, $program]);

    if (!$stmt->fetchColumn()) {
        echo json_encode(['success' => false, 'message' => 'Selected facilitator is not under your component']);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_admin_sections
        WHERE user_id = ? AND course_section = ?
    ");
    $stmt->execute([$facilitatorId, $courseSection]);
    if ((int) $stmt->fetchColumn() === 0) {
        echo json_encode(['success' => false, 'message' => 'Please choose one of the facilitator existing folders']);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT program
        FROM tbl_section_folders
        WHERE course_section = ?
        ORDER BY CASE WHEN program = ? THEN 0 ELSE 1 END
        LIMIT 1
    ");
    $stmt->execute([$courseSection, $program]);
    $folderProgram = normalizeProgram($stmt->fetchColumn());

    if (!$folderProgram) {
        $folderProgram = inferProgramFromText($courseSection);
    }

    if ($folderProgram !== $program) {
        echo json_encode(['success' => false, 'message' => 'Selected folder does not match your component']);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $conn->prepare("
        SELECT s.tbl_student_id, s.course_section, s.created_by, creator.role AS creator_role, creator.program AS creator_program
        FROM tbl_student s
        LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
        WHERE s.tbl_student_id IN ($placeholders)
    ");
    $stmt->execute($studentIds);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($students) !== count($studentIds)) {
        echo json_encode(['success' => false, 'message' => 'One or more selected students are not available for this coordinator']);
        exit();
    }

    foreach ($students as $student) {
        $studentProgram = normalizeProgram($student['course_section'])
            ?: inferProgramFromText($student['course_section'])
            ?: normalizeProgram($student['creator_program'] ?? null);

        if ($studentProgram !== $program) {
            echo json_encode(['success' => false, 'message' => 'One or more selected students are not available for this coordinator']);
            exit();
        }
    }

    $conn->beginTransaction();

    $stmt = $conn->prepare("
        UPDATE tbl_student
        SET created_by = ?, course_section = ?
        WHERE tbl_student_id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$facilitatorId, $courseSection], $studentIds));
    $assignedCount = $stmt->rowCount();

    $verifyStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_student
        WHERE tbl_student_id IN ($placeholders)
          AND created_by = ?
          AND course_section = ?
    ");
    $verifyStmt->execute(array_merge($studentIds, [$facilitatorId, $courseSection]));
    $verifiedCount = (int) $verifyStmt->fetchColumn();

    if ($verifiedCount !== count($studentIds)) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'Assignment was not saved for all selected students. Please try again.']);
        exit();
    }

    $conn->commit();
    markSharedDataChanged($conn);

    echo json_encode([
        'success' => true,
        'message' => $verifiedCount . ' student' . ($verifiedCount === 1 ? '' : 's') . ' moved to facilitator folder successfully',
        'assigned_count' => $assignedCount,
        'verified_count' => $verifiedCount,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $error->getMessage()]);
}
