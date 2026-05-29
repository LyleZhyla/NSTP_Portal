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
    $user_id = $_POST['user_id'] ?? '';
    $course_section = trim($_POST['course_section'] ?? '');
    
    if (empty($user_id) || empty($course_section)) {
        echo json_encode(['success' => false, 'message' => 'Admin ID and Section are required']);
        exit();
    }
    
    try {
        // Check if admin exists and is not super_admin
        $checkStmt = $conn->prepare("SELECT user_id, role, program FROM tbl_users WHERE user_id = ?");
        $checkStmt->execute([$user_id]);
        $admin = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$admin) {
            echo json_encode(['success' => false, 'message' => 'Admin not found']);
            exit();
        }
        
        if ($admin['role'] === 'super_admin') {
            echo json_encode(['success' => false, 'message' => 'Cannot assign sections to super admin']);
            exit();
        }

        if (!canManageUserRecord($currentUser, $admin)) {
            echo json_encode(['success' => false, 'message' => 'You are not allowed to assign sections to this account']);
            exit();
        }

        if ($currentUser['role'] === 'coordinator' && inferProgramFromText($course_section) !== normalizeProgram($currentUser['program'])) {
            echo json_encode(['success' => false, 'message' => 'Section must match your assigned program']);
            exit();
        }
        
        // Check if this section is already assigned to this admin
        $checkSectionStmt = $conn->prepare("
            SELECT admin_section_id FROM tbl_admin_sections 
            WHERE user_id = ? AND course_section = ?
        ");
        $checkSectionStmt->execute([$user_id, $course_section]);
        
        if ($checkSectionStmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'This section is already assigned to this admin']);
            exit();
        }
        
        // Insert the section assignment
        $insertStmt = $conn->prepare("
            INSERT INTO tbl_admin_sections (user_id, course_section, assigned_by, assigned_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $insertStmt->execute([$user_id, $course_section, $_SESSION['user_id']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Section assigned successfully',
            'assignment_id' => $conn->lastInsertId()
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
