<?php
session_start();
header('Content-Type: application/json');
require_once '../conn/conn.php'; // Fixed path
require_once '../include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessAdminManagement($currentUser['role'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($userId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid admin ID']);
    exit();
}

try {
    $targetStmt = $conn->prepare("SELECT user_id, role, program FROM tbl_users WHERE user_id = ?");
    $targetStmt->execute([$userId]);
    $targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser || !canManageUserRecord($currentUser, $targetUser)) {
        echo json_encode(['success' => false, 'message' => 'You are not allowed to view this account']);
        exit();
    }

    // Get admin's assigned sections
    $stmt = $conn->prepare("
        SELECT a.*, 
               creator.full_name as assigned_by_fullname,
               creator.username as assigned_by_name
        FROM tbl_admin_sections a
        LEFT JOIN tbl_users creator ON a.assigned_by = creator.user_id
        WHERE a.user_id = ?
        ORDER BY a.assigned_at DESC
    ");
    $stmt->execute([$userId]);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'sections' => $sections
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
