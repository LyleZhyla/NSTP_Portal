<?php
session_start();
header('Content-Type: application/json'); // Always return JSON
include("../conn/conn.php");
require_once "../include/user-permissions.php";
require_once "../include/student-account-automation.php";

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
        
        ensureStudentNumberColumn($conn);

        $studentId = $_POST['tbl_student_id'];
        $studentName = trim($_POST['student_name']);
        $studentCourse = trim($_POST['course_section']); // Admin folder section
        $studentNumber = isset($_POST['student_number']) ? preg_replace('/\D/', '', (string) $_POST['student_number']) : null;
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
            if ($userRole === 'super_admin') {
                if (!preg_match('/^\d{10}$/', (string) $studentNumber)) {
                    throw new Exception('Student number must be exactly 10 digits');
                }
            } else {
                $studentNumber = null;
            }
            
            // Check if student exists and get creator info
            $checkStmt = $conn->prepare("SELECT tbl_student_id, created_by, user_id, student_number, course_section, original_section FROM tbl_student WHERE tbl_student_id = :student_id");
            $checkStmt->bindParam(":student_id", $studentId, PDO::PARAM_INT);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() === 0) {
                throw new Exception('Student not found!');
            }
            
            $student = $checkStmt->fetch(PDO::FETCH_ASSOC);
            $studentUserId = (int) ($student['user_id'] ?? 0);
            $currentStudentNumber = preg_replace('/\D/', '', (string) ($student['student_number'] ?? ''));
            
            // Check permission
            if ($userRole === 'coordinator') {
                $coordinatorProgram = normalizeProgram($currentUser['program'] ?? null);
                $studentProgram = studentProgramForAttendance($conn, $student);
                if (!$coordinatorProgram || $studentProgram !== $coordinatorProgram) {
                    throw new Exception('You do not have permission to edit this student!');
                }
            } elseif ($userRole !== 'super_admin' && $student['created_by'] != $userId) {
                throw new Exception('You do not have permission to edit this student!');
            }

            if ($userRole === 'super_admin') {
                $duplicateStudentStmt = $conn->prepare("
                    SELECT tbl_student_id
                    FROM tbl_student
                    WHERE student_number = ? AND tbl_student_id != ?
                    LIMIT 1
                ");
                $duplicateStudentStmt->execute([$studentNumber, $studentId]);
                if ($duplicateStudentStmt->fetchColumn()) {
                    throw new Exception('Student number already exists in the student masterlist');
                }

                $duplicateUserSql = "
                    SELECT user_id
                    FROM tbl_users
                    WHERE username = ? AND role = 'student'
                ";
                $duplicateUserParams = [$studentNumber];
                if ($studentUserId > 0) {
                    $duplicateUserSql .= " AND user_id != ?";
                    $duplicateUserParams[] = $studentUserId;
                }
                $duplicateUserSql .= " LIMIT 1";
                $duplicateUserStmt = $conn->prepare($duplicateUserSql);
                $duplicateUserStmt->execute($duplicateUserParams);
                if ($duplicateUserStmt->fetchColumn()) {
                    throw new Exception('Student number already exists as another student username');
                }

                try {
                    $duplicateRegistrationStmt = $conn->prepare("
                        SELECT registration_id, user_id
                        FROM tbl_public_student_registrations
                        WHERE student_number = ?
                          AND (user_id IS NULL OR user_id = 0 OR user_id != ?)
                        LIMIT 1
                    ");
                    $duplicateRegistrationStmt->execute([$studentNumber, $studentUserId]);
                    if ($studentNumber !== $currentStudentNumber && $duplicateRegistrationStmt->fetchColumn()) {
                        throw new Exception('Student number already exists in public registrations');
                    }
                } catch (PDOException $error) {
                    if ($error->getCode() !== '42S02') {
                        throw $error;
                    }
                }
            }
            
            // For regular admins, verify they are assigned to the new admin folder section
            if ($userRole === 'coordinator') {
                ensureSectionFoldersTable($conn);
                $folderStmt = $conn->prepare("
                    SELECT COUNT(*)
                    FROM tbl_section_folders
                    WHERE program = ? AND course_section = ?
                ");
                $folderStmt->execute([normalizeProgram($currentUser['program'] ?? null), $studentCourse]);
                if ((int) $folderStmt->fetchColumn() === 0) {
                    throw new Exception("The selected section is not part of your component: $studentCourse");
                }
            } elseif ($userRole !== 'super_admin') {
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
                $studentNumberSql = $userRole === 'super_admin' ? ", student_number = :student_number" : "";
                $stmt = $conn->prepare("
                    UPDATE tbl_student 
                    SET student_name = :student_name, 
                        course_section = :course_section, 
                        original_section = :original_section
                        {$studentNumberSql}
                    WHERE tbl_student_id = :tbl_student_id
                ");
                
                $stmt->bindParam(":tbl_student_id", $studentId, PDO::PARAM_INT); 
                $stmt->bindParam(":student_name", $studentName, PDO::PARAM_STR); 
                $stmt->bindParam(":course_section", $studentCourse, PDO::PARAM_STR);
                $stmt->bindParam(":original_section", $originalSection, PDO::PARAM_STR);
                if ($userRole === 'super_admin') {
                    $stmt->bindParam(":student_number", $studentNumber, PDO::PARAM_STR);
                }
            } else {
                // Update without original_section
                $studentNumberSql = $userRole === 'super_admin' ? ", student_number = :student_number" : "";
                $stmt = $conn->prepare("
                    UPDATE tbl_student 
                    SET student_name = :student_name, 
                        course_section = :course_section
                        {$studentNumberSql}
                    WHERE tbl_student_id = :tbl_student_id
                ");
                
                $stmt->bindParam(":tbl_student_id", $studentId, PDO::PARAM_INT); 
                $stmt->bindParam(":student_name", $studentName, PDO::PARAM_STR); 
                $stmt->bindParam(":course_section", $studentCourse, PDO::PARAM_STR);
                if ($userRole === 'super_admin') {
                    $stmt->bindParam(":student_number", $studentNumber, PDO::PARAM_STR);
                }
            }

            $stmt->execute();

            if ($studentUserId > 0) {
                if ($userRole === 'super_admin') {
                    $userUpdateStmt = $conn->prepare("UPDATE tbl_users SET full_name = ?, username = ? WHERE user_id = ? AND role = 'student'");
                    $userUpdateStmt->execute([$studentName, $studentNumber, $studentUserId]);
                } else {
                    $userUpdateStmt = $conn->prepare("UPDATE tbl_users SET full_name = ? WHERE user_id = ? AND role = 'student'");
                    $userUpdateStmt->execute([$studentName, $studentUserId]);
                }
            }
            
            // Success response
            $response['success'] = true;
            $response['message'] = 'Student updated successfully!';
            $response['data'] = [
                'student_id' => $studentId,
                'student_name' => $studentName,
                'student_number' => $studentNumber ?? ($student['student_number'] ?? ''),
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
