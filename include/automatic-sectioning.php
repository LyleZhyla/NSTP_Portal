<?php

require_once __DIR__ . '/user-permissions.php';
require_once __DIR__ . '/section-folders.php';
require_once __DIR__ . '/college-courses.php';

function autoSectionMaxOptions() {
    return [20, 30, 35, 40, 45, 50, 60];
}

function autoSectionGroupingOptions() {
    return [
        'college_course' => 'Selected College Groups (courses may mix)',
        'course' => 'Course only',
        'college' => 'College only',
    ];
}

function autoSectionComponentOptions() {
    return ['CWTS', 'LTS', 'ROTC'];
}

function autoSectionEnabledSettingKey($component) {
    $component = normalizeProgram($component);
    return $component ? 'auto_section_enabled_' . strtolower($component) : '';
}

function isAutoSectionEnabled(PDO $conn, $component) {
    $component = normalizeProgram($component);
    if (!$component || !in_array($component, autoSectionComponentOptions(), true)) {
        return false;
    }

    $default = in_array($component, ['CWTS', 'LTS'], true) ? '1' : '0';
    return getSystemSetting($conn, autoSectionEnabledSettingKey($component), $default) === '1';
}

function saveAutoSectionEnabled(PDO $conn, $component, $enabled) {
    $component = normalizeProgram($component);
    if (!$component || !in_array($component, autoSectionComponentOptions(), true)) {
        throw new InvalidArgumentException('Invalid automatic section component.');
    }

    setSystemSetting($conn, autoSectionEnabledSettingKey($component), $enabled ? '1' : '0');
}

function getEnabledAutoSectionComponents(PDO $conn) {
    return array_values(array_filter(autoSectionComponentOptions(), static fn($component) => isAutoSectionEnabled($conn, $component)));
}

function autoSectionGroupingSettingKey($component = null) {
    $component = normalizeProgram($component);
    return $component ? 'auto_section_grouping_' . strtolower($component) : 'auto_section_grouping';
}

function getAutoSectionGroupingMode(PDO $conn, $component = null) {
    $componentKey = autoSectionGroupingSettingKey($component);
    $fallback = getSystemSetting($conn, 'auto_section_grouping', 'college_course');
    $mode = (string) getSystemSetting($conn, $componentKey, $fallback);
    return array_key_exists($mode, autoSectionGroupingOptions()) ? $mode : 'college_course';
}

function saveAutoSectionGroupingMode(PDO $conn, $mode, $component = null) {
    $mode = trim((string) $mode);
    if (!array_key_exists($mode, autoSectionGroupingOptions())) {
        throw new InvalidArgumentException('Invalid automatic section grouping mode.');
    }

    setSystemSetting($conn, autoSectionGroupingSettingKey($component), $mode);
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

function autoSectionCollegeOptions() {
    $codes = ['CAF', 'CAS', 'CBM', 'CED', 'CET', 'CVM'];
    $options = [];
    foreach (getCollegeCourseData() as $index => $collegeItem) {
        if (!isset($codes[$index])) {
            continue;
        }
        $options[$codes[$index]] = (string) ($collegeItem['college'] ?? $codes[$index]);
    }
    return $options;
}

function autoSectionCollegeGroupsSettingKey($component = null) {
    $component = normalizeProgram($component);
    return $component ? 'auto_section_college_groups_' . strtolower($component) : 'auto_section_college_groups';
}

function defaultAutoSectionCollegeGroups() {
    $groups = [];
    foreach (array_keys(autoSectionCollegeOptions()) as $collegeCode) {
        $groups[$collegeCode] = $collegeCode;
    }
    return $groups;
}

function normalizeAutoSectionCollegeGroups($groups) {
    $normalized = defaultAutoSectionCollegeGroups();
    foreach ($normalized as $collegeCode => $defaultGroup) {
        $requestedGroup = strtoupper(trim((string) ($groups[$collegeCode] ?? '')));
        $normalized[$collegeCode] = preg_match('/^(?:[A-F]|G[1-9][0-9]*)$/', $requestedGroup)
            ? $requestedGroup
            : $defaultGroup;
    }
    return $normalized;
}

function getAutoSectionCollegeGroups(PDO $conn, $component = null) {
    $componentKey = autoSectionCollegeGroupsSettingKey($component);
    $fallback = (string) getSystemSetting($conn, 'auto_section_college_groups', '');
    $encoded = (string) getSystemSetting($conn, $componentKey, $fallback);
    $decoded = json_decode($encoded, true);
    return normalizeAutoSectionCollegeGroups(is_array($decoded) ? $decoded : []);
}

function saveAutoSectionCollegeGroups(PDO $conn, $groups, $component = null) {
    $normalized = normalizeAutoSectionCollegeGroups(is_array($groups) ? $groups : []);
    setSystemSetting($conn, autoSectionCollegeGroupsSettingKey($component), json_encode($normalized));
    return $normalized;
}

function autoSectionCollegeCode($college) {
    $college = strtolower(autoSectionCleanPart($college));
    foreach (autoSectionCollegeOptions() as $collegeCode => $collegeName) {
        if ($college === strtolower(autoSectionCleanPart($collegeName)) || $college === strtolower($collegeCode)) {
            return $collegeCode;
        }
    }
    return '';
}

function autoSectionCollegePoolKey($college, array $collegeGroups = []) {
    $college = autoSectionCleanPart($college);
    $collegeCode = autoSectionCollegeCode($college);
    if ($collegeCode === '') {
        return 'college:' . strtolower($college !== '' ? $college : 'Unspecified');
    }

    $groups = normalizeAutoSectionCollegeGroups($collegeGroups);
    return 'college-group:' . strtolower($groups[$collegeCode] ?? $collegeCode);
}

function autoSectionCleanPart($value) {
    $value = trim(preg_replace('/\s+/', ' ', (string) $value));
    return strtoupper($value) === 'N/A' ? '' : $value;
}

function autoSectionGroupKey($mode, $college, $course, array $collegeGroups = []) {
    $college = autoSectionCleanPart($college);
    $course = autoSectionCleanPart($course);
    $unspecified = 'Unspecified';

    if ($mode === 'college') {
        return strtolower($college !== '' ? $college : $unspecified);
    }
    if ($mode === 'course') {
        return strtolower($course !== '' ? $course : $unspecified);
    }

    return autoSectionCollegePoolKey($college, $collegeGroups);
}

function autoSectionBalancedSizes($studentCount, $maxStudents) {
    $studentCount = max(0, (int) $studentCount);
    $maxStudents = max(1, (int) $maxStudents);
    if ($studentCount === 0) {
        return [];
    }

    $sizes = [];
    $remaining = $studentCount;
    while ($remaining > 0) {
        $size = min($remaining, $maxStudents);
        $sizes[] = $size;
        $remaining -= $size;
    }
    return $sizes;
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

function autoSectionUsesAutomaticFolders($component) {
    return in_array(autoSectionComponent($component), autoSectionComponentOptions(), true);
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

function autoSectionFindFolderForGroup(PDO $conn, $component, $college, $course, $createdBy = null) {
    $component = autoSectionComponent($component);
    $maxStudents = getAutoSectionMaxStudents($conn, $component);
    $groupingMode = getAutoSectionGroupingMode($conn, $component);
    $collegeGroups = getAutoSectionCollegeGroups($conn, $component);
    $targetGroupKey = autoSectionGroupKey($groupingMode, $college, $course, $collegeGroups);
    $stats = autoSectionFolderStats($conn, $component, $createdBy);

    $createdClause = $createdBy === null ? 's.created_by IS NULL' : 's.created_by = ?';
    $params = $createdBy === null ? [] : [(int) $createdBy];
    $params[] = autoSectionFolderPrefix($component) . ' %';
    $groupStmt = $conn->prepare("
        SELECT s.course_section, s.original_section, r.college, r.course
        FROM tbl_student s
        LEFT JOIN tbl_public_student_registrations r
          ON r.student_number = s.student_number
         AND r.registration_id = (
            SELECT MAX(r2.registration_id)
            FROM tbl_public_student_registrations r2
            WHERE r2.student_number = s.student_number
         )
        WHERE {$createdClause}
          AND s.course_section LIKE ?
    ");
    $groupStmt->execute($params);
    $folderGroupKeys = [];
    foreach ($groupStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowCourse = autoSectionCleanPart($row['course'] ?? '') ?: autoSectionCleanPart($row['original_section'] ?? '');
        $folderGroupKeys[$row['course_section']][autoSectionGroupKey($groupingMode, $row['college'] ?? '', $rowCourse, $collegeGroups)] = true;
    }

    foreach ($stats as $info) {
        if ($info['count'] >= $maxStudents || empty($folderGroupKeys[$info['folder']][$targetGroupKey])) {
            continue;
        }
        createSectionFolder($conn, $component, $info['folder']);
        return $info['folder'];
    }

    $nextNumber = empty($stats) ? 1 : (max(array_keys($stats)) + 1);
    $folderName = autoSectionFolderName($component, $nextNumber);
    createSectionFolder($conn, $component, $folderName);
    return $folderName;
}

function autoSectionFolderForStudent(PDO $conn, $component, $course, $yearSection, $fallbackOriginal = '', $college = '', $createdBy = null) {
    $component = autoSectionComponent($component, $fallbackOriginal);
    $groupLabel = autoSectionOriginalSection($course, $yearSection, $fallbackOriginal);
    if (!isAutoSectionEnabled($conn, $component)) {
        return $component;
    }

    return autoSectionFindFolderForGroup($conn, $component, $college, $course, $createdBy);
}

function removeUnusedAutoSectionFolders(PDO $conn, $component) {
    $component = autoSectionComponent($component);
    ensureSectionFoldersTable($conn);

    $stmt = $conn->prepare("
        SELECT
            f.folder_id,
            f.course_section,
            COUNT(DISTINCT s.tbl_student_id) AS student_count,
            COUNT(DISTINCT ads.admin_section_id) AS assignment_count
        FROM tbl_section_folders f
        LEFT JOIN tbl_student s ON s.course_section = f.course_section
        LEFT JOIN tbl_admin_sections ads ON ads.course_section = f.course_section
        WHERE f.program = ?
          AND f.course_section LIKE ?
        GROUP BY f.folder_id, f.course_section
        HAVING student_count = 0 AND assignment_count = 0
    ");
    $stmt->execute([$component, autoSectionFolderPrefix($component) . ' %']);

    $deleteStmt = $conn->prepare("DELETE FROM tbl_section_folders WHERE folder_id = ?");
    $removed = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $folder) {
        if (autoSectionFolderNumber($component, $folder['course_section']) === null) {
            continue;
        }
        $deleteStmt->execute([(int) $folder['folder_id']]);
        $removed += $deleteStmt->rowCount();
    }
    return $removed;
}

function rebuildAutoSectionFolders(PDO $conn, $component = null) {
    $components = $component ? [autoSectionComponent($component)] : getEnabledAutoSectionComponents($conn);
    $components = array_values(array_filter($components, static fn($item) => isAutoSectionEnabled($conn, $item)));
    $moved = 0;

    foreach ($components as $currentComponent) {
        $stmt = $conn->prepare("
            SELECT
                s.tbl_student_id,
                s.student_number,
                s.student_name,
                s.original_section,
                s.course_section,
                r.college AS reg_college,
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
        $students = array_values(array_filter($students, static function ($student) use ($currentComponent) {
            $resolvedComponent = normalizeProgram($student['reg_component'] ?? null)
                ?: normalizeProgram($student['user_program'] ?? null)
                ?: inferProgramFromText($student['course_section'] ?? '')
                ?: 'PUBLIC';

            return $resolvedComponent === $currentComponent;
        }));
        $groupingMode = getAutoSectionGroupingMode($conn, $currentComponent);
        $maxStudents = getAutoSectionMaxStudents($conn, $currentComponent);
        $collegeGroups = getAutoSectionCollegeGroups($conn, $currentComponent);
        $groupedStudents = [];
        foreach ($students as $student) {
            $groupCourse = autoSectionCleanPart($student['reg_course'] ?? '') ?: autoSectionCleanPart($student['original_section'] ?? '');
            $groupKey = autoSectionGroupKey($groupingMode, $student['reg_college'] ?? '', $groupCourse, $collegeGroups);
            $groupedStudents[$groupKey][] = $student;
        }
        ksort($groupedStudents, SORT_NATURAL | SORT_FLAG_CASE);

        $folderNumber = 1;
        foreach ($groupedStudents as $groupStudents) {
            $balancedSizes = autoSectionBalancedSizes(count($groupStudents), $maxStudents);
            $studentOffset = 0;
            foreach ($balancedSizes as $balancedSize) {
                $folder = autoSectionFolderName($currentComponent, $folderNumber++);
                createSectionFolder($conn, $currentComponent, $folder);
                $sectionStudents = array_slice($groupStudents, $studentOffset, $balancedSize);
                $studentOffset += $balancedSize;

                foreach ($sectionStudents as $student) {
                    $originalSection = autoSectionOriginalSection(
                        $student['reg_course'] ?? '',
                        $student['reg_year_section'] ?? '',
                        $student['original_section'] ?? ''
                    );

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
        }

        removeUnusedAutoSectionFolders($conn, $currentComponent);
    }

    return $moved;
}
