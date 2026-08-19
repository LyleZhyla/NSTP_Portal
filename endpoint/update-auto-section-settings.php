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
        throw new RuntimeException('Automatic sectioning is unavailable for your assigned component.');
    }

    $maxStudents = (int) ($_POST['max_students'] ?? 40);
    $minStudents = (int) ($_POST['min_students'] ?? 20);
    $groupingMode = (string) ($_POST['grouping_mode'] ?? 'college_course');
    $collegeGroups = normalizeAutoSectionCollegeGroups((array) ($_POST['college_groups'] ?? []));
    $requestedComponents = array_values(array_filter(array_map('normalizeProgram', (array) ($_POST['section_components'] ?? []))));
    saveAutoSectionMaxStudents($conn, $maxStudents, $component);
    saveAutoSectionMinStudents($conn, $minStudents, $component);
    saveAutoSectionGroupingMode($conn, $groupingMode, $component);
    saveAutoSectionCollegeGroups($conn, $collegeGroups, $component);
    if ($component) {
        saveAutoSectionEnabled($conn, $component, in_array($component, $requestedComponents, true));
    } else {
        foreach (autoSectionComponentOptions() as $sectionComponent) {
            $isSelected = in_array($sectionComponent, $requestedComponents, true);
            saveAutoSectionEnabled($conn, $sectionComponent, $isSelected);
            if ($isSelected) {
                saveAutoSectionMaxStudents($conn, $maxStudents, $sectionComponent);
                saveAutoSectionMinStudents($conn, $minStudents, $sectionComponent);
                saveAutoSectionGroupingMode($conn, $groupingMode, $sectionComponent);
                saveAutoSectionCollegeGroups($conn, $collegeGroups, $sectionComponent);
            }
        }
    }

    $scope = $component ?: 'default';
    markSharedDataChanged($conn);
    logSystemEvent($conn, 'auto_section_settings_updated', "Set {$scope} automatic sectioning to {$groupingMode}, minimum {$minStudents} and target {$maxStudents} students per folder.");

    echo json_encode([
        'success' => true,
        'message' => 'Automatic sectioning setting saved.',
        'max_students' => $maxStudents,
        'min_students' => $minStudents,
        'grouping_mode' => $groupingMode,
        'college_groups' => $collegeGroups,
        'enabled_components' => getEnabledAutoSectionComponents($conn),
    ]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
