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
        'message' => 'Announcement posted. ' . (int) $result['recipient_count'] . ' in-app notification(s) created. ' . (int) $result['email_sent_count'] . ' email(s) sent. ' . (int) $result['email_invalid_count'] . ' invalid/placeholder email(s). ' . (int) $result['email_failed_count'] . ' send failure(s).',
        'recipient_count' => (int) $result['recipient_count'],
        'email_sent_count' => (int) $result['email_sent_count'],
        'email_skipped_count' => (int) $result['email_skipped_count'],
        'email_invalid_count' => (int) $result['email_invalid_count'],
        'email_failed_count' => (int) $result['email_failed_count'],
    ]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
