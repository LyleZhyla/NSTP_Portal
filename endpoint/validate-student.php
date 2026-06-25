<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['valid' => false, 'message' => 'User not authenticated']);
    exit;
}

include('../conn/conn.php');
require_once '../include/user-permissions.php';

if (!canAccessStaffTools($_SESSION['role'] ?? '')) {
    echo json_encode(['valid' => false, 'message' => 'Only staff accounts can scan attendance']);
    exit;
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessStaffTools($currentUser['role'] ?? '')) {
    echo json_encode(['valid' => false, 'message' => 'Only staff accounts can scan attendance']);
    exit;
}

$qr_code = isset($_POST['qr_code']) ? trim($_POST['qr_code']) : '';

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

        // Check if student has already attended today
        $today = date('Y-m-d');
        $checkStmt = $conn->prepare("
            SELECT COUNT(*) FROM tbl_attendance 
            WHERE tbl_student_id = ? AND DATE(time_in) = ?
        ");
        $checkStmt->execute([$student['tbl_student_id'], $today]);
        $alreadyAttended = $checkStmt->fetchColumn() > 0;
        
        echo json_encode([
            'valid' => true,
            'message' => 'Student found',
            'student_id' => $student['tbl_student_id'],
            'student_name' => $student['student_name'],
            'course_section' => $student['course_section'],
            'already_attended' => $alreadyAttended,
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
