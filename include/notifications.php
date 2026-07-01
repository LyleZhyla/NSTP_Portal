<?php

require_once __DIR__ . '/user-permissions.php';
require_once __DIR__ . '/attendance-settings.php';
require_once __DIR__ . '/mailer.php';

function ensureNotificationTables(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_notifications (
            notification_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(40) NOT NULL,
            title VARCHAR(180) NOT NULL,
            message TEXT NOT NULL,
            related_table VARCHAR(80) NULL,
            related_id INT NULL,
            emailed TINYINT(1) NOT NULL DEFAULT 0,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            INDEX idx_user_read_created (user_id, is_read, created_at),
            INDEX idx_related (related_table, related_id),
            UNIQUE KEY unique_user_type_related (user_id, type, related_table, related_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_announcements (
            announcement_id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            body TEXT NOT NULL,
            scope_program VARCHAR(20) NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            archived_by INT NULL,
            INDEX idx_scope_created (scope_program, created_at),
            INDEX idx_created_by (created_by),
            INDEX idx_archived_at (archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    foreach ([
        'archived_at' => "ALTER TABLE tbl_announcements ADD COLUMN archived_at DATETIME NULL AFTER created_at",
        'archived_by' => "ALTER TABLE tbl_announcements ADD COLUMN archived_by INT NULL AFTER archived_at",
    ] as $column => $sql) {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tbl_announcements'
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $conn->exec($sql);
        }
    }
}

function ensureAbsentNotificationTable(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_absent_notifications (
            absent_notification_id INT AUTO_INCREMENT PRIMARY KEY,
            tbl_student_id INT NOT NULL,
            user_id INT NULL,
            attendance_date DATE NOT NULL,
            cutoff_time DATETIME NOT NULL,
            notify_after DATETIME NOT NULL,
            notification_id INT NULL,
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_student_attendance_date (tbl_student_id, attendance_date),
            INDEX idx_attendance_date (attendance_date),
            INDEX idx_user_id (user_id),
            INDEX idx_notification_id (notification_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function cleanNotificationText($value, $maxLength = 5000) {
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $maxLength)
        : substr($value, 0, $maxLength);
}

function notificationStudentEmail(PDO $conn, array $student) {
    $userId = (int) ($student['user_id'] ?? 0);
    if ($userId > 0) {
        $stmt = $conn->prepare("SELECT email, full_name FROM tbl_users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($user && !isPlaceholderEmail($user['email'] ?? '') && filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return [
                'email' => $user['email'],
                'name' => $user['full_name'] ?: ($student['student_name'] ?? 'Student'),
            ];
        }
    }

    $studentNumber = trim((string) ($student['student_number'] ?? ''));
    if ($studentNumber !== '') {
        $stmt = $conn->prepare("
            SELECT email, first_name, last_name
            FROM tbl_public_student_registrations
            WHERE student_number = ? AND email <> ''
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$studentNumber]);
        $registration = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($registration && filter_var($registration['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            return [
                'email' => $registration['email'],
                'name' => trim(($registration['first_name'] ?? '') . ' ' . ($registration['last_name'] ?? '')) ?: ($student['student_name'] ?? 'Student'),
            ];
        }
    }

    return null;
}

function createUserNotification(PDO $conn, $userId, $type, $title, $message, $relatedTable = null, $relatedId = null) {
    ensureNotificationTables($conn);

    $userId = (int) $userId;
    $type = cleanNotificationText($type, 40);
    $title = cleanNotificationText($title, 180);
    $message = trim((string) $message);
    $relatedId = $relatedId ? (int) $relatedId : null;

    $stmt = $conn->prepare("
        INSERT IGNORE INTO tbl_notifications (user_id, type, title, message, related_table, related_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $type,
        $title,
        $message,
        $relatedTable,
        $relatedId,
    ]);

    $notificationId = (int) $conn->lastInsertId();
    if ($notificationId > 0) {
        return $notificationId;
    }

    $lookupStmt = $conn->prepare("
        SELECT notification_id
        FROM tbl_notifications
        WHERE user_id = ?
          AND type = ?
          AND related_table <=> ?
          AND related_id <=> ?
        LIMIT 1
    ");
    $lookupStmt->execute([$userId, $type, $relatedTable, $relatedId]);

    return (int) $lookupStmt->fetchColumn();
}

function markNotificationEmailed(PDO $conn, $notificationId) {
    if ((int) $notificationId <= 0) {
        return false;
    }

    $stmt = $conn->prepare("UPDATE tbl_notifications SET emailed = 1 WHERE notification_id = ?");
    return $stmt->execute([(int) $notificationId]);
}

function sendLateAttendanceNotification(PDO $conn, array $student, array $attendance) {
    if (stripos((string) ($attendance['status'] ?? ''), 'Late') !== 0 || empty($student['user_id'])) {
        return false;
    }

    ensureNotificationTables($conn);

    $timeIn = $attendance['time_in'] ?? date('Y-m-d H:i:s');
    $dateLabel = date('F d, Y', strtotime($timeIn));
    $timeLabel = date('h:i A', strtotime($timeIn));
    $status = (string) ($attendance['status'] ?? 'Late');
    $title = 'Late Attendance Notice';
    $message = "You were marked late on {$dateLabel} at {$timeLabel}. Status: {$status}.";
    $notificationId = createUserNotification(
        $conn,
        (int) $student['user_id'],
        'late_attendance',
        $title,
        $message,
        'tbl_attendance',
        (int) ($attendance['tbl_attendance_id'] ?? 0)
    );

    if ($notificationId <= 0) {
        return false;
    }

    $recipient = notificationStudentEmail($conn, $student);
    if (!$recipient) {
        return false;
    }

    $safeName = htmlspecialchars($recipient['name'] ?: ($student['student_name'] ?? 'Student'), ENT_QUOTES, 'UTF-8');
    $safeDate = htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8');
    $safeTime = htmlspecialchars($timeLabel, ENT_QUOTES, 'UTF-8');
    $safeStatus = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
    $bodyHtml = <<<HTML
<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:#1f2937;">Hello {$safeName},</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#40534a;">Your attendance scan for {$safeDate} was recorded as late.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:22px 0;background:#fff8e6;border:1px solid #f4d685;border-left:6px solid #f59e0b;border-radius:10px;">
    <tr><td style="padding:16px 18px;color:#1f2937;"><strong style="color:#0f5132;">Date:</strong> {$safeDate}<br><strong style="color:#0f5132;">Time:</strong> {$safeTime}<br><strong style="color:#0f5132;">Status:</strong> {$safeStatus}</td></tr>
</table>
<p style="margin:0;font-size:15px;line-height:1.7;color:#40534a;">Please coordinate with your facilitator if you need clarification.</p>
HTML;

    $htmlBody = renderAppEmailTemplate($title, 'Your attendance scan today was marked late.', $bodyHtml);
    $textBody = "Hello {$recipient['name']},\n\nYour attendance scan for {$dateLabel} at {$timeLabel} was recorded as {$status}.\n\nTAU NSTP Portal";

    if (sendAppMail($recipient['email'], $recipient['name'], $title, $htmlBody, $textBody)) {
        markNotificationEmailed($conn, $notificationId);
        return true;
    }

    return false;
}

function absentNotificationGraceHours(PDO $conn) {
    return getAbsentNotificationGraceHours($conn);
}

function sendAbsentAttendanceNotification(PDO $conn, array $student, $attendanceDate, $cutoffDateTime, $notifyAfter, $graceHours = 5) {
    ensureNotificationTables($conn);
    ensureAbsentNotificationTable($conn);

    $studentId = (int) ($student['tbl_student_id'] ?? 0);
    if ($studentId <= 0) {
        return ['created' => false, 'reason' => 'invalid_student'];
    }

    $attendanceDate = date('Y-m-d', strtotime($attendanceDate));
    $cutoffDateTime = date('Y-m-d H:i:s', strtotime($cutoffDateTime));
    $notifyAfter = date('Y-m-d H:i:s', strtotime($notifyAfter));
    $userId = !empty($student['user_id']) ? (int) $student['user_id'] : null;

    $stmt = $conn->prepare("
        INSERT IGNORE INTO tbl_absent_notifications
            (tbl_student_id, user_id, attendance_date, cutoff_time, notify_after)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$studentId, $userId, $attendanceDate, $cutoffDateTime, $notifyAfter]);

    if ((int) $conn->lastInsertId() <= 0) {
        return ['created' => false, 'reason' => 'already_notified'];
    }

    $absentNotificationId = (int) $conn->lastInsertId();
    $dateLabel = date('F d, Y', strtotime($attendanceDate));
    $cutoffLabel = date('h:i A', strtotime($cutoffDateTime));
    $notifyAfterLabel = date('h:i A', strtotime($notifyAfter));
    $studentName = trim((string) ($student['student_name'] ?? 'Student'));
    $title = 'Absent Attendance Notice';
    $message = "You did not record attendance for {$dateLabel} by {$notifyAfterLabel}. You are considered absent for this day. Please coordinate with your facilitator or coordinator about your absence.";
    $notificationId = 0;

    if ($userId) {
        $notificationId = createUserNotification(
            $conn,
            $userId,
            'absent_attendance',
            $title,
            $message,
            'tbl_absent_notifications',
            $absentNotificationId
        );

        if ($notificationId > 0) {
            $updateStmt = $conn->prepare("
                UPDATE tbl_absent_notifications
                SET notification_id = ?
                WHERE absent_notification_id = ?
            ");
            $updateStmt->execute([$notificationId, $absentNotificationId]);
        }
    }

    $emailSent = false;
    $recipient = notificationStudentEmail($conn, $student);
    if ($recipient) {
        $safeName = htmlspecialchars($recipient['name'] ?: $studentName, ENT_QUOTES, 'UTF-8');
        $safeDate = htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8');
        $safeCutoff = htmlspecialchars($cutoffLabel, ENT_QUOTES, 'UTF-8');
        $safeNotifyAfter = htmlspecialchars($notifyAfterLabel, ENT_QUOTES, 'UTF-8');
        $safeGraceHours = htmlspecialchars((string) $graceHours, ENT_QUOTES, 'UTF-8');
        $bodyHtml = <<<HTML
<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:#1f2937;">Hello {$safeName},</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#40534a;">No attendance scan was recorded for {$safeDate} within the allowed time.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:22px 0;background:#fff1f2;border:1px solid #fecdd3;border-left:6px solid #b23a48;border-radius:10px;">
    <tr><td style="padding:16px 18px;color:#1f2937;"><strong style="color:#0f5132;">Date:</strong> {$safeDate}<br><strong style="color:#0f5132;">Late start time:</strong> {$safeCutoff}<br><strong style="color:#0f5132;">Absent notification time:</strong> {$safeNotifyAfter}<br><strong style="color:#0f5132;">Grace period:</strong> {$safeGraceHours} hour(s)</td></tr>
</table>
<p style="margin:0;font-size:15px;line-height:1.7;color:#40534a;">You are considered absent for this day. Please coordinate with your facilitator or coordinator about your absence.</p>
HTML;

        $htmlBody = renderAppEmailTemplate($title, 'You are considered absent for today.', $bodyHtml);
        $textBody = "Hello {$recipient['name']},\n\nNo attendance scan was recorded for {$dateLabel} by {$notifyAfterLabel}. You are considered absent for this day. Please coordinate with your facilitator or coordinator about your absence.\n\nTAU NSTP Portal";

        if (sendAppMail($recipient['email'], $recipient['name'], $title, $htmlBody, $textBody)) {
            $emailSent = true;
            if ($notificationId > 0) {
                markNotificationEmailed($conn, $notificationId);
            }

            $emailStmt = $conn->prepare("
                UPDATE tbl_absent_notifications
                SET email_sent = 1
                WHERE absent_notification_id = ?
            ");
            $emailStmt->execute([$absentNotificationId]);
        }
    }

    return [
        'created' => true,
        'absent_notification_id' => $absentNotificationId,
        'notification_id' => $notificationId,
        'email_sent' => $emailSent,
    ];
}

function processAbsentAttendanceNotifications(PDO $conn, $attendanceDate = null, $now = null, ?array $actor = null) {
    ensureAbsentNotificationTable($conn);
    ensureAttendancePerformanceIndexes($conn);

    $nowTimestamp = $now ? strtotime($now) : time();
    if (!$nowTimestamp) {
        $nowTimestamp = time();
    }

    $attendanceDate = $attendanceDate ? date('Y-m-d', strtotime($attendanceDate)) : date('Y-m-d', $nowTimestamp);
    $attendanceDateStart = $attendanceDate . ' 00:00:00';
    $attendanceDateEnd = date('Y-m-d 00:00:00', strtotime($attendanceDate . ' +1 day'));
    $graceHours = absentNotificationGraceHours($conn);
    $cutoffs = getAttendanceCutoffs($conn);
    $accessJoin = '';
    $accessWhere = '';
    $accessParams = [];

    if ($actor !== null) {
        $access = studentAttendanceAccessSqlForUser($actor, 's');
        $accessJoin = 'LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section';
        $accessWhere = 'AND ' . $access['condition'];
        $accessParams = $access['params'];
    }

    $stmt = $conn->prepare("
        SELECT DISTINCT s.*
        FROM tbl_student s
        {$accessJoin}
        WHERE NOT EXISTS (
            SELECT 1
            FROM tbl_attendance a
            WHERE a.tbl_student_id = s.tbl_student_id
              AND a.time_in >= ?
              AND a.time_in < ?
        )
        {$accessWhere}
        ORDER BY s.tbl_student_id ASC
    ");
    $stmt->execute(array_merge([$attendanceDateStart, $attendanceDateEnd], $accessParams));
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = [
        'attendance_date' => $attendanceDate,
        'grace_hours' => $graceHours,
        'checked' => 0,
        'not_due' => 0,
        'created' => 0,
        'already_notified' => 0,
        'email_sent' => 0,
        'skipped' => 0,
    ];

    foreach ($students as $student) {
        $summary['checked']++;
        $component = attendanceComponentForStudent($conn, $student);
        $morningCutoff = $cutoffs[$component]['morning'] ?? '08:00';
        $cutoffDateTime = $attendanceDate . ' ' . $morningCutoff . ':00';
        $notifyAfterTimestamp = strtotime($cutoffDateTime . ' +' . $graceHours . ' hours');

        if (!$notifyAfterTimestamp || $nowTimestamp < $notifyAfterTimestamp) {
            $summary['not_due']++;
            continue;
        }

        $result = sendAbsentAttendanceNotification(
            $conn,
            $student,
            $attendanceDate,
            $cutoffDateTime,
            date('Y-m-d H:i:s', $notifyAfterTimestamp),
            $graceHours
        );

        if (!empty($result['created'])) {
            $summary['created']++;
            if (!empty($result['email_sent'])) {
                $summary['email_sent']++;
            }
            continue;
        }

        if (($result['reason'] ?? '') === 'already_notified') {
            $summary['already_notified']++;
        } else {
            $summary['skipped']++;
        }
    }

    return $summary;
}

function normalizeAnnouncementRecipientScope($recipientScope) {
    $recipientScope = strtolower(trim((string) $recipientScope));
    return in_array($recipientScope, ['students', 'staff', 'all'], true) ? $recipientScope : 'all';
}

function announcementRecipients(PDO $conn, $scopeProgram = null, $createdBy = null, $excludeUserId = null, $recipientScope = 'all') {
    $recipients = [];
    $recipientScope = normalizeAnnouncementRecipientScope($recipientScope);

    $scopeProgram = normalizeProgram($scopeProgram);

    if (in_array($recipientScope, ['students', 'all'], true)) {
        $params = [];
        $where = ["u.role = 'student'", "s.user_id IS NOT NULL"];
        $joins = "INNER JOIN tbl_student s ON s.user_id = u.user_id";

        if ($scopeProgram) {
            $where[] = "(u.program = ? OR s.course_section = ? OR s.course_section LIKE ?)";
            $params[] = $scopeProgram;
            $params[] = $scopeProgram;
            $params[] = '%' . $scopeProgram . '%';
        }

        if ($createdBy) {
            $where[] = "s.created_by = ?";
            $params[] = (int) $createdBy;
        }

        if ($excludeUserId) {
            $where[] = "u.user_id <> ?";
            $params[] = (int) $excludeUserId;
        }

        $stmt = $conn->prepare("
            SELECT DISTINCT u.user_id, u.full_name, u.email, s.tbl_student_id, s.student_name, s.student_number
            FROM tbl_users u
            {$joins}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY u.full_name
        ");
        $stmt->execute($params);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $recipient) {
            $recipients[(int) $recipient['user_id']] = $recipient;
        }
    }

    if (in_array($recipientScope, ['staff', 'all'], true)) {
        $staffParams = [];
        $staffWhere = ["u.role IN ('coordinator', 'facilitator')"];

        if ($scopeProgram) {
            $staffWhere[] = "u.program = ?";
            $staffParams[] = $scopeProgram;
        }

        if ($excludeUserId) {
            $staffWhere[] = "u.user_id <> ?";
            $staffParams[] = (int) $excludeUserId;
        }

        $staffStmt = $conn->prepare("
            SELECT DISTINCT u.user_id, u.full_name, u.email, NULL AS tbl_student_id, NULL AS student_name, NULL AS student_number
            FROM tbl_users u
            WHERE " . implode(' AND ', $staffWhere) . "
            ORDER BY u.full_name
        ");
        $staffStmt->execute($staffParams);

        foreach ($staffStmt->fetchAll(PDO::FETCH_ASSOC) as $recipient) {
            $recipients[(int) $recipient['user_id']] = $recipient;
        }
    }

    return array_values($recipients);
}

function createAnnouncement(PDO $conn, array $actor, $title, $body, $scopeProgram = null, $recipientScope = 'all') {
    ensureNotificationTables($conn);

    $actorRole = $actor['role'] ?? '';
    $scopeProgram = normalizeProgram($scopeProgram);
    $recipientScope = normalizeAnnouncementRecipientScope($recipientScope);
    $createdByRestriction = null;

    if ($actorRole === 'coordinator') {
        $scopeProgram = normalizeProgram($actor['program'] ?? null);
    } elseif ($actorRole === 'facilitator') {
        $scopeProgram = normalizeProgram($actor['program'] ?? null);
        $createdByRestriction = (int) ($actor['user_id'] ?? 0);
    } elseif ($actorRole !== 'super_admin') {
        throw new RuntimeException('Unauthorized announcement creator.');
    }

    $title = cleanNotificationText($title, 180);
    $body = trim((string) $body);
    if ($title === '' || $body === '') {
        throw new InvalidArgumentException('Title and message are required.');
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_announcements (title, body, scope_program, created_by)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$title, $body, $scopeProgram, (int) $actor['user_id']]);
    $announcementId = (int) $conn->lastInsertId();

    $recipients = announcementRecipients($conn, $scopeProgram, $createdByRestriction, (int) ($actor['user_id'] ?? 0), $recipientScope);
    $emailSentCount = 0;
    $emailSkippedCount = 0;
    $emailInvalidCount = 0;
    $emailFailedCount = 0;
    foreach ($recipients as $recipient) {
        $notificationId = createUserNotification(
            $conn,
            (int) $recipient['user_id'],
            'announcement',
            $title,
            $body,
            'tbl_announcements',
            $announcementId
        );

        if ($notificationId <= 0 || isPlaceholderEmail($recipient['email'] ?? '') || !filter_var($recipient['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $emailSkippedCount++;
            $emailInvalidCount++;
            continue;
        }

        $safeName = htmlspecialchars($recipient['full_name'] ?: $recipient['student_name'] ?: 'NSTP User', ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeBody = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        $bodyHtml = <<<HTML
<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:#1f2937;">Hello {$safeName},</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#40534a;">A new NSTP announcement has been posted:</p>
<div style="margin:22px 0;padding:18px 20px;background:#f3faf6;border:1px solid #cce8d6;border-left:6px solid #198754;border-radius:10px;">
    <h2 style="margin:0 0 10px;font-size:18px;color:#0f5132;">{$safeTitle}</h2>
    <div style="font-size:15px;line-height:1.7;color:#40534a;">{$safeBody}</div>
</div>
HTML;
        $htmlBody = renderAppEmailTemplate('NSTP Announcement', 'A new announcement was posted in the TAU NSTP Portal.', $bodyHtml);
        $recipientName = $recipient['full_name'] ?: $recipient['student_name'] ?: 'NSTP User';
        $textBody = "Hello {$recipientName},\n\n{$title}\n\n{$body}\n\nTAU NSTP Portal";

        if (sendAppMail($recipient['email'], $recipientName, 'NSTP Announcement: ' . $title, $htmlBody, $textBody)) {
            markNotificationEmailed($conn, $notificationId);
            $emailSentCount++;
        } else {
            $emailSkippedCount++;
            $emailFailedCount++;
        }
    }

    return [
        'announcement_id' => $announcementId,
        'recipient_count' => count($recipients),
        'email_sent_count' => $emailSentCount,
        'email_skipped_count' => $emailSkippedCount,
        'email_invalid_count' => $emailInvalidCount,
        'email_failed_count' => $emailFailedCount,
    ];
}

function getUnreadNotifications(PDO $conn, $userId, $limit = 10) {
    ensureNotificationTables($conn);

    $stmt = $conn->prepare("
        SELECT *
        FROM tbl_notifications
        WHERE user_id = ? AND is_read = 0
        ORDER BY created_at DESC
        LIMIT " . max(1, (int) $limit)
    );
    $stmt->execute([(int) $userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getUserNotifications(PDO $conn, $userId, $limit = 12) {
    ensureNotificationTables($conn);

    $stmt = $conn->prepare("
        SELECT *
        FROM tbl_notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT " . max(1, (int) $limit)
    );
    $stmt->execute([(int) $userId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function countUnreadNotifications(PDO $conn, $userId) {
    ensureNotificationTables($conn);

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_notifications
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([(int) $userId]);

    return (int) $stmt->fetchColumn();
}

function getRecentAnnouncements(PDO $conn, array $actor, $limit = 20) {
    ensureNotificationTables($conn);

    $where = ["a.archived_at IS NULL"];
    $params = [];
    if (($actor['role'] ?? '') === 'coordinator') {
        $where[] = "a.scope_program = ?";
        $params[] = normalizeProgram($actor['program'] ?? null);
    } elseif (($actor['role'] ?? '') === 'facilitator') {
        $where[] = "a.created_by = ?";
        $params[] = (int) ($actor['user_id'] ?? 0);
    }

    $sql = "
        SELECT a.*, u.full_name AS creator_name, u.role AS creator_role
        FROM tbl_announcements a
        LEFT JOIN tbl_users u ON u.user_id = a.created_by
    ";
    $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY a.created_at DESC LIMIT " . max(1, (int) $limit);

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function announcementManageWhereClause(array $actor, array $announcementIds, array &$params) {
    $ids = array_values(array_unique(array_map('intval', $announcementIds)));
    $ids = array_values(array_filter($ids, fn($id) => $id > 0));
    if (empty($ids)) {
        throw new InvalidArgumentException('Please select at least one announcement.');
    }

    $params = $ids;
    $where = 'announcement_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';

    if (($actor['role'] ?? '') !== 'super_admin') {
        $where .= ' AND created_by = ?';
        $params[] = (int) ($actor['user_id'] ?? 0);
    }

    return $where;
}

function archiveAnnouncements(PDO $conn, array $actor, array $announcementIds) {
    ensureNotificationTables($conn);

    $whereParams = [];
    $where = announcementManageWhereClause($actor, $announcementIds, $whereParams);
    $params = array_merge([(int) ($actor['user_id'] ?? 0)], $whereParams);

    $stmt = $conn->prepare("
        UPDATE tbl_announcements
        SET archived_at = NOW(), archived_by = ?
        WHERE {$where}
          AND archived_at IS NULL
    ");
    $stmt->execute($params);

    return $stmt->rowCount();
}

function deleteAnnouncements(PDO $conn, array $actor, array $announcementIds) {
    ensureNotificationTables($conn);

    $params = [];
    $where = announcementManageWhereClause($actor, $announcementIds, $params);

    $selectStmt = $conn->prepare("SELECT announcement_id FROM tbl_announcements WHERE {$where}");
    $selectStmt->execute($params);
    $deletableIds = array_map('intval', $selectStmt->fetchAll(PDO::FETCH_COLUMN));
    if (empty($deletableIds)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($deletableIds), '?'));
    $conn->beginTransaction();
    try {
        $notificationStmt = $conn->prepare("
            DELETE FROM tbl_notifications
            WHERE related_table = 'tbl_announcements'
              AND related_id IN ({$placeholders})
        ");
        $notificationStmt->execute($deletableIds);

        $deleteStmt = $conn->prepare("DELETE FROM tbl_announcements WHERE announcement_id IN ({$placeholders})");
        $deleteStmt->execute($deletableIds);
        $deleted = $deleteStmt->rowCount();

        $conn->commit();
        return $deleted;
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }
}
