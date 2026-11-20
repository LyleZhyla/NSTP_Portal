<?php
session_start();
require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../include/student-cor.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../cor-upload.php');
    exit();
}

$pendingUserId = (int) ($_SESSION['pending_cor_user_id'] ?? 0);
$loggedUserId = (int) ($_SESSION['user_id'] ?? 0);
$userId = $pendingUserId ?: $loggedUserId;
$csrf = (string) ($_POST['csrf_token'] ?? '');
if ($userId < 1 || empty($_SESSION['cor_upload_csrf']) || !hash_equals($_SESSION['cor_upload_csrf'], $csrf)) {
    $_SESSION['cor_upload_error'] = 'Nag-expire ang upload session. Mag-login at subukan ulit.';
    header('Location: ../login.php');
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT user_id, username, email, full_name, role, program, profile_picture
        FROM tbl_users
        WHERE user_id = ? AND role = 'student'
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new RuntimeException('Hindi makita ang student account.');
    }

    saveStudentCorUpload($conn, $userId, $_FILES['cor_file'] ?? []);
    activateUserSession($conn, $user);
    $_SESSION['student_flash_success'] = 'Na-save na ang COR mo. Maaari mo nang gamitin ang account para sa bagong semester.';
    header('Location: ../student-dashboard.php');
    exit();
} catch (Throwable $error) {
    $_SESSION['cor_upload_error'] = $error instanceof RuntimeException
        ? $error->getMessage()
        : 'Hindi ma-save ang COR ngayon. Subukan ulit mamaya.';
    error_log('Student COR upload failed: ' . $error->getMessage());
    header('Location: ../cor-upload.php');
    exit();
}

