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
    $folderIds = array_values(array_filter($folderIds, fn($id) => $id > 0));

    if (empty($folderIds)) {
        throw new RuntimeException('Folder is required.');
    }

    ensureSectionFoldersTable($conn);
    $placeholders = implode(',', array_fill(0, count($folderIds), '?'));
    $stmt = $conn->prepare("
        SELECT folder_id, program, course_section, is_locked
        FROM tbl_section_folders
        WHERE folder_id IN ($placeholders)
    ");
    $stmt->execute($folderIds);
    $folders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($folders) !== count($folderIds)) {
        throw new RuntimeException('Folder not found.');
    }

    foreach ($folders as $folder) {
        $rawProgram = strtoupper(trim((string) ($folder['program'] ?? '')));
        $program = $rawProgram === 'PUBLIC' ? 'PUBLIC' : normalizeProgram($rawProgram);
        if (!$program) {
            throw new RuntimeException('Folder program is invalid.');
        }

        if (($currentUser['role'] ?? '') === 'coordinator' && normalizeProgram($currentUser['program'] ?? null) !== $program) {
            throw new RuntimeException('You are not allowed to delete this folder.');
        }
        if ((int) ($folder['is_locked'] ?? 1) === 1) {
            throw new RuntimeException('This folder is locked. The Super Admin must unlock it before it can be deleted.');
        }
    }

    $conn->beginTransaction();

    $moveStmt = $conn->prepare("
        UPDATE tbl_student
        SET course_section = ?, created_by = NULL
        WHERE course_section = ?
    ");
    $assignmentStmt = $conn->prepare("
        DELETE ads
        FROM tbl_admin_sections ads
        INNER JOIN tbl_users u ON u.user_id = ads.user_id
        WHERE ads.course_section = ?
          AND u.role = 'facilitator'
          AND u.program = ?
    ");
    $deleteStmt = $conn->prepare("DELETE FROM tbl_section_folders WHERE folder_id = ?");

    $releasedStudents = 0;
    $deletedFolders = 0;
    $deletedFolderNames = [];

    foreach ($folders as $folder) {
        $rawProgram = strtoupper(trim((string) ($folder['program'] ?? '')));
        $program = $rawProgram === 'PUBLIC' ? 'PUBLIC' : normalizeProgram($rawProgram);
        $courseSection = $folder['course_section'];

        $moveStmt->execute([$program, $courseSection]);
        $releasedStudents += $moveStmt->rowCount();

        $assignmentStmt->execute([$courseSection, $program]);
        $deleteStmt->execute([(int) $folder['folder_id']]);

        if ($deleteStmt->rowCount() > 0) {
            $deletedFolders++;
            $deletedFolderNames[] = $courseSection;
        }
    }

    if ($deletedFolders === 0) {
        throw new RuntimeException('Folder was not deleted. It may have already been removed.');
    }

    $conn->commit();
    markSharedDataChanged($conn);

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
