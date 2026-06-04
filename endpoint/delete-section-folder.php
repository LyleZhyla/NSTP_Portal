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

    $folderIdsInput = $_POST['folder_ids'] ?? [];
    if (!is_array($folderIdsInput)) {
        $folderIdsInput = [$folderIdsInput];
    }

    $folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIdsInput))));
    $singleFolderId = (int) ($_POST['folder_id'] ?? 0);
    if (empty($folderIds) && $singleFolderId > 0) {
        $folderIds = [$singleFolderId];
    }

    if (empty($folderIds)) {
        throw new RuntimeException('Folder is required.');
    }

    ensureSectionFoldersTable($conn);
    $placeholders = implode(',', array_fill(0, count($folderIds), '?'));
    $stmt = $conn->prepare("
        SELECT folder_id, program, course_section
        FROM tbl_section_folders
        WHERE folder_id IN ($placeholders)
    ");
    $stmt->execute($folderIds);
    $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($folders) !== count($folderIds)) {
        throw new RuntimeException('Folder not found.');
    }

    foreach ($folders as $folder) {
        $program = normalizeProgram($folder['program'] ?? null);
        if (!$program) {
            throw new RuntimeException('Folder program is invalid.');
        }

        if (($currentUser['role'] ?? '') === 'coordinator' && normalizeProgram($currentUser['program'] ?? null) !== $program) {
            throw new RuntimeException('You are not allowed to delete this folder.');
        }
    }

    $conn->beginTransaction();

    $moveStmt = $conn->prepare("
        UPDATE tbl_student
        SET course_section = ?, created_by = NULL
        WHERE course_section = ?
    ");
    $assignmentStmt = $conn->prepare("DELETE FROM tbl_admin_sections WHERE course_section = ?");
    $deleteStmt = $conn->prepare("DELETE FROM tbl_section_folders WHERE folder_id = ?");

    $releasedStudents = 0;
    $deletedFolders = 0;
    $deletedFolderNames = [];

    foreach ($folders as $folder) {
        $program = normalizeProgram($folder['program'] ?? null);
        $courseSection = $folder['course_section'];

        $moveStmt->execute([$program, $courseSection]);
        $releasedStudents += $moveStmt->rowCount();

        $assignmentStmt->execute([$courseSection]);
        $deleteStmt->execute([(int) $folder['folder_id']]);

        $deletedFolders++;
        $deletedFolderNames[] = $courseSection;
    }

    $conn->commit();

    logSystemEvent(
        $conn,
        $deletedFolders === 1 ? 'section_folder_deleted' : 'section_folders_deleted',
        'Deleted ' . $deletedFolders . ' folder(s): ' . implode(', ', $deletedFolderNames) . '. Released ' . $releasedStudents . ' student(s) back to pending.'
    );

    echo json_encode([
        'success' => true,
        'message' => $deletedFolders === 1
            ? 'Folder deleted. Students were released back to pending.'
            : $deletedFolders . ' folders deleted. Students were released back to pending.',
        'deleted_folders' => $deletedFolders,
        'released_students' => $releasedStudents,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
