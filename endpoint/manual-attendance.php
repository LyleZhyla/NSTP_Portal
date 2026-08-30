<?php
session_start();
require_once '../conn/conn.php';
require_once '../include/attendance-settings.php';
require_once '../include/notifications.php';
require_once '../include/student-account-automation.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessStaffTools($currentUser['role'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Only staff accounts can record attendance']);
    exit();
}

$student_number = trim((string) ($_POST['student_number'] ?? ''));
$time_in = $_POST['time_in'] ?? '';
$notes = $_POST['notes'] ?? '';

if ($student_number === '') {
    echo json_encode(['success' => false, 'message' => 'Student number is required']);
    exit();
}

if (!preg_match('/^\d{10}$/', $student_number)) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid 10-digit student number']);
    exit();
}

try {
    // Resolve the typed student number and retain the existing staff access rules.
    $stmt = $conn->prepare("
        SELECT s.*
        FROM tbl_student s
        WHERE s.student_number = ?
    ");
    $stmt->execute([$student_number]);
    $matchingStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $accessibleStudents = array_values(array_filter($matchingStudents, static function (array $student) use ($conn, $currentUser): bool {
        return canRecordStudentAttendance($conn, $currentUser, $student);
    }));

    if (count($accessibleStudents) === 0) {
        echo json_encode(['success' => false, 'message' => 'Student not found or not enrolled in your section']);
        exit();
    }

    if (count($accessibleStudents) > 1) {
        echo json_encode(['success' => false, 'message' => 'Multiple student records use this student number. Please contact the administrator.']);
        exit();
    }

    $student = $accessibleStudents[0];
    $student_id = (int) $student['tbl_student_id'];
    
    // Use current time if not provided
    if (empty($time_in)) {
        $time_in = date('Y-m-d H:i:s');
    } else {
        $time_in = date('Y-m-d H:i:s', strtotime($time_in));
    }
    
    // Check if already attended today
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_attendance 
                           WHERE tbl_student_id = ? AND DATE(time_in) = DATE(?)");
    $stmt->execute([$student_id, $time_in]);
    
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Already attended today']);
        exit();
    }
    
    $status = getAttendanceStatusForStudent($conn, $student, $time_in);
    
    // Insert record
    $columns = "tbl_student_id, time_in, status";
    $placeholders = "?, ?, ?";
    $params = [$student_id, $time_in, $status];
    
    // Add notes if provided
    if (!empty($notes)) {
        $columns .= ", notes";
        $placeholders .= ", ?";
        $params[] = $notes;
    }
    
    $sql = "INSERT INTO tbl_attendance ($columns) VALUES ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    clearAbsentAttendanceNotificationForStudentDate($conn, $student_id, $time_in);

    $accountAutomation = null;
    if (!empty($student['student_number'])) {
        try {
            $accountAutomation = autoCreateStudentAccountIfEligible($conn, $student['student_number']);
        } catch (Throwable $automationError) {
            error_log(
                'Student account automation failed for student number '
                . $student['student_number']
                . ': '
                . $automationError->getMessage()
            );
            $accountAutomation = [
                'created' => false,
                'reason' => 'automation_failed',
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Manual attendance recorded successfully',
        'account_automation' => $accountAutomation
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
