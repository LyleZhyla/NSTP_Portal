<?php

require_once __DIR__ . '/user-permissions.php';
require_once __DIR__ . '/section-folders.php';

function autoSectionMaxOptions() {
    return [20, 30, 35, 40, 45, 50, 60];
}

function autoSectionMaxSettingKey($component = null) {
    $component = normalizeProgram($component);
    return $component ? 'auto_section_max_students_' . strtolower($component) : 'auto_section_max_students';
}

function getAutoSectionMaxStudents(PDO $conn, $component = null) {
    $componentKey = autoSectionMaxSettingKey($component);
    $fallback = getSystemSetting($conn, 'auto_section_max_students', '40');
    $value = (int) getSystemSetting($conn, $componentKey, $fallback);
    return $value > 0 ? $value : 40;
}

function saveAutoSectionMaxStudents(PDO $conn, $maxStudents, $component = null) {
    $maxStudents = (int) $maxStudents;
    if ($maxStudents < 1 || $maxStudents > 200) {
        throw new InvalidArgumentException('Maximum students must be between 1 and 200.');
    }

    setSystemSetting($conn, autoSectionMaxSettingKey($component), (string) $maxStudents);
}

function autoSectionCleanPart($value) {
    $value = trim(preg_replace('/\s+/', ' ', (string) $value));
    return strtoupper($value) === 'N/A' ? '' : $value;
}

function autoSectionOriginalSection($course, $yearSection, $fallback = '') {
    $course = autoSectionCleanPart($course);
    $yearSection = autoSectionCleanPart($yearSection);

    $parts = array_filter([$course, $yearSection], fn($part) => $part !== '');
    if (!empty($parts)) {
        return implode(' ', $parts);
    }

    $fallback = autoSectionCleanPart($fallback);
    return $fallback !== '' ? $fallback : 'Unspecified Section';
}

function autoSectionComponent($component, $fallbackText = '') {
    $program = normalizeProgram($component);
    if ($program) {
        return $program;
    }

    $program = inferProgramFromText($fallbackText);
    return $program ?: 'PUBLIC';
}

function autoSectionFolderPrefix($component) {
    $component = autoSectionComponent($component);
    return $component === 'PUBLIC' ? 'PUBLIC' : $component;
}

function autoSectionFolderName($component, $number) {
    return autoSectionFolderPrefix($component) . ' ' . autoSectionAlphaLabel(max(1, (int) $number));
}

function autoSectionAlphaLabel($number) {
    $number = max(1, (int) $number);
    $label = '';

    while ($number > 0) {
        $number--;
        $label = chr(65 + ($number % 26)) . $label;
        $number = intdiv($number, 26);
    }

    return $label;
}

function autoSectionAlphaNumber($label) {
    $label = strtoupper(trim((string) $label));
    if (!preg_match('/^[A-Z]+$/', $label)) {
        return null;
    }

    $number = 0;
    for ($index = 0; $index < strlen($label); $index++) {
        $number = ($number * 26) + (ord($label[$index]) - 64);
    }

    return $number;
}

function autoSectionFolderNumber($component, $folderName) {
    $prefix = preg_quote(autoSectionFolderPrefix($component), '/');
    if (preg_match('/^' . $prefix . '\s+([A-Z]+)$/i', trim((string) $folderName), $matches)) {
        return autoSectionAlphaNumber($matches[1]);
    }

    return null;
}

function autoSectionFolderStats(PDO $conn, $component, $createdBy = null) {
    $prefix = autoSectionFolderPrefix($component);
    $whereCreatedBy = $createdBy === null ? 's.created_by IS NULL' : 's.created_by = ?';
    $params = [$prefix . ' %'];
    if ($createdBy !== null) {
        array_unshift($params, (int) $createdBy);
    }

    $stmt = $conn->prepare("
        SELECT s.course_section, COUNT(*) AS student_count
        FROM tbl_student s
        WHERE $whereCreatedBy
          AND s.course_section LIKE ?
        GROUP BY s.course_section
        ORDER BY s.course_section ASC
    ");
    $stmt->execute($params);

    $stats = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $number = autoSectionFolderNumber($component, $row['course_section']);
        if ($number === null) {
            continue;
        }

        $stats[$number] = [
            'folder' => $row['course_section'],
            'count' => (int) $row['student_count'],
        ];
    }

    ksort($stats);
    return $stats;
}

function autoSectionFindFolderForGroup(PDO $conn, $component, $groupLabel, $createdBy = null) {
    $component = autoSectionComponent($component);
    $maxStudents = getAutoSectionMaxStudents($conn, $component);
    $stats = autoSectionFolderStats($conn, $component, $createdBy);
    $groupLabel = autoSectionCleanPart($groupLabel);

    if ($groupLabel !== '') {
        foreach ($stats as $info) {
            if ($info['count'] >= $maxStudents) {
                continue;
            }

            $createdClause = $createdBy === null ? 'created_by IS NULL' : 'created_by = ?';
            $params = $createdBy === null
                ? [$info['folder'], $groupLabel]
                : [(int) $createdBy, $info['folder'], $groupLabel];

            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM tbl_student
                WHERE $createdClause
                  AND course_section = ?
                  AND original_section = ?
            ");
            $stmt->execute($params);
            if ((int) $stmt->fetchColumn() > 0) {
                createSectionFolder($conn, $component, $info['folder']);
                return $info['folder'];
            }
        }
    }

    foreach ($stats as $info) {
        if ($info['count'] < $maxStudents) {
            createSectionFolder($conn, $component, $info['folder']);
            return $info['folder'];
        }
    }

    $nextNumber = empty($stats) ? 1 : (max(array_keys($stats)) + 1);
    $folderName = autoSectionFolderName($component, $nextNumber);
    createSectionFolder($conn, $component, $folderName);
    return $folderName;
}

function autoSectionFolderForStudent(PDO $conn, $component, $course, $yearSection, $fallbackOriginal = '', $createdBy = null) {
    $component = autoSectionComponent($component, $fallbackOriginal);
    $groupLabel = autoSectionOriginalSection($course, $yearSection, $fallbackOriginal);
    return autoSectionFindFolderForGroup($conn, $component, $groupLabel, $createdBy);
}

function rebuildAutoSectionFolders(PDO $conn, $component = null) {
    $components = $component ? [autoSectionComponent($component)] : ['CWTS', 'LTS', 'ROTC', 'PUBLIC'];
    $moved = 0;

    foreach ($components as $currentComponent) {
        $stmt = $conn->prepare("
            SELECT
                s.tbl_student_id,
                s.student_number,
                s.student_name,
                s.original_section,
                s.course_section,
                r.course AS reg_course,
                r.year_section AS reg_year_section,
                r.component AS reg_component,
                u.program AS user_program
            FROM tbl_student s
            LEFT JOIN tbl_users u ON s.user_id = u.user_id
            LEFT JOIN tbl_public_student_registrations r
              ON r.student_number = s.student_number
             AND r.registration_id = (
                SELECT MAX(r2.registration_id)
                FROM tbl_public_student_registrations r2
                WHERE r2.student_number = s.student_number
             )
            WHERE s.created_by IS NULL
              AND (
                COALESCE(r.component, '') = ?
                OR COALESCE(u.program, '') = ?
                OR s.course_section = ?
                OR s.course_section LIKE ?
              )
            ORDER BY
                COALESCE(NULLIF(r.course, ''), s.original_section, s.student_name) ASC,
                COALESCE(NULLIF(r.year_section, ''), s.original_section, '') ASC,
                s.student_name ASC,
                s.tbl_student_id ASC
        ");
        $stmt->execute([$currentComponent, $currentComponent, $currentComponent, autoSectionFolderPrefix($currentComponent) . ' %']);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $folderNumber = 1;
        $folderCount = 0;

        foreach ($students as $student) {
            $originalSection = autoSectionOriginalSection(
                $student['reg_course'] ?? '',
                $student['reg_year_section'] ?? '',
                $student['original_section'] ?? ''
            );
            $maxStudents = getAutoSectionMaxStudents($conn, $currentComponent);
            if ($folderCount >= $maxStudents) {
                $folderNumber++;
                $folderCount = 0;
            }

            $folder = autoSectionFolderName($currentComponent, $folderNumber);
            createSectionFolder($conn, $currentComponent, $folder);
            $folderCount++;

            if ($student['course_section'] !== $folder || ($student['original_section'] ?? '') !== $originalSection) {
                $updateStmt = $conn->prepare("
                    UPDATE tbl_student
                    SET course_section = ?, original_section = ?
                    WHERE tbl_student_id = ?
                ");
                $updateStmt->execute([$folder, $originalSection, $student['tbl_student_id']]);
                $moved++;
            }
        }
    }

    return $moved;
}
