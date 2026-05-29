<?php
session_start();
header('Content-Type: application/json');
require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessAdminManagement($currentUser['role'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assignment_id = $_POST['assignment_id'] ?? '';
    
    if (empty($assignment_id)) {
        echo json_encode(['success' => false, 'message' => 'Assignment ID is required']);
        exit();
    }
    
    try {
        // Check if assignment exists
        $checkStmt = $conn->prepare("
            SELECT a.admin_section_id, a.course_section, u.user_id, u.role, u.program
            FROM tbl_admin_sections a
            INNER JOIN tbl_users u ON u.user_id = a.user_id
            WHERE a.admin_section_id = ?
        ");
        $checkStmt->execute([$assignment_id]);
        
        if ($checkStmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'Assignment not found']);
            exit();
        }

        $assignment = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!canManageUserRecord($currentUser, $assignment)) {
            echo json_encode(['success' => false, 'message' => 'You are not allowed to remove this assignment']);
            exit();
        }
        
        // Delete the assignment
        $deleteStmt = $conn->prepare("DELETE FROM tbl_admin_sections WHERE admin_section_id = ?");
        $deleteStmt->execute([$assignment_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Section assignment removed successfully'
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
