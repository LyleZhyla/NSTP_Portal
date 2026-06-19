<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'User not authenticated']);
    exit;
}

date_default_timezone_set('Asia/Manila');
include('../conn/conn.php');
require_once '../include/user-permissions.php';

$admin_id = $_SESSION['user_id'];
$admin_role = $_SESSION['role'] ?? 'facilitator';
$currentUser = getCurrentUserRecord($conn);
ensureRotcAttendanceSchema($conn);
$isRotcFacilitator = $admin_role === 'facilitator'
    && normalizeProgram($currentUser['program'] ?? ($_SESSION['program'] ?? null)) === 'ROTC';
$facilitatorStudentAccessCondition = "(s.created_by = ? OR ads.user_id = ?"
    . ($isRotcFacilitator ? " OR " . rotcMs1StudentSqlCondition('s') : "")
    . ")";
$facilitatorScanRestrictionEnabled = isFacilitatorScanRestrictionEnabled($conn);
$canViewAllAttendance = $admin_role === 'super_admin'
    || ($admin_role === 'facilitator' && !$facilitatorScanRestrictionEnabled);

try {
    // Get statistics
    if ($canViewAllAttendance) {
        // Total present today
        $totalStmt = $conn->prepare("
            SELECT COUNT(*) FROM tbl_attendance 
            WHERE DATE(time_in) = CURDATE()
        ");
        $totalStmt->execute();
        $total = $totalStmt->fetchColumn();
        
        // On time
        $onTimeStmt = $conn->prepare("
            SELECT COUNT(*) FROM tbl_attendance 
            WHERE DATE(time_in) = CURDATE() 
            AND status LIKE 'On Time%'
        ");
        $onTimeStmt->execute();
        $onTime = $onTimeStmt->fetchColumn();
        
        // Late
        $lateStmt = $conn->prepare("
            SELECT COUNT(*) FROM tbl_attendance 
            WHERE DATE(time_in) = CURDATE() 
            AND status LIKE 'Late%'
        ");
        $lateStmt->execute();
        $late = $lateStmt->fetchColumn();
        
        // Get attendance records with student info
        $recordsStmt = $conn->prepare("
            SELECT a.tbl_attendance_id, a.tbl_student_id, a.time_in, a.status,
                   s.student_name, s.course_section
            FROM tbl_attendance a
            LEFT JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            WHERE DATE(a.time_in) = CURDATE()
            ORDER BY a.time_in DESC
        ");
        $recordsStmt->execute();
        
    } else {
        // Regular admin - only see students from their sections
        $totalStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM tbl_attendance a
            INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
            WHERE DATE(a.time_in) = CURDATE() 
            AND {$facilitatorStudentAccessCondition}
        ");
        $totalStmt->execute([$admin_id, $admin_id]);
        $total = $totalStmt->fetchColumn();
        
        // On time
        $onTimeStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM tbl_attendance a
            INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
            WHERE DATE(a.time_in) = CURDATE() 
            AND a.status LIKE 'On Time%'
            AND {$facilitatorStudentAccessCondition}
        ");
        $onTimeStmt->execute([$admin_id, $admin_id]);
        $onTime = $onTimeStmt->fetchColumn();
        
        // Late
        $lateStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM tbl_attendance a
            INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
            WHERE DATE(a.time_in) = CURDATE() 
            AND a.status LIKE 'Late%'
            AND {$facilitatorStudentAccessCondition}
        ");
        $lateStmt->execute([$admin_id, $admin_id]);
        $late = $lateStmt->fetchColumn();
        
        // Get attendance records with student info
        $recordsStmt = $conn->prepare("
            SELECT a.tbl_attendance_id, a.tbl_student_id, a.time_in, a.status,
                   s.student_name, s.course_section
            FROM tbl_attendance a
            INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
            WHERE DATE(a.time_in) = CURDATE() 
            AND {$facilitatorStudentAccessCondition}
            ORDER BY a.time_in DESC
        ");
        $recordsStmt->execute([$admin_id, $admin_id]);
    }
    
    $records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format records for display
    $formattedRecords = [];
    foreach ($records as $record) {
        $timeIn = strtotime($record['time_in']);
        $formattedRecords[] = [
            'id' => $record['tbl_attendance_id'],
            'student_id' => $record['tbl_student_id'],
            'student_name' => $record['student_name'] ?? 'Unknown Student',
            'course_section' => $record['course_section'] ?? 'N/A',
            'time_in' => $record['time_in'],
            'time_formatted' => date('h:i A', $timeIn),
            'date_formatted' => date('M d, Y', $timeIn),
            'status' => $record['status'] ?? 'On Time'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'statistics' => [
            'total' => (int)$total,
            'on_time' => (int)$onTime,
            'late' => (int)$late
        ],
        'records' => $formattedRecords
    ]);
    
} catch (Exception $e) {
    error_log("Error in get-attendance-data: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
