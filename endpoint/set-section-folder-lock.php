<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/section-folders.php';

function folderLockResponse($success, $message, array $extra = []) {
    echo json_encode(array_merge([
        'success' => (bool) $success,
        'message' => (string) $message,
    ], $extra));
    exit();
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    folderLockResponse(false, 'Only the Super Admin can lock or unlock folders.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    folderLockResponse(false, 'Invalid request method.');
}

$csrf = $_POST['csrf_token'] ?? '';
if (!is_string($csrf) || empty($_SESSION['folder_lock_csrf']) || !hash_equals($_SESSION['folder_lock_csrf'], $csrf)) {
    folderLockResponse(false, 'Your session expired. Reload the page and try again.');
}

$folderId = (int) ($_POST['folder_id'] ?? 0);
$isLocked = filter_var($_POST['is_locked'] ?? null, FILTER_VALIDATE_INT);
if ($folderId <= 0 || !in_array($isLocked, [0, 1], true)) {
    folderLockResponse(false, 'Folder and lock status are required.');
}

try {
    ensureSectionFoldersTable($conn);
    $conn->beginTransaction();

    $stmt = $conn->prepare("SELECT folder_id, program, course_section, is_locked FROM tbl_section_folders WHERE folder_id = ? FOR UPDATE");
    $stmt->execute([$folderId]);
    $folder = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$folder) {
        throw new RuntimeException('Folder not found.');
    }

    $updateStmt = $conn->prepare("UPDATE tbl_section_folders SET is_locked = ? WHERE folder_id = ?");
    $updateStmt->execute([$isLocked, $folderId]);
    $conn->commit();

    markSharedDataChanged($conn);
    logSystemEvent(
        $conn,
        $isLocked ? 'section_folder_locked' : 'section_folder_unlocked',
        ($isLocked ? 'Locked' : 'Unlocked') . ' student folder ' . $folder['course_section'] . ' (' . $folder['program'] . ').'
    );

    folderLockResponse(true, $isLocked ? 'Folder locked.' : 'Folder unlocked. Students can now be moved or removed.', [
        'folder_id' => $folderId,
        'is_locked' => (bool) $isLocked,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Folder lock update failed: ' . $error->getMessage());
    folderLockResponse(false, $error->getMessage());
}
