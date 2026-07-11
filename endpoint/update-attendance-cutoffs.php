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
    $details = 'Updated morning and afternoon late start times.';

    if (($currentUser['role'] ?? '') === 'super_admin' && isset($_POST['absent_notification_grace_hours'])) {
        saveAbsentNotificationGraceHours($conn, $_POST['absent_notification_grace_hours']);
        $details .= ' Updated absent notification delay to ' . (int) $_POST['absent_notification_grace_hours'] . ' hour(s).';
    }

    // Saved attendance rows keep the status calculated at scan time. Recompute
    // today's rows immediately so a corrected cutoff also fixes earlier scans.
    $recalculation = recalculateAttendanceStatusesForDate($conn, date('Y-m-d'));
    $details .= ' Recalculated ' . $recalculation['checked'] . ' record(s) for today; corrected ' . $recalculation['updated'] . '.';

    logSystemEvent($conn, 'attendance_cutoffs_updated', $details);

    echo json_encode([
        'success' => true,
        'message' => 'Attendance time settings were updated successfully. Today\'s records checked: '
            . $recalculation['checked'] . '; corrected: ' . $recalculation['updated'] . '.',
        'recalculation' => $recalculation,
    ]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
