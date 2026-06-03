<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

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
$currentUser = getCurrentUserRecord($conn);

if ($userRole === 'super_admin') {
    $targetUserId = $adminId > 0 ? $adminId : $userId;
} elseif ($userRole === 'coordinator') {
    $targetUserId = $adminId > 0 ? $adminId : 0;
    $targetStmt = $conn->prepare("SELECT user_id, role, program FROM tbl_users WHERE user_id = ?");
    $targetStmt->execute([$targetUserId]);
    $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentUser || !$targetUser || !canManageUserRecord($currentUser, $targetUser)) {
        $response['message'] = 'You do not have access to this facilitator.';
        echo json_encode($response);
        exit();
    }

    if ($section !== '') {
        $checkStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM tbl_admin_sections
            WHERE user_id = ? AND course_section = ?
        ");
        $checkStmt->execute([$targetUserId, $section]);
        if ((int) $checkStmt->fetchColumn() === 0) {
            $response['message'] = 'You do not have access to this section.';
            echo json_encode($response);
            exit();
        }
    }
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
