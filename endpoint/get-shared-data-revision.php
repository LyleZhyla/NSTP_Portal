<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessStaffTools($currentUser['role'] ?? '')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

echo json_encode([
    'success' => true,
    'revision' => getSharedDataRevision($conn),
]);
