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
    saveAutoSectionMaxStudents($conn, $maxStudents, $component);

    $scope = $component ?: 'default';
    logSystemEvent($conn, 'auto_section_settings_updated', "Set {$scope} automatic folder max students to {$maxStudents}.");

    echo json_encode([
        'success' => true,
        'message' => 'Automatic sectioning setting saved.',
        'max_students' => $maxStudents,
    ]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
