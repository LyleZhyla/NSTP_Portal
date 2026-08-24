<?php
session_start();

// Set JSON header
header('Content-Type: application/json');

// Error reporting - don't display errors in output
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

try {
    $currentUser = getCurrentUserRecord($conn);
    if (!$currentUser || !canAccessStaffTools($currentUser['role'] ?? '')) {
        throw new RuntimeException('Unauthorized access');
    }
    
    // Create archive table if it doesn't exist
    $createTableSQL = "CREATE TABLE IF NOT EXISTS tbl_attendance_archive (
        tbl_attendance_archive_id INT AUTO_INCREMENT PRIMARY KEY,
        tbl_attendance_id INT NOT NULL,
        tbl_student_id INT NOT NULL,
        time_in TIMESTAMP NOT NULL,
        status VARCHAR(50) NULL,
        archived_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_student_id (tbl_student_id),
        INDEX idx_time_in (time_in),
        INDEX idx_archived_date (archived_date)
    )";
    
    $conn->exec($createTableSQL);
    
    $summary = [];
    $attendanceAccess = studentComponentAttendanceAccessSqlForUser($currentUser, 's');
    $accessCondition = $attendanceAccess['condition'];
    $accessParams = $attendanceAccess['params'];

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_attendance_archive aa
        INNER JOIN tbl_student s ON s.tbl_student_id = aa.tbl_student_id
        WHERE {$accessCondition}
    ");
    $stmt->execute($accessParams);
    $summary['total_archived'] = $stmt->fetchColumn();

    $stmt = $conn->prepare("
        SELECT MIN(DATE(aa.time_in)) AS earliest_date,
               MAX(DATE(aa.time_in)) AS latest_date,
               COUNT(DISTINCT DATE(aa.time_in)) AS unique_days
        FROM tbl_attendance_archive aa
        INNER JOIN tbl_student s ON s.tbl_student_id = aa.tbl_student_id
        WHERE {$accessCondition}
    ");
    $stmt->execute($accessParams);
    $rangeData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $summary['earliest_date'] = $rangeData['earliest_date'] ?? null;
    $summary['latest_date'] = $rangeData['latest_date'] ?? null;
    $summary['unique_days'] = $rangeData['unique_days'] ?? 0;

    $stmt = $conn->prepare("
        SELECT DATE(aa.time_in) AS attendance_date,
               COUNT(*) AS record_count
        FROM tbl_attendance_archive aa
        INNER JOIN tbl_student s ON s.tbl_student_id = aa.tbl_student_id
        WHERE {$accessCondition}
        GROUP BY DATE(aa.time_in)
        ORDER BY attendance_date DESC
        LIMIT 30
    ");
    $stmt->execute($accessParams);
    $summary['daily_breakdown'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_attendance a
        INNER JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id
        WHERE {$accessCondition}
    ");
    $stmt->execute($accessParams);
    $summary['active_records'] = $stmt->fetchColumn();
    
    // Format dates for display
    $summary['latest_date'] = $summary['latest_date'] ?? '-';
    $summary['earliest_date'] = $summary['earliest_date'] ?? '-';
    $summary['total_archived'] = (int)($summary['total_archived'] ?? 0);
    $summary['active_records'] = (int)($summary['active_records'] ?? 0);
    $summary['unique_days'] = (int)($summary['unique_days'] ?? 0);
    
    echo json_encode([
        'success' => true,
        'summary' => $summary
    ]);
    
} catch (Exception $e) {
    error_log("Error in get-archive-summary.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error getting archive summary: ' . $e->getMessage()
    ]);
}
?>
