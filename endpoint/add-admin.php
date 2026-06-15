<?php
session_start();
require_once '../conn/conn.php'; // Fixed path - removed the dot
require_once '../include/user-permissions.php';
require_once '../include/mailer.php';

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
    
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = requestedManagedRole($currentUser, $_POST['role'] ?? 'facilitator');
    $program = requestedManagedProgram($currentUser, $_POST['program'] ?? null);

    if ($currentUser['role'] === 'coordinator' && !$program) {
        echo json_encode(['success' => false, 'message' => 'Coordinator account has no assigned program']);
        exit();
    }
    
    // Validate inputs
    if (empty($full_name) || empty($username) || empty($email)) {
        echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email address']);
        exit();
    }

    function generateTemporaryPassword($length = 12) {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $password;
    }
    
    // Check if username already exists
    $checkStmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ?");
    $checkStmt->execute([$username]);
    
    if ($checkStmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        exit();
    }
    
    // Check if email already exists
    $checkStmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ?");
    $checkStmt->execute([$email]);
    
    if ($checkStmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'Email already exists']);
        exit();
    }
    
    $password = generateTemporaryPassword();
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $created_by = $_SESSION['user_id'];
    
    // Handle profile picture upload
    $profile_picture = null;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/profiles/';
        
        // Create directory if it doesn't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
        $file_name = uniqid() . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $file_name;
        
        // Validate file type
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
        if (in_array($_FILES['profile_picture']['type'], $allowed_types)) {
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                $profile_picture = 'uploads/profiles/' . $file_name;
            }
        }
    }
    
    try {
        if ($profile_picture) {
            $stmt = $conn->prepare("
                INSERT INTO tbl_users (full_name, username, email, password_hash, role, program, created_by, profile_picture) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$full_name, $username, $email, $password_hash, $role, $program, $created_by, $profile_picture]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO tbl_users (full_name, username, email, password_hash, role, program, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$full_name, $username, $email, $password_hash, $role, $program, $created_by]);
        }

        logSystemEvent($conn, 'user_created', "Created {$role} account: {$username}" . ($program ? " ({$program})" : ''));
        $emailSent = sendAccountCredentialsEmail($email, $full_name, $username, $password, $role);
        
        echo json_encode([
            'success' => true, 
            'message' => $emailSent
                ? 'User account created successfully. Login credentials were sent to the email address.'
                : 'User account created successfully, but the login credentials email was not sent. Please check SMTP settings.',
            'username' => $username,
            'temporary_password' => $password
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
