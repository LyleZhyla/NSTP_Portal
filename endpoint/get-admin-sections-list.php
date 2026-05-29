<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

include('../conn/conn.php');

try {
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'] ?? 'facilitator';
    
    if ($userRole === 'super_admin') {
        // Super admin gets all sections
        $stmt = $conn->prepare("
            SELECT DISTINCT course_section 
            FROM tbl_student 
            WHERE course_section IS NOT NULL AND course_section != ''
            UNION
            SELECT DISTINCT course_section 
            FROM tbl_admin_sections
            ORDER BY course_section
        ");
        $stmt->execute();
        $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // Regular admin gets only their assigned sections
        $stmt = $conn->prepare("
            SELECT course_section 
            FROM tbl_admin_sections 
            WHERE user_id = ?
            ORDER BY course_section
        ");
        $stmt->execute([$userId]);
        $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    echo json_encode([
        'success' => true,
        'sections' => $sections,
        'has_multiple' => count($sections) > 1,
        'count' => count($sections)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>