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

$enabled = ($_POST['enabled'] ?? '0') === '1';
$wasEnabled = isStudentComponentChangeEnabled($conn);
if ($enabled && !$wasEnabled) {
    setSystemSetting($conn, 'student_component_change_round', (string) (getStudentComponentChangeRound($conn) + 1));
}
setSystemSetting($conn, 'student_component_change_enabled', $enabled ? '1' : '0');

logSystemEvent(
    $conn,
    $enabled ? 'student_component_change_enabled' : 'student_component_change_disabled',
    'Super Admin ' . ($enabled ? 'enabled' : 'disabled') . ' direct component changes for all students.'
);

echo json_encode([
    'success' => true,
    'enabled' => $enabled,
    'message' => $enabled
        ? 'All students can now change their saved component once without approval.'
        : 'Saved component changes require Super Admin approval again.',
]);
