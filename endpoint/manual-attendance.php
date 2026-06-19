<?php
session_start();
require_once '../conn/conn.php';
require_once '../include/attendance-settings.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessStaffTools($currentUser['role'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Only staff accounts can record attendance']);
    exit();
}

$student_id = $_POST['student_id'] ?? '';
$time_in = $_POST['time_in'] ?? '';
$notes = $_POST['notes'] ?? '';

if (empty($student_id)) {
    echo json_encode(['success' => false, 'message' => 'Student is required']);
    exit();
}

try {
    // Validate student exists and can be handled by this staff account.
    $stmt = $conn->prepare("
        SELECT s.*
        FROM tbl_student s
        WHERE s.tbl_student_id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student || !canRecordStudentAttendance($conn, $currentUser, $student)) {
        echo json_encode(['success' => false, 'message' => 'Student not found or not enrolled in your section']);
        exit();
    }
    
    // Use current time if not provided
    if (empty($time_in)) {
        $time_in = date('Y-m-d H:i:s');
    } else {
        $time_in = date('Y-m-d H:i:s', strtotime($time_in));
    }
    
    // Check if already attended today
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_attendance 
                           WHERE tbl_student_id = ? AND DATE(time_in) = DATE(?)");
    $stmt->execute([$student_id, $time_in]);
    
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Already attended today']);
        exit();
    }
    
    $status = getAttendanceStatusForStudent($conn, $student, $time_in);
    
    // Insert record
    $columns = "tbl_student_id, time_in, status";
    $placeholders = "?, ?, ?";
    $params = [$student_id, $time_in, $status];
    
    // Add notes if provided
    if (!empty($notes)) {
        $columns .= ", notes";
        $placeholders .= ", ?";
        $params[] = $notes;
    }
    
    $sql = "INSERT INTO tbl_attendance ($columns) VALUES ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'message' => 'Manual attendance recorded successfully'
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
