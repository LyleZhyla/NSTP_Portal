<?php

require_once __DIR__ . '/user-permissions.php';

if (!function_exists('studentManagementIdentityKey')) {
function studentManagementIdentityKey(array $student) {
    $studentNumber = preg_replace('/\D/', '', (string) ($student['student_number'] ?? ''));
    if ($studentNumber !== '') {
        return 'student:' . $studentNumber;
    }
    if (!empty($student['user_id'])) {
        return 'user:' . (int) $student['user_id'];
    }
    return 'record:' . (int) ($student['tbl_student_id'] ?? 0);
}
}

if (!function_exists('studentManagementUnassignedStudents')) {
function studentManagementUnassignedStudents(PDO $conn) {
    $stmt = $conn->query("
        SELECT
            s.*,
            student_user.program AS _account_program,
            creator.role AS _creator_role,
            creator.program AS _creator_program,
            (
                SELECT r.component
                FROM tbl_public_student_registrations r
                WHERE r.registrant_role = 'student'
                  AND COALESCE(r.status, 'submitted') NOT IN ('attendance_only', 'account_deleted')
                  AND (
                        (s.user_id IS NOT NULL AND r.user_id = s.user_id)
                        OR (NULLIF(TRIM(s.student_number), '') IS NOT NULL AND r.student_number = s.student_number)
                  )
                ORDER BY r.registration_id DESC
                LIMIT 1
            ) AS _registration_component
        FROM tbl_student s
        LEFT JOIN tbl_users student_user ON student_user.user_id = s.user_id AND student_user.role = 'student'
        LEFT JOIN tbl_users creator ON creator.user_id = s.created_by
        ORDER BY s.tbl_student_id DESC
    ");

    $students = [];
    $seenIdentities = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
        $identityKey = studentManagementIdentityKey($student);
        if (isset($seenIdentities[$identityKey])) {
            continue;
        }
        $seenIdentities[$identityKey] = true;

        $component = resolveStudentComponentFromSources(
            $student['_account_program'] ?? null,
            $student['_registration_component'] ?? null,
            $student['course_section'] ?? null,
            $student['_creator_role'] ?? null,
            $student['_creator_program'] ?? null
        );
        if (!$component) {
            $students[] = $student;
        }
    }

    usort($students, static function ($left, $right) {
        $nameComparison = strnatcasecmp(
            (string) ($left['student_name'] ?? ''),
            (string) ($right['student_name'] ?? '')
        );
        return $nameComparison !== 0
            ? $nameComparison
            : ((int) ($left['tbl_student_id'] ?? 0) <=> (int) ($right['tbl_student_id'] ?? 0));
    });

    return $students;
}
}

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

    // The dashboard's No Component total must represent the exact actionable
    // student rows shown in Student Management, using the same deduplication.
    $componentCounts['Unassigned'] = count(studentManagementUnassignedStudents($conn));

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
