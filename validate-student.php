<?php
session_start();
require_once '../conn/conn.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['valid' => false, 'message' => 'Unauthorized']);
    exit();
}

$qr_code = $_POST['qr_code'] ?? '';
$admin_id = $_SESSION['user_id'];

if (empty($qr_code)) {
    echo json_encode(['valid' => false, 'message' => 'QR code is required']);
    exit();
}

try {
    // Check if student exists and belongs to this admin
    $stmt = $conn->prepare("
        SELECT s.tbl_student_id, s.student_name, s.course_section
        FROM tbl_student s
        LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
        WHERE s.generated_code = ? 
        AND (s.created_by = ? OR ads.user_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$qr_code, $admin_id, $admin_id]);
    
    if ($student = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode([
            'valid' => true,
            'student_id' => $student['tbl_student_id'],
            'student_name' => $student['student_name'],
            'course_section' => $student['course_section']
        ]);
    } else {
        echo json_encode(['valid' => false, 'message' => 'Student not found or not enrolled in your section']);
    }
} catch (PDOException $e) {
    echo json_encode(['valid' => false, 'message' => 'Database error']);
}
?>