<?php
session_start();

// Ensure we're returning JSON
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

include('../conn/conn.php');
require_once '../include/student-account-automation.php';

$response = ['success' => false, 'message' => ''];

try {
    ensureStudentNumberColumn($conn);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $studentName = isset($_POST['student_name']) ? trim($_POST['student_name']) : '';
    $studentNumber = isset($_POST['student_number']) ? preg_replace('/\D/', '', $_POST['student_number']) : '';
    $originalSection = isset($_POST['original_section']) ? trim($_POST['original_section']) : '';
    $courseSection = isset($_POST['course_section']) ? trim($_POST['course_section']) : ''; // This is the admin's folder section
    $generatedCode = isset($_POST['generated_code']) ? trim($_POST['generated_code']) : '';
    
    if (empty($studentName)) {
        throw new Exception('Student name is required');
    }

    if (!preg_match('/^\d{10}$/', $studentNumber)) {
        throw new Exception('Student number must be exactly 10 digits');
    }
    
    if (empty($originalSection)) {
        throw new Exception('Student\'s original course and section is required');
    }
    
    if (empty($generatedCode)) {
        throw new Exception('QR code is required. Please generate QR code first.');
    }

    $user_role = $_SESSION['role'] ?? 'facilitator';
    $user_id = $_SESSION['user_id'];

    if ($user_role === 'facilitator') {
        throw new Exception('Facilitators can only export student data.');
    }
    
    if ($user_role === 'super_admin') {
        throw new Exception('Super Admin has read-only access to student folders.');
    } else {
        // Regular admin - check if they have multiple sections
        if (empty($courseSection)) {
            throw new Exception('Please select a folder section to add the student to');
        }
        
        // Verify the admin is actually assigned to this section folder
        $checkStmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM tbl_admin_sections 
            WHERE user_id = ? AND course_section = ?
        ");
        $checkStmt->execute([$user_id, $courseSection]);
        
        if ($checkStmt->fetchColumn() == 0) {
            throw new Exception('You are not assigned to this section folder');
        }
        
        $finalCourseSection = $courseSection;
        
        // Update the user's assigned_section to the most recently used section
        $updateStmt = $conn->prepare("
            UPDATE tbl_users SET assigned_section = ? WHERE user_id = ?
        ");
        $updateStmt->execute([$courseSection, $user_id]);
    }

    // Check if QR code already exists
    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM tbl_student WHERE generated_code = ?");
    $checkStmt->execute([$generatedCode]);
    if ($checkStmt->fetchColumn() > 0) {
        throw new Exception('QR code already exists. Please generate a new one.');
    }

    // Insert student with both folder section and original section
    $stmt = $conn->prepare("
        INSERT INTO tbl_student (student_name, student_number, original_section, course_section, generated_code, created_by) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $result = $stmt->execute([$studentName, $studentNumber, $originalSection, $finalCourseSection, $generatedCode, $user_id]);
    
    if (!$result) {
        throw new Exception('Failed to insert student into database');
    }
    
    $studentId = $conn->lastInsertId();

    $response['success'] = true;
    $response['message'] = 'Student added successfully!';
    $response['data'] = [
        'student_id' => $studentId,
        'student_name' => $studentName,
        'student_number' => $studentNumber,
        'original_section' => $originalSection,
        'folder_section' => $finalCourseSection,
        'generated_code' => $generatedCode
    ];

} catch (Exception $e) {
    error_log("Error in add-student.php: " . $e->getMessage());
    $response['message'] = $e->getMessage();
}

ob_clean();
echo json_encode($response);
exit();
?>
