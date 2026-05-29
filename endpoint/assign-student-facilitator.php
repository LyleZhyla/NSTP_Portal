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
$program = normalizeProgram($currentUser['program'] ?? null);

if ($studentId <= 0 || $facilitatorId <= 0 || !$program) {
    echo json_encode(['success' => false, 'message' => 'Student and facilitator are required']);
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

    if (!$student || normalizeProgram($student['course_section']) !== $program) {
        echo json_encode(['success' => false, 'message' => 'Student is not available for this coordinator']);
        exit();
    }

    $alreadyAssignedToProgramFacilitator = !empty($student['created_by'])
        && ($student['creator_role'] ?? '') === 'facilitator'
        && normalizeProgram($student['creator_program'] ?? null) === $program;

    if ($alreadyAssignedToProgramFacilitator) {
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

    $stmt = $conn->prepare("UPDATE tbl_student SET created_by = ? WHERE tbl_student_id = ?");
    $stmt->execute([$facilitatorId, $studentId]);

    echo json_encode(['success' => true, 'message' => 'Student assigned to facilitator successfully']);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $error->getMessage()]);
}
