<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'coordinator') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$studentId = (int) ($_POST['student_id'] ?? 0);
$facilitatorId = (int) ($_POST['facilitator_id'] ?? 0);
$courseSection = trim((string) ($_POST['course_section'] ?? ''));
$program = normalizeProgram($currentUser['program'] ?? null);

if ($studentId <= 0 || $facilitatorId <= 0 || $courseSection === '' || !$program) {
    echo json_encode(['success' => false, 'message' => 'Student, facilitator, and folder are required']);
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT s.tbl_student_id, s.course_section, s.created_by, creator.role AS creator_role, creator.program AS creator_program
        FROM tbl_student s
        LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
        WHERE s.tbl_student_id = ?
    ");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student is not available for this coordinator']);
        exit();
    }

    $studentProgram = normalizeProgram($student['course_section'])
        ?: inferProgramFromText($student['course_section'])
        ?: normalizeProgram($student['creator_program'] ?? null);

    if ($studentProgram !== $program) {
        echo json_encode(['success' => false, 'message' => 'Student is not available for this coordinator']);
        exit();
    }

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

    if (inferProgramFromText($courseSection) !== $program) {
        echo json_encode(['success' => false, 'message' => 'Selected folder does not match your component']);
        exit();
    }

    $stmt = $conn->prepare("UPDATE tbl_student SET created_by = ?, course_section = ? WHERE tbl_student_id = ?");
    $stmt->execute([$facilitatorId, $courseSection, $studentId]);

    $stmt = $conn->prepare("
        SELECT s.tbl_student_id, s.student_name, s.course_section, s.created_by,
               u.full_name AS facilitator_name, u.username AS facilitator_username
        FROM tbl_student s
        LEFT JOIN tbl_users u ON s.created_by = u.user_id
        WHERE s.tbl_student_id = ?
    ");
    $stmt->execute([$studentId]);
    $updatedStudent = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$updatedStudent || (int) $updatedStudent['created_by'] !== $facilitatorId || $updatedStudent['course_section'] !== $courseSection) {
        echo json_encode(['success' => false, 'message' => 'Assignment was not saved. Please try again.']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Student moved to facilitator folder successfully',
        'student' => [
            'id' => (int) $updatedStudent['tbl_student_id'],
            'name' => $updatedStudent['student_name'],
            'folder' => $updatedStudent['course_section'],
            'facilitator' => $updatedStudent['facilitator_name'] ?: $updatedStudent['facilitator_username'],
        ],
    ]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $error->getMessage()]);
}
