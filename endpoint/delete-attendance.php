<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

date_default_timezone_set('Asia/Manila');
include ('../conn/conn.php');

header('Content-Type: application/json');

try {
    // Check if attendance_id is provided
    if (!isset($_REQUEST['attendance_id']) && !isset($_REQUEST['attendance'])) {
        echo json_encode(['success' => false, 'message' => 'Attendance ID is required']);
        exit();
    }
    
    // Get attendance ID (support both parameter names)
    $attendance_id = $_REQUEST['attendance_id'] ?? $_REQUEST['attendance'];
    $admin_id = $_SESSION['user_id'];
    $admin_role = $_SESSION['role'] ?? 'facilitator';
    
    // Verify that the user has permission to delete this attendance record
    if ($admin_role == 'super_admin') {
        // Super admin can delete any record
        $stmt = $conn->prepare("DELETE FROM tbl_attendance WHERE tbl_attendance_id = ?");
        $stmt->execute([$attendance_id]);
        
    } else {
        // Regular admin can only delete records of students in their sections
        $stmt = $conn->prepare("
            DELETE a FROM tbl_attendance a
            INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
            WHERE a.tbl_attendance_id = ? 
            AND (s.created_by = ? OR ads.user_id = ?)
        ");
        $stmt->execute([$attendance_id, $admin_id, $admin_id]);
    }
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Attendance record deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Record not found or you do not have permission to delete it']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'General error: ' . $e->getMessage()]);
}
?>