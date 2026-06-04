<?php
session_start();
header('Content-Type: application/json');
require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/section-folders.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessAdminManagement($currentUser['role'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_POST['user_id'] ?? '';
    $course_section = trim($_POST['course_section'] ?? '');
    
    if (empty($user_id) || empty($course_section)) {
        echo json_encode(['success' => false, 'message' => 'Admin ID and Section are required']);
        exit();
    }
    
    try {
        $conn->beginTransaction();
        $assignment = assignSectionFolderToFacilitator($conn, $user_id, $course_section, $currentUser);
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Folder assigned successfully',
            'assignment_id' => $assignment['assignment_id'],
            'moved_students' => $assignment['moved_students']
        ]);
        
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
