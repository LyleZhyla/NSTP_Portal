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

    $folderIds = $_POST['folder_ids'] ?? [];
    if (!is_array($folderIds)) {
        $folderIds = explode(',', (string) $folderIds);
    }

    $folderIds = array_values(array_unique(array_filter(array_map('intval', $folderIds), fn($id) => $id > 0)));
    if (empty($folderIds)) {
        throw new RuntimeException('Please select at least one folder.');
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

    if (empty($folders)) {
        throw new RuntimeException('Selected folders were not found.');
    }

    $actorProgram = normalizeProgram($currentUser['program'] ?? null);
    foreach ($folders as $folder) {
        $folderProgram = normalizeProgram($folder['program'] ?? null);
        if (!$folderProgram) {
            throw new RuntimeException('One selected folder has an invalid program.');
        }

        if (($currentUser['role'] ?? '') === 'coordinator' && $actorProgram !== $folderProgram) {
            throw new RuntimeException('You are not allowed to delete one or more selected folders.');
        }
    }

    $conn->beginTransaction();

    $deleted = 0;
    $releasedStudents = 0;
    foreach ($folders as $folder) {
        $program = normalizeProgram($folder['program']);

        $moveStmt = $conn->prepare("
            UPDATE tbl_student
            SET course_section = ?, created_by = NULL
            WHERE course_section = ?
        ");
        $moveStmt->execute([$program, $folder['course_section']]);
        $releasedStudents += $moveStmt->rowCount();

        $assignmentStmt = $conn->prepare("DELETE FROM tbl_admin_sections WHERE course_section = ?");
        $assignmentStmt->execute([$folder['course_section']]);

        $deleteStmt = $conn->prepare("DELETE FROM tbl_section_folders WHERE folder_id = ?");
        $deleteStmt->execute([(int) $folder['folder_id']]);
        $deleted += $deleteStmt->rowCount();
    }

    $conn->commit();

    logSystemEvent(
        $conn,
        'section_folders_deleted',
        'Deleted ' . $deleted . ' folder(s) and released ' . $releasedStudents . ' student(s) to pending.'
    );

    echo json_encode([
        'success' => true,
        'message' => "Deleted {$deleted} folder(s). Released {$releasedStudents} student(s) back to pending.",
        'deleted' => $deleted,
        'released_students' => $releasedStudents,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
