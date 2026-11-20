<?php
session_start();
require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/profile-picture-utils.php';
require_once '../include/student-cor.php';

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../login.php?error=Invalid request method");
    exit();
}

// Get and sanitize input
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

// Validate inputs
if (empty($username) || empty($password)) {
    header("Location: ../login.php?error=Username and password are required");
    exit();
}

try {
    // Find user by username or email
    $stmt = $conn->prepare("
        SELECT user_id, username, email, password_hash, full_name, role, program, profile_picture
        FROM tbl_users 
        WHERE username = :username OR email = :username
    ");
    
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        header("Location: ../login.php?error=Invalid username or password");
        exit();
    }
    
    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        header("Location: ../login.php?error=Invalid username or password");
        exit();
    }
    
    activateUserSession($conn, $user);

    if ($user['role'] === 'student' && studentNeedsCurrentCor($conn, (int) $user['user_id'])) {
        header('Location: ../cor-upload.php');
        exit();
    }

    if ($user['role'] === 'student') {
        $redirect = '../student-dashboard.php';
    } elseif (in_array($user['role'], ['super_admin', 'coordinator'], true)) {
        $redirect = '../landing_page.php';
    } else {
        $redirect = '../index.php';
    }
    header("Location: " . $redirect);
    exit();
    
} catch (PDOException $e) {
    header("Location: ../login.php?error=Login failed: " . urlencode($e->getMessage()));
    exit();
}
?>
