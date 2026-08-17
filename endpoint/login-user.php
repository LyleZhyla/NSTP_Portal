<?php
session_start();
require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/profile-picture-utils.php';

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
    
    // Set session variables
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['program'] = $user['program'] ?? null;
    $_SESSION['last_activity'] = time();

    // Durable account-open tracking. The audit log remains as a fallback for
    // databases where the one-time performance migration is still pending.
    try {
        $loginActivityStmt = $conn->prepare("
            UPDATE tbl_users
            SET first_login_at = COALESCE(first_login_at, NOW()),
                last_login_at = NOW(),
                login_count = login_count + 1
            WHERE user_id = ?
        ");
        $loginActivityStmt->execute([(int) $user['user_id']]);
    } catch (Throwable $ignored) {
        // Backward compatibility until db/optimize_public_registrations.sql runs.
    }

    $profilePicture = trim((string) ($user['profile_picture'] ?? ''));
    if ($profilePicture === '' || !profilePictureExists($profilePicture, dirname(__DIR__))) {
        $profilePicture = syncRegistrationProfilePicture($conn, (int) $user['user_id'], dirname(__DIR__));
    }
    if ($profilePicture !== '') {
        $_SESSION['profile_picture'] = $profilePicture;
    } else {
        unset($_SESSION['profile_picture']);
    }

    logSystemEvent($conn, 'user_login', $user['username'] . ' logged in as ' . $user['role']);
    
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
