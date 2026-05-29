<?php
session_start();
require_once '../conn/conn.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_id = $_POST['admin_id'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate passwords
    if ($password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
        exit();
    }
    
    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long']);
        exit();
    }
    
    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Update password
    $stmt = $conn->prepare("
        UPDATE tbl_admin 
        SET password_hash = ?
        WHERE tbl_admin_id = ?
    ");
    
    $stmt->execute([$password_hash, $admin_id]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Password changed successfully'
    ]);
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>