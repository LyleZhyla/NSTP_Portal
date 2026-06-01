<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit();
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'coordinator') {
    $response['message'] = 'Only coordinators can delete facilitator folders.';
    echo json_encode($response);
    exit();
}

$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
if ($assignmentId <= 0) {
    $response['message'] = 'Folder assignment is required.';
    echo json_encode($response);
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT a.admin_section_id, a.user_id, a.course_section, u.role, u.program, u.full_name, u.username
        FROM tbl_admin_sections a
        INNER JOIN tbl_users u ON u.user_id = a.user_id
        WHERE a.admin_section_id = ?
    ");
    $stmt->execute([$assignmentId]);
    $folder = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$folder) {
        throw new RuntimeException('Folder not found.');
    }

    if (!canManageUserRecord($currentUser, $folder)) {
        throw new RuntimeException('You are not allowed to delete this folder.');
    }

    $conn->beginTransaction();

    $studentStmt = $conn->prepare("
        SELECT tbl_student_id
        FROM tbl_student
        WHERE created_by = ? AND course_section = ?
    ");
    $studentStmt->execute([(int) $folder['user_id'], $folder['course_section']]);
    $studentIds = array_map('intval', $studentStmt->fetchAll(PDO::FETCH_COLUMN));

    if (!empty($studentIds)) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));

        $archiveStmt = $conn->prepare("DELETE FROM tbl_attendance_archive WHERE tbl_student_id IN ($placeholders)");
        $archiveStmt->execute($studentIds);

        $attendanceStmt = $conn->prepare("DELETE FROM tbl_attendance WHERE tbl_student_id IN ($placeholders)");
        $attendanceStmt->execute($studentIds);

        $deleteStudentsStmt = $conn->prepare("DELETE FROM tbl_student WHERE tbl_student_id IN ($placeholders)");
        $deleteStudentsStmt->execute($studentIds);
    }

    $deleteFolderStmt = $conn->prepare("DELETE FROM tbl_admin_sections WHERE admin_section_id = ?");
    $deleteFolderStmt->execute([$assignmentId]);

    if (function_exists('logSystemEvent')) {
        $facilitatorName = trim($folder['full_name'] ?? '') ?: ($folder['username'] ?? 'Facilitator');
        logSystemEvent(
            $conn,
            'folder_deleted',
            'Deleted folder "' . $folder['course_section'] . '" for ' . $facilitatorName . ' with ' . count($studentIds) . ' student(s).'
        );
    }

    $conn->commit();

    $response['success'] = true;
    $response['message'] = 'Folder deleted successfully.';
    $response['deleted_students'] = count($studentIds);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    $response['message'] = $error->getMessage();
}

echo json_encode($response);
exit();
?>
