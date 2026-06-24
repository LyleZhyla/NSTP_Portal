<?php
session_start();
require_once '../conn/conn.php'; // Fixed path
require_once '../include/user-permissions.php';
require_once '../include/profile-picture-utils.php';

function deleteAdminTableExists(PDO $conn, $tableName) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$tableName]);
    return (int) $stmt->fetchColumn() > 0;
}

function deleteAdminColumnExists(PDO $conn, $tableName, $columnName) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tableName, $columnName]);
    return (int) $stmt->fetchColumn() > 0;
}

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
    
    // Prevent deleting yourself
    if ($user_id == $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'You cannot delete your own account']);
        exit();
    }
    
    try {
        // Check if user exists and get their role and profile picture
        $checkStmt = $conn->prepare("SELECT user_id, role, program, profile_picture FROM tbl_users WHERE user_id = ?");
        $checkStmt->execute([$user_id]);
        
        if ($checkStmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit();
        }
        
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!canManageUserRecord($currentUser, $user)) {
            echo json_encode(['success' => false, 'message' => 'You are not allowed to delete this account']);
            exit();
        }
        
        // Check if trying to delete super_admin
        if ($user['role'] === 'super_admin') {
            echo json_encode(['success' => false, 'message' => 'Cannot delete super administrator accounts']);
            exit();
        }
        
        ensureSystemLogsTable($conn);

        // Start transaction
        $conn->beginTransaction();

        if (deleteAdminTableExists($conn, 'tbl_student')) {
            $unlinkCreatedStudentsStmt = $conn->prepare("UPDATE tbl_student SET created_by = NULL WHERE created_by = ?");
            $unlinkCreatedStudentsStmt->execute([$user_id]);

            $unlinkStudentAccountStmt = $conn->prepare("UPDATE tbl_student SET user_id = NULL WHERE user_id = ?");
            $unlinkStudentAccountStmt->execute([$user_id]);
        }

        if (deleteAdminTableExists($conn, 'tbl_public_student_registrations')) {
            $unlinkRegistrationStmt = $conn->prepare("UPDATE tbl_public_student_registrations SET user_id = NULL WHERE user_id = ?");
            $unlinkRegistrationStmt->execute([$user_id]);
        }

        if (deleteAdminTableExists($conn, 'tbl_admin_sections')) {
            $unlinkAssignedByStmt = $conn->prepare("UPDATE tbl_admin_sections SET assigned_by = NULL WHERE assigned_by = ?");
            $unlinkAssignedByStmt->execute([$user_id]);
        }

        if (deleteAdminTableExists($conn, 'tbl_users') && deleteAdminColumnExists($conn, 'tbl_users', 'created_by')) {
            $unlinkCreatedUsersStmt = $conn->prepare("UPDATE tbl_users SET created_by = NULL WHERE created_by = ?");
            $unlinkCreatedUsersStmt->execute([$user_id]);
        }

        if (deleteAdminTableExists($conn, 'tbl_notifications')) {
            $deleteNotificationsStmt = $conn->prepare("DELETE FROM tbl_notifications WHERE user_id = ?");
            $deleteNotificationsStmt->execute([$user_id]);
        }

        if (deleteAdminTableExists($conn, 'tbl_system_logs') && deleteAdminColumnExists($conn, 'tbl_system_logs', 'user_id')) {
            $unlinkLogsStmt = $conn->prepare("UPDATE tbl_system_logs SET user_id = NULL WHERE user_id = ?");
            $unlinkLogsStmt->execute([$user_id]);
        }
        
        if ($user['role'] === 'student') {
            // Student rows are unlinked above so their attendance history can stay intact.
        }

        // First, delete related records in tbl_admin_sections
        if (deleteAdminTableExists($conn, 'tbl_admin_sections')) {
            $deleteSectionsStmt = $conn->prepare("DELETE FROM tbl_admin_sections WHERE user_id = ?");
            $deleteSectionsStmt->execute([$user_id]);
        }
        
        // Delete profile picture file if exists
        deleteProfilePictureFile($user['profile_picture'] ?? '', dirname(__DIR__));
        
        // Delete the user
        $deleteUserStmt = $conn->prepare("DELETE FROM tbl_users WHERE user_id = ?");
        $deleteUserStmt->execute([$user_id]);
        logSystemEvent($conn, 'user_deleted', "Deleted {$user['role']} account ID {$user_id}" . (!empty($user['program']) ? " ({$user['program']})" : ''));
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => ucfirst(str_replace('_', ' ', $user['role'])) . ' account deleted successfully'
        ]);
        
    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
