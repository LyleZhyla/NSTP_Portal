<?php

require_once __DIR__ . '/user-permissions.php';

if (!function_exists('canonicalStudentComponentCounts')) {
function canonicalStudentComponentCounts(PDO $conn) {
    $componentCounts = ['CWTS' => 0, 'LTS' => 0, 'ROTC' => 0, 'Unassigned' => 0];
    $countedStudentKeys = [];

    $studentIdentityKey = static function ($studentNumber, $userId = null, $fallback = null) {
        $studentNumber = preg_replace('/\D/', '', (string) $studentNumber);
        if ($studentNumber !== '') {
            return 'student:' . $studentNumber;
        }
        if (!empty($userId)) {
            return 'user:' . (int) $userId;
        }
        return 'record:' . (string) $fallback;
    };

    $countStudentOnce = static function ($identityKey, $program) use (&$componentCounts, &$countedStudentKeys) {
        if (isset($countedStudentKeys[$identityKey])) {
            return;
        }
        $countedStudentKeys[$identityKey] = true;
        $program = normalizeProgram($program);
        $componentCounts[$program ?: 'Unassigned']++;
    };

    $stmt = $conn->query("
        SELECT u.user_id, u.username AS student_number, u.program,
               (
                   SELECT r.component
                   FROM tbl_public_student_registrations r
                   WHERE r.registrant_role = 'student'
                     AND COALESCE(r.status, 'submitted') NOT IN ('attendance_only', 'account_deleted')
                     AND (r.user_id = u.user_id OR r.student_number = u.username)
                   ORDER BY r.registration_id DESC
                   LIMIT 1
               ) AS registration_component
        FROM tbl_users u
        WHERE u.role = 'student'
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $program = normalizeProgram($row['program'] ?? null)
            ?: normalizeProgram($row['registration_component'] ?? null);
        $countStudentOnce(
            $studentIdentityKey(
                $row['student_number'] ?? '',
                $row['user_id'] ?? null,
                'account-' . ($row['user_id'] ?? '')
            ),
            $program
        );
    }

    $stmt = $conn->query("
        SELECT registration_id, user_id, student_number, component
        FROM tbl_public_student_registrations
        WHERE registrant_role = 'student'
          AND COALESCE(status, 'submitted') NOT IN ('attendance_only', 'account_deleted')
        ORDER BY registration_id DESC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $countStudentOnce(
            $studentIdentityKey(
                $row['student_number'] ?? '',
                $row['user_id'] ?? null,
                'registration-' . $row['registration_id']
            ),
            $row['component'] ?? null
        );
    }

    $stmt = $conn->query("
        SELECT s.tbl_student_id, s.user_id, s.student_number, s.course_section,
               u.program AS account_program
        FROM tbl_student s
        LEFT JOIN tbl_users u ON u.user_id = s.user_id AND u.role = 'student'
        ORDER BY s.tbl_student_id DESC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $program = normalizeProgram($row['account_program'] ?? null)
            ?: inferProgramFromText($row['course_section'] ?? '');
        $countStudentOnce(
            $studentIdentityKey(
                $row['student_number'] ?? '',
                $row['user_id'] ?? null,
                'masterlist-' . $row['tbl_student_id']
            ),
            $program
        );
    }

    return $componentCounts;
}
}

if (!function_exists('canonicalStudentCountForComponent')) {
function canonicalStudentCountForComponent(PDO $conn, $component) {
    $component = normalizeProgram($component);
    if (!$component) {
        return 0;
    }
    $counts = canonicalStudentComponentCounts($conn);
    return (int) ($counts[$component] ?? 0);
}
}
