<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/notifications.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessStaffTools($currentUser['role'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $result = createAnnouncement(
        $conn,
        $currentUser,
        $_POST['title'] ?? '',
        $_POST['body'] ?? '',
        $_POST['scope_program'] ?? null,
        $_POST['recipient_scope'] ?? 'all'
    );

    logSystemEvent($conn, 'announcement_created', 'Created announcement #' . $result['announcement_id']);

    echo json_encode([
        'success' => true,
        'message' => 'Announcement posted. ' . (int) $result['recipient_count'] . ' recipient(s) were notified.',
        'recipient_count' => (int) $result['recipient_count'],
    ]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
