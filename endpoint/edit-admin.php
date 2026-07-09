<?php
session_start();
require_once '../conn/conn.php'; // Fixed path
require_once '../include/user-permissions.php';
require_once '../include/profile-picture-utils.php';
require_once '../include/student-account-automation.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessAdminManagement($currentUser['role'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Enable error reporting for debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    $user_id = $_POST['user_id'] ?? '';
    
    if (empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        exit();
    }

    $targetStmt = $conn->prepare("SELECT user_id, username, role, program FROM tbl_users WHERE user_id = ?");
    $targetStmt->execute([$user_id]);
    $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser || !canManageUserRecord($currentUser, $targetUser)) {
        echo json_encode(['success' => false, 'message' => 'You are not allowed to manage this account']);
        exit();
    }
    
    // Check if this is a password-only update
    $password_only_update = isset($_POST['password_only']) && $_POST['password_only'] == '1';
    
    if (!$password_only_update) {
        // Normal update - get all fields
        $full_name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = requestedManagedRole($currentUser, $_POST['role'] ?? 'facilitator');
        $program = requestedManagedProgram($currentUser, $_POST['program'] ?? null);
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $remove_picture = isset($_POST['remove_profile_picture']) && $_POST['remove_profile_picture'] == '1';
        $isTargetStudent = ($targetUser['role'] ?? '') === 'student';
        $isStudentAfterUpdate = $role === 'student';
        $canEditStudentUsername = ($currentUser['role'] ?? '') === 'super_admin' && $isTargetStudent;
        $oldStudentUsername = $targetUser['username'] ?? '';
        
        if (($isTargetStudent || $isStudentAfterUpdate) && !$canEditStudentUsername) {
            $username = $targetUser['username'] ?? $username;
        }

        // Validate inputs for normal update
        if (empty($full_name) || empty($username) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
            exit();
        }

        if ($canEditStudentUsername) {
            ensureStudentNumberColumn($conn);
            $username = preg_replace('/\D/', '', $username);

            if (!preg_match('/^\d{10}$/', $username)) {
                echo json_encode(['success' => false, 'message' => 'Student number/username must be exactly 10 digits']);
                exit();
            }
        }
        
        // Check if username already exists (excluding current user)
        $checkStmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ? AND user_id != ?");
        $checkStmt->execute([$username, $user_id]);
        
        if ($checkStmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            exit();
        }

        if ($canEditStudentUsername) {
            $checkStmt = $conn->prepare("
                SELECT tbl_student_id
                FROM tbl_student
                WHERE student_number = ?
                  AND (user_id IS NULL OR user_id = 0 OR user_id != ?)
                LIMIT 1
            ");
            $checkStmt->execute([$username, $user_id]);

            if ($checkStmt->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'Student number already exists in the student masterlist']);
                exit();
            }

            try {
                $checkStmt = $conn->prepare("
                    SELECT registration_id
                    FROM tbl_public_student_registrations
                    WHERE student_number = ?
                      AND (user_id IS NULL OR user_id = 0 OR user_id != ?)
                    LIMIT 1
                ");
                $checkStmt->execute([$username, $user_id]);

                if ($username !== $oldStudentUsername && $checkStmt->fetchColumn()) {
                    echo json_encode(['success' => false, 'message' => 'Student number already exists in public registrations']);
                    exit();
                }
            } catch (PDOException $error) {
                if ($error->getCode() !== '42S02') {
                    throw $error;
                }
            }
        }
        
        // Check if email already exists (excluding current user)
        $checkStmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ? AND user_id != ?");
        $checkStmt->execute([$email, $user_id]);
        
        if ($checkStmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'Email already exists']);
            exit();
        }
        
        // Handle profile picture upload
        $profile_picture_update = '';
        $uploadedPicture = null;
        $shouldDeleteCurrentPicture = false;
        
        // Get current profile picture
        $stmt = $conn->prepare("SELECT profile_picture FROM tbl_users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_picture = $current_user['profile_picture'] ?? null;
        
        // Check if we need to remove the picture
        if ($remove_picture && $current_picture) {
            $profile_picture_update = ", profile_picture = NULL";
            $shouldDeleteCurrentPicture = true;
        }
        
        // Handle new profile picture upload
        if (isset($_FILES['profile_picture']) && ($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploadedPicture = uploadProfilePicture($_FILES['profile_picture'], dirname(__DIR__), 'account');
            } catch (RuntimeException $error) {
                echo json_encode(['success' => false, 'message' => $error->getMessage()]);
                exit();
            }

            if ($current_picture && !$remove_picture) {
                $shouldDeleteCurrentPicture = true;
            }

            $profile_picture_update = ", profile_picture = " . $conn->quote($uploadedPicture);
        }
        
        // Handle password update if provided
        if (!empty($password)) {
            if ($password !== $confirm_password) {
                echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
                exit();
            }
            
            if (strlen($password) < 8) {
                echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long']);
                exit();
            }
            
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Update with password and profile picture
            $sql = "UPDATE tbl_users 
                    SET full_name = ?, username = ?, email = ?, role = ?, program = ?, password_hash = ?, last_password_change = NULL, updated_at = CURRENT_TIMESTAMP 
                    $profile_picture_update
                    WHERE user_id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$full_name, $username, $email, $role, $program, $password_hash, $user_id]);
        } else {
            // Update without password
            $sql = "UPDATE tbl_users 
                    SET full_name = ?, username = ?, email = ?, role = ?, program = ?, updated_at = CURRENT_TIMESTAMP 
                    $profile_picture_update
                    WHERE user_id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$full_name, $username, $email, $role, $program, $user_id]);
        }

        if ($shouldDeleteCurrentPicture && $current_picture) {
            deleteProfilePictureFile($current_picture, dirname(__DIR__));
        }

        if ($canEditStudentUsername && $username !== $oldStudentUsername) {
            $studentStmt = $conn->prepare("
                UPDATE tbl_student
                SET student_number = ?, student_name = ?
                WHERE user_id = ? OR student_number = ?
            ");
            $studentStmt->execute([$username, $full_name, $user_id, $oldStudentUsername]);

            try {
                $registrationStmt = $conn->prepare("
                    UPDATE tbl_public_student_registrations
                    SET student_number = ?
                    WHERE user_id = ? OR student_number = ?
                ");
                $registrationStmt->execute([$username, $user_id, $oldStudentUsername]);
            } catch (PDOException $error) {
                if ($error->getCode() !== '42S02') {
                    throw $error;
                }
            }
        }
    } else {
        // Password-only update
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Password is required']);
            exit();
        }
        
        if ($password !== $confirm_password) {
            echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
            exit();
        }
        
        if (strlen($password) < 8) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long']);
            exit();
        }
        
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("
            UPDATE tbl_users 
            SET password_hash = ?, last_password_change = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE user_id = ?
        ");
        
        $stmt->execute([$password_hash, $user_id]);
    }
    
    logSystemEvent(
        $conn,
        $password_only_update ? 'user_password_changed' : 'user_updated',
        ($password_only_update ? 'Changed password for user ID ' : 'Updated user account ID ') . $user_id
    );

    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true, 
            'message' => $password_only_update ? 'Password changed successfully' : 'User account updated successfully'
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'message' => 'No changes were made'
        ]);
    }
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
