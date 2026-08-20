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

    $component = null;
    if (($currentUser['role'] ?? '') === 'coordinator') {
        $component = normalizeProgram($currentUser['program'] ?? null);
        if (!$component) {
            throw new RuntimeException('Coordinator program is missing.');
        }
        if (!autoSectionUsesAutomaticFolders($component)) {
            throw new RuntimeException('Automatic sectioning is unavailable for your assigned component.');
        }
    }

    ensureSectionFoldersTable($conn);

    $maxStudents = (int) ($_POST['max_students'] ?? getAutoSectionMaxStudents($conn, $component));
    $minStudents = (int) ($_POST['min_students'] ?? getAutoSectionMinStudents($conn, $component));
    $groupingMode = (string) ($_POST['grouping_mode'] ?? getAutoSectionGroupingMode($conn, $component));
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

    $conn->beginTransaction();
    $moved = rebuildAutoSectionFolders($conn, $component);
    if ($conn->inTransaction()) {
        $conn->commit();
    }

    markSharedDataChanged($conn);
    $rebuiltComponents = $component ? [$component] : $requestedComponents;
    $sequentialComponents = array_values(array_intersect($rebuiltComponents, ['CWTS', 'LTS']));
    $ruleDescription = $sequentialComponents
        ? 'sequential Course-Year/Section order with up to ' . $maxStudents . ' students per folder'
        : "{$groupingMode}, minimum {$minStudents} and target {$maxStudents} students per folder";
    logSystemEvent($conn, 'auto_section_folders_rebuilt', "Rebuilt automatic folder sections using {$ruleDescription}; {$moved} student record(s) updated.");

    echo json_encode([
        'success' => true,
        'message' => "Automatic folders rebuilt. CWTS/LTS sections were filled sequentially by Course and Year/Section. {$moved} student record(s) updated.",
        'updated' => $moved,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
