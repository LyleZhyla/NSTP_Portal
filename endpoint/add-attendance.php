<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

date_default_timezone_set('Asia/Manila');
include('../conn/conn.php');
require_once '../include/user-permissions.php';

$admin_id = $_SESSION['user_id'];
$qr_code = isset($_POST['qr_code']) ? trim($_POST['qr_code']) : '';

if (empty($qr_code)) {
    echo json_encode(['success' => false, 'message' => 'No QR code provided']);
    exit;
}

try {
    // Log the QR code for debugging
    error_log("Recording attendance for QR code: " . $qr_code);
    
    // Find the student
    $stmt = $conn->prepare("
        SELECT * FROM tbl_student 
        WHERE generated_code = ? OR generated_code LIKE ? OR qr_code = ? OR qr_code LIKE ?
    ");
    $stmt->execute([$qr_code, '%' . $qr_code . '%', $qr_code, '%' . $qr_code . '%']);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        error_log("Student not found for QR code: " . $qr_code);
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }
    
    // Check if already attended today
    $today = date('Y-m-d');
    $checkStmt = $conn->prepare("
        SELECT COUNT(*) FROM tbl_attendance 
        WHERE tbl_student_id = ? AND DATE(time_in) = ?
    ");
    $checkStmt->execute([$student['tbl_student_id'], $today]);
    
    if ($checkStmt->fetchColumn() > 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'Student already attended today'
        ]);
        exit;
    }
    
    // Record attendance
    $time_in = date('Y-m-d H:i:s');
    $cutoff = date('Y-m-d') . ' 08:00:00';
    $status = (strtotime($time_in) > strtotime($cutoff)) ? 'Late' : 'On Time';
    
    $insertStmt = $conn->prepare("
        INSERT INTO tbl_attendance (tbl_student_id, time_in, status) 
        VALUES (?, ?, ?)
    ");
    $insertStmt->execute([
        $student['tbl_student_id'],
        $time_in,
        $status
    ]);
    
    $attendance_id = $conn->lastInsertId();
    
    error_log("Attendance recorded for student ID: " . $student['tbl_student_id'] . " (Attendance ID: " . $attendance_id . ")");
    
    echo json_encode([
        'success' => true,
        'message' => 'Attendance recorded successfully',
        'attendance_id' => $attendance_id,
        'student_name' => $student['student_name'],
        'course_section' => $student['course_section'],
        'time' => date('h:i A', strtotime($time_in)),
        'status' => $status
    ]);
    
} catch (Exception $e) {
    error_log("Attendance error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
