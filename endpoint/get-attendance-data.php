<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'User not authenticated']);
    exit;
}

date_default_timezone_set('Asia/Manila');
include('../conn/conn.php');
require_once '../include/attendance-settings.php';

$admin_id = $_SESSION['user_id'];
$admin_role = $_SESSION['role'] ?? 'facilitator';
$currentUser = getCurrentUserRecord($conn);
ensureRotcAttendanceSchema($conn);
ensureAttendancePerformanceIndexes($conn);
$timeOutEnabled = ensureAttendanceTimeOutSchema($conn);
$timeOutSelect = $timeOutEnabled ? 'a.time_out' : 'NULL AS time_out';
$canViewAllAttendance = $admin_role === 'super_admin';
$attendanceAccess = studentComponentAttendanceAccessSqlForUser($currentUser ?: ['role' => $admin_role, 'user_id' => $admin_id], 's');
$attendanceAccessCondition = $attendanceAccess['condition'];
$attendanceAccessParams = $attendanceAccess['params'];

try {
    $todayStart = date('Y-m-d 00:00:00');
    $tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));

    // Get statistics
    if ($canViewAllAttendance) {
        // Total present today
        $totalStmt = $conn->prepare("
            SELECT COUNT(*) FROM tbl_attendance 
            WHERE time_in >= ? AND time_in < ?
        ");
        $totalStmt->execute([$todayStart, $tomorrowStart]);
        $total = $totalStmt->fetchColumn();
        
        // On time
        $onTimeStmt = $conn->prepare("
            SELECT COUNT(*) FROM tbl_attendance 
            WHERE time_in >= ? AND time_in < ?
            AND status LIKE 'On Time%'
        ");
        $onTimeStmt->execute([$todayStart, $tomorrowStart]);
        $onTime = $onTimeStmt->fetchColumn();
        
        // Late
        $lateStmt = $conn->prepare("
            SELECT COUNT(*) FROM tbl_attendance 
            WHERE time_in >= ? AND time_in < ?
            AND status LIKE 'Late%'
        ");
        $lateStmt->execute([$todayStart, $tomorrowStart]);
        $late = $lateStmt->fetchColumn();
        
        // Get attendance records with student info
        $recordsStmt = $conn->prepare("
            SELECT a.tbl_attendance_id, a.tbl_student_id, a.time_in, {$timeOutSelect}, a.status,
                   s.student_name, s.course_section
            FROM tbl_attendance a
            LEFT JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            WHERE a.time_in >= ? AND a.time_in < ?
            ORDER BY a.time_in DESC
        ");
        $recordsStmt->execute([$todayStart, $tomorrowStart]);
        
    } else {
        // Regular admin - only see students from their sections
        $totalStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM tbl_attendance a
            INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            WHERE a.time_in >= ? AND a.time_in < ?
            AND {$attendanceAccessCondition}
        ");
        $totalStmt->execute(array_merge([$todayStart, $tomorrowStart], $attendanceAccessParams));
        $total = $totalStmt->fetchColumn();
        
        // On time
        $onTimeStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM tbl_attendance a
            INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            WHERE a.time_in >= ? AND a.time_in < ?
            AND a.status LIKE 'On Time%'
            AND {$attendanceAccessCondition}
        ");
        $onTimeStmt->execute(array_merge([$todayStart, $tomorrowStart], $attendanceAccessParams));
        $onTime = $onTimeStmt->fetchColumn();
        
        // Late
        $lateStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM tbl_attendance a
            INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            WHERE a.time_in >= ? AND a.time_in < ?
            AND a.status LIKE 'Late%'
            AND {$attendanceAccessCondition}
        ");
        $lateStmt->execute(array_merge([$todayStart, $tomorrowStart], $attendanceAccessParams));
        $late = $lateStmt->fetchColumn();
        
        // Get attendance records with student info
        $recordsStmt = $conn->prepare("
            SELECT a.tbl_attendance_id, a.tbl_student_id, a.time_in, {$timeOutSelect}, a.status,
                   s.student_name, s.course_section
            FROM tbl_attendance a
            INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            WHERE a.time_in >= ? AND a.time_in < ?
            AND {$attendanceAccessCondition}
            ORDER BY a.time_in DESC
        ");
        $recordsStmt->execute(array_merge([$todayStart, $tomorrowStart], $attendanceAccessParams));
    }
    
    $records = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format records for display
    $formattedRecords = [];
    foreach ($records as $record) {
        $timeIn = strtotime($record['time_in']);
        $timeOut = !empty($record['time_out']) ? strtotime($record['time_out']) : null;
        $formattedRecords[] = [
            'id' => $record['tbl_attendance_id'],
            'student_id' => $record['tbl_student_id'],
            'student_name' => $record['student_name'] ?? 'Unknown Student',
            'course_section' => $record['course_section'] ?? 'N/A',
            'time_in' => $record['time_in'],
            'time_formatted' => date('h:i A', $timeIn),
            'date_formatted' => date('M d, Y', $timeIn),
            'time_out' => $record['time_out'] ?? null,
            'time_out_formatted' => $timeOut ? date('h:i A', $timeOut) : 'Not yet',
            'time_out_date_formatted' => $timeOut ? date('M d, Y', $timeOut) : '',
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
