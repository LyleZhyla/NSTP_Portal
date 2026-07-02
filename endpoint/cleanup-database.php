<?php
session_start();
require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

header('Content-Type: application/json');

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || $currentUser['role'] !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

if (trim($_POST['confirmation_text'] ?? '') !== 'DELETE') {
    echo json_encode(['success' => false, 'message' => 'Confirmation text is required']);
    exit();
}

$deleteCoordinators = isset($_POST['delete_coordinators']);
$deleteFacilitators = isset($_POST['delete_facilitators']);
$deleteStudents = isset($_POST['delete_students']);
$deleteAnnouncementNotifications = isset($_POST['delete_announcement_notifications']);
$deleteAbsentNotifications = isset($_POST['delete_absent_notifications']);

if (!$deleteCoordinators && !$deleteFacilitators && !$deleteStudents && !$deleteAnnouncementNotifications && !$deleteAbsentNotifications) {
    echo json_encode(['success' => false, 'message' => 'Choose at least one data group to delete']);
    exit();
}

function maintenanceTableExists(PDO $conn, $tableName) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $stmt->execute([$tableName]);
    return (int) $stmt->fetchColumn() > 0;
}

function maintenanceDelete(PDO $conn, $sql, array $params = []) {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

try {
    ensureSystemLogsTable($conn);
    $conn->beginTransaction();

    $deleted = [
        'coordinators' => 0,
        'facilitators' => 0,
        'student_records' => 0,
        'student_accounts' => 0,
        'registrations' => 0,
        'attendance' => 0,
        'archived_attendance' => 0,
        'section_assignments' => 0,
        'announcement_notifications' => 0,
        'absent_notifications' => 0,
        'absent_bell_notifications' => 0,
    ];

    if ($deleteStudents) {
        if (maintenanceTableExists($conn, 'tbl_notifications')) {
            $deleted['absent_bell_notifications'] += maintenanceDelete($conn, "
                DELETE FROM tbl_notifications
                WHERE related_table = 'tbl_absent_notifications'
                   OR type = 'absent_attendance'
            ");
        }

        if (maintenanceTableExists($conn, 'tbl_absent_notifications')) {
            $deleted['absent_notifications'] += maintenanceDelete($conn, "DELETE FROM tbl_absent_notifications");
        }

        if (maintenanceTableExists($conn, 'tbl_attendance')) {
            $deleted['attendance'] = maintenanceDelete($conn, "
                DELETE FROM tbl_attendance
                WHERE tbl_student_id IN (SELECT tbl_student_id FROM tbl_student)
            ");
        }

        if (maintenanceTableExists($conn, 'tbl_attendance_archive')) {
            $deleted['archived_attendance'] = maintenanceDelete($conn, "
                DELETE FROM tbl_attendance_archive
                WHERE tbl_student_id IN (SELECT tbl_student_id FROM tbl_student)
            ");
        }

        if (maintenanceTableExists($conn, 'tbl_public_student_registrations')) {
            $deleted['registrations'] = maintenanceDelete($conn, "DELETE FROM tbl_public_student_registrations");
        }

        if (maintenanceTableExists($conn, 'tbl_student')) {
            $deleted['student_records'] = maintenanceDelete($conn, "DELETE FROM tbl_student");
        }

        $deleted['student_accounts'] = maintenanceDelete($conn, "DELETE FROM tbl_users WHERE role = 'student'");
    }

    $staffRoles = [];
    if ($deleteCoordinators) {
        $staffRoles[] = 'coordinator';
    }
    if ($deleteFacilitators) {
        $staffRoles[] = 'facilitator';
    }

    if (!empty($staffRoles)) {
        $placeholders = implode(',', array_fill(0, count($staffRoles), '?'));

        if (maintenanceTableExists($conn, 'tbl_admin_sections')) {
            $deleted['section_assignments'] = maintenanceDelete($conn, "
                DELETE FROM tbl_admin_sections
                WHERE user_id IN (
                    SELECT user_id FROM tbl_users
                    WHERE role IN ($placeholders)
                )
            ", $staffRoles);
        }

        if ($deleteCoordinators) {
            $deleted['coordinators'] = maintenanceDelete($conn, "DELETE FROM tbl_users WHERE role = 'coordinator'");
        }

        if ($deleteFacilitators) {
            $deleted['facilitators'] = maintenanceDelete($conn, "DELETE FROM tbl_users WHERE role = 'facilitator'");
        }
    }

    if ($deleteAnnouncementNotifications && maintenanceTableExists($conn, 'tbl_notifications')) {
        $deleted['announcement_notifications'] = maintenanceDelete($conn, "
            DELETE FROM tbl_notifications
            WHERE related_table = 'tbl_announcements'
               OR type = 'announcement'
        ");
    }

    if ($deleteAbsentNotifications) {
        if (maintenanceTableExists($conn, 'tbl_notifications')) {
            $deleted['absent_bell_notifications'] += maintenanceDelete($conn, "
                DELETE FROM tbl_notifications
                WHERE related_table = 'tbl_absent_notifications'
                   OR type = 'absent_attendance'
            ");
        }

        if (maintenanceTableExists($conn, 'tbl_absent_notifications')) {
            $deleted['absent_notifications'] += maintenanceDelete($conn, "DELETE FROM tbl_absent_notifications");
        }
    }

    $details = [];
    foreach ($deleted as $label => $count) {
        if ($count > 0) {
            $details[] = str_replace('_', ' ', $label) . ': ' . $count;
        }
    }

    $logDetails = empty($details) ? 'No matching rows deleted' : implode(', ', $details);
    if ($conn->inTransaction()) {
        $conn->commit();
    }
    logSystemEvent($conn, 'database_cleanup', $logDetails);

    echo json_encode([
        'success' => true,
        'message' => empty($details)
            ? 'No matching data found. Super admin accounts were preserved.'
            : 'Selected data deleted. Super admin accounts were preserved. Deleted: ' . implode(', ', $details),
        'deleted' => $deleted,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    echo json_encode(['success' => false, 'message' => 'Cleanup failed: ' . $error->getMessage()]);
}
?>
