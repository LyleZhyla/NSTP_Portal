<?php
session_start();
include("../conn/conn.php");
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'imported' => 0, 'errors' => [], 'total_rows' => 0];

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Unauthorized access';
    echo json_encode($response);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'facilitator';

if ($user_role === 'super_admin') {
    $response['message'] = 'Super Admin has read-only access to student folders.';
    echo json_encode($response);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];
    
    // Validate file
    $allowedExts = ['xls', 'xlsx', 'csv'];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($fileExt, $allowedExts)) {
        $response['message'] = 'Invalid file type. Please upload an Excel file (.xlsx, .xls, or .csv)';
        echo json_encode($response);
        exit;
    }
    
    // Check file size (max 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        $response['message'] = 'File size too large. Maximum 10MB allowed.';
        echo json_encode($response);
        exit;
    }
    
    try {
        // Get target admin folder section from form data
        $targetSection = isset($_POST['import_section']) ? trim($_POST['import_section']) : '';
        
        // For regular admins, validate the target section
        if ($user_role !== 'super_admin') {
            // Get admin's assigned sections
            $sections_stmt = $conn->prepare("
                SELECT course_section 
                FROM tbl_admin_sections 
                WHERE user_id = ?
            ");
            $sections_stmt->execute([$user_id]);
            $assignedSections = $sections_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($assignedSections)) {
                $response['message'] = 'You are not assigned to any admin folders. Please contact super admin.';
                echo json_encode($response);
                exit;
            }
            
            // If no target section specified, use first assigned section
            if (empty($targetSection)) {
                $targetSection = $assignedSections[0];
            }
            
            // Verify target section is in admin's assigned sections
            if (!in_array($targetSection, $assignedSections)) {
                $response['message'] = 'You are not assigned to the selected admin folder.';
                echo json_encode($response);
                exit;
            }
        } else {
            // Super admin must specify target section
            if (empty($targetSection)) {
                $response['message'] = 'Please specify a target admin folder section.';
                echo json_encode($response);
                exit;
            }
        }
        
        // Load the Excel file
        $spreadsheet = IOFactory::load($file['tmp_name']);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Get the highest row number and column letter
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        
        $response['total_rows'] = $highestRow - 1; // Subtract header row
        
        $importedCount = 0;
        $errors = [];
        $skippedCount = 0;
        
        // Check if original_section column exists in database
        try {
            $testStmt = $conn->prepare("SELECT original_section FROM tbl_student LIMIT 1");
            $testStmt->execute();
            $columnExists = true;
        } catch (PDOException $e) {
            // Column doesn't exist, add it
            try {
                $alterStmt = $conn->prepare("ALTER TABLE tbl_student ADD COLUMN original_section VARCHAR(255) DEFAULT NULL AFTER student_name");
                $alterStmt->execute();
                $columnExists = true;
                error_log("Added original_section column to tbl_student");
            } catch (PDOException $alterError) {
                error_log("Failed to add original_section column: " . $alterError->getMessage());
                $columnExists = false;
                $errors[] = "Warning: Could not add original_section column. Original sections will not be saved.";
            }
        }
        
        // Get existing students for this admin to check duplicates
        $existingStmt = $conn->prepare("
            SELECT student_name, original_section, course_section 
            FROM tbl_student 
            WHERE created_by = ?
        ");
        $existingStmt->execute([$user_id]);
        $existingStudents = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create lookup arrays for faster duplicate checking
        $existingByNameAndOriginal = [];
        $existingByNameAndFolder = [];
        
        foreach ($existingStudents as $student) {
            $name = strtolower(trim($student['student_name']));
            $original = strtolower(trim($student['original_section'] ?? ''));
            $folder = strtolower(trim($student['course_section']));
            
            if (!empty($original)) {
                $existingByNameAndOriginal[$name][$original] = true;
            }
            $existingByNameAndFolder[$name][$folder] = true;
        }
        
        // Start from row 2 (assuming row 1 has headers) and go through all rows
        for ($row = 2; $row <= $highestRow; $row++) {
            // Read cell values
            $studentName = trim($worksheet->getCell('A' . $row)->getValue() ?? '');
            $originalSection = trim($worksheet->getCell('B' . $row)->getValue() ?? '');
            
            // For super admin, also read the admin folder column if available
            $adminFolder = $targetSection; // Default to target section
            if ($user_role === 'super_admin' && $highestColumnIndex >= 3) {
                $excelAdminFolder = trim($worksheet->getCell('C' . $row)->getValue() ?? '');
                if (!empty($excelAdminFolder)) {
                    $adminFolder = $excelAdminFolder;
                }
            }
            
            // Skip empty rows
            if (empty($studentName)) {
                continue;
            }
            
            // Log for debugging
            error_log("Processing row $row: Name='$studentName', Original='$originalSection', Admin='$adminFolder'");
            
            if (empty($originalSection)) {
                $errors[] = "Row $row: Missing original section for student '$studentName'";
                $skippedCount++;
                continue;
            }
            
            // Check for duplicates - ONLY check within the same admin folder
            $isDuplicate = false;
            $studentNameLower = strtolower($studentName);
            $originalSectionLower = strtolower($originalSection);
            $adminFolderLower = strtolower($adminFolder);
            
            // Check if student with same name and same ORIGINAL section already exists in ANY folder
            if (isset($existingByNameAndOriginal[$studentNameLower][$originalSectionLower])) {
                $isDuplicate = true;
                $errors[] = "Row $row: Student '$studentName' with original section '$originalSection' already exists in the system";
            }
            // Check if student with same name already exists in the SAME admin folder
            else if (isset($existingByNameAndFolder[$studentNameLower][$adminFolderLower])) {
                $isDuplicate = true;
                $errors[] = "Row $row: Student '$studentName' already exists in admin folder '$adminFolder'";
            }
            
            if ($isDuplicate) {
                $skippedCount++;
                continue;
            }
            
            // Generate unique code
            $generatedCode = 'STU_' . uniqid() . '_' . rand(1000, 9999);
            
            // Insert new student
            try {
                if ($columnExists) {
                    $stmt = $conn->prepare("
                        INSERT INTO tbl_student 
                        (student_name, original_section, course_section, generated_code, created_by) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $result = $stmt->execute([$studentName, $originalSection, $adminFolder, $generatedCode, $user_id]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO tbl_student 
                        (student_name, course_section, generated_code, created_by) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $result = $stmt->execute([$studentName, $adminFolder, $generatedCode, $user_id]);
                }
                
                if ($result) {
                    $importedCount++;
                    error_log("Successfully imported student: $studentName");
                    
                    // Add to lookup arrays to prevent duplicates within same import
                    $existingByNameAndOriginal[$studentNameLower][$originalSectionLower] = true;
                    $existingByNameAndFolder[$studentNameLower][$adminFolderLower] = true;
                    
                } else {
                    $errors[] = "Row $row: Failed to insert student '$studentName'";
                    $skippedCount++;
                }
                
            } catch (PDOException $e) {
                // Check if it's a duplicate entry error
                if ($e->errorInfo[1] == 1062) { // MySQL duplicate entry error code
                    $errors[] = "Row $row: Student '{$studentName}' already exists (duplicate entry)";
                } else {
                    $errors[] = "Row $row: Failed to import '{$studentName}' - " . $e->getMessage();
                }
                error_log("Import error for row $row: " . $e->getMessage());
                $skippedCount++;
            }
        }
        
        // Prepare success message
        if ($importedCount > 0) {
            $response['success'] = true;
            $message = "Successfully imported {$importedCount} out of {$response['total_rows']} students into admin folder '{$targetSection}'.";
            if ($skippedCount > 0) {
                $message .= " Skipped {$skippedCount} duplicate/invalid entries.";
            }
            if (!empty($errors)) {
                $message .= " " . count($errors) . " warnings/errors occurred.";
            }
            $response['message'] = $message;
            $response['imported'] = $importedCount;
            $response['skipped'] = $skippedCount;
            $response['errors'] = array_slice($errors, 0, 50); // Limit to 50 errors
        } else {
            $response['message'] = 'No students were imported. Check errors below.';
            $response['errors'] = $errors;
        }
        
    } catch (Exception $e) {
        $response['message'] = 'Error processing Excel file: ' . $e->getMessage();
        error_log("Import Excel Error: " . $e->getMessage());
    }
} else {
    $response['message'] = 'No file uploaded or invalid request.';
}

// Clear any output buffering
if (ob_get_length()) ob_clean();

echo json_encode($response);
exit();
?>
