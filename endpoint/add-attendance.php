<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

include('../conn/conn.php');
require_once '../include/attendance-settings.php';
require_once '../include/notifications.php';
require_once '../include/student-account-automation.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

if (!canAccessStaffTools($_SESSION['role'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Only staff accounts can scan attendance']);
    exit;
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessStaffTools($currentUser['role'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Only staff accounts can scan attendance']);
    exit;
}

ensureAttendancePerformanceIndexes($conn);
if (!ensureAttendanceTimeOutSchema($conn)) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare the Time Out feature. Please contact the administrator.']);
    exit;
}

$qr_code = isset($_POST['qr_code']) ? trim($_POST['qr_code']) : '';
$scanMode = strtolower(trim((string) ($_POST['scan_mode'] ?? 'time_in')));
if (!in_array($scanMode, ['time_in', 'time_out'], true)) {
    $scanMode = 'time_in';
}

if (empty($qr_code)) {
    echo json_encode(['success' => false, 'message' => 'No QR code provided']);
    exit;
}

try {
    // Log the QR code for debugging
    error_log("Recording attendance for QR code: " . $qr_code);
    
    // Find the student
    $stmt = $conn->prepare("
        SELECT * FROM tbl_student 
        WHERE generated_code = ? OR generated_code LIKE ? OR qr_code = ? OR qr_code LIKE ?
    ");
    $stmt->execute([$qr_code, '%' . $qr_code . '%', $qr_code, '%' . $qr_code . '%']);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        error_log("Student not found for QR code: " . $qr_code);
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }

    if (!canRecordStudentAttendance($conn, $currentUser, $student)) {
        $message = ($currentUser['role'] ?? '') === 'coordinator'
            ? 'This student is outside your NSTP program. Attendance was not recorded.'
            : 'This student is assigned to another facilitator. Attendance was not recorded.';
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }
    
    // Find today's attendance row. Time Out updates the same row instead of
    // creating a second attendance record.
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
    $checkStmt = $conn->prepare("
        SELECT tbl_attendance_id, time_in, time_out, status
        FROM tbl_attendance
        WHERE tbl_student_id = ? AND time_in >= ? AND time_in < ?
        ORDER BY time_in DESC
        LIMIT 1
    ");
    $checkStmt->execute([$student['tbl_student_id'], $today . ' 00:00:00', $tomorrow . ' 00:00:00']);
    $todayAttendance = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($scanMode === 'time_out') {
        if (!$todayAttendance) {
            echo json_encode([
                'success' => false,
                'message' => 'No Time In record found for this student today.'
            ]);
            exit;
        }
        if (!empty($todayAttendance['time_out'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Student already timed out today at ' . date('h:i A', strtotime($todayAttendance['time_out'])) . '.'
            ]);
            exit;
        }

        $timeOut = date('Y-m-d H:i:s');
        $updateStmt = $conn->prepare("
            UPDATE tbl_attendance
            SET time_out = ?
            WHERE tbl_attendance_id = ?
              AND time_out IS NULL
        ");
        $updateStmt->execute([$timeOut, $todayAttendance['tbl_attendance_id']]);
        if ($updateStmt->rowCount() !== 1) {
            echo json_encode(['success' => false, 'message' => 'Time Out was already recorded.']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => 'Time Out recorded successfully',
            'attendance_id' => (int) $todayAttendance['tbl_attendance_id'],
            'student_name' => $student['student_name'],
            'course_section' => $student['course_section'],
            'time' => date('h:i A', strtotime($timeOut)),
            'time_out' => $timeOut,
            'status' => 'Timed Out',
            'scan_mode' => 'time_out'
        ]);
        exit;
    }

    if ($todayAttendance) {
        echo json_encode([
            'success' => false,
            'message' => 'Student already timed in today at ' . date('h:i A', strtotime($todayAttendance['time_in'])) . '.'
        ]);
        exit;
    }
    
    // Record attendance
    $time_in = date('Y-m-d H:i:s');
    $status = getAttendanceStatusForStudent($conn, $student, $time_in);
    
    $insertStmt = $conn->prepare("
        INSERT INTO tbl_attendance (tbl_student_id, time_in, status) 
        VALUES (?, ?, ?)
    ");
    $insertStmt->execute([
        $student['tbl_student_id'],
        $time_in,
        $status
    ]);
    
    $attendance_id = $conn->lastInsertId();
    clearAbsentAttendanceNotificationForStudentDate($conn, $student['tbl_student_id'], $time_in);

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
    
    error_log("Attendance recorded for student ID: " . $student['tbl_student_id'] . " (Attendance ID: " . $attendance_id . ")");
    
    echo json_encode([
        'success' => true,
        'message' => 'Attendance recorded successfully',
        'attendance_id' => $attendance_id,
        'student_name' => $student['student_name'],
        'course_section' => $student['course_section'],
        'time' => date('h:i A', strtotime($time_in)),
        'status' => $status,
        'scan_mode' => 'time_in',
        'account_automation' => $accountAutomation
    ]);
    
} catch (Exception $e) {
    error_log("Attendance error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
