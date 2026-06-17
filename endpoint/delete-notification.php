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
        DELETE FROM tbl_notifications
        WHERE notification_id = ? AND user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$notificationId, (int) $_SESSION['user_id']]);

    echo json_encode([
        'success' => $stmt->rowCount() > 0,
        'message' => $stmt->rowCount() > 0 ? 'Notification deleted.' : 'Notification not found.',
    ]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
