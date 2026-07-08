<?php
session_start();
header('Content-Type: application/json'); // Always return JSON
include("../conn/conn.php");
require_once "../include/user-permissions.php";

$response = ['success' => false, 'message' => ''];

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser) {
    $response['message'] = 'Unauthorized access!';
    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check for all required fields
    if (isset($_POST['tbl_student_id']) && isset($_POST['student_name']) && isset($_POST['course_section'])) {
        
        $studentId = $_POST['tbl_student_id'];
        $studentName = trim($_POST['student_name']);
        $studentCourse = trim($_POST['course_section']); // Admin folder section
        // Get original section, use empty string as fallback if not provided
        $originalSection = isset($_POST['original_section']) ? trim($_POST['original_section']) : '';
        $userId = (int) $currentUser['user_id'];
        $userRole = $currentUser['role'] ?? 'facilitator';
        
        try {
            if ($userRole === 'facilitator') {
                throw new Exception('Facilitators can only export student data.');
            }

            // Validate inputs
            if (empty($studentName)) {
                throw new Exception('Student name is required');
            }
            if (empty($studentCourse)) {
                throw new Exception('Admin folder section is required');
            }
            
            // Check if student exists and get creator info
            $checkStmt = $conn->prepare("SELECT created_by, user_id FROM tbl_student WHERE tbl_student_id = :student_id");
            $checkStmt->bindParam(":student_id", $studentId, PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() === 0) {
                throw new Exception('Student not found!');
            }
            
            $student = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            // Check permission
            if ($userRole !== 'super_admin' && $student['created_by'] != $userId) {
                throw new Exception('You do not have permission to edit this student!');
            }
            
            // For regular admins, verify they are assigned to the new admin folder section
            if ($userRole !== 'super_admin') {
                $checkSectionStmt = $conn->prepare("
                    SELECT COUNT(*) 
                    FROM tbl_admin_sections 
                    WHERE user_id = ? AND course_section = ?
                ");
                $checkSectionStmt->execute([$userId, $studentCourse]);
                
                if ($checkSectionStmt->fetchColumn() == 0) {
                    throw new Exception("You are not assigned to the admin folder: $studentCourse");
                }
            }
            
            // First, check if original_section column exists
            try {
                // Try to select original_section to check if column exists
                $testStmt = $conn->prepare("SELECT original_section FROM tbl_student WHERE tbl_student_id = ? LIMIT 1");
                $testStmt->execute([$studentId]);
                $columnExists = true;
            } catch (PDOException $e) {
                // Column doesn't exist
                $columnExists = false;
                
                // Add the column
                try {
                    $alterStmt = $conn->prepare("ALTER TABLE tbl_student ADD COLUMN original_section VARCHAR(255) DEFAULT NULL AFTER student_name");
                    $alterStmt->execute();
                    $columnExists = true;
                } catch (PDOException $alterError) {
                    error_log("Failed to add original_section column: " . $alterError->getMessage());
                    throw new Exception("Database setup error. Please contact administrator.");
                }
            }
            
            // Now perform the update based on whether column exists
            if ($columnExists) {
                // Update with original_section
                $stmt = $conn->prepare("
                    UPDATE tbl_student 
                    SET student_name = :student_name, 
                        course_section = :course_section, 
                        original_section = :original_section 
                    WHERE tbl_student_id = :tbl_student_id
                ");
                
                $stmt->bindParam(":tbl_student_id", $studentId, PDO::PARAM_INT); 
                $stmt->bindParam(":student_name", $studentName, PDO::PARAM_STR); 
                $stmt->bindParam(":course_section", $studentCourse, PDO::PARAM_STR);
                $stmt->bindParam(":original_section", $originalSection, PDO::PARAM_STR);
            } else {
                // Update without original_section
                $stmt = $conn->prepare("
                    UPDATE tbl_student 
                    SET student_name = :student_name, 
                        course_section = :course_section 
                    WHERE tbl_student_id = :tbl_student_id
                ");
                
                $stmt->bindParam(":tbl_student_id", $studentId, PDO::PARAM_INT); 
                $stmt->bindParam(":student_name", $studentName, PDO::PARAM_STR); 
                $stmt->bindParam(":course_section", $studentCourse, PDO::PARAM_STR);
            }

            $stmt->execute();

            $studentUserId = (int) ($student['user_id'] ?? 0);
            if ($studentUserId > 0) {
                $userUpdateStmt = $conn->prepare("UPDATE tbl_users SET full_name = ? WHERE user_id = ? AND role = 'student'");
                $userUpdateStmt->execute([$studentName, $studentUserId]);
            }
            
            // Success response
            $response['success'] = true;
            $response['message'] = 'Student updated successfully!';
            $response['data'] = [
                'student_id' => $studentId,
                'student_name' => $studentName,
                'original_section' => $originalSection,
                'folder_section' => $studentCourse
            ];
            
        } catch (PDOException $e) {
            error_log("PDO Error in update-student.php: " . $e->getMessage());
            $response['message'] = 'Database error occurred. Please try again.';
            // Only show detailed error to super admin
            if ($userRole === 'super_admin') {
                $response['message'] .= ' Error: ' . $e->getMessage();
            }
        } catch (Exception $e) {
            error_log("General Error in update-student.php: " . $e->getMessage());
            $response['message'] = $e->getMessage();
        }

    } else {
        $missing = [];
        if (!isset($_POST['tbl_student_id'])) $missing[] = 'student_id';
        if (!isset($_POST['student_name'])) $missing[] = 'student_name';
        if (!isset($_POST['course_section'])) $missing[] = 'course_section';
        
        $response['message'] = 'Missing required fields: ' . implode(', ', $missing);
    }
} else {
    $response['message'] = 'Invalid request method';
}

// Clear any output buffering
if (ob_get_length()) ob_clean();

// Send JSON response
echo json_encode($response);
exit();
?>
