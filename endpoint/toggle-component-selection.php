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
$hasRotcMsLevel = array_key_exists('rotc_ms_level', $_POST);
$rotcMsLevel = $hasRotcMsLevel ? normalizeRotcMsLevel($_POST['rotc_ms_level']) : null;

if ($hasRotcMsLevel && !$rotcMsLevel) {
    echo json_encode(['success' => false, 'message' => 'Invalid ROTC MS level.']);
    exit();
}

if ($rotcMsLevel) {
    if (getSystemSetting($conn, 'component_selection_components_configured', '0') !== '1') {
        $legacyEnabled = isComponentSelectionEnabled($conn) ? '1' : '0';
        foreach (['CWTS', 'LTS', 'ROTC'] as $availableComponent) {
            setSystemSetting($conn, 'component_selection_' . strtolower($availableComponent) . '_enabled', $legacyEnabled);
        }
        setSystemSetting($conn, 'component_selection_components_configured', '1');
    }

    if (getSystemSetting($conn, 'component_selection_rotc_ms_configured', '0') !== '1') {
        $legacyRotcEnabled = getSystemSetting($conn, 'component_selection_rotc_enabled', '0');
        foreach (getRotcMsLevels() as $availableMsLevel) {
            setSystemSetting($conn, rotcMsLevelSettingKey($availableMsLevel), $legacyRotcEnabled);
        }
        setSystemSetting($conn, 'component_selection_rotc_ms_configured', '1');
    }

    setSystemSetting($conn, rotcMsLevelSettingKey($rotcMsLevel), $enabled);
    $hasOpenRotcLevel = false;
    foreach (getRotcMsLevels() as $availableMsLevel) {
        if (getSystemSetting($conn, rotcMsLevelSettingKey($availableMsLevel), '0') === '1') {
            $hasOpenRotcLevel = true;
            break;
        }
    }
    setSystemSetting($conn, 'component_selection_rotc_enabled', $hasOpenRotcLevel ? '1' : '0');
    $hasOpenComponent = false;
    foreach (['CWTS', 'LTS', 'ROTC'] as $availableComponent) {
        if (getSystemSetting($conn, 'component_selection_' . strtolower($availableComponent) . '_enabled', '0') === '1') {
            $hasOpenComponent = true;
            break;
        }
    }
    setSystemSetting($conn, 'component_selection_enabled', $hasOpenComponent ? '1' : '0');
} elseif ($component) {
    if (getSystemSetting($conn, 'component_selection_components_configured', '0') !== '1') {
        foreach (['CWTS', 'LTS', 'ROTC'] as $availableComponent) {
            setSystemSetting($conn, 'component_selection_' . strtolower($availableComponent) . '_enabled', '0');
        }
        setSystemSetting($conn, 'component_selection_components_configured', '1');
    }
    setSystemSetting($conn, 'component_selection_' . strtolower($component) . '_enabled', $enabled);
    if ($component === 'ROTC') {
        setSystemSetting($conn, 'component_selection_rotc_ms_configured', '1');
        foreach (getRotcMsLevels() as $availableMsLevel) {
            setSystemSetting($conn, rotcMsLevelSettingKey($availableMsLevel), $enabled);
        }
    }
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
$openRotcMsLevels = getOpenRotcMsLevels($conn);
$selectionEnabled = !empty($openComponents);
logSystemEvent(
    $conn,
    'component_selection_' . ($enabled === '1' ? 'opened' : 'closed'),
    'Super Admin ' . ($enabled === '1' ? 'opened ' : 'closed ') . ($rotcMsLevel ?: ($component ?: 'student component selection')) . '.'
);

echo json_encode([
    'success' => true,
    'enabled' => $enabled === '1',
    'component' => $component,
    'open_components' => $openComponents,
    'open_rotc_ms_levels' => $openRotcMsLevels,
    'selection_enabled' => $selectionEnabled,
    'message' => ($rotcMsLevel ?: ($component ?: 'Component selection')) . ' is now ' . ($enabled === '1' ? 'open.' : 'closed.'),
]);
