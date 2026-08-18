<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/automatic-sectioning.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !in_array($currentUser['role'] ?? '', ['super_admin', 'coordinator'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method');
    }

    $component = ($currentUser['role'] ?? '') === 'coordinator'
        ? normalizeProgram($currentUser['program'] ?? null)
        : null;

    if (($currentUser['role'] ?? '') === 'coordinator' && !$component) {
        throw new RuntimeException('Coordinator program is missing.');
    }
    if ($component && !autoSectionUsesAutomaticFolders($component)) {
        throw new RuntimeException('Automatic folder sectioning is not used for ROTC.');
    }

    $maxStudents = (int) ($_POST['max_students'] ?? 40);
    $groupingMode = (string) ($_POST['grouping_mode'] ?? 'college_course');
    $requestedComponents = array_values(array_filter(array_map('normalizeProgram', (array) ($_POST['section_components'] ?? []))));
    saveAutoSectionMaxStudents($conn, $maxStudents, $component);
    saveAutoSectionGroupingMode($conn, $groupingMode, $component);
    if ($component) {
        saveAutoSectionEnabled($conn, $component, in_array($component, $requestedComponents, true));
    } else {
        foreach (autoSectionComponentOptions() as $sectionComponent) {
            saveAutoSectionEnabled($conn, $sectionComponent, in_array($sectionComponent, $requestedComponents, true));
        }
    }

    $scope = $component ?: 'default';
    logSystemEvent($conn, 'auto_section_settings_updated', "Set {$scope} automatic sectioning to {$groupingMode}, {$maxStudents} students per section.");

    echo json_encode([
        'success' => true,
        'message' => 'Automatic sectioning setting saved.',
        'max_students' => $maxStudents,
        'grouping_mode' => $groupingMode,
        'enabled_components' => getEnabledAutoSectionComponents($conn),
    ]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
