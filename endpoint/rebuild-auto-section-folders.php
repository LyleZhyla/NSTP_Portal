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
    }

    ensureSectionFoldersTable($conn);

    $conn->beginTransaction();
    $moved = rebuildAutoSectionFolders($conn, $component);
    if ($conn->inTransaction()) {
        $conn->commit();
    }

    logSystemEvent($conn, 'auto_section_folders_rebuilt', "Rebuilt automatic folder sections; {$moved} student record(s) updated.");

    echo json_encode([
        'success' => true,
        'message' => "Automatic folders rebuilt. {$moved} student record(s) updated.",
        'updated' => $moved,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
