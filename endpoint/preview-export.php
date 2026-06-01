<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';

$response = ['success' => false, 'message' => '', 'students' => []];

if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Unauthorized access';
    echo json_encode($response);
    exit();
}

$userId = (int) $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'facilitator';
$section = trim((string) ($_GET['section'] ?? ''));
$adminId = (int) ($_GET['admin_id'] ?? $userId);
$targetUserId = $userId;

if ($userRole === 'super_admin') {
    $targetUserId = $adminId > 0 ? $adminId : $userId;
} elseif ($userRole === 'facilitator') {
    if ($section === '') {
        $response['message'] = 'Please select a section to export.';
        echo json_encode($response);
        exit();
    }

    $checkStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_admin_sections
        WHERE user_id = ? AND course_section = ?
    ");
    $checkStmt->execute([$userId, $section]);
    if ((int) $checkStmt->fetchColumn() === 0) {
        $response['message'] = 'You do not have access to this section.';
        echo json_encode($response);
        exit();
    }
} else {
    $response['message'] = 'Export preview is not available for this account.';
    echo json_encode($response);
    exit();
}

try {
    if ($section !== '') {
        $stmt = $conn->prepare("
            SELECT student_name, generated_code, original_section, course_section
            FROM tbl_student
            WHERE created_by = ? AND course_section = ?
            ORDER BY student_name ASC
        ");
        $stmt->execute([$targetUserId, $section]);
    } else {
        $stmt = $conn->prepare("
            SELECT student_name, generated_code, original_section, course_section
            FROM tbl_student
            WHERE created_by = ?
            ORDER BY course_section ASC, student_name ASC
        ");
        $stmt->execute([$targetUserId]);
    }

    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['success'] = true;
    $response['students'] = $students;
    $response['message'] = count($students) . ' student(s) found.';
} catch (Throwable $error) {
    $response['message'] = $error->getMessage();
}

echo json_encode($response);
exit();
?>
