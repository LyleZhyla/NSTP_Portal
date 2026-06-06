<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/notifications.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

$notificationId = (int) ($_POST['notification_id'] ?? 0);
if ($notificationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid notification']);
    exit;
}

try {
    ensureNotificationTables($conn);
    $stmt = $conn->prepare("
        UPDATE tbl_notifications
        SET is_read = 1, read_at = NOW()
        WHERE notification_id = ? AND user_id = ?
    ");
    $stmt->execute([$notificationId, (int) $_SESSION['user_id']]);

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
