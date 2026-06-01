<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !in_array($currentUser['role'] ?? '', ['super_admin', 'coordinator'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$enabled = ($_POST['enabled'] ?? '0') === '1' ? '1' : '0';
setSystemSetting($conn, 'facilitator_scan_restriction_enabled', $enabled);

$actorLabel = ($currentUser['role'] ?? '') === 'coordinator' ? 'Coordinator' : 'Super Admin';
logSystemEvent(
    $conn,
    'facilitator_scan_restriction_' . ($enabled === '1' ? 'enabled' : 'disabled'),
    $enabled === '1'
        ? $actorLabel . ' enabled facilitator assigned-student scan restriction.'
        : $actorLabel . ' disabled facilitator assigned-student scan restriction.'
);

echo json_encode([
    'success' => true,
    'enabled' => $enabled === '1',
    'message' => $enabled === '1'
        ? 'Facilitators can now scan only their assigned students.'
        : 'Facilitators can now scan students during the common module phase.',
]);
