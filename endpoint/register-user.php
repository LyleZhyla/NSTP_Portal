<?php
session_start();
require_once '../conn/conn.php';
require_once '../include/mailer.php';

// Set JSON header
header('Content-Type: application/json');

// Initialize response
$response = ['success' => false, 'message' => ''];

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method';
    echo json_encode($response);
    exit();
}

// Get and sanitize input
$full_name = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$password_confirm = $_POST['password_confirm'] ?? ($_POST['confirm_password'] ?? '');

// Validate inputs
if (empty($full_name) || empty($username) || empty($email) || empty($password)) {
    $response['message'] = 'All fields are required';
    echo json_encode($response);
    exit();
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $response['message'] = 'Invalid email format';
    echo json_encode($response);
    exit();
}

// Validate password length
if (strlen($password) < 6) {
    $response['message'] = 'Password must be at least 6 characters';
    echo json_encode($response);
    exit();
}

// Check if passwords match
if ($password !== $password_confirm) {
    $response['message'] = 'Passwords do not match';
    echo json_encode($response);
    exit();
}

try {
    // Check if username or email already exists
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_users WHERE username = :username OR email = :email");
    $stmt->execute(['username' => $username, 'email' => $email]);
    
    if ($stmt->fetchColumn() > 0) {
        $response['message'] = 'Username or email already exists';
        echo json_encode($response);
        exit();
    }
    
    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert new user
    $stmt = $conn->prepare("
        INSERT INTO tbl_users (username, email, password_hash, full_name, role) 
        VALUES (:username, :email, :password_hash, :full_name, 'student')
    ");
    
    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => $password_hash,
        'full_name' => $full_name
    ]);

    sendAccountCredentialsEmail($email, $full_name, $username, $password, 'student');
    
    $response['success'] = true;
    $response['message'] = 'Registration successful! You can now login.';
    
} catch (PDOException $e) {
    $response['message'] = 'Registration failed: ' . $e->getMessage();
}

echo json_encode($response);
?>
