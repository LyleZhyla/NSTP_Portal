<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Super admin access is required.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$minutes = (int) ($_POST['minutes'] ?? 5);
$allowedMinutes = [1, 3, 5, 10, 15, 30, 60];

if (!in_array($minutes, $allowedMinutes, true)) {
    echo json_encode(['success' => false, 'message' => 'Please choose a valid timeout option.']);
    exit();
}

setSystemSetting($conn, 'inactivity_timeout_minutes', (string) $minutes);
logSystemEvent($conn, 'inactivity_timeout_updated', "Set inactivity timeout to {$minutes} minute(s)");

echo json_encode([
    'success' => true,
    'minutes' => $minutes,
    'message' => "Inactivity timeout is now {$minutes} minute(s).",
]);
?>
