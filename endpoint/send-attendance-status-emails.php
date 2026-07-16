<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../include/notifications.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.');
    }

    $currentUser = getCurrentUserRecord($conn);
    if (!$currentUser || !in_array($currentUser['role'] ?? '', ['super_admin', 'coordinator'], true)) {
        http_response_code(403);
        throw new RuntimeException('Only coordinators and the super admin can send these emails.');
    }

    $type = strtolower(trim((string) ($_POST['type'] ?? '')));
    if (!in_array($type, ['late', 'absent'], true)) {
        throw new InvalidArgumentException('Invalid attendance email type.');
    }

    $requestedDate = trim((string) ($_POST['date'] ?? date('Y-m-d')));
    $dateObject = DateTime::createFromFormat('!Y-m-d', $requestedDate);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $requestedDate) {
        throw new InvalidArgumentException('Invalid attendance date.');
    }
    $attendanceDate = $dateObject->format('Y-m-d');
    $attendanceDateEnd = date('Y-m-d', strtotime($attendanceDate . ' +1 day'));

    $access = studentAttendanceAccessSqlForUser($currentUser, 's');
    $accessCondition = $access['condition'];
    $accessParams = $access['params'];
    $summary = [
        'type' => $type,
        'attendance_date' => $attendanceDate,
        'eligible' => 0,
        'sent' => 0,
        'no_email' => 0,
        'failed' => 0,
    ];

    if ($type === 'late') {
        ensureAttendanceEmailTrackingSchema($conn);
        $stmt = $conn->prepare("
            SELECT a.tbl_attendance_id, a.time_in, a.status, a.late_email_sent, s.*
            FROM tbl_attendance a
            INNER JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id
            LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
            WHERE a.time_in >= ?
              AND a.time_in < ?
              AND a.status LIKE 'Late%'
              AND a.late_email_sent = 0
              AND {$accessCondition}
            ORDER BY a.time_in ASC
            LIMIT 200
        ");
        $stmt->execute(array_merge([$attendanceDate . ' 00:00:00', $attendanceDateEnd . ' 00:00:00'], $accessParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary['eligible'] = count($rows);

        foreach ($rows as $row) {
            if (!notificationStudentEmail($conn, $row)) {
                $summary['no_email']++;
                continue;
            }

            if (sendLateAttendanceNotification($conn, $row, $row)) {
                $summary['sent']++;
            } else {
                $summary['failed']++;
            }
        }
    } else {
        // Create today's due absent records first, without sending mail. The
        // grace-period and class-session rules remain enforced here.
        $summary['processing'] = processAbsentAttendanceNotifications(
            $conn,
            $attendanceDate,
            null,
            $currentUser
        );

        $stmt = $conn->prepare("
            SELECT an.*, s.*
            FROM tbl_absent_notifications an
            INNER JOIN tbl_student s ON s.tbl_student_id = an.tbl_student_id
            LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
            WHERE an.attendance_date = ?
              AND an.email_sent = 0
              AND {$accessCondition}
            ORDER BY an.absent_notification_id ASC
            LIMIT 200
        ");
        $stmt->execute(array_merge([$attendanceDate], $accessParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary['eligible'] = count($rows);

        foreach ($rows as $row) {
            if (!notificationStudentEmail($conn, $row)) {
                $summary['no_email']++;
                continue;
            }

            if (sendPendingAbsentAttendanceEmail($conn, $row, $row)) {
                $summary['sent']++;
            } else {
                $summary['failed']++;
            }
        }
    }

    logSystemEvent(
        $conn,
        $type . '_attendance_emails_sent',
        ucfirst($type) . ' attendance email batch for ' . $attendanceDate
            . ': sent ' . $summary['sent']
            . ', no email ' . $summary['no_email']
            . ', failed ' . $summary['failed'] . '.'
    );

    echo json_encode([
        'success' => true,
        'message' => $summary['sent'] > 0
            ? "Sent {$summary['sent']} {$type} attendance email(s)."
            : "No pending {$type} attendance emails were sent.",
        'summary' => $summary,
    ]);
} catch (Throwable $error) {
    echo json_encode([
        'success' => false,
        'message' => $error->getMessage(),
    ]);
}
