<?php
session_start();
require_once '../conn/conn.php'; // Fixed path
require_once '../include/user-permissions.php';

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

    $targetStmt = $conn->prepare("SELECT user_id, role, program FROM tbl_users WHERE user_id = ?");
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
        
        // Validate inputs for normal update
        if (empty($full_name) || empty($username) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
            exit();
        }
        
        // Check if username already exists (excluding current user)
        $checkStmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ? AND user_id != ?");
        $checkStmt->execute([$username, $user_id]);
        
        if ($checkStmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'message' => 'Username already exists']);
            exit();
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
        $params = [];
        
        // Get current profile picture
        $stmt = $conn->prepare("SELECT profile_picture FROM tbl_users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_picture = $current_user['profile_picture'] ?? null;
        
        // Check if we need to remove the picture
        if ($remove_picture && $current_picture) {
            // Delete the file
            $file_path = '../' . $current_picture;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            $profile_picture_update = ", profile_picture = NULL";
        }
        
        // Handle new profile picture upload
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/profiles/';
            
            // Create directory if it doesn't exist
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Delete old picture if exists
            if ($current_picture && !$remove_picture) {
                $old_file_path = '../' . $current_picture;
                if (file_exists($old_file_path)) {
                    unlink($old_file_path);
                }
            }
            
            $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $file_name = uniqid() . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $file_name;
            
            // Validate file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
            if (in_array($_FILES['profile_picture']['type'], $allowed_types)) {
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                    $profile_picture_update = ", profile_picture = 'uploads/profiles/" . $file_name . "'";
                }
            }
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
                    SET full_name = ?, username = ?, email = ?, role = ?, program = ?, password_hash = ?, updated_at = CURRENT_TIMESTAMP 
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
            SET password_hash = ?, updated_at = CURRENT_TIMESTAMP
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
