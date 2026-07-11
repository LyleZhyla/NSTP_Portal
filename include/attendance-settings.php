<?php

require_once __DIR__ . '/user-permissions.php';

function attendanceComponents() {
    return ['CWTS', 'LTS', 'ROTC_BASIC', 'ROTC_ADVANCED', 'PUBLIC'];
}

function attendanceComponentLabel($component) {
    $labels = [
        'ROTC_BASIC' => 'ROTC Basic Cadets',
        'ROTC_ADVANCED' => 'ROTC Advance Cadets',
        'PUBLIC' => 'Public Registration',
    ];

    return $labels[$component] ?? $component;
}

function normalizeAttendanceComponent($value) {
    $program = normalizeProgram($value);
    if ($program) {
        return $program;
    }

    $text = strtoupper(trim((string) $value));
    if ($text === '' || strpos($text, 'PUBLIC') !== false || strpos($text, 'PENDING') !== false) {
        return 'PUBLIC';
    }

    $inferred = inferProgramFromText($text);
    return $inferred ?: 'PUBLIC';
}

function defaultAttendanceCutoffs() {
    return [
        'CWTS' => ['morning' => '08:00', 'afternoon' => '13:00'],
        'LTS' => ['morning' => '08:00', 'afternoon' => '13:00'],
        'ROTC_BASIC' => ['morning' => '07:00', 'afternoon' => '13:00'],
        'ROTC_ADVANCED' => ['morning' => '07:00', 'afternoon' => '13:00'],
        'PUBLIC' => ['morning' => '08:00', 'afternoon' => '13:00'],
    ];
}

function getAttendanceCutoffs(PDO $conn) {
    $defaults = defaultAttendanceCutoffs();
    $cutoffs = [];

    foreach (attendanceComponents() as $component) {
        $legacyComponent = strpos($component, 'ROTC_') === 0 ? 'ROTC' : $component;
        $cutoffs[$component] = [
            'morning' => getSystemSetting(
                $conn,
                'attendance_' . strtolower($component) . '_morning_cutoff',
                getSystemSetting($conn, 'attendance_' . strtolower($legacyComponent) . '_morning_cutoff', $defaults[$component]['morning'])
            ),
            'afternoon' => getSystemSetting(
                $conn,
                'attendance_' . strtolower($component) . '_afternoon_cutoff',
                getSystemSetting($conn, 'attendance_' . strtolower($legacyComponent) . '_afternoon_cutoff', $defaults[$component]['afternoon'])
            ),
        ];
    }

    return $cutoffs;
}

function validAttendanceTime($timeValue) {
    return is_string($timeValue) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $timeValue);
}

function saveAttendanceCutoffs(PDO $conn, array $cutoffs) {
    foreach (attendanceComponents() as $component) {
        foreach (['morning', 'afternoon'] as $period) {
            $value = $cutoffs[$component][$period] ?? null;
            if (!validAttendanceTime($value)) {
                throw new InvalidArgumentException('Please enter valid late start times.');
            }

            setSystemSetting($conn, 'attendance_' . strtolower($component) . '_' . $period . '_cutoff', $value);
        }
    }
}

function attendanceCutoffComponentsForUser(array $user) {
    $role = $user['role'] ?? '';
    if ($role === 'super_admin') {
        return attendanceComponents();
    }

    if ($role !== 'coordinator') {
        return [];
    }

    $program = normalizeProgram($user['program'] ?? null);
    if ($program === 'ROTC') {
        return ['ROTC_BASIC', 'ROTC_ADVANCED'];
    }

    return $program ? [$program] : [];
}

function saveAttendanceCutoffsForComponents(PDO $conn, array $cutoffs, array $components) {
    $validComponents = attendanceComponents();
    foreach ($components as $component) {
        if (!in_array($component, $validComponents, true)) {
            continue;
        }

        foreach (['morning', 'afternoon'] as $period) {
            $value = $cutoffs[$component][$period] ?? null;
            if (!validAttendanceTime($value)) {
                throw new InvalidArgumentException('Please enter valid late start times.');
            }

            setSystemSetting($conn, 'attendance_' . strtolower($component) . '_' . $period . '_cutoff', $value);
        }
    }
}

function getAbsentNotificationGraceHours(PDO $conn) {
    $hours = (int) getSystemSetting($conn, 'absent_notification_grace_hours', '5');
    return max(1, min(24, $hours));
}

function saveAbsentNotificationGraceHours(PDO $conn, $hours) {
    $hours = filter_var($hours, FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => 1,
            'max_range' => 24,
        ],
    ]);

    if ($hours === false) {
        throw new InvalidArgumentException('Please enter a valid absent notification delay from 1 to 24 hours.');
    }

    setSystemSetting($conn, 'absent_notification_grace_hours', (string) $hours);
}

function attendanceComponentForStudent(PDO $conn, array $student) {
    $studentId = (int) ($student['tbl_student_id'] ?? 0);
    if ($studentId > 0) {
        $stmt = $conn->prepare("
            SELECT u.program AS account_component,
                   (
                       SELECT r.component
                       FROM tbl_public_student_registrations r
                       WHERE (s.user_id IS NOT NULL AND r.user_id = s.user_id)
                          OR (s.student_number IS NOT NULL AND s.student_number <> '' AND r.student_number = s.student_number)
                       ORDER BY r.registration_id DESC
                       LIMIT 1
                   ) AS registration_component,
                   EXISTS (
                       SELECT 1
                       FROM tbl_public_student_registrations r2
                       WHERE (s.user_id IS NOT NULL AND r2.user_id = s.user_id)
                          OR (s.student_number IS NOT NULL AND s.student_number <> '' AND r2.student_number = s.student_number)
                   ) AS has_public_registration
            FROM tbl_student s
            LEFT JOIN tbl_users u ON u.user_id = s.user_id
            WHERE s.tbl_student_id = ?
            LIMIT 1
        ");
        $stmt->execute([$studentId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $accountComponent = normalizeProgram($source['account_component'] ?? null);
        $registrationComponent = normalizeProgram($source['registration_component'] ?? null);

        if ($accountComponent) {
            if ($accountComponent === 'ROTC') {
                return getRotcAttendanceGroup($conn, $student) === 'ROTC_MS31_MS41'
                    ? 'ROTC_ADVANCED'
                    : 'ROTC_BASIC';
            }
            return $accountComponent;
        }
        if ($registrationComponent) {
            if ($registrationComponent === 'ROTC') {
                return getRotcAttendanceGroup($conn, $student) === 'ROTC_MS31_MS41'
                    ? 'ROTC_ADVANCED'
                    : 'ROTC_BASIC';
            }
            return $registrationComponent;
        }
        if (!empty($source['has_public_registration'])) {
            return 'PUBLIC';
        }
    }

    if (isRotcStudentRecord($conn, $student)) {
        return getRotcAttendanceGroup($conn, $student) === 'ROTC_MS31_MS41'
            ? 'ROTC_ADVANCED'
            : 'ROTC_BASIC';
    }

    return normalizeAttendanceComponent($student['course_section'] ?? '');
}

function getAttendanceStatus(PDO $conn, $courseSection, $timeIn = null) {
    $timeIn = $timeIn ?: date('Y-m-d H:i:s');
    $timestamp = strtotime($timeIn);
    if (!$timestamp) {
        $timestamp = time();
    }

    $component = normalizeAttendanceComponent($courseSection);
    if ($component === 'ROTC') {
        $component = normalizeRotcMsLevel($courseSection) && in_array(normalizeRotcMsLevel($courseSection), ['MS-31', 'MS-41'], true)
            ? 'ROTC_ADVANCED'
            : 'ROTC_BASIC';
    }
    $cutoffs = getAttendanceCutoffs($conn);
    $hour = (int) date('G', $timestamp);
    $period = $hour < 12 ? 'morning' : 'afternoon';
    $cutoff = date('Y-m-d', $timestamp) . ' ' . ($cutoffs[$component][$period] ?? '08:00') . ':00';
    $periodLabel = $period === 'morning' ? 'Morning' : 'Afternoon';

    return strtotime(date('Y-m-d H:i:s', $timestamp)) > strtotime($cutoff)
        ? 'Late - ' . $periodLabel
        : 'On Time - ' . $periodLabel;
}

function getAttendanceStatusForStudent(PDO $conn, array $student, $timeIn = null) {
    $timeIn = $timeIn ?: date('Y-m-d H:i:s');
    $timestamp = strtotime($timeIn);
    if (!$timestamp) {
        $timestamp = time();
    }

    $component = attendanceComponentForStudent($conn, $student);
    $cutoffs = getAttendanceCutoffs($conn);
    $hour = (int) date('G', $timestamp);
    $period = $hour < 12 ? 'morning' : 'afternoon';
    $cutoff = date('Y-m-d', $timestamp) . ' ' . ($cutoffs[$component][$period] ?? '08:00') . ':00';
    $periodLabel = $period === 'morning' ? 'Morning' : 'Afternoon';

    return strtotime(date('Y-m-d H:i:s', $timestamp)) > strtotime($cutoff)
        ? 'Late - ' . $periodLabel
        : 'On Time - ' . $periodLabel;
}

function recalculateAttendanceStatusesForDate(PDO $conn, $date) {
    $date = date('Y-m-d', strtotime((string) $date));
    $tomorrow = date('Y-m-d', strtotime($date . ' +1 day'));
    $stmt = $conn->prepare("
        SELECT a.tbl_attendance_id, a.time_in, a.status, s.*
        FROM tbl_attendance a
        INNER JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id
        WHERE a.time_in >= ? AND a.time_in < ?
        ORDER BY a.tbl_attendance_id
    ");
    $stmt->execute([$date . ' 00:00:00', $tomorrow . ' 00:00:00']);

    $checked = 0;
    $updated = 0;
    $updateStmt = $conn->prepare("UPDATE tbl_attendance SET status = ? WHERE tbl_attendance_id = ?");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $attendance) {
        $checked++;
        $correctStatus = getAttendanceStatusForStudent($conn, $attendance, $attendance['time_in']);
        if ((string) $attendance['status'] === $correctStatus) {
            continue;
        }
        $updateStmt->execute([$correctStatus, (int) $attendance['tbl_attendance_id']]);
        $updated++;
    }

    return ['date' => $date, 'checked' => $checked, 'updated' => $updated];
}

function attendanceArchiveTableExists(PDO $conn) {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'tbl_attendance_archive'");
        $exists = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function studentAttendanceTimeline(PDO $conn, array $student, $limit = null, $date = null) {
    $studentId = (int) ($student['tbl_student_id'] ?? 0);
    if ($studentId <= 0) {
        return [];
    }

    $activeWhere = 'tbl_student_id = ?';
    $archiveWhere = 'tbl_student_id = ?';
    $activeParams = [$studentId];
    $archiveParams = [$studentId];

    if ($date !== null) {
        $attendanceDate = date('Y-m-d', strtotime($date));
        $activeWhere .= ' AND DATE(time_in) = ?';
        $archiveWhere .= ' AND DATE(time_in) = ?';
        $activeParams[] = $attendanceDate;
        $archiveParams[] = $attendanceDate;
    }

    $queries = [
        "SELECT tbl_attendance_id, tbl_student_id, time_in, status, NULL AS archived_date, 0 AS is_archived
         FROM tbl_attendance
         WHERE {$activeWhere}",
    ];
    $params = $activeParams;

    if (attendanceArchiveTableExists($conn)) {
        $queries[] = "SELECT tbl_attendance_id, tbl_student_id, time_in, NULL AS status, archived_date, 1 AS is_archived
                      FROM tbl_attendance_archive
                      WHERE {$archiveWhere}";
        $params = array_merge($params, $archiveParams);
    }

    $sql = implode(' UNION ALL ', $queries) . ' ORDER BY time_in DESC';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, (int) $limit);
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($records as &$record) {
        if (trim((string) ($record['status'] ?? '')) === '') {
            $record['status'] = getAttendanceStatusForStudent($conn, $student, $record['time_in'] ?? null);
        }
    }
    unset($record);

    return $records;
}

function studentAttendanceHistoricalSummary(PDO $conn, array $student) {
    $summary = [
        'total' => 0,
        'present' => 0,
        'late' => 0,
    ];

    foreach (studentAttendanceTimeline($conn, $student) as $record) {
        $summary['total']++;
        $status = trim((string) ($record['status'] ?? ''));
        if (stripos($status, 'Late') === 0) {
            $summary['late']++;
        } elseif (stripos($status, 'Absent') !== 0) {
            $summary['present']++;
        }
    }

    return $summary;
}

function hasAttendanceForStudentScopeOnDate(PDO $conn, array $student, $date = null) {
    $courseSection = trim((string) ($student['course_section'] ?? ''));
    if ($courseSection === '') {
        return false;
    }

    $attendanceDate = $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');

    $rotcGroup = getRotcAttendanceGroup($conn, $student);
    if ($rotcGroup) {
        ensureRotcAttendanceSchema($conn);
        $rotcGroupCondition = $rotcGroup === 'ROTC_MS31_MS41'
            ? rotcAdvancedStudentSqlCondition('s')
            : rotcMs1StudentSqlCondition('s');

        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM tbl_attendance a
            INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            WHERE DATE(a.time_in) = ?
              AND {$rotcGroupCondition}
        ");
        $stmt->execute([$attendanceDate]);

        return (int) $stmt->fetchColumn() > 0;
    }

    $createdBy = isset($student['created_by']) && $student['created_by'] !== ''
        ? (int) $student['created_by']
        : null;

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_attendance a
        INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
        WHERE DATE(a.time_in) = ?
        AND s.course_section = ?
        AND s.created_by <=> ?
    ");
    $stmt->execute([$attendanceDate, $courseSection, $createdBy]);

    return (int) $stmt->fetchColumn() > 0;
}
