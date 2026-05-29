<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$enabled = ($_POST['enabled'] ?? '0') === '1' ? '1' : '0';
setSystemSetting($conn, 'component_selection_enabled', $enabled);
logSystemEvent(
    $conn,
    'component_selection_' . ($enabled === '1' ? 'opened' : 'closed'),
    $enabled === '1' ? 'Super Admin opened student component selection.' : 'Super Admin closed student component selection.'
);

echo json_encode([
    'success' => true,
    'enabled' => $enabled === '1',
    'message' => $enabled === '1' ? 'Component selection is now open.' : 'Component selection is now closed.',
]);
