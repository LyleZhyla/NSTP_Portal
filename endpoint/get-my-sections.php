<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

include('../conn/conn.php');

try {
    // Get admin's assigned sections
    $stmt = $conn->prepare("
        SELECT a.course_section, a.assigned_at,
               u.full_name as assigned_by_name
        FROM tbl_admin_sections a
        LEFT JOIN tbl_users u ON a.assigned_by = u.user_id
        WHERE a.user_id = ?
        ORDER BY a.assigned_at DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get count of students in each assigned section
    foreach ($sections as &$section) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM tbl_student 
            WHERE course_section = ? AND created_by = ?
        ");
        $stmt->execute([$section['course_section'], $_SESSION['user_id']]);
        $section['student_count'] = $stmt->fetchColumn();
    }

    echo json_encode([
        'success' => true,
        'sections' => $sections
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>