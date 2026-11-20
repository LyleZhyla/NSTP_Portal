<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../include/attendance-settings.php';
require_once __DIR__ . '/../include/student-cor.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST request required.']);
    exit();
}
if (($_SESSION['role'] ?? '') !== 'super_admin' || empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Super admin access is required.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$csrf = (string) ($input['csrf_token'] ?? '');
if (empty($_SESSION['semester_rollover_csrf']) || !hash_equals($_SESSION['semester_rollover_csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Nag-expire ang request. I-refresh ang page at subukan ulit.']);
    exit();
}
$semesterLabel = trim((string) ($input['semester_label'] ?? ''));
if ($semesterLabel === '' || mb_strlen($semesterLabel) > 150) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Maglagay ng semester name na hanggang 150 characters.']);
    exit();
}

try {
    ensureAttendanceTimeOutSchema($conn);
    ensureStudentCorSchema($conn);
    setSystemSetting($conn, 'student_cor_requirement_enabled', getSystemSetting($conn, 'student_cor_requirement_enabled', '0'));

    $archiveHasTimeOut = attendanceTableHasTimeOutColumn($conn, 'tbl_attendance_archive');
    $archiveHasStatus = attendanceArchiveHasStatusColumn($conn);
    $semesterKey = date('Ymd_His') . '_' . bin2hex(random_bytes(4));

    $conn->beginTransaction();
    $records = $conn->query('SELECT * FROM tbl_attendance ORDER BY tbl_attendance_id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $columns = ['tbl_attendance_id', 'tbl_student_id', 'time_in'];
    if ($archiveHasTimeOut) $columns[] = 'time_out';
    if ($archiveHasStatus) $columns[] = 'status';
    $columns[] = 'archived_date';
    $placeholders = array_fill(0, count($columns) - 1, '?');
    $placeholders[] = 'NOW()';
    $insert = $conn->prepare('INSERT INTO tbl_attendance_archive (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
    foreach ($records as $record) {
        $params = [$record['tbl_attendance_id'], $record['tbl_student_id'], $record['time_in']];
        if ($archiveHasTimeOut) $params[] = $record['time_out'] ?? null;
        if ($archiveHasStatus) $params[] = $record['status'] ?? null;
        $insert->execute($params);
    }
    $conn->exec('DELETE FROM tbl_attendance');

    $setting = $conn->prepare("
        INSERT INTO tbl_system_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    foreach ([
        'current_semester_key' => $semesterKey,
        'current_semester_label' => $semesterLabel,
        'student_cor_requirement_enabled' => '1',
        'semester_started_at' => date('Y-m-d H:i:s'),
    ] as $key => $value) {
        $setting->execute([$key, $value]);
    }
    $conn->commit();

    logSystemEvent(
        $conn,
        'new_semester_started',
        $semesterLabel . ': archived ' . count($records) . ' attendance record(s); student COR requirement enabled. CWTS/LTS sections and facilitator assignments were retained.'
    );
    echo json_encode([
        'success' => true,
        'message' => 'Bagong semester activated. Na-archive ang ' . count($records) . ' attendance record(s), at kailangan na ng bagong COR ang student accounts.',
        'archived_count' => count($records),
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('New semester rollover failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Hindi natuloy ang semester rollover. Walang binagong attendance data.']);
}

