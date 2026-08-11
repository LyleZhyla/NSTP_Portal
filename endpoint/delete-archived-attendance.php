<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Super admin access is required.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$scope = (string) ($input['scope'] ?? '');
$date = trim((string) ($input['date'] ?? ''));

try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_attendance_archive (
            tbl_attendance_archive_id INT AUTO_INCREMENT PRIMARY KEY,
            tbl_attendance_id INT NOT NULL,
            tbl_student_id INT NOT NULL,
            time_in TIMESTAMP NOT NULL,
            status VARCHAR(50) NULL,
            archived_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_student_id (tbl_student_id),
            INDEX idx_time_in (time_in),
            INDEX idx_archived_date (archived_date)
        )
    ");

    if ($scope === 'day') {
        $dateValue = DateTime::createFromFormat('Y-m-d', $date);
        $dateErrors = DateTime::getLastErrors();
        $hasDateErrors = is_array($dateErrors) && ((int) $dateErrors['warning_count'] > 0 || (int) $dateErrors['error_count'] > 0);

        if (!$dateValue || $hasDateErrors) {
            echo json_encode(['success' => false, 'message' => 'Please provide a valid archive date.']);
            exit();
        }

        $stmt = $conn->prepare("DELETE FROM tbl_attendance_archive WHERE DATE(time_in) = ?");
        $stmt->execute([$date]);
        $deletedCount = $stmt->rowCount();
        logSystemEvent($conn, 'archived_attendance_deleted', "Deleted archived attendance for {$date}: {$deletedCount} record(s)");
    } elseif ($scope === 'all') {
        $stmt = $conn->prepare("DELETE FROM tbl_attendance_archive");
        $stmt->execute();
        $deletedCount = $stmt->rowCount();
        logSystemEvent($conn, 'archived_attendance_deleted', "Deleted all archived attendance: {$deletedCount} record(s)");
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid delete scope.']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => "Deleted {$deletedCount} archived record(s).",
        'deleted_count' => $deletedCount,
    ]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $error->getMessage()]);
}
?>
