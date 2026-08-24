<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['valid' => false, 'message' => 'User not authenticated']);
    exit;
}

include('../conn/conn.php');
require_once '../include/attendance-settings.php';

if (!canAccessStaffTools($_SESSION['role'] ?? '')) {
    echo json_encode(['valid' => false, 'message' => 'Only staff accounts can scan attendance']);
    exit;
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessStaffTools($currentUser['role'] ?? '')) {
    echo json_encode(['valid' => false, 'message' => 'Only staff accounts can scan attendance']);
    exit;
}

ensureAttendancePerformanceIndexes($conn);
if (!ensureAttendanceTimeOutSchema($conn)) {
    echo json_encode(['valid' => false, 'message' => 'Unable to prepare the Time Out feature.']);
    exit;
}

$qr_code = isset($_POST['qr_code']) ? trim($_POST['qr_code']) : '';
$scanMode = strtolower(trim((string) ($_POST['scan_mode'] ?? 'time_in')));
if (!in_array($scanMode, ['time_in', 'time_out'], true)) {
    $scanMode = 'time_in';
}

if (empty($qr_code)) {
    echo json_encode(['valid' => false, 'message' => 'No QR code provided']);
    exit;
}

try {
    // Log the QR code for debugging
    error_log("Validating QR code: " . $qr_code);
    
    // First, try exact match on generated_code
    $stmt = $conn->prepare("
        SELECT s.*, u.full_name as admin_name 
        FROM tbl_student s 
        LEFT JOIN tbl_users u ON s.created_by = u.user_id 
        WHERE s.generated_code = ?
    ");
    $stmt->execute([$qr_code]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If not found, try with LIKE (in case of hidden characters)
    if (!$student) {
        $stmt = $conn->prepare("
            SELECT s.*, u.full_name as admin_name 
            FROM tbl_student s 
            LEFT JOIN tbl_users u ON s.created_by = u.user_id 
            WHERE s.generated_code LIKE ?
        ");
        $stmt->execute(['%' . $qr_code . '%']);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Also try qr_code column for backward compatibility
    if (!$student) {
        $stmt = $conn->prepare("
            SELECT s.*, u.full_name as admin_name 
            FROM tbl_student s 
            LEFT JOIN tbl_users u ON s.created_by = u.user_id 
            WHERE s.qr_code = ? OR s.qr_code LIKE ?
        ");
        $stmt->execute([$qr_code, '%' . $qr_code . '%']);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($student) {
        if (!canRecordStudentAttendance($conn, $currentUser, $student)) {
            $message = ($currentUser['role'] ?? '') === 'coordinator'
                ? 'This student is outside your NSTP program'
                : 'This student is assigned to another facilitator';
            echo json_encode([
                'valid' => false,
                'message' => $message
            ]);
            exit;
        }

        // Read today's single attendance row so the scanner can distinguish
        // between a pending Time Out and an already completed attendance.
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $checkStmt = $conn->prepare("
            SELECT tbl_attendance_id, time_in, time_out
            FROM tbl_attendance
            WHERE tbl_student_id = ? AND time_in >= ? AND time_in < ?
            ORDER BY time_in DESC
            LIMIT 1
        ");
        $checkStmt->execute([$student['tbl_student_id'], $today . ' 00:00:00', $tomorrow . ' 00:00:00']);
        $todayAttendance = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $hasTimeIn = !empty($todayAttendance['time_in']);
        $hasTimeOut = !empty($todayAttendance['time_out']);
        $canScan = $scanMode === 'time_out'
            ? ($hasTimeIn && !$hasTimeOut)
            : !$hasTimeIn;
        
        echo json_encode([
            'valid' => true,
            'message' => 'Student found',
            'student_id' => $student['tbl_student_id'],
            'student_name' => $student['student_name'],
            'course_section' => $student['course_section'],
            'already_attended' => $hasTimeIn,
            'has_time_in' => $hasTimeIn,
            'has_time_out' => $hasTimeOut,
            'time_in' => $todayAttendance['time_in'] ?? null,
            'time_out' => $todayAttendance['time_out'] ?? null,
            'scan_mode' => $scanMode,
            'can_scan' => $canScan,
            'qr_code' => $student['generated_code']
        ]);
    } else {
        error_log("Student not found for QR code: " . $qr_code);
        echo json_encode([
            'valid' => false, 
            'message' => 'No student found with this QR Code'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Validation error: " . $e->getMessage());
    echo json_encode(['valid' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
