<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/attendance-settings.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !in_array($currentUser['role'] ?? '', ['super_admin', 'coordinator'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $cutoffs = [];
    $allowedComponents = attendanceCutoffComponentsForUser($currentUser);
    if (empty($allowedComponents)) {
        echo json_encode(['success' => false, 'message' => 'No attendance time settings are available for your account.']);
        exit();
    }

    foreach ($allowedComponents as $component) {
        $key = strtolower($component);
        $cutoffs[$component] = [
            'morning' => trim((string) ($_POST[$key . '_morning'] ?? '')),
            'afternoon' => trim((string) ($_POST[$key . '_afternoon'] ?? '')),
        ];
    }

    saveAttendanceCutoffsForComponents($conn, $cutoffs, $allowedComponents);
    logSystemEvent($conn, 'attendance_cutoffs_updated', 'Updated morning and afternoon late start times.');

    echo json_encode(['success' => true, 'message' => 'Late start times were updated successfully.']);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
