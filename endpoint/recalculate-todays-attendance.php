<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

require_once '../conn/conn.php';
require_once '../include/attendance-settings.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Only the Super Admin can recalculate all attendance records.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

try {
    $result = recalculateAttendanceStatusesForDate($conn, date('Y-m-d'));
    logSystemEvent(
        $conn,
        'attendance_statuses_recalculated',
        'Recalculated ' . $result['checked'] . ' attendance record(s) for ' . $result['date'] . '; updated ' . $result['updated'] . '.'
    );
    echo json_encode([
        'success' => true,
        'message' => "Today's attendance was recalculated. Checked: {$result['checked']}. Corrected: {$result['updated']}.",
        'result' => $result,
    ]);
} catch (Throwable $error) {
    error_log('Attendance recalculation failed: ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to recalculate attendance: ' . $error->getMessage()]);
}
