<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/mailer.php';

function generateAccountEmailTemporaryPassword($length = 12) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessAdminManagement($currentUser['role'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$userId = (int) ($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT user_id, full_name, username, email, role, program
        FROM tbl_users
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser || !canManageUserRecord($currentUser, $targetUser)) {
        echo json_encode(['success' => false, 'message' => 'You are not allowed to send credentials to this account']);
        exit();
    }

    if (($targetUser['role'] ?? '') !== 'facilitator') {
        echo json_encode(['success' => false, 'message' => 'Credential email can only be sent to facilitator accounts here']);
        exit();
    }

    $email = trim((string) ($targetUser['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isPlaceholderEmail($email)) {
        echo json_encode(['success' => false, 'message' => 'This facilitator does not have a valid email address']);
        exit();
    }

    $temporaryPassword = generateAccountEmailTemporaryPassword();
    $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);

    $conn->beginTransaction();

    $updateStmt = $conn->prepare("
        UPDATE tbl_users
        SET password_hash = ?, updated_at = CURRENT_TIMESTAMP
        WHERE user_id = ?
    ");
    $updateStmt->execute([$passwordHash, $userId]);

    $emailSent = sendAccountCredentialsEmail(
        $email,
        $targetUser['full_name'] ?? '',
        $targetUser['username'] ?? '',
        $temporaryPassword,
        $targetUser['role'] ?? 'facilitator'
    );

    if (!$emailSent) {
        $conn->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Credentials were not sent. ' . (getAppMailLastError() ?: 'Please check SMTP settings.'),
        ]);
        exit();
    }

    $conn->commit();
    logSystemEvent($conn, 'user_credentials_emailed', 'Sent new facilitator credentials to user ID ' . $userId);

    echo json_encode([
        'success' => true,
        'message' => 'New login credentials were sent to ' . $email . '.',
        'username' => $targetUser['username'],
        'temporary_password' => $temporaryPassword,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    error_log('Facilitator credential email failed: ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to send credentials: ' . $error->getMessage()]);
}
?>
