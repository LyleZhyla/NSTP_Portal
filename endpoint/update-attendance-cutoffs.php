<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/attendance-settings.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || $currentUser['role'] !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $cutoffs = [];
    foreach (attendanceComponents() as $component) {
        $key = strtolower($component);
        $cutoffs[$component] = [
            'morning' => trim((string) ($_POST[$key . '_morning'] ?? '')),
            'afternoon' => trim((string) ($_POST[$key . '_afternoon'] ?? '')),
        ];
    }

    saveAttendanceCutoffs($conn, $cutoffs);
    logSystemEvent($conn, 'attendance_cutoffs_updated', 'Updated morning and afternoon late start times.');

    echo json_encode(['success' => true, 'message' => 'Late start times were updated successfully.']);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
