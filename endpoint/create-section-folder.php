<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/section-folders.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessAdminManagement($currentUser['role'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method');
    }

    $program = normalizeProgram($_POST['program'] ?? null);
    if (($currentUser['role'] ?? '') === 'coordinator') {
        $program = normalizeProgram($currentUser['program'] ?? null);
    }

    $folderName = trim((string) ($_POST['course_section'] ?? ''));

    $createdFolder = createSectionFolder($conn, $program, $folderName, $currentUser['user_id'] ?? null);
    markSharedDataChanged($conn);

    if (function_exists('logSystemEvent')) {
        logSystemEvent($conn, 'section_folder_created', "Created folder {$createdFolder}.");
    }

    echo json_encode([
        'success' => true,
        'message' => 'Folder created successfully.',
        'folder' => $createdFolder,
        'program' => $program,
    ]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
