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
$component = normalizeProgram($_POST['component'] ?? null);

if ($component) {
    if (getSystemSetting($conn, 'component_selection_components_configured', '0') !== '1') {
        foreach (['CWTS', 'LTS', 'ROTC'] as $availableComponent) {
            setSystemSetting($conn, 'component_selection_' . strtolower($availableComponent) . '_enabled', '0');
        }
        setSystemSetting($conn, 'component_selection_components_configured', '1');
    }
    setSystemSetting($conn, 'component_selection_' . strtolower($component) . '_enabled', $enabled);
    $hasOpenComponent = false;
    foreach (['CWTS', 'LTS', 'ROTC'] as $availableComponent) {
        if (getSystemSetting($conn, 'component_selection_' . strtolower($availableComponent) . '_enabled', '0') === '1') {
            $hasOpenComponent = true;
            break;
        }
    }
    setSystemSetting($conn, 'component_selection_enabled', $hasOpenComponent ? '1' : '0');
} else {
    setSystemSetting($conn, 'component_selection_enabled', $enabled);
}

$openComponents = getOpenStudentComponents($conn);
$selectionEnabled = !empty($openComponents);
logSystemEvent(
    $conn,
    'component_selection_' . ($enabled === '1' ? 'opened' : 'closed'),
    'Super Admin ' . ($enabled === '1' ? 'opened ' : 'closed ') . ($component ?: 'student component selection') . '.'
);

echo json_encode([
    'success' => true,
    'enabled' => $enabled === '1',
    'component' => $component,
    'open_components' => $openComponents,
    'selection_enabled' => $selectionEnabled,
    'message' => ($component ?: 'Component selection') . ' is now ' . ($enabled === '1' ? 'open.' : 'closed.'),
]);
