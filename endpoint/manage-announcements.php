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

$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$announcementIds = $_POST['announcement_ids'] ?? [];
if (!is_array($announcementIds)) {
    $announcementIds = [$announcementIds];
}

try {
    if ($action === 'archive') {
        $affected = archiveAnnouncements($conn, $currentUser, $announcementIds);
        logSystemEvent($conn, 'announcements_archived', 'Archived ' . $affected . ' announcement(s).');
        echo json_encode([
            'success' => true,
            'message' => 'Archived ' . $affected . ' announcement(s).',
            'affected_count' => $affected,
        ]);
        exit;
    }

    if ($action === 'delete') {
        $affected = deleteAnnouncements($conn, $currentUser, $announcementIds);
        logSystemEvent($conn, 'announcements_deleted', 'Deleted ' . $affected . ' announcement(s).');
        echo json_encode([
            'success' => true,
            'message' => 'Deleted ' . $affected . ' announcement(s).',
            'affected_count' => $affected,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
