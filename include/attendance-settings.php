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

function attendanceComponentForStudent(PDO $conn, array $student) {
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

    return strtotime(date('Y-m-d H:i:s', $timestamp)) >= strtotime($cutoff)
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

    return strtotime(date('Y-m-d H:i:s', $timestamp)) >= strtotime($cutoff)
        ? 'Late - ' . $periodLabel
        : 'On Time - ' . $periodLabel;
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
