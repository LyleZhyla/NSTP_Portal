<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include('./include/theme-loader.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: landing_page.php");
    exit();
}

include('./conn/conn.php');
require_once './include/user-permissions.php';
include('./include/logo-functions.php');

date_default_timezone_set('Asia/Manila');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = $_SESSION['role'] ?? 'facilitator';
$userName = $_SESSION['full_name'] ?? 'User';

if (!in_array($userRole, ['coordinator', 'facilitator'], true)) {
    header("Location: profile.php");
    exit();
}

require_once __DIR__ . '/include/grade-schema.php';

function slugGradeGroup($value) {
    $slug = strtolower(trim((string) $value));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug !== '' ? substr($slug, 0, 60) : 'additional';
}

function selectedGradeGroupLabel(array $source, $default = 'Additional Requirements') {
    $selected = trim((string) ($source['group_label_select'] ?? ''));
    $new = trim((string) ($source['group_label_new'] ?? ''));
    $fallback = trim((string) ($source['group_label'] ?? $default));

    if ($selected === '__new' && $new !== '') {
        return $new;
    }

    if ($selected !== '' && $selected !== '__new') {
        return $selected;
    }

    return $fallback !== '' ? $fallback : $default;
}

function gradeTableExists(PDO $conn, $tableName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$tableName]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $error) {
        return false;
    }
}

function gradeCleanNamePart($value) {
    $value = trim(preg_replace('/\s+/', ' ', (string) ($value ?? '')));
    return strtoupper($value) === 'N/A' ? '' : $value;
}

function gradeMiddleInitial($middleName) {
    $middleName = gradeCleanNamePart($middleName);
    return $middleName !== '' ? strtoupper(substr($middleName, 0, 1)) . '.' : '';
}

function gradeFormatNameParts($lastName, $firstName, $middleName = '', $extensionName = '') {
    $lastName = gradeCleanNamePart($lastName);
    $firstName = gradeCleanNamePart($firstName);
    $middleInitial = gradeMiddleInitial($middleName);
    $extensionName = gradeCleanNamePart($extensionName);

    $firstNameParts = array_values(array_filter([$firstName, $middleInitial, $extensionName], fn($part) => $part !== ''));
    $name = $lastName !== ''
        ? $lastName . ', ' . implode(' ', $firstNameParts)
        : implode(' ', $firstNameParts);

    return trim(preg_replace('/\s+/', ' ', $name), ' ,');
}

function gradeDisplayStudentName(array $student) {
    $registrationName = gradeFormatNameParts(
        $student['reg_last_name'] ?? '',
        $student['reg_first_name'] ?? '',
        $student['reg_middle_name'] ?? '',
        $student['reg_extension_name'] ?? ''
    );

    if ($registrationName !== '') {
        return $registrationName;
    }

    $studentName = gradeCleanNamePart($student['student_name'] ?? '');
    if ($studentName === '') {
        return 'Unknown Student';
    }

    if (strpos($studentName, ',') !== false) {
        [$lastName, $rest] = array_map('trim', explode(',', $studentName, 2));
        $parts = preg_split('/\s+/', $rest, -1, PREG_SPLIT_NO_EMPTY);
        $firstName = array_shift($parts) ?: '';
        $middleName = implode(' ', $parts);
        $formatted = gradeFormatNameParts($lastName, $firstName, $middleName);
        return $formatted !== '' ? $formatted : $studentName;
    }

    $parts = preg_split('/\s+/', $studentName, -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) < 2) {
        return $studentName;
    }

    $lastName = array_pop($parts);
    $firstName = array_shift($parts) ?: '';
    $middleName = implode(' ', $parts);
    $formatted = gradeFormatNameParts($lastName, $firstName, $middleName);
    return $formatted !== '' ? $formatted : $studentName;
}

function gradeProgramCondition($program, &$params) {
    $program = normalizeProgram($program);

    if ($program === 'LTS') {
        return "UPPER(s.course_section) LIKE ?";
    }

    if ($program === 'CWTS') {
        return "UPPER(s.course_section) LIKE ?";
    }

    if ($program === 'ROTC') {
        return rotcStudentSqlCondition('s');
    }

    return "1 = 0";
}

function gradeProgramParams($program) {
    $program = normalizeProgram($program);

    if ($program === 'ROTC') {
        return [];
    }

    return ['%' . $program . '%'];
}

function getGradeSetting(PDO $conn, $key, $default) {
    $stmt = $conn->prepare("SELECT setting_value FROM tbl_grade_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : $value;
}

function setGradeSetting(PDO $conn, $key, $value, $userId) {
    $stmt = $conn->prepare("
        INSERT INTO tbl_grade_settings (setting_key, setting_value, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
    ");
    $stmt->execute([$key, $value, $userId]);
}

function hideGradeColumnForCurrentSheet(PDO $conn, $columnId, $userId, $programScope) {
    $stmt = $conn->prepare("
        INSERT INTO tbl_grade_column_visibility (grade_column_id, user_id, program_scope, is_hidden, updated_by)
        VALUES (?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE is_hidden = 1, updated_by = VALUES(updated_by)
    ");
    $stmt->execute([(int) $columnId, (int) $userId, $programScope, (int) $userId]);
}

function unhideDefaultGradeColumnsForCurrentSheet(PDO $conn, $userId, $programScope) {
    $stmt = $conn->prepare("
        UPDATE tbl_grade_column_visibility v
        INNER JOIN tbl_grade_columns c ON c.grade_column_id = v.grade_column_id
        SET v.is_hidden = 0, v.updated_by = ?
        WHERE v.user_id = ?
          AND (v.program_scope <=> ?)
          AND c.is_default = 1
    ");
    $stmt->execute([(int) $userId, (int) $userId, $programScope]);
}

function formatGradeNumber($value, $decimals = 2) {
    if ($value === null || $value === '') {
        return '-';
    }

    return number_format((float) $value, $decimals);
}

function transmuteGrade($percentage, $passingPercentage = 75, $highestGrade = 1, $passingGrade = 3, $failingGrade = 5) {
    $percentage = (float) $percentage;
    $passingPercentage = max(0.01, min(99.99, (float) $passingPercentage));
    $highestGrade = (float) $highestGrade;
    $passingGrade = (float) $passingGrade;
    $failingGrade = (float) $failingGrade;

    if ($percentage < $passingPercentage) {
        return $failingGrade;
    }

    $range = max(0.01, 100 - $passingPercentage);
    $grade = $highestGrade
        + ((100 - min(100, $percentage)) / $range)
        * ($passingGrade - $highestGrade);

    return round(max($highestGrade, min($passingGrade, $grade)), 2);
}

function isManualAttendanceColumn(array $column) {
    return strpos((string) ($column['column_key'] ?? ''), 'manual_attendance_') === 0
        || strcasecmp(trim((string) ($column['group_label'] ?? '')), 'Manual Attendance') === 0;
}

function isSystemAttendanceColumn(array $column) {
    return strpos((string) ($column['column_key'] ?? ''), 'system_attendance_') === 0;
}

function buildGradeGroups(array $gradeColumns) {
    $groups = [];

    foreach ($gradeColumns as $column) {
        $groupCode = $column['group_code'];
        if (!isset($groups[$groupCode])) {
            $groups[$groupCode] = [
                'label' => $column['group_label'],
                'max' => 0,
                'weight' => 0,
                'weights' => [],
                'has_custom' => false,
                'count' => 0,
            ];
        }

        $weight = (float) $column['weight_percent'];
        $groups[$groupCode]['max'] += (float) $column['max_score'];
        $groups[$groupCode]['weights'][] = $weight;
        $groups[$groupCode]['has_custom'] = $groups[$groupCode]['has_custom'] || ((int) $column['is_default'] === 0);
        $groups[$groupCode]['count']++;
    }

    foreach ($groups as $groupCode => $group) {
        $uniqueWeights = array_values(array_unique(array_map(function ($weight) {
            return number_format((float) $weight, 4, '.', '');
        }, $group['weights'])));

        $groups[$groupCode]['weight'] = count($uniqueWeights) > 1
            ? array_sum(array_map('floatval', $uniqueWeights))
            : (float) ($group['weights'][0] ?? 0);
    }

    return $groups;
}

function gradeExistingCategoryWeight(array $gradeColumns, $groupLabel) {
    $weights = [];
    foreach ($gradeColumns as $column) {
        if (strcasecmp(trim((string) $column['group_label']), trim((string) $groupLabel)) !== 0) {
            continue;
        }
        $weights[] = (float) $column['weight_percent'];
    }

    if (!$weights) {
        return null;
    }

    $uniqueWeights = array_values(array_unique(array_map(fn($weight) => number_format($weight, 4, '.', ''), $weights)));
    return (float) $uniqueWeights[0];
}

function computeStudentGradeSummary(
    array $gradeColumns,
    array $scores,
    array $gradeGroups,
    $attendanceCount,
    $totalMeetings,
    $passingPercentage = 75,
    $highestGrade = 1,
    $passingGrade = 3,
    $failingGrade = 5
) {
    $rawTotal = 0;
    $maxTotal = 0;
    $weightedPoints = 0;
    $totalWeight = 0;
    $rawByGroup = [];
    $gradedRawByGroup = [];
    $gradedMaxByGroup = [];
    $gradedCount = 0;
    foreach ($gradeColumns as $column) {
        $columnId = (int) $column['grade_column_id'];
        $scoreValue = isSystemAttendanceColumn($column)
            ? min((int) $attendanceCount, (int) $totalMeetings)
            : ($scores[$columnId] ?? null);
        $isGraded = $scoreValue !== null && $scoreValue !== '';
        $score = $isGraded ? (float) $scoreValue : 0;

        $groupCode = $column['group_code'];
        if (!isset($rawByGroup[$groupCode])) {
            $rawByGroup[$groupCode] = 0;
        }

        $rawByGroup[$groupCode] += $score;
        $rawTotal += $score;
        $maxTotal += (float) $column['max_score'];
        if ($isGraded) {
            $gradedRawByGroup[$groupCode] = ($gradedRawByGroup[$groupCode] ?? 0) + $score;
            $gradedMaxByGroup[$groupCode] = ($gradedMaxByGroup[$groupCode] ?? 0) + (float) $column['max_score'];
            $gradedCount++;
        }
    }

    $groupSummaries = [];
    $currentWeightedPoints = 0;
    $activeWeight = 0;
    foreach ($gradeGroups as $groupCode => $group) {
        $groupMax = max((float) $group['max'], 1);
        $groupPercent = (($rawByGroup[$groupCode] ?? 0) / $groupMax) * 100;
        $groupWeight = (float) $group['weight'];
        $groupWeighted = ($groupPercent / 100) * $groupWeight;
        $weightedPoints += $groupWeighted;
        $totalWeight += $groupWeight;

        $gradedMaximum = (float) ($gradedMaxByGroup[$groupCode] ?? 0);
        $currentGroupWeighted = null;
        if ($gradedMaximum > 0) {
            $currentGroupWeighted = (($gradedRawByGroup[$groupCode] ?? 0) / $gradedMaximum) * $groupWeight;
            $currentWeightedPoints += $currentGroupWeighted;
            $activeWeight += $groupWeight;
        }

        $groupSummaries[$groupCode] = [
            'earned' => (float) ($rawByGroup[$groupCode] ?? 0),
            'maximum' => (float) $group['max'],
            'weighted' => $groupWeighted,
            'current_weighted' => $currentGroupWeighted,
        ];
    }

    $scorePercent = $maxTotal > 0 ? ($rawTotal / $maxTotal) * 100 : 0;
    $weightedPercent = $totalWeight > 0 ? ($weightedPoints / $totalWeight) * 100 : $scorePercent;
    $currentPercentage = $activeWeight > 0 ? ($currentWeightedPoints / $activeWeight) * 100 : null;
    $totalItems = count($gradeColumns);
    if ($totalItems === 0) {
        $progressStatus = 'no_items';
        $progressLabel = 'No score items';
    } elseif ($gradedCount === 0) {
        $progressStatus = 'not_started';
        $progressLabel = 'Not started';
    } elseif ($gradedCount < $totalItems) {
        $progressStatus = ($currentPercentage ?? 0) >= (float) $passingPercentage ? 'on_track' : 'at_risk';
        $progressLabel = $progressStatus === 'on_track' ? 'On track' : 'At risk';
    } else {
        $progressStatus = $weightedPercent >= (float) $passingPercentage ? 'completed' : 'needs_improvement';
        $progressLabel = $progressStatus === 'completed' ? 'Completed' : 'Needs improvement';
    }

    return [
        'raw_total' => $rawTotal,
        'max_total' => $maxTotal,
        'score_percent' => $scorePercent,
        'weighted_percent' => $weightedPercent,
        'current_percentage' => $currentPercentage,
        'total_weight' => $totalWeight,
        'final_grade' => transmuteGrade($weightedPercent, $passingPercentage, $highestGrade, $passingGrade, $failingGrade),
        'graded_count' => $gradedCount,
        'total_count' => $totalItems,
        'pending_count' => max(0, $totalItems - $gradedCount),
        'completion_percentage' => $totalItems > 0 ? ($gradedCount / $totalItems) * 100 : 0,
        'progress_status' => $progressStatus,
        'progress_label' => $progressLabel,
        'groups' => $groupSummaries,
    ];
}

ensureGradeTables($conn);
seedDefaultGradeColumns($conn);

$messages = [];
$errors = [];
$currentUser = getCurrentUserRecord($conn);
$currentProgram = normalizeProgram($_SESSION['program'] ?? ($currentUser['program'] ?? null));
$settingScope = $currentProgram ? strtolower($currentProgram) : 'global';
$columnVisibilityScope = $currentProgram ?: 'global';
$totalMeetingsKey = 'total_meetings_' . $settingScope;
$systemAttendanceEnabledKey = 'system_attendance_enabled_' . $settingScope;
$passingPercentageKey = 'passing_percentage_' . $settingScope;
$highestGradeKey = 'highest_grade_' . $settingScope;
$passingGradeKey = 'passing_grade_' . $settingScope;
$failingGradeKey = 'failing_grade_' . $settingScope;
$canComputeGrades = $userRole === 'facilitator';
$canManageColumns = $userRole === 'coordinator';
$columnOwnerId = $userId;

if ($currentProgram && $userRole !== 'coordinator') {
    $coordinatorStmt = $conn->prepare("
        SELECT user_id
        FROM tbl_users
        WHERE role = 'coordinator' AND program = ?
        ORDER BY user_id ASC
        LIMIT 1
    ");
    $coordinatorStmt->execute([$currentProgram]);
    $coordinatorId = (int) $coordinatorStmt->fetchColumn();
    if ($coordinatorId > 0) {
        $columnOwnerId = $coordinatorId;
    }
}

$columnsStmt = $conn->prepare("
    SELECT *
    FROM tbl_grade_columns
    WHERE is_active = 1
      AND (program_scope IS NULL OR program_scope = ?)
      AND (
        is_default = 1
        OR created_by IS NULL
        OR created_by = ?
      )
      AND NOT EXISTS (
        SELECT 1
        FROM tbl_grade_column_visibility v
        WHERE v.grade_column_id = tbl_grade_columns.grade_column_id
          AND v.user_id = ?
          AND (v.program_scope <=> ?)
          AND v.is_hidden = 1
      )
    ORDER BY sort_order ASC, grade_column_id ASC
");
$gradeColumns = [];
$gradeColumnIds = [];

$gradeFolderOptions = [];
$selectedGradeFolder = '';
$selectedGradeFacilitatorId = null;
$selectedGradeFolderLabel = 'No folder selected';

if ($userRole === 'coordinator') {
    $stmt = $conn->prepare("
        SELECT ads.user_id AS facilitator_id,
               ads.course_section,
               COALESCE(NULLIF(u.full_name, ''), u.username) AS facilitator_name,
               COUNT(s.tbl_student_id) AS student_count
        FROM tbl_admin_sections ads
        INNER JOIN tbl_users u ON u.user_id = ads.user_id
        LEFT JOIN tbl_student s
            ON s.created_by = ads.user_id
           AND s.course_section = ads.course_section
        WHERE u.role = 'facilitator' AND u.program = ?
        GROUP BY ads.user_id, ads.course_section, u.full_name, u.username
        ORDER BY facilitator_name ASC, ads.course_section ASC
    ");
    $stmt->execute([$currentProgram]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (int) $row['facilitator_id'] . '::' . $row['course_section'];
        $gradeFolderOptions[] = [
            'key' => $key,
            'facilitator_id' => (int) $row['facilitator_id'],
            'folder' => $row['course_section'],
            'label' => ($row['facilitator_name'] ?: 'Facilitator') . ' / ' . $row['course_section'],
            'student_count' => (int) $row['student_count'],
        ];
    }

    $requestedFolderKey = trim((string) ($_GET['grade_folder'] ?? ''));
    foreach ($gradeFolderOptions as $option) {
        if ($option['key'] === $requestedFolderKey || ($requestedFolderKey === '' && count($gradeFolderOptions) === 1)) {
            $selectedGradeFacilitatorId = $option['facilitator_id'];
            $selectedGradeFolder = $option['folder'];
            $selectedGradeFolderLabel = $option['label'];
            break;
        }
    }
} else {
    $stmt = $conn->prepare("
        SELECT ads.course_section, COUNT(s.tbl_student_id) AS student_count
        FROM tbl_admin_sections ads
        LEFT JOIN tbl_student s
            ON s.created_by = ads.user_id
           AND s.course_section = ads.course_section
        WHERE ads.user_id = ?
        GROUP BY ads.course_section
        ORDER BY ads.course_section ASC
    ");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $gradeFolderOptions[] = [
            'key' => $row['course_section'],
            'facilitator_id' => $userId,
            'folder' => $row['course_section'],
            'label' => $row['course_section'],
            'student_count' => (int) $row['student_count'],
        ];
    }

    if ($currentProgram === 'ROTC' && empty($gradeFolderOptions)) {
        $rotcCondition = rotcStudentSqlCondition('s');
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS student_count
            FROM tbl_student s
            WHERE {$rotcCondition}
        ");
        $stmt->execute();
        $gradeFolderOptions[] = [
            'key' => 'rotc_all',
            'facilitator_id' => $userId,
            'folder' => '__rotc_all__',
            'label' => 'All ROTC Students',
            'student_count' => (int) $stmt->fetchColumn(),
        ];
    }

    $requestedFolderKey = trim((string) ($_GET['grade_folder'] ?? ''));
    foreach ($gradeFolderOptions as $option) {
        if ($option['key'] === $requestedFolderKey || ($requestedFolderKey === '' && count($gradeFolderOptions) === 1)) {
            $selectedGradeFacilitatorId = $userId;
            $selectedGradeFolder = $option['folder'];
            $selectedGradeFolderLabel = $option['label'];
            break;
        }
    }
}

$sheetOwnerId = $columnOwnerId;
$columnsStmt->execute([$currentProgram, $sheetOwnerId, $sheetOwnerId, $columnVisibilityScope]);
$gradeColumns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
$systemAttendanceEnabled = getGradeSetting($conn, $systemAttendanceEnabledKey, '1') !== '0';
$systemAttendanceColumn = null;
foreach ($gradeColumns as $column) {
    if (isSystemAttendanceColumn($column)) {
        $systemAttendanceColumn = $column;
        break;
    }
}

// Convert the legacy standalone attendance percentage into a real score item.
if ($systemAttendanceEnabled && !$systemAttendanceColumn) {
    $legacyGroups = buildGradeGroups(array_values(array_filter(
        $gradeColumns,
        fn($column) => !isManualAttendanceColumn($column)
    )));
    $legacyScoreWeight = array_sum(array_map(fn($group) => (float) $group['weight'], $legacyGroups));
    $attendanceWeight = max(0, 100 - $legacyScoreWeight);
    $attendanceGroupLabel = 'Attendance';
    $attendanceGroupCode = slugGradeGroup($attendanceGroupLabel);
    $attendanceColumnKey = 'system_attendance_' . substr(sha1($settingScope . ':' . $sheetOwnerId), 0, 24);
    $totalMeetingsForColumn = max(1, (int) getGradeSetting($conn, $totalMeetingsKey, '11'));
    $stmt = $conn->prepare("
        INSERT IGNORE INTO tbl_grade_columns
            (column_key, program_scope, label, group_code, group_label, max_score, weight_percent, sort_order, is_default, is_active, created_by, updated_by)
        VALUES (?, ?, 'System Attendance', ?, ?, ?, ?, 490, 0, 1, ?, ?)
    ");
    $stmt->execute([
        $attendanceColumnKey,
        $currentProgram,
        $attendanceGroupCode,
        $attendanceGroupLabel,
        $totalMeetingsForColumn,
        $attendanceWeight,
        $sheetOwnerId,
        $userId,
    ]);
    $systemColumnIdStmt = $conn->prepare("SELECT grade_column_id FROM tbl_grade_columns WHERE column_key = ? LIMIT 1");
    $systemColumnIdStmt->execute([$attendanceColumnKey]);
    $legacySystemColumnId = (int) $systemColumnIdStmt->fetchColumn();
    if ($legacySystemColumnId > 0) {
        $restoreSystemColumn = $conn->prepare("
            INSERT INTO tbl_grade_column_visibility (grade_column_id, user_id, program_scope, is_hidden, updated_by)
            VALUES (?, ?, ?, 0, ?)
            ON DUPLICATE KEY UPDATE is_hidden = 0, updated_by = VALUES(updated_by)
        ");
        $restoreSystemColumn->execute([$legacySystemColumnId, $sheetOwnerId, $columnVisibilityScope, $userId]);
    }
    $columnsStmt->execute([$currentProgram, $sheetOwnerId, $sheetOwnerId, $columnVisibilityScope]);
    $gradeColumns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($systemAttendanceEnabled) {
    $systemAttendanceColumn = null;
    foreach ($gradeColumns as $column) {
        if (isSystemAttendanceColumn($column)) {
            $systemAttendanceColumn = $column;
            break;
        }
    }
    if ($systemAttendanceColumn) {
        $syncLegacyManualAttendance = $conn->prepare("
            UPDATE tbl_grade_columns
            SET group_code = ?, group_label = ?, weight_percent = ?, updated_by = ?
            WHERE grade_column_id = ?
        ");
        $manualAttendanceSynced = false;
        foreach ($gradeColumns as $column) {
            if (!isManualAttendanceColumn($column)) {
                continue;
            }
            if ($column['group_code'] === $systemAttendanceColumn['group_code']
                && (float) $column['weight_percent'] === (float) $systemAttendanceColumn['weight_percent']) {
                continue;
            }
            $syncLegacyManualAttendance->execute([
                $systemAttendanceColumn['group_code'],
                $systemAttendanceColumn['group_label'],
                $systemAttendanceColumn['weight_percent'],
                $userId,
                (int) $column['grade_column_id'],
            ]);
            $manualAttendanceSynced = true;
        }
        if ($manualAttendanceSynced) {
            $columnsStmt->execute([$currentProgram, $sheetOwnerId, $sheetOwnerId, $columnVisibilityScope]);
            $gradeColumns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}
$gradeColumnIds = array_map('intval', array_column($gradeColumns, 'grade_column_id'));

$studentParams = [];
$hasPublicRegistrationTable = gradeTableExists($conn, 'tbl_public_student_registrations');
$registrationSelectSql = $hasPublicRegistrationTable ? "
        r.last_name AS reg_last_name,
        r.first_name AS reg_first_name,
        r.middle_name AS reg_middle_name,
        r.extension_name AS reg_extension_name,
" : "
        NULL AS reg_last_name,
        NULL AS reg_first_name,
        NULL AS reg_middle_name,
        NULL AS reg_extension_name,
";
$registrationJoinSql = $hasPublicRegistrationTable ? "
        LEFT JOIN (
            SELECT student_number, MAX(registration_id) AS latest_registration_id
            FROM tbl_public_student_registrations
            WHERE registrant_role = 'student'
              AND student_number IS NOT NULL
              AND student_number <> ''
            GROUP BY student_number
        ) latest_reg ON latest_reg.student_number = s.student_number
        LEFT JOIN tbl_public_student_registrations r ON r.registration_id = latest_reg.latest_registration_id
" : "";
$studentOrderSql = $hasPublicRegistrationTable
    ? "ORDER BY COALESCE(NULLIF(r.last_name, ''), s.student_name) ASC, COALESCE(NULLIF(r.first_name, ''), '') ASC, s.student_name ASC"
    : "ORDER BY s.student_name ASC";
if ($selectedGradeFolder === '__rotc_all__' && $currentProgram === 'ROTC') {
    $rotcCondition = rotcStudentSqlCondition('s');
    $studentSql = "
        SELECT s.*,
               {$registrationSelectSql}
               COALESCE(NULLIF(u.full_name, ''), u.username, 'ROTC') AS facilitator_name
        FROM tbl_student s
        LEFT JOIN tbl_users u ON s.created_by = u.user_id
        {$registrationJoinSql}
        WHERE {$rotcCondition}
        {$studentOrderSql}
    ";
} elseif ($selectedGradeFolder !== '' && $selectedGradeFacilitatorId) {
    $studentSql = "
        SELECT s.*,
               {$registrationSelectSql}
               COALESCE(NULLIF(u.full_name, ''), u.username, 'Pending Facilitator Assignment') AS facilitator_name
        FROM tbl_student s
        LEFT JOIN tbl_users u ON s.created_by = u.user_id
        {$registrationJoinSql}
        WHERE s.created_by = ? AND s.course_section = ?
        {$studentOrderSql}
    ";
    $studentParams = [$selectedGradeFacilitatorId, $selectedGradeFolder];
} else {
    $studentSql = "
        SELECT s.*,
               {$registrationSelectSql}
               COALESCE(NULLIF(u.full_name, ''), u.username, 'Facilitator') AS facilitator_name
        FROM tbl_student s
        LEFT JOIN tbl_users u ON s.created_by = u.user_id
        {$registrationJoinSql}
        WHERE 1 = 0
    ";
}

$studentsStmt = $conn->prepare($studentSql);
$studentsStmt->execute($studentParams);
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
$accessibleStudentIds = array_map('intval', array_column($students, 'tbl_student_id'));
$accessibleStudentLookup = array_fill_keys($accessibleStudentIds, true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    $columnManagementActions = [
        'add_column',
        'delete_column',
        'delete_selected_columns',
        'new_class_record',
        'restore_default_record',
        'edit_column',
        'settings',
        'remove_system_attendance',
    ];

    if (in_array($action, $columnManagementActions, true) && !$canManageColumns) {
        $errors[] = 'Only coordinators can edit grade columns and grade settings.';
    }

    if ($action === 'add_column' && $canManageColumns) {
        $scoreItemType = $_POST['score_item_type'] ?? 'score';
        $isSystemAttendance = $scoreItemType === 'system_attendance';
        $isManualAttendance = $scoreItemType === 'manual_attendance';

        if ($isSystemAttendance) {
            $groupLabel = selectedGradeGroupLabel($_POST, '');
            $weight = (float) ($_POST['weight_percent'] ?? 0);
            $existingCategoryWeight = gradeExistingCategoryWeight(
                array_values(array_filter($gradeColumns, fn($column) => !isSystemAttendanceColumn($column))),
                $groupLabel
            );
            if ($existingCategoryWeight !== null) {
                $weight = $existingCategoryWeight;
            }

            if ($groupLabel === '') {
                $errors[] = 'Choose a category for System Attendance.';
            } elseif (!$isManualAttendance && $weight <= 0) {
                $errors[] = 'Category weight must be greater than zero.';
            } else {
                $groupCode = slugGradeGroup($groupLabel);
                $totalMeetingsForColumn = max(1, (int) getGradeSetting($conn, $totalMeetingsKey, '11'));
                $systemColumn = null;
                foreach ($gradeColumns as $column) {
                    if (isSystemAttendanceColumn($column)) {
                        $systemColumn = $column;
                        break;
                    }
                }

                if ($systemColumn) {
                    $stmt = $conn->prepare("
                        UPDATE tbl_grade_columns
                        SET group_code = ?, group_label = ?, max_score = ?, weight_percent = ?, is_active = 1, updated_by = ?
                        WHERE grade_column_id = ?
                    ");
                    $stmt->execute([$groupCode, $groupLabel, $totalMeetingsForColumn, $weight, $userId, (int) $systemColumn['grade_column_id']]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO tbl_grade_columns
                            (column_key, program_scope, label, group_code, group_label, max_score, weight_percent, sort_order, is_default, is_active, created_by, updated_by)
                        VALUES (?, ?, 'System Attendance', ?, ?, ?, ?, 490, 0, 1, ?, ?)
                    ");
                    $stmt->execute([
                        'system_attendance_' . bin2hex(random_bytes(8)),
                        $currentProgram,
                        $groupCode,
                        $groupLabel,
                        $totalMeetingsForColumn,
                        $weight,
                        $userId,
                        $userId,
                    ]);
                }

                $syncManualAttendance = $conn->prepare("
                    UPDATE tbl_grade_columns
                    SET group_code = ?, group_label = ?, weight_percent = ?, updated_by = ?
                    WHERE grade_column_id = ?
                ");
                foreach ($gradeColumns as $column) {
                    if (isManualAttendanceColumn($column)) {
                        $syncManualAttendance->execute([$groupCode, $groupLabel, $weight, $userId, (int) $column['grade_column_id']]);
                    }
                }
                setGradeSetting($conn, $systemAttendanceEnabledKey, '1', $userId);
                $messages[] = 'System Attendance added under ' . $groupLabel . '.';
            }
        } else {
            $label = trim($_POST['label'] ?? '');
            $groupLabel = selectedGradeGroupLabel($_POST);
            $maxScore = (float) ($_POST['max_score'] ?? 0);
            $weight = (float) ($_POST['weight_percent'] ?? 0);
            if ($isManualAttendance) {
                $label = $label !== '' ? $label : 'Manual Attendance Score';
                foreach ($gradeColumns as $column) {
                    if (isSystemAttendanceColumn($column)) {
                        $groupLabel = (string) $column['group_label'];
                        $weight = (float) $column['weight_percent'];
                        break;
                    }
                }
            } else {
                $groupLabel = $groupLabel !== '' ? $groupLabel : 'Additional Requirements';
                $existingCategoryWeight = gradeExistingCategoryWeight($gradeColumns, $groupLabel);
                if ($existingCategoryWeight !== null) {
                    $weight = $existingCategoryWeight;
                }
            }
            $groupCode = slugGradeGroup($groupLabel);

            if ($label === '') {
                $errors[] = 'Column name is required.';
            } elseif ($maxScore <= 0) {
                $errors[] = 'Max score must be greater than zero.';
            } elseif ($weight <= 0) {
                $errors[] = 'Weight percent must be greater than zero.';
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO tbl_grade_columns
                        (column_key, program_scope, label, group_code, group_label, max_score, weight_percent, sort_order, is_default, is_active, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?)
                ");
                $stmt->execute([
                    ($isManualAttendance ? 'manual_attendance_' : 'custom_') . bin2hex(random_bytes(8)),
                    $currentProgram,
                    $label,
                    $groupCode,
                    $groupLabel,
                    $maxScore,
                    $weight,
                    500 + time(),
                    $userId,
                    $userId,
                ]);
                if ($isManualAttendance) {
                    setGradeSetting($conn, $systemAttendanceEnabledKey, '1', $userId);
                }
                $messages[] = $isManualAttendance ? 'Manual attendance score added.' : 'New grade column added.';
            }
        }
    }

    if ($action === 'delete_column' && $canManageColumns) {
        $columnId = (int) ($_POST['column_id'] ?? 0);
        $selectedColumn = null;
        foreach ($gradeColumns as $column) {
            if ((int) $column['grade_column_id'] === $columnId) {
                $selectedColumn = $column;
                break;
            }
        }

        if (!$selectedColumn) {
            $errors[] = 'Column not found in the current class record.';
        } elseif ((int) $selectedColumn['is_default'] === 1) {
            hideGradeColumnForCurrentSheet($conn, $columnId, $userId, $columnVisibilityScope);
            $messages[] = 'Column removed from your current class record.';
        } elseif ($userRole === 'coordinator') {
            $stmt = $conn->prepare("UPDATE tbl_grade_columns SET is_active = 0, updated_by = ? WHERE grade_column_id = ? AND is_default = 0");
            $stmt->execute([$userId, $columnId]);
            hideGradeColumnForCurrentSheet($conn, $columnId, $userId, $columnVisibilityScope);
            $messages[] = 'Custom column removed from the class record.';
        } else {
            $stmt = $conn->prepare("UPDATE tbl_grade_columns SET is_active = 0, updated_by = ? WHERE grade_column_id = ? AND is_default = 0 AND created_by = ?");
            $stmt->execute([$userId, $columnId, $userId]);
            if ($stmt->rowCount() > 0) {
                $messages[] = 'Custom column removed from the class record.';
            } else {
                hideGradeColumnForCurrentSheet($conn, $columnId, $userId, $columnVisibilityScope);
                $messages[] = 'Column removed from your current class record.';
            }
        }
    }

    if ($action === 'delete_selected_columns' && $canManageColumns) {
        $columnIds = $_POST['column_ids'] ?? [];
        if (!is_array($columnIds) || empty($columnIds)) {
            $errors[] = 'Select at least one column to remove.';
        } else {
            $columnIds = array_values(array_unique(array_map('intval', $columnIds)));
            $activeColumnLookup = [];
            foreach ($gradeColumns as $column) {
                $activeColumnLookup[(int) $column['grade_column_id']] = $column;
            }

            $removedCount = 0;
            foreach ($columnIds as $columnId) {
                if (!isset($activeColumnLookup[$columnId])) {
                    continue;
                }

                $column = $activeColumnLookup[$columnId];
                if ((int) $column['is_default'] === 1) {
                    hideGradeColumnForCurrentSheet($conn, $columnId, $userId, $columnVisibilityScope);
                    $removedCount++;
                    continue;
                }

                if ($userRole === 'coordinator') {
                    $stmt = $conn->prepare("UPDATE tbl_grade_columns SET is_active = 0, updated_by = ? WHERE grade_column_id = ? AND is_default = 0");
                    $stmt->execute([$userId, $columnId]);
                    hideGradeColumnForCurrentSheet($conn, $columnId, $userId, $columnVisibilityScope);
                    $removedCount++;
                } else {
                    $stmt = $conn->prepare("UPDATE tbl_grade_columns SET is_active = 0, updated_by = ? WHERE grade_column_id = ? AND is_default = 0 AND created_by = ?");
                    $stmt->execute([$userId, $columnId, $userId]);
                    if ($stmt->rowCount() > 0) {
                        $removedCount++;
                    } else {
                        hideGradeColumnForCurrentSheet($conn, $columnId, $userId, $columnVisibilityScope);
                        $removedCount++;
                    }
                }
            }

            if ($removedCount > 0) {
                $messages[] = $removedCount . ' column(s) removed from the current class record.';
            } else {
                $errors[] = 'No valid columns were selected.';
            }
        }
    }

    if ($action === 'new_class_record' && $canManageColumns) {
        foreach ($gradeColumns as $column) {
            hideGradeColumnForCurrentSheet($conn, (int) $column['grade_column_id'], $userId, $columnVisibilityScope);
        }
        setGradeSetting($conn, $systemAttendanceEnabledKey, '0', $userId);
        $messages[] = 'Current class record cleared. Add new columns to build a new grading sheet.';
    }

    if ($action === 'restore_default_record' && $canManageColumns) {
        unhideDefaultGradeColumnsForCurrentSheet($conn, $userId, $columnVisibilityScope);
        setGradeSetting($conn, $systemAttendanceEnabledKey, '1', $userId);
        $messages[] = 'Default class record restored.';
    }

    if ($action === 'edit_column' && $canManageColumns) {
        $columnId = (int) ($_POST['column_id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $groupLabel = selectedGradeGroupLabel($_POST);
        $maxScore = (float) ($_POST['max_score'] ?? 0);
        $weight = (float) ($_POST['weight_percent'] ?? 0);
        $groupLabel = $groupLabel !== '' ? $groupLabel : 'Additional Requirements';
        $selectedColumn = null;
        foreach ($gradeColumns as $column) {
            if ((int) $column['grade_column_id'] === $columnId) {
                $selectedColumn = $column;
                break;
            }
        }
        $isManualAttendance = $selectedColumn && isManualAttendanceColumn($selectedColumn);
        if ($isManualAttendance) {
            foreach ($gradeColumns as $column) {
                if (isSystemAttendanceColumn($column)) {
                    $groupLabel = (string) $column['group_label'];
                    $weight = (float) $column['weight_percent'];
                    break;
                }
            }
        }
        $otherColumns = array_values(array_filter($gradeColumns, fn($column) => (int) $column['grade_column_id'] !== $columnId));
        $existingCategoryWeight = gradeExistingCategoryWeight($otherColumns, $groupLabel);
        $keptSameCategory = $selectedColumn
            && strcasecmp(trim((string) $selectedColumn['group_label']), trim($groupLabel)) === 0;
        if (!$isManualAttendance && $keptSameCategory) {
            $weight = (float) $selectedColumn['weight_percent'];
        } elseif ($existingCategoryWeight !== null) {
            $weight = $existingCategoryWeight;
        }

        if ($label === '') {
            $errors[] = 'Column name is required.';
        } elseif ($maxScore <= 0) {
            $errors[] = 'Max score must be greater than zero.';
        } elseif (!$isManualAttendance && $weight <= 0) {
            $errors[] = 'Weight percent must be greater than zero.';
        } else {
            $activeColumnLookup = [];
            foreach ($gradeColumns as $column) {
                $activeColumnLookup[(int) $column['grade_column_id']] = true;
            }

            if (!$selectedColumn || !isset($activeColumnLookup[$columnId])) {
                $errors[] = 'Column not found in the current class record.';
            } else {
                $stmt = $conn->prepare("
                    UPDATE tbl_grade_columns
                    SET label = ?, group_code = ?, group_label = ?, max_score = ?, weight_percent = ?, updated_by = ?
                    WHERE grade_column_id = ?
                ");
                $stmt->execute([$label, slugGradeGroup($groupLabel), $groupLabel, $maxScore, $weight, $userId, $columnId]);
                if ($isManualAttendance) {
                    setGradeSetting($conn, $systemAttendanceEnabledKey, '1', $userId);
                }
                $messages[] = 'Grade column updated.';
            }
        }
    }

    if ($action === 'remove_system_attendance' && $canManageColumns) {
        setGradeSetting($conn, $systemAttendanceEnabledKey, '0', $userId);
        $messages[] = 'System Attendance removed from the current grading sheet. Saved attendance data and manual scores were kept.';
    }

    if ($action === 'settings' && $canManageColumns) {
        $meetings = max(1, (int) ($_POST['total_meetings'] ?? 11));
        $passingPercentageInput = (float) ($_POST['passing_percentage'] ?? 75);
        $highestGradeInput = (float) ($_POST['highest_grade'] ?? 1);
        $passingGradeInput = (float) ($_POST['passing_grade'] ?? 3);
        $failingGradeInput = (float) ($_POST['failing_grade'] ?? 5);
        $settingsCategories = $_POST['categories'] ?? [];
        $settingsGradeColumns = array_values(array_filter(
            $gradeColumns,
            fn($column) => !isSystemAttendanceColumn($column) || $systemAttendanceEnabled
        ));
        $currentGroups = buildGradeGroups($settingsGradeColumns);
        $categoryUpdates = [];

        if ($passingPercentageInput <= 0 || $passingPercentageInput >= 100) {
            $errors[] = 'Passing percentage must be between 0 and 100.';
        } elseif ($highestGradeInput >= $passingGradeInput || $passingGradeInput >= $failingGradeInput) {
            $errors[] = 'Use an ascending scale such as 1.00 highest, 3.00 passing, and 5.00 failing.';
        }

        if (!is_array($settingsCategories)) {
            $errors[] = 'Invalid grading category settings.';
        } else {
            foreach ($currentGroups as $groupCode => $group) {
                $category = $settingsCategories[$groupCode] ?? null;
                if (!is_array($category)) {
                    $errors[] = 'Every active grading category must be included.';
                    continue;
                }
                $name = trim((string) ($category['name'] ?? ''));
                $weight = (float) ($category['weight'] ?? -1);
                if ($name === '' || $weight < 0 || $weight > 100) {
                    $errors[] = 'Each category needs a name and a weight from 0% to 100%.';
                    continue;
                }
                $categoryUpdates[$groupCode] = ['name' => $name, 'weight' => $weight];
            }
        }

        $scoreCategoryWeight = array_sum(array_column($categoryUpdates, 'weight'));
        if (!$errors && $scoreCategoryWeight > 100.001) {
            $errors[] = 'Category weights cannot exceed 100%. Current total: ' . formatGradeNumber($scoreCategoryWeight, 2) . '%.';
        }

        if (!$errors) {
            $conn->beginTransaction();
            try {
                setGradeSetting($conn, $totalMeetingsKey, (string) $meetings, $userId);
                setGradeSetting($conn, $passingPercentageKey, (string) $passingPercentageInput, $userId);
                setGradeSetting($conn, $highestGradeKey, (string) $highestGradeInput, $userId);
                setGradeSetting($conn, $passingGradeKey, (string) $passingGradeInput, $userId);
                setGradeSetting($conn, $failingGradeKey, (string) $failingGradeInput, $userId);

                $updateSystemMaximum = $conn->prepare("
                    UPDATE tbl_grade_columns
                    SET max_score = ?, updated_by = ?
                    WHERE grade_column_id = ?
                ");
                foreach ($gradeColumns as $column) {
                    if (isSystemAttendanceColumn($column)) {
                        $updateSystemMaximum->execute([$meetings, $userId, (int) $column['grade_column_id']]);
                    }
                }

                $updateCategoryItem = $conn->prepare("
                    UPDATE tbl_grade_columns
                    SET group_code = ?, group_label = ?, weight_percent = ?, updated_by = ?
                    WHERE grade_column_id = ?
                ");
                foreach ($categoryUpdates as $oldGroupCode => $category) {
                    $newGroupCode = slugGradeGroup($category['name']);
                    foreach ($settingsGradeColumns as $column) {
                        if ($column['group_code'] !== $oldGroupCode) {
                            continue;
                        }
                        $updateCategoryItem->execute([
                            $newGroupCode,
                            $category['name'],
                            $category['weight'],
                            $userId,
                            (int) $column['grade_column_id'],
                        ]);
                    }
                }
                $conn->commit();
            } catch (Throwable $error) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $error;
            }
            $messages[] = 'Grade settings updated.';
        }
    }

    if ($action === 'save_score_ajax') {
        header('Content-Type: application/json; charset=utf-8');

        if (!$canComputeGrades) {
            http_response_code(403);
            echo json_encode(['message' => 'Only facilitators can encode scores.']);
            exit();
        }

        $studentId = (int) ($_POST['student_id'] ?? 0);
        $columnId = (int) ($_POST['column_id'] ?? 0);
        $scoreValue = trim((string) ($_POST['score'] ?? ''));
        $ajaxSystemAttendanceEnabled = getGradeSetting($conn, $systemAttendanceEnabledKey, '1') !== '0';
        $ajaxGradeColumns = array_values(array_filter(
            $gradeColumns,
            fn($column) => !isSystemAttendanceColumn($column) || $ajaxSystemAttendanceEnabled
        ));
        $columnLookup = [];
        foreach ($ajaxGradeColumns as $column) {
            if (isSystemAttendanceColumn($column)) {
                continue;
            }
            $columnLookup[(int) $column['grade_column_id']] = $column;
        }

        if (!isset($accessibleStudentLookup[$studentId]) || !isset($columnLookup[$columnId])) {
            http_response_code(404);
            echo json_encode(['message' => 'Student or score item is unavailable in this class record.']);
            exit();
        }

        $maxScore = (float) $columnLookup[$columnId]['max_score'];
        if ($scoreValue !== '' && (!is_numeric($scoreValue) || (float) $scoreValue < 0 || (float) $scoreValue > $maxScore)) {
            http_response_code(422);
            echo json_encode(['message' => 'Score must be between 0 and ' . formatGradeNumber($maxScore) . '.']);
            exit();
        }

        $score = $scoreValue === '' ? null : (float) $scoreValue;
        $upsert = $conn->prepare("
            INSERT INTO tbl_grade_scores (grade_column_id, tbl_student_id, score, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE score = VALUES(score), updated_by = VALUES(updated_by)
        ");
        $upsert->execute([$columnId, $studentId, $score, $userId]);

        $scoreStmt = $conn->prepare("
            SELECT grade_column_id, score
            FROM tbl_grade_scores
            WHERE tbl_student_id = ?
              AND grade_column_id IN (" . implode(',', array_fill(0, count($ajaxGradeColumns), '?')) . ")
        ");
        $scoreStmt->execute(array_merge([$studentId], array_map('intval', array_column($ajaxGradeColumns, 'grade_column_id'))));
        $studentScores = [];
        foreach ($scoreStmt->fetchAll(PDO::FETCH_ASSOC) as $scoreRow) {
            $studentScores[(int) $scoreRow['grade_column_id']] = $scoreRow['score'];
        }

        $attendanceStmt = $conn->prepare("
            SELECT COUNT(DISTINCT attendance_date)
            FROM (
                SELECT DATE(time_in) AS attendance_date FROM tbl_attendance WHERE tbl_student_id = ?
                UNION ALL
                SELECT DATE(time_in) AS attendance_date FROM tbl_attendance_archive WHERE tbl_student_id = ?
            ) attendance_days
        ");
        $attendanceStmt->execute([$studentId, $studentId]);
        $ajaxTotalMeetings = max(1, (int) getGradeSetting($conn, $totalMeetingsKey, '11'));
        $ajaxGroups = buildGradeGroups($ajaxGradeColumns);
        $ajaxPassingPercentage = (float) getGradeSetting($conn, $passingPercentageKey, '75');
        $ajaxHighestGrade = (float) getGradeSetting($conn, $highestGradeKey, '1');
        $ajaxPassingGrade = (float) getGradeSetting($conn, $passingGradeKey, '3');
        $ajaxFailingGrade = (float) getGradeSetting($conn, $failingGradeKey, '5');
        $summary = computeStudentGradeSummary(
            $ajaxGradeColumns,
            $studentScores,
            $ajaxGroups,
            min($ajaxTotalMeetings, (int) $attendanceStmt->fetchColumn()),
            $ajaxTotalMeetings,
            $ajaxPassingPercentage,
            $ajaxHighestGrade,
            $ajaxPassingGrade,
            $ajaxFailingGrade
        );

        echo json_encode([
            'message' => 'Score saved.',
            'percentage' => round((float) $summary['weighted_percent'], 2),
            'grade' => round((float) $summary['final_grade'], 2),
            'completion_percentage' => round((float) $summary['completion_percentage'], 2),
            'progress_status' => $summary['progress_status'],
            'progress_label' => $summary['progress_label'],
            'pending_count' => $summary['pending_count'],
            'groups' => array_map(fn($group) => [
                'earned' => round((float) $group['earned'], 2),
                'maximum' => round((float) $group['maximum'], 2),
                'weighted' => round((float) $group['weighted'], 2),
            ], $summary['groups']),
        ]);
        exit();
    }

    if ($action === 'save_scores' && $canComputeGrades) {
        $scores = $_POST['scores'] ?? [];
        $columnLookup = [];
        foreach ($gradeColumns as $column) {
            if (isSystemAttendanceColumn($column)) {
                continue;
            }
            $columnLookup[(int) $column['grade_column_id']] = (float) $column['max_score'];
        }

        $upsert = $conn->prepare("
            INSERT INTO tbl_grade_scores (grade_column_id, tbl_student_id, score, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE score = VALUES(score), updated_by = VALUES(updated_by)
        ");

        foreach ($scores as $studentId => $studentScores) {
            $studentId = (int) $studentId;
            if (!isset($accessibleStudentLookup[$studentId]) || !is_array($studentScores)) {
                continue;
            }

            foreach ($studentScores as $columnId => $scoreValue) {
                $columnId = (int) $columnId;
                if (!isset($columnLookup[$columnId])) {
                    continue;
                }

                $scoreValue = trim((string) $scoreValue);
                $score = $scoreValue === '' ? null : max(0, min((float) $scoreValue, $columnLookup[$columnId]));
                $upsert->execute([$columnId, $studentId, $score, $userId]);
            }
        }

        $messages[] = 'Grades saved successfully.';
    }

    if ($action === 'save_scores' && !$canComputeGrades) {
        $errors[] = 'Only facilitators can compute and save grades.';
    }

    $redirectParams = [$messages ? 'saved' : 'error' => 1];
    if (!empty($_GET['grade_folder'])) {
        $redirectParams['grade_folder'] = (string) $_GET['grade_folder'];
    }
    $query = '?' . http_build_query($redirectParams);
    $_SESSION['grade_messages'] = $messages;
    $_SESSION['grade_errors'] = $errors;
    header("Location: grades.php" . $query);
    exit();
}

if (!empty($_SESSION['grade_messages'])) {
    $messages = $_SESSION['grade_messages'];
    unset($_SESSION['grade_messages']);
}

if (!empty($_SESSION['grade_errors'])) {
    $errors = $_SESSION['grade_errors'];
    unset($_SESSION['grade_errors']);
}

$columnsStmt->execute([$currentProgram, $sheetOwnerId, $sheetOwnerId, $columnVisibilityScope]);
$gradeColumns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
$gradeColumnIds = array_map('intval', array_column($gradeColumns, 'grade_column_id'));
$systemAttendanceEnabled = getGradeSetting($conn, $systemAttendanceEnabledKey, '1') !== '0';
$activeGradeColumns = array_values(array_filter(
    $gradeColumns,
    fn($column) => !isSystemAttendanceColumn($column) || $systemAttendanceEnabled
));
$activeSystemAttendanceColumn = null;
foreach ($activeGradeColumns as $column) {
    if (isSystemAttendanceColumn($column)) {
        $activeSystemAttendanceColumn = $column;
        break;
    }
}
$gradeCategoryOptions = [];
foreach ($gradeColumns as $column) {
    if (isSystemAttendanceColumn($column)) {
        continue;
    }
    $categoryLabel = trim((string) ($column['group_label'] ?? ''));
    if ($categoryLabel !== '') {
        $gradeCategoryOptions[$categoryLabel] = true;
    }
}
$gradeCategoryOptions = array_keys($gradeCategoryOptions);
sort($gradeCategoryOptions, SORT_NATURAL | SORT_FLAG_CASE);

$scoresByStudent = [];
if (!empty($accessibleStudentIds) && !empty($gradeColumnIds)) {
    $studentPlaceholders = implode(',', array_fill(0, count($accessibleStudentIds), '?'));
    $columnPlaceholders = implode(',', array_fill(0, count($gradeColumnIds), '?'));
    $stmt = $conn->prepare("
        SELECT tbl_student_id, grade_column_id, score
        FROM tbl_grade_scores
        WHERE tbl_student_id IN ($studentPlaceholders)
          AND grade_column_id IN ($columnPlaceholders)
    ");
    $stmt->execute(array_merge($accessibleStudentIds, $gradeColumnIds));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $scoreRow) {
        $scoresByStudent[(int) $scoreRow['tbl_student_id']][(int) $scoreRow['grade_column_id']] = $scoreRow['score'];
    }
}

$attendanceCounts = [];
if (!empty($accessibleStudentIds)) {
    $studentPlaceholders = implode(',', array_fill(0, count($accessibleStudentIds), '?'));
    $stmt = $conn->prepare("
        SELECT tbl_student_id, COUNT(DISTINCT attendance_date) AS attendance_count
        FROM (
            SELECT tbl_student_id, DATE(time_in) AS attendance_date
            FROM tbl_attendance
            WHERE tbl_student_id IN ($studentPlaceholders)
            UNION ALL
            SELECT tbl_student_id, DATE(time_in) AS attendance_date
            FROM tbl_attendance_archive
            WHERE tbl_student_id IN ($studentPlaceholders)
        ) attendance_days
        GROUP BY tbl_student_id
    ");
    $stmt->execute(array_merge($accessibleStudentIds, $accessibleStudentIds));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $attendanceRow) {
        $attendanceCounts[(int) $attendanceRow['tbl_student_id']] = (int) $attendanceRow['attendance_count'];
    }
}

$totalMeetings = max(1, (int) getGradeSetting($conn, $totalMeetingsKey, '11'));
$passingPercentage = (float) getGradeSetting($conn, $passingPercentageKey, '75');
$highestGrade = (float) getGradeSetting($conn, $highestGradeKey, '1');
$passingGrade = (float) getGradeSetting($conn, $passingGradeKey, '3');
$failingGrade = (float) getGradeSetting($conn, $failingGradeKey, '5');
$gradeGroups = buildGradeGroups($activeGradeColumns);
$scoreWeight = array_sum(array_map(function ($group) {
    return (float) $group['weight'];
}, $gradeGroups));
$gradeTableColumnCount = count($activeGradeColumns) + (count($gradeGroups) * 2) + 5;
$gradeGroupTones = [];
$gradeGroupToneIndex = 1;
foreach (array_keys($gradeGroups) as $groupCode) {
    $gradeGroupTones[$groupCode] = 'grade-group-tone-' . $gradeGroupToneIndex;
    $gradeGroupToneIndex = ($gradeGroupToneIndex % 4) + 1;
}
$gradeSummariesByStudent = [];
$gradebookMetrics = [
    'students' => count($students),
    'on_track' => 0,
    'needs_attention' => 0,
    'completed' => 0,
    'average_completion' => 0,
];
$completionTotal = 0;
foreach ($students as $student) {
    $studentId = (int) $student['tbl_student_id'];
    $summary = computeStudentGradeSummary(
        $activeGradeColumns,
        $scoresByStudent[$studentId] ?? [],
        $gradeGroups,
        min($totalMeetings, $attendanceCounts[$studentId] ?? 0),
        $totalMeetings,
        $passingPercentage,
        $highestGrade,
        $passingGrade,
        $failingGrade
    );
    $gradeSummariesByStudent[$studentId] = $summary;
    $completionTotal += (float) $summary['completion_percentage'];
    if ($summary['progress_status'] === 'on_track') {
        $gradebookMetrics['on_track']++;
    }
    if (in_array($summary['progress_status'], ['at_risk', 'needs_improvement'], true)) {
        $gradebookMetrics['needs_attention']++;
    }
    if ($summary['progress_status'] === 'completed') {
        $gradebookMetrics['completed']++;
    }
}
if (count($students) > 0) {
    $gradebookMetrics['average_completion'] = round($completionTotal / count($students), 1);
}
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grades · TAU NSTP National Service Training Program</title>
    <?php echo getFaviconTags(); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        :root {
            --grade-green: #166534;
            --grade-green-dark: #0f4d2a;
            --grade-green-soft: #ecfdf3;
            --grade-border: #d8e2dc;
            --grade-ink: #1f2937;
            --grade-muted: #64748b;
        }

        .grade-page-heading {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .grade-page-icon {
            width: 48px;
            height: 48px;
            flex: 0 0 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            color: #fff;
            background: linear-gradient(145deg, var(--grade-green), #22a35a);
            box-shadow: 0 8px 20px rgba(22, 101, 52, 0.2);
        }

        .grade-page-kicker {
            color: var(--grade-green);
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.09em;
            text-transform: uppercase;
        }

        .grade-page-title {
            color: var(--grade-ink);
            font-size: 1.55rem;
            font-weight: 800;
            line-height: 1.15;
        }

        .grade-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
        }

        .class-record-tools-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .class-record-title {
            min-width: 240px;
            flex: 1 1 auto;
        }

        .class-record-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
            margin-left: auto;
        }

        .class-record-actions form {
            display: flex;
            margin: 0;
        }

        .class-record-actions .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            min-width: 108px;
            border-radius: 6px;
            font-weight: 700;
            white-space: nowrap;
        }

        .grade-card {
            border-radius: 12px;
            border: 1px solid var(--grade-border);
            box-shadow: 0 3px 14px rgba(15, 77, 42, 0.05);
            overflow: hidden;
        }

        .grade-sheet-selector {
            background: linear-gradient(135deg, #ffffff 0%, #f5fbf7 100%);
        }

        .grade-sheet-selector .form-control {
            min-height: 42px;
            border-color: #b8c9bd;
            border-radius: 8px;
            font-weight: 600;
        }

        .sheet-context {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .sheet-context-item {
            min-width: 0;
            padding: 8px 10px;
            border-left: 2px solid #b9dec6;
        }

        .sheet-context-label {
            display: block;
            color: var(--grade-muted);
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .sheet-context-value {
            display: block;
            overflow: hidden;
            color: var(--grade-ink);
            font-size: 0.86rem;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .grade-summary .info-box {
            min-height: 82px;
            background: #fff;
        }

        .grade-summary .info-box-icon {
            width: 64px;
            border-radius: 0;
            background: var(--grade-green-soft) !important;
            color: var(--grade-green) !important;
            font-size: 1.25rem;
        }

        .grade-summary .info-box-text {
            color: var(--grade-muted);
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .grade-summary .info-box-number {
            color: var(--grade-ink);
            font-size: 1.4rem;
        }

        .grade-progress-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .grade-progress-card {
            min-width: 0;
            padding: 14px 16px;
            border: 1px solid var(--grade-border);
            border-top: 3px solid #94a3b8;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 3px 12px rgba(15, 77, 42, 0.05);
        }

        .grade-progress-card.on-track { border-top-color: #168865; }
        .grade-progress-card.attention { border-top-color: #dc6b32; }
        .grade-progress-card.completed { border-top-color: #2563eb; }

        .grade-progress-card span,
        .grade-progress-card strong,
        .grade-progress-card small {
            display: block;
        }

        .grade-progress-card span {
            color: var(--grade-muted);
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .grade-progress-card strong {
            margin-top: 4px;
            color: var(--grade-ink);
            font-size: 1.45rem;
        }

        .grade-progress-card small {
            margin-top: 2px;
            overflow: hidden;
            color: var(--grade-muted);
            font-size: 0.72rem;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .grade-table-wrap {
            max-height: calc(100vh - 230px);
            overflow: auto;
            border-top: 1px solid var(--grade-border);
            border-bottom: 1px solid var(--grade-border);
            background: #fff;
        }

        .grade-table {
            width: max-content;
            min-width: 100%;
            margin-bottom: 0;
            color: var(--grade-ink);
            border-collapse: separate;
            border-spacing: 0;
        }

        .grade-table th,
        .grade-table td {
            vertical-align: middle !important;
            white-space: nowrap;
            font-size: 0.84rem;
            border-top: 0;
            border-left: 0;
            border-color: var(--grade-border);
            height: 48px;
            text-align: center;
        }

        .grade-table thead th {
            position: sticky;
            top: 0;
            z-index: 6;
            height: auto;
            padding: 10px 9px;
            background: #f4f8f5;
            color: #24402e;
            border-bottom-width: 1px;
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.025em;
            text-transform: uppercase;
        }

        .grade-table thead .grade-category-row th {
            top: 0;
            height: 40px;
        }

        .grade-table thead .grade-assessment-row th {
            top: 40px;
            height: 40px;
            padding-top: 8px;
            padding-bottom: 8px;
            text-transform: none;
        }

        .grade-table thead .grade-max-row th {
            top: 80px;
            height: 34px;
            padding-top: 5px;
            padding-bottom: 5px;
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: none;
        }

        .grade-table tbody tr:nth-child(even) td {
            background-color: #fbfdfb;
        }

        .grade-table tbody tr:hover td {
            background-color: #f0f9f3;
        }

        .grade-table .sticky-index {
            position: sticky;
            left: 0;
            z-index: 8;
            width: 46px;
            min-width: 46px;
            max-width: 46px;
            background: #f7faf8;
            color: var(--grade-muted);
            font-weight: 700;
            box-shadow: 1px 0 0 var(--grade-border);
        }

        .grade-table .sticky-name {
            position: sticky;
            left: 46px;
            z-index: 7;
            background: #fff;
            min-width: 220px;
            max-width: 280px;
            white-space: normal;
            text-align: left;
            box-shadow: 2px 0 4px rgba(15, 77, 42, 0.08);
        }

        .grade-table thead .sticky-index,
        .grade-table thead .sticky-name {
            background: var(--grade-green-dark);
            color: #fff;
            z-index: 10;
        }

        .grade-table thead .sticky-index {
            z-index: 11;
        }

        .grade-table .grade-category-header {
            min-width: 112px;
            color: #fff;
            border-bottom-color: rgba(255, 255, 255, 0.28);
            text-align: center;
        }

        .grade-table .grade-category-header small {
            display: block;
            margin-top: 2px;
            color: rgba(255, 255, 255, 0.82);
            font-size: 0.67rem;
            font-weight: 700;
            letter-spacing: 0;
            text-transform: none;
        }

        .grade-group-tone-1 { background: #166534 !important; }
        .grade-group-tone-2 { background: #087f5b !important; }
        .grade-group-tone-3 { background: #0f766e !important; }
        .grade-group-tone-4 { background: #3f6212 !important; }

        .grade-table .assessment-header {
            min-width: 116px;
            max-width: 150px;
            background: #edf7f0;
            color: #214e31;
            line-height: 1.25;
            white-space: normal;
        }

        .grade-table .assessment-header .small-label {
            margin-top: 3px;
            color: #54715d;
        }

        .grade-table .category-total-heading {
            min-width: 112px;
            background: #e7eef5;
            color: #40536a;
        }

        .score-input {
            width: 78px;
            min-width: 78px;
            height: 34px;
            margin: 0 auto;
            text-align: center;
            padding-left: 6px;
            padding-right: 6px;
            border-color: #b7c8bc;
            border-radius: 6px;
            background: #fffef4;
            font-weight: 700;
        }

        .score-cell {
            padding: 4px !important;
            background: #fff;
        }

        .score-input:focus {
            border-color: var(--grade-green);
            box-shadow: 0 0 0 0.16rem rgba(22, 101, 52, 0.15);
        }

        .computed-cell {
            min-width: 90px;
            background: #eef6ff !important;
            color: #244b74;
            font-weight: 700;
        }

        .final-cell {
            min-width: 100px;
            background: #dcfce7 !important;
            color: #14532d;
            font-weight: 800;
        }

        .grade-table tbody .final-cell {
            font-size: 1rem;
        }

        .grade-progress-cell {
            min-width: 142px;
            background: #fff;
            text-align: left !important;
        }

        .grade-progress-cell small {
            display: block;
            margin-top: 4px;
            color: var(--grade-muted);
            font-size: 0.7rem;
        }

        .progress-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            background: #eef2f7;
            color: #526174;
            font-size: 0.68rem;
            font-weight: 800;
        }

        .progress-status.on_track,
        .progress-status.completed {
            background: #e9f8f1;
            color: #137052;
        }

        .progress-status.at_risk,
        .progress-status.needs_improvement {
            background: #fff0e8;
            color: #a74416;
        }

        .autosave-status {
            padding: 6px 10px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .autosave-status.saving { background: #fff7df; color: #a86608; }
        .autosave-status.saved { background: #eaf8f1; color: #168865; }
        .autosave-status.error { background: #fff0f2; color: #b42f45; }

        .score-input.saving { opacity: 0.6; }
        .score-input.saved { border-color: #42b388; background: #effbf6; }

        .small-label {
            display: block;
            color: #6c757d;
            font-size: 0.72rem;
            font-weight: 600;
        }

        .column-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            margin: 2px;
            background: #fff;
        }

        .grade-folder-card {
            border-left: 4px solid #198754;
        }

        .class-record-header {
            gap: 12px;
            padding: 14px 16px;
            background: linear-gradient(90deg, #f7fbf8 0%, #fff 60%);
        }

        .class-record-heading {
            min-width: 220px;
        }

        .class-record-heading .card-title {
            float: none;
            display: block;
            color: var(--grade-green-dark);
            font-weight: 800;
        }

        .class-record-legend {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-left: auto;
            color: var(--grade-muted);
            font-size: 0.75rem;
        }

        .legend-swatch {
            width: 11px;
            height: 11px;
            display: inline-block;
            margin-right: 4px;
            border: 1px solid #b7c8bc;
            border-radius: 3px;
            vertical-align: -1px;
        }

        .legend-swatch.input { background: #fffef4; }
        .legend-swatch.computed { background: #eef6ff; }

        .grade-print-button {
            white-space: nowrap;
        }

        body.dark-mode .grade-page-title,
        body.dark-mode .sheet-context-value,
        body.dark-mode .grade-summary .info-box-number,
        body.dark-mode .grade-progress-card strong {
            color: #f1f5f9;
        }

        body.dark-mode .grade-sheet-selector,
        body.dark-mode .class-record-header,
        body.dark-mode .grade-progress-card {
            background: #303733;
        }

        body.dark-mode .grade-table-wrap,
        body.dark-mode .grade-table .sticky-name {
            background: #363636;
        }

        body.dark-mode .grade-table tbody tr:nth-child(even) td {
            background-color: #303633;
        }

        body.dark-mode .grade-table tbody tr:hover td {
            background-color: #3b463f;
        }

        body.dark-mode .grade-table .sticky-index {
            background: #303633;
        }

        body.dark-mode .grade-table .assessment-header {
            background: #304238;
            color: #d9fbe4;
        }

        body.dark-mode .grade-table .assessment-header .small-label {
            color: #b4c9bb !important;
        }

        body.dark-mode .score-input {
            background: #454536 !important;
            color: #fff !important;
        }

        body.dark-mode .computed-cell {
            background: #293c4e !important;
            color: #dbeafe;
        }

        body.dark-mode .final-cell {
            background: #244431 !important;
            color: #dcfce7;
        }

        .collapse-toggle {
            color: #198754;
            font-weight: 800;
            text-decoration: none;
        }

        .collapse-toggle:hover {
            color: #0f5132;
            text-decoration: none;
        }

        @media (max-width: 768px) {
            .grade-table-wrap {
                max-height: none;
            }

            .grade-toolbar {
                align-items: stretch;
            }

            .sheet-context {
                grid-template-columns: 1fr;
            }

            .grade-progress-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .sheet-context-item {
                padding-top: 3px;
                padding-bottom: 3px;
            }

            .class-record-header,
            .class-record-legend {
                align-items: stretch !important;
                flex-direction: column;
            }

            .class-record-legend {
                width: 100%;
                margin-left: 0;
                gap: 5px;
            }

            .class-record-tools-header,
            .class-record-actions {
                align-items: stretch;
                flex-direction: column;
                margin-left: 0;
            }

            .class-record-actions .btn,
            .class-record-actions form {
                width: 100%;
            }
        }

        @media (max-width: 520px) {
            .grade-progress-grid {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            .main-header,
            .main-sidebar,
            .content-header,
            .grade-sheet-selector,
            .grade-summary,
            .grade-progress-grid,
            .class-record-tools-header,
            .class-record-legend,
            .grade-print-button,
            .main-footer {
                display: none !important;
            }

            .content-wrapper {
                margin-left: 0 !important;
                background: #fff !important;
            }

            .content {
                padding: 0 !important;
            }

            .grade-card {
                border: 0;
                box-shadow: none;
            }

            .grade-table-wrap {
                max-height: none;
                overflow: visible;
                border: 0;
            }

            .grade-table {
                width: 100%;
                min-width: 0;
                font-size: 8pt;
            }

            .grade-table thead th,
            .grade-table .sticky-index,
            .grade-table .sticky-name {
                position: static;
            }

            .score-input {
                width: 52px;
                min-width: 52px;
                height: 26px;
                padding: 2px;
                border: 0;
            }

            @page {
                size: landscape;
                margin: 8mm;
            }
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <?php include './include/header-notifications.php'; ?>
        </ul>
    </nav>

    <?php include 'adminlte-sidebar.php'; ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="grade-toolbar">
                    <div class="grade-page-heading">
                        <span class="grade-page-icon"><i class="fas fa-file-invoice"></i></span>
                        <div>
                            <div class="grade-page-kicker">Smart NSTP Management</div>
                            <h1 class="grade-page-title mb-1">Grading Sheet</h1>
                            <div class="text-muted small">
                                <?php echo htmlspecialchars(ucfirst($userRole)); ?> workspace
                                <?php if ($currentProgram): ?>
                                    · <?php echo htmlspecialchars($currentProgram); ?>
                                <?php endif; ?>
                                · <?php echo count($students); ?> student(s)
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <?php foreach ($messages as $message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($message); ?>
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                <?php endforeach; ?>

                <?php if ($scoreWeight > 100.001): ?>
                    <div class="alert alert-warning" role="alert">
                        <i class="fas fa-triangle-exclamation mr-1"></i>
                        Category weights total <?php echo formatGradeNumber($scoreWeight, 2); ?>%. Smart NSTP grading requires a 100% total; open <strong>Settings</strong> and correct the category weights.
                    </div>
                <?php endif; ?>

                <div class="card grade-card grade-folder-card grade-sheet-selector">
                    <div class="card-body py-3">
                        <form method="GET" class="row align-items-end">
                            <div class="col-lg-5 col-md-6 mb-3 mb-md-0">
                                <label class="mb-1 font-weight-bold" for="grade_folder">
                                    <i class="fas fa-folder-open mr-1 text-success"></i>Select Class Record
                                </label>
                                <select class="form-control" id="grade_folder" name="grade_folder" onchange="this.form.submit()" <?php echo empty($gradeFolderOptions) ? 'disabled' : ''; ?>>
                                    <?php if (empty($gradeFolderOptions)): ?>
                                        <option value="">No assigned folders</option>
                                    <?php else: ?>
                                        <?php if ($selectedGradeFolder === '' && count($gradeFolderOptions) > 1): ?>
                                            <option value="" selected disabled>Choose a folder for grading</option>
                                        <?php endif; ?>
                                        <?php foreach ($gradeFolderOptions as $option): ?>
                                            <?php
                                                $isSelected = $option['folder'] === $selectedGradeFolder
                                                    && (int) $option['facilitator_id'] === (int) $selectedGradeFacilitatorId;
                                            ?>
                                            <option value="<?php echo htmlspecialchars($option['key']); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($option['label']); ?> (<?php echo (int) $option['student_count']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-lg-7 col-md-6">
                                <div class="sheet-context">
                                    <div class="sheet-context-item">
                                        <span class="sheet-context-label">Program</span>
                                        <span class="sheet-context-value"><?php echo htmlspecialchars($currentProgram ?: 'NSTP'); ?></span>
                                    </div>
                                    <div class="sheet-context-item">
                                        <span class="sheet-context-label">Active Class</span>
                                        <span class="sheet-context-value" title="<?php echo htmlspecialchars($selectedGradeFolderLabel); ?>"><?php echo htmlspecialchars($selectedGradeFolderLabel); ?></span>
                                    </div>
                                    <div class="sheet-context-item">
                                        <span class="sheet-context-label">Prepared by</span>
                                        <span class="sheet-context-value"><?php echo htmlspecialchars($userName); ?></span>
                                    </div>
                                </div>
                                <?php if ($selectedGradeFolder === '' && count($gradeFolderOptions) > 1): ?>
                                    <div class="small text-danger mt-2">Select one class record first so students do not get mixed.</div>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="grade-progress-grid" aria-label="Gradebook progress summary">
                    <article class="grade-progress-card">
                        <span>Students</span><strong><?php echo (int) $gradebookMetrics['students']; ?></strong><small>Enrolled in the active class</small>
                    </article>
                    <article class="grade-progress-card on-track">
                        <span>On track</span><strong><?php echo (int) $gradebookMetrics['on_track']; ?></strong><small>Current standing meets the target</small>
                    </article>
                    <article class="grade-progress-card attention">
                        <span>Needs attention</span><strong><?php echo (int) $gradebookMetrics['needs_attention']; ?></strong><small>Below target or incomplete</small>
                    </article>
                    <article class="grade-progress-card completed">
                        <span>Completed</span><strong><?php echo (int) $gradebookMetrics['completed']; ?></strong><small>All score items recorded</small>
                    </article>
                    <article class="grade-progress-card">
                        <span>Encoding progress</span><strong><?php echo formatGradeNumber($gradebookMetrics['average_completion'], 1); ?>%</strong><small>Average score-item completion</small>
                    </article>
                </div>

                <?php if ($canManageColumns): ?>
                    <div class="card grade-card">
                        <div class="card-header class-record-tools-header">
                            <div class="class-record-title">
                                <button type="button"
                                        class="btn btn-link btn-sm p-0 collapse-toggle"
                                        data-toggle="collapse"
                                        data-target="#activeColumnsPanel"
                                        aria-expanded="false"
                                        aria-controls="activeColumnsPanel">
                                    <i class="fas fa-chevron-right mr-1" id="activeColumnsIcon"></i>
                                    <i class="fas fa-list mr-2"></i>Score Items
                                </button>
                                <div class="small text-muted mt-1">Coordinator controls for the active grading sheet.</div>
                            </div>
                            <div class="class-record-actions">
                                <button type="button" class="btn btn-primary btn-sm" id="addScoreItemBtn" data-toggle="modal" data-target="#addColumnModal">
                                    <i class="fas fa-plus mr-1"></i>Add Score Item
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-outline-success btn-sm"
                                    id="addManualAttendanceBtn"
                                    data-toggle="modal"
                                    data-target="#addColumnModal"
                                    data-max-score="<?php echo (int) $totalMeetings; ?>">
                                    <i class="fas fa-user-check mr-1"></i>Add Manual Attendance
                                </button>
                                <button class="btn btn-outline-secondary btn-sm" data-toggle="modal" data-target="#settingsModal">
                                    <i class="fas fa-sliders-h mr-1"></i>Settings
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Create a new blank class record? This will remove all current columns from your view. Saved scores remain in the database but will be hidden with the removed columns.');">
                                    <input type="hidden" name="action" value="new_class_record">
                                    <button class="btn btn-outline-danger btn-sm">
                                        <i class="fas fa-file-circle-plus mr-1"></i>New Record
                                    </button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="restore_default_record">
                                    <button class="btn btn-outline-info btn-sm">
                                        <i class="fas fa-rotate-left mr-1"></i>Restore
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="card-body collapse" id="activeColumnsPanel">
                            <?php if ($systemAttendanceEnabled): ?>
                                <span class="column-chip">
                                    <i class="fas fa-clock text-success"></i>
                                    System Attendance
                                    <?php if ($activeSystemAttendanceColumn): ?>
                                        <small class="text-muted">· <?php echo htmlspecialchars($activeSystemAttendanceColumn['group_label']); ?> · <?php echo formatGradeNumber($activeSystemAttendanceColumn['weight_percent'], 2); ?>% · System</small>
                                    <?php endif; ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove System Attendance from this grading sheet? Attendance records and saved manual scores will be kept.');">
                                        <input type="hidden" name="action" value="remove_system_attendance">
                                        <button class="btn btn-xs btn-link text-danger p-0" title="Remove System Attendance">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                </span>
                            <?php endif; ?>
                            <?php $editableGradeColumns = array_values(array_filter($gradeColumns, fn($column) => !isSystemAttendanceColumn($column))); ?>
                            <?php if (empty($editableGradeColumns)): ?>
                                <span class="text-muted"><?php echo $systemAttendanceEnabled ? 'No manual score items yet.' : 'No score items yet. Add a column to start a new class record.'; ?></span>
                            <?php else: ?>
                                <form method="POST" id="deleteSelectedColumnsForm" class="mb-2" onsubmit="return confirm('Remove the selected columns from the current class record? Existing scores will be kept in the database but hidden with the removed columns.');">
                                    <input type="hidden" name="action" value="delete_selected_columns">
                                    <div id="selectedColumnsPayload"></div>
                                    <div class="d-flex flex-wrap align-items-center" style="gap: 8px;">
                                        <div class="custom-control custom-checkbox mr-2">
                                            <input type="checkbox" class="custom-control-input" id="selectAllGradeColumns">
                                            <label class="custom-control-label" for="selectAllGradeColumns">Select all</label>
                                        </div>
                                        <button class="btn btn-sm btn-outline-danger" id="deleteSelectedColumnsBtn" disabled>
                                            <i class="fas fa-trash mr-1"></i>Delete Selected
                                        </button>
                                    </div>
                                </form>
                                <?php foreach ($editableGradeColumns as $column): ?>
                                    <span class="column-chip">
                                        <input
                                            type="checkbox"
                                            class="grade-column-checkbox"
                                            value="<?php echo (int) $column['grade_column_id']; ?>">
                                        <?php echo htmlspecialchars($column['label']); ?>
                                        <small class="text-muted">
                                            /<?php echo formatGradeNumber($column['max_score'], 0); ?>
                                            · <?php echo formatGradeNumber($column['weight_percent'], 0); ?>%
                                            · <?php echo htmlspecialchars($column['group_label']); ?>
                                            - <?php echo (int) $column['is_default'] === 1 ? 'Default' : 'Custom'; ?>
                                        </small>
                                        <button
                                            type="button"
                                            class="btn btn-xs btn-link text-primary p-0 edit-column-btn"
                                            title="Edit column"
                                            data-toggle="modal"
                                            data-target="#editColumnModal"
                                            data-column-id="<?php echo (int) $column['grade_column_id']; ?>"
                                            data-label="<?php echo htmlspecialchars($column['label']); ?>"
                                            data-group-label="<?php echo htmlspecialchars($column['group_label']); ?>"
                                            data-max-score="<?php echo htmlspecialchars((string) $column['max_score']); ?>"
                                            data-weight-percent="<?php echo htmlspecialchars((string) $column['weight_percent']); ?>"
                                            data-manual-attendance="<?php echo isManualAttendanceColumn($column) ? '1' : '0'; ?>">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this column from the current class record? Existing scores will be kept in the database but hidden with the removed column.');">
                                            <input type="hidden" name="action" value="delete_column">
                                            <input type="hidden" name="column_id" value="<?php echo (int) $column['grade_column_id']; ?>">
                                            <button class="btn btn-xs btn-link text-danger p-0" title="Remove column">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?php if ($canComputeGrades): ?>
                        <input type="hidden" name="action" value="save_scores">
                    <?php endif; ?>
                    <div class="card grade-card">
                        <div class="card-header class-record-header d-flex align-items-center flex-wrap">
                            <div class="class-record-heading">
                                <h3 class="card-title mb-0"><i class="fas fa-table mr-2"></i>Student Class Record</h3>
                                <div class="small text-muted mt-1">Type a raw score, then press Enter or click outside to save automatically.</div>
                            </div>
                            <div class="class-record-legend">
                                <span><i class="legend-swatch input"></i>Editable score</span>
                                <span><i class="legend-swatch computed"></i>Auto-computed</span>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm grade-print-button" onclick="window.print()">
                                <i class="fas fa-print mr-1"></i>Print Sheet
                            </button>
                            <?php if ($canComputeGrades): ?>
                                <span id="grade-save-status" class="autosave-status">Ready</span>
                            <?php else: ?>
                                <span class="badge badge-secondary px-3 py-2">
                                    <i class="fas fa-eye mr-1"></i>View Only
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-0">
                            <div class="grade-table-wrap">
                                <table class="table table-bordered table-hover grade-table">
                                    <thead>
                                        <tr class="grade-category-row">
                                            <th rowspan="3" class="sticky-index">#</th>
                                            <th rowspan="3" class="sticky-name">Student</th>
                                            <?php foreach ($gradeGroups as $groupCode => $group): ?>
                                                <th colspan="<?php echo (int) $group['count'] + 2; ?>" class="grade-category-header <?php echo htmlspecialchars($gradeGroupTones[$groupCode]); ?>">
                                                    <?php echo htmlspecialchars($group['label']); ?>
                                                    · <?php echo formatGradeNumber($group['weight'], 2); ?>%
                                                </th>
                                            <?php endforeach; ?>
                                            <th rowspan="3" class="computed-cell">Recorded<br>Weighted</th>
                                            <th rowspan="3" class="final-cell">Computed<br>Grade</th>
                                            <th rowspan="3" class="category-total-heading">Progress</th>
                                        </tr>
                                        <tr class="grade-assessment-row">
                                            <?php foreach ($gradeGroups as $groupCode => $group): ?>
                                                <?php foreach ($activeGradeColumns as $column): ?>
                                                    <?php if ($column['group_code'] !== $groupCode) continue; ?>
                                                    <th class="assessment-header">
                                                        <?php echo htmlspecialchars($column['label']); ?>
                                                    </th>
                                                <?php endforeach; ?>
                                                <th class="category-total-heading">TS</th>
                                                <th class="category-total-heading">Weighted</th>
                                            <?php endforeach; ?>
                                        </tr>
                                        <tr class="grade-max-row">
                                            <?php foreach ($gradeGroups as $groupCode => $group): ?>
                                                <?php foreach ($activeGradeColumns as $column): ?>
                                                    <?php if ($column['group_code'] !== $groupCode) continue; ?>
                                                    <th><?php echo formatGradeNumber($column['max_score'], 2); ?></th>
                                                <?php endforeach; ?>
                                                <th><?php echo formatGradeNumber($group['max'], 2); ?></th>
                                                <th><?php echo formatGradeNumber($group['weight'], 2); ?>%</th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($students)): ?>
                                            <tr>
                                                <td colspan="<?php echo $gradeTableColumnCount; ?>" class="text-center text-muted py-5">
                                                    <i class="fas fa-user-graduate fa-2x d-block mb-2"></i>
                                                    No students available for this account.
                                                </td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($students as $studentIndex => $student): ?>
                                            <?php
                                            $studentId = (int) $student['tbl_student_id'];
                                            $studentDisplayName = gradeDisplayStudentName($student);
                                            $attendanceCount = min($totalMeetings, $attendanceCounts[$studentId] ?? 0);
                                            $summary = $gradeSummariesByStudent[$studentId];
                                            ?>
                                            <tr class="grade-row" data-attendance-count="<?php echo (int) $attendanceCount; ?>">
                                                <td class="sticky-index"><?php echo $studentIndex + 1; ?></td>
                                                <td class="sticky-name">
                                                    <strong><?php echo htmlspecialchars($studentDisplayName); ?></strong>
                                                    <?php if (!empty($student['student_number'])): ?>
                                                        <span class="small-label"><?php echo htmlspecialchars($student['student_number']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php foreach ($gradeGroups as $groupCode => $group): ?>
                                                    <?php foreach ($activeGradeColumns as $column): ?>
                                                        <?php if ($column['group_code'] !== $groupCode) continue; ?>
                                                        <?php
                                                        $columnId = (int) $column['grade_column_id'];
                                                        $value = $scoresByStudent[$studentId][$columnId] ?? '';
                                                        ?>
                                                        <?php if (isSystemAttendanceColumn($column)): ?>
                                                            <td class="computed-cell"><?php echo (int) $attendanceCount; ?> / <?php echo (int) $totalMeetings; ?></td>
                                                        <?php else: ?>
                                                        <td class="score-cell">
                                                            <input
                                                                type="number"
                                                                class="form-control form-control-sm score-input"
                                                                value="<?php echo htmlspecialchars((string) $value); ?>"
                                                                min="0"
                                                                max="<?php echo htmlspecialchars((string) $column['max_score']); ?>"
                                                                data-max="<?php echo htmlspecialchars((string) $column['max_score']); ?>"
                                                                data-group="<?php echo htmlspecialchars($column['group_code']); ?>"
                                                                data-column="<?php echo $columnId; ?>"
                                                                data-student="<?php echo $studentId; ?>"
                                                                step="0.01"
                                                                <?php echo $canComputeGrades ? '' : 'readonly disabled'; ?>>
                                                        </td>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                    <td class="computed-cell" data-group-total="<?php echo htmlspecialchars($groupCode); ?>"><?php echo formatGradeNumber($summary['groups'][$groupCode]['earned']); ?> / <?php echo formatGradeNumber($summary['groups'][$groupCode]['maximum']); ?></td>
                                                    <td class="computed-cell" data-group-weighted="<?php echo htmlspecialchars($groupCode); ?>"><?php echo formatGradeNumber($summary['groups'][$groupCode]['weighted']); ?></td>
                                                <?php endforeach; ?>
                                                <td class="computed-cell js-weighted-percent"><?php echo formatGradeNumber($summary['weighted_percent']); ?>%</td>
                                                <td class="final-cell js-final-grade"><?php echo formatGradeNumber($summary['final_grade']); ?></td>
                                                <td class="grade-progress-cell">
                                                    <span class="progress-status <?php echo htmlspecialchars($summary['progress_status']); ?>" data-progress-status><?php echo htmlspecialchars($summary['progress_label']); ?></span>
                                                    <small data-progress-detail><?php echo formatGradeNumber($summary['completion_percentage'], 0); ?>% encoded · <?php echo (int) $summary['pending_count']; ?> pending</small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer text-muted">
                            Smart NSTP formula: each category applies its configured weight. System Attendance is an auto-computed score item inside its selected category; manual attendance items in that category share the same percentage.
                        </div>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <?php include 'footer.php'; ?>
</div>

<?php if ($canManageColumns): ?>
<div class="modal fade" id="addColumnModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add_column">
            <input type="hidden" name="score_item_type" id="addColumnType" value="score">
            <div class="modal-header">
                <h5 class="modal-title" id="addColumnModalTitle"><i class="fas fa-plus mr-2"></i>Add Score Item</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Score Item</label>
                    <select id="addColumnItemType" class="form-control">
                        <option value="score">Custom Score Item</option>
                        <option value="manual_attendance">Manual Attendance</option>
                        <option value="system_attendance" <?php echo $systemAttendanceEnabled ? 'disabled' : ''; ?>><?php echo $systemAttendanceEnabled ? 'System Attendance (already added)' : 'System Attendance'; ?></option>
                    </select>
                </div>
                <div class="alert alert-info py-2 d-none" id="systemAttendanceItemHelp">
                    <strong>System-generated score:</strong> attendance records supply the score automatically. Choose the category that will control its percentage.
                </div>
                <div class="form-group" id="addColumnNameGroup">
                    <label>Score Item Name</label>
                    <input type="text" name="label" id="addColumnLabel" class="form-control" maxlength="160" placeholder="Example: Reflection Paper" required>
                </div>
                <div class="alert alert-success py-2 d-none" id="manualAttendanceGroupingHelp">
                    <strong>Shared attendance percentage:</strong> this manual score is combined with system attendance and uses the same Attendance percentage.
                </div>
                <div class="form-group" id="addColumnCategoryGroup">
                    <label>Category / Percentage Group</label>
                    <select name="group_label_select" id="addColumnGroupSelect" class="form-control" required>
                        <option value="" selected disabled>Choose a category</option>
                        <?php if (empty($gradeCategoryOptions)): ?>
                            <option value="Additional Requirements">Additional Requirements</option>
                        <?php else: ?>
                            <?php foreach ($gradeCategoryOptions as $categoryLabel): ?>
                                <?php $categoryWeight = gradeExistingCategoryWeight($gradeColumns, $categoryLabel); ?>
                                <option value="<?php echo htmlspecialchars($categoryLabel); ?>" data-weight="<?php echo htmlspecialchars((string) $categoryWeight); ?>"><?php echo htmlspecialchars($categoryLabel); ?> — shares <?php echo formatGradeNumber($categoryWeight, 2); ?>%</option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <option value="__new">Own percentage (create new category)</option>
                    </select>
                    <input type="text" name="group_label_new" id="addColumnGroupNew" class="form-control mt-2 d-none" maxlength="120" placeholder="New category name">
                    <small class="text-muted" id="addColumnCategoryHelp">Choose an existing category to share that weight, or create a new category for a separate percentage.</small>
                </div>
                <div class="form-row" id="addColumnScoringGroup">
                    <div class="form-group col-md-6" id="addColumnMaxScoreGroup">
                        <label>Max Score</label>
                        <input type="number" name="max_score" id="addColumnMaxScore" class="form-control" min="1" step="0.01" value="100" required>
                    </div>
                    <div class="form-group col-md-6" id="addColumnWeightGroup">
                        <label>Category Weight %</label>
                        <input type="number" name="weight_percent" id="addColumnWeightPercent" class="form-control" min="1" step="0.01" value="10" required>
                        <small class="text-muted" id="addColumnWeightHelp">Set the percentage for the new category.</small>
                    </div>
                </div>
                <div class="alert alert-info mb-0" id="addColumnScopeHelp">
                    Added columns are scoped to your current component<?php echo $currentProgram ? ' (' . htmlspecialchars($currentProgram) . ')' : ''; ?> and are included in the weighted grade.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="addColumnSubmitBtn"><i class="fas fa-plus mr-1"></i>Add Score Item</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editColumnModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="edit_column">
            <input type="hidden" name="column_id" id="editColumnId">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-pen mr-2"></i>Edit Grade Column</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Score Item</label>
                    <input type="text" name="label" id="editColumnLabel" class="form-control" maxlength="160" required>
                </div>
                <div class="form-group" id="editColumnCategoryGroup">
                    <label>Category / Percentage Group</label>
                    <select name="group_label_select" id="editColumnGroupSelect" class="form-control">
                        <?php if (empty($gradeCategoryOptions)): ?>
                            <option value="Additional Requirements">Additional Requirements</option>
                        <?php else: ?>
                            <?php foreach ($gradeCategoryOptions as $categoryLabel): ?>
                                <?php $categoryWeight = gradeExistingCategoryWeight($gradeColumns, $categoryLabel); ?>
                                <option value="<?php echo htmlspecialchars($categoryLabel); ?>" data-weight="<?php echo htmlspecialchars((string) $categoryWeight); ?>"><?php echo htmlspecialchars($categoryLabel); ?> — shares <?php echo formatGradeNumber($categoryWeight, 2); ?>%</option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <option value="__new">Own percentage (create new category)</option>
                    </select>
                    <input type="text" name="group_label_new" id="editColumnGroupNew" class="form-control mt-2 d-none" maxlength="120" placeholder="New category name">
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Max Score</label>
                        <input type="number" name="max_score" id="editColumnMaxScore" class="form-control" min="1" step="0.01" required>
                    </div>
                    <div class="form-group col-md-6" id="editColumnWeightGroup">
                        <label>Category Weight %</label>
                        <input type="number" name="weight_percent" id="editColumnWeightPercent" class="form-control" min="1" step="0.01" required>
                        <small class="text-muted">Moving this item to an existing category uses that category's weight.</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save Column</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="settingsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-xl" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="settings">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sliders-h mr-2"></i>Grade Settings</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="form-group col-lg-4">
                        <label>Total Attendance Meetings</label>
                        <input type="number" name="total_meetings" class="form-control" min="1" value="<?php echo (int) $totalMeetings; ?>" required>
                        <small class="text-muted">This is the maximum score of the System Attendance score item.</small>
                    </div>
                    <div class="col-lg-8">
                        <label class="d-block">1.00–5.00 Transmutation</label>
                        <div class="form-row">
                            <div class="form-group col-md-3"><label class="small">Passing %</label><input type="number" name="passing_percentage" class="form-control" min="1" max="99.99" step="0.01" value="<?php echo htmlspecialchars((string) $passingPercentage); ?>" required></div>
                            <div class="form-group col-md-3"><label class="small">Highest Grade</label><input type="number" name="highest_grade" class="form-control" min="0" max="5" step="0.01" value="<?php echo htmlspecialchars((string) $highestGrade); ?>" required></div>
                            <div class="form-group col-md-3"><label class="small">Passing Grade</label><input type="number" name="passing_grade" class="form-control" min="0" max="5" step="0.01" value="<?php echo htmlspecialchars((string) $passingGrade); ?>" required></div>
                            <div class="form-group col-md-3"><label class="small">Failing Grade</label><input type="number" name="failing_grade" class="form-control" min="0" max="5" step="0.01" value="<?php echo htmlspecialchars((string) $failingGrade); ?>" required></div>
                        </div>
                        <small class="text-muted">Default: below 75% = 5.00; 75% = 3.00; 100% = 1.00.</small>
                    </div>
                </div>
                <?php if (!empty($gradeGroups)): ?>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <strong>Grading Categories</strong>
                        <span class="small text-muted">Total configured weight: <?php echo formatGradeNumber($scoreWeight, 2); ?>%</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-3">
                            <thead class="thead-light">
                                <tr>
                                    <th style="min-width: 260px;">Category</th>
                                    <th style="width: 180px;">Weight %</th>
                                    <th style="width: 160px;">Score Items</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gradeGroups as $groupCode => $group): ?>
                                    <tr>
                                        <td>
                                            <input
                                                type="text"
                                                name="categories[<?php echo htmlspecialchars($groupCode); ?>][name]"
                                                class="form-control form-control-sm"
                                                maxlength="120"
                                                value="<?php echo htmlspecialchars($group['label']); ?>"
                                                required>
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                name="categories[<?php echo htmlspecialchars($groupCode); ?>][weight]"
                                                class="form-control form-control-sm"
                                                min="0"
                                                max="100"
                                                step="0.01"
                                                value="<?php echo htmlspecialchars((string) $group['weight']); ?>"
                                                required>
                                        </td>
                                        <td class="align-middle"><?php echo (int) $group['count']; ?> item(s)</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="alert alert-info mb-0">
                    System Attendance follows the weight of its selected category. Manual attendance items in that category share the same percentage. You can remove System Attendance and add it again later under another category.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save Settings</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script>
function formatNumber(value) {
    return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

const $saveStatus = $('#grade-save-status');
let activeGradeSave = 0;

$('.score-input').each(function() {
    this.dataset.savedValue = this.value;
}).on('keydown', function(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        this.blur();
    }
}).on('change', async function() {
    const input = this;
    if (input.value === input.dataset.savedValue) return;

    const max = Number(input.max || 0);
    const numericValue = input.value === '' ? null : Number(input.value);
    if (numericValue !== null && (!Number.isFinite(numericValue) || numericValue < 0 || numericValue > max)) {
        input.value = input.dataset.savedValue;
        $saveStatus.text('Score must be between 0 and ' + formatNumber(max)).attr('class', 'autosave-status error');
        return;
    }

    const requestId = ++activeGradeSave;
    input.classList.add('saving');
    $saveStatus.text('Saving…').attr('class', 'autosave-status saving');

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Accept': 'application/json'},
            body: new URLSearchParams({
                action: 'save_score_ajax',
                student_id: input.dataset.student,
                column_id: input.dataset.column,
                score: input.value
            })
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Unable to save score.');

        input.dataset.savedValue = input.value;
        const $row = $(input).closest('.grade-row');
        $row.find('.js-weighted-percent').text(formatNumber(data.percentage) + '%');
        $row.find('.js-final-grade').text(formatNumber(data.grade));
        $row.find('[data-progress-status]')
            .text(data.progress_label)
            .attr('class', 'progress-status ' + data.progress_status);
        $row.find('[data-progress-detail]').text(
            Number(data.completion_percentage).toFixed(0) + '% encoded · ' + data.pending_count + ' pending'
        );

        Object.entries(data.groups || {}).forEach(function([groupCode, group]) {
            $row.find('[data-group-total="' + groupCode + '"]').text(formatNumber(group.earned) + ' / ' + formatNumber(group.maximum));
            $row.find('[data-group-weighted="' + groupCode + '"]').text(formatNumber(group.weighted));
        });
        input.classList.add('saved');
        window.setTimeout(() => input.classList.remove('saved'), 900);
        if (requestId === activeGradeSave) {
            $saveStatus.text('All changes saved').attr('class', 'autosave-status saved');
        }
    } catch (error) {
        input.value = input.dataset.savedValue;
        $saveStatus.text(error.message).attr('class', 'autosave-status error');
    } finally {
        input.classList.remove('saving');
    }
});

function syncNewCategoryInput(prefix) {
    const $select = $('#' + prefix + 'ColumnGroupSelect');
    const $weight = prefix === 'edit' ? $('#editColumnWeightPercent') : $('input[name="weight_percent"]', '#addColumnModal');
    const isNew = $select.val() === '__new';
    const categoryWeight = $select.find('option:selected').data('weight');
    $('#' + prefix + 'ColumnGroupNew')
        .toggleClass('d-none', !isNew)
        .prop('required', isNew);

    if (!isNew && categoryWeight !== undefined && categoryWeight !== '') {
        $weight.val(categoryWeight).attr('min', 1).prop('readonly', true);
        if (prefix === 'add') {
            $('#addColumnWeightHelp').text('Included under the selected category and uses its existing ' + categoryWeight + '% weight.');
        }
    } else {
        $weight.attr('min', 1).prop('readonly', false);
        if (prefix === 'add') {
            $('#addColumnWeightHelp').text('Set a separate percentage for this new category.');
        }
    }

}

$('#addColumnGroupSelect, #editColumnGroupSelect').on('change', function() {
    syncNewCategoryInput(this.id.indexOf('edit') === 0 ? 'edit' : 'add');
});

function configureAddScoreItemType(type) {
    const isManualAttendance = type === 'manual_attendance';
    const isSystemAttendance = type === 'system_attendance';
    const choosesCategory = !isManualAttendance;

    $('#addColumnType').val(type);
    $('#addColumnItemType').val(type);
    $('#manualAttendanceGroupingHelp').toggleClass('d-none', !isManualAttendance);
    $('#systemAttendanceItemHelp').toggleClass('d-none', !isSystemAttendance);
    $('#addColumnNameGroup').toggleClass('d-none', isSystemAttendance);
    $('#addColumnCategoryGroup').toggleClass('d-none', !choosesCategory);
    $('#addColumnScoringGroup').removeClass('d-none');
    $('#addColumnMaxScoreGroup').toggleClass('d-none', isSystemAttendance);
    $('#addColumnWeightGroup').toggleClass('d-none', isManualAttendance);
    $('#addColumnScopeHelp').removeClass('d-none');
    $('#addColumnLabel, #addColumnMaxScore').prop('disabled', isSystemAttendance).prop('required', !isSystemAttendance);
    $('#addColumnGroupSelect, #addColumnGroupNew, #addColumnWeightPercent').prop('disabled', !choosesCategory);

    if (isSystemAttendance) {
        $('#addColumnModalTitle').html('<i class="fas fa-clock mr-2"></i>Add System Attendance');
        $('#addColumnSubmitBtn').html('<i class="fas fa-plus mr-1"></i>Add System Attendance');
        syncNewCategoryInput('add');
    } else if (isManualAttendance) {
        if (!$('#addColumnLabel').val()) {
            $('#addColumnLabel').val('Manual Attendance Score');
        }
        $('#addColumnMaxScore').val($('#addManualAttendanceBtn').data('max-score'));
        $('#addColumnModalTitle').html('<i class="fas fa-user-check mr-2"></i>Add Manual Attendance');
        $('#addColumnSubmitBtn').html('<i class="fas fa-plus mr-1"></i>Add Manual Attendance');
    } else {
        $('#addColumnModalTitle').html('<i class="fas fa-plus mr-2"></i>Add Score Item');
        $('#addColumnSubmitBtn').html('<i class="fas fa-plus mr-1"></i>Add Score Item');
        syncNewCategoryInput('add');
    }
}

$('#addColumnItemType').on('change', function() {
    configureAddScoreItemType(this.value);
});

$('#addScoreItemBtn').on('click', function() {
    const $select = $('#addColumnGroupSelect');
    $('#addColumnLabel').val('');
    $('#addColumnMaxScore').val(100);
    $('#addColumnWeightPercent').val(10);
    $('#addColumnGroupNew').val('');
    $select.prop('selectedIndex', 0);
    configureAddScoreItemType('score');
});

$('#addManualAttendanceBtn').on('click', function() {
    const $select = $('#addColumnGroupSelect');

    $('#addColumnLabel').val('Manual Attendance Score');
    $('#addColumnMaxScore').val($(this).data('max-score'));
    $('#addColumnWeightPercent').val(1);
    $select.val('__new');
    $('#addColumnGroupNew').val('Manual Attendance');
    configureAddScoreItemType('manual_attendance');
});

$('.edit-column-btn').on('click', function() {
    const groupLabel = String($(this).data('group-label') || '');
    const isManualAttendance = Number($(this).data('manual-attendance')) === 1;
    $('#editColumnId').val($(this).data('column-id'));
    $('#editColumnLabel').val($(this).data('label'));
    $('#editColumnMaxScore').val($(this).data('max-score'));
    $('#editColumnWeightPercent').val($(this).data('weight-percent'));

    if ($('#editColumnGroupSelect option').filter(function() { return this.value === groupLabel; }).length) {
        $('#editColumnGroupSelect').val(groupLabel);
        $('#editColumnGroupNew').val('');
    } else {
        $('#editColumnGroupSelect').val('__new');
        $('#editColumnGroupNew').val(groupLabel);
    }
    syncNewCategoryInput('edit');
    $('#editColumnCategoryGroup, #editColumnWeightGroup').toggleClass('d-none', isManualAttendance);
    $('#editColumnGroupSelect, #editColumnGroupNew, #editColumnWeightPercent').prop('disabled', isManualAttendance);
});

syncNewCategoryInput('add');
syncNewCategoryInput('edit');

function refreshSelectedColumnControls() {
    const checkedCount = $('.grade-column-checkbox:checked').length;
    const totalCount = $('.grade-column-checkbox').length;
    $('#deleteSelectedColumnsBtn').prop('disabled', checkedCount === 0);
    $('#selectAllGradeColumns').prop('checked', totalCount > 0 && checkedCount === totalCount);
}

$('.grade-column-checkbox').on('change', refreshSelectedColumnControls);

$('#selectAllGradeColumns').on('change', function() {
    $('.grade-column-checkbox').prop('checked', this.checked);
    refreshSelectedColumnControls();
});

$('#deleteSelectedColumnsForm').on('submit', function(event) {
    const selected = $('.grade-column-checkbox:checked').map(function() {
        return this.value;
    }).get();

    if (selected.length === 0) {
        event.preventDefault();
        return false;
    }

    const payload = $('#selectedColumnsPayload').empty();
    selected.forEach(function(columnId) {
        $('<input>', {
            type: 'hidden',
            name: 'column_ids[]',
            value: columnId
        }).appendTo(payload);
    });
});

$('#activeColumnsPanel')
    .on('show.bs.collapse', function() {
        $('#activeColumnsIcon').removeClass('fa-chevron-right').addClass('fa-chevron-down');
    })
    .on('hide.bs.collapse', function() {
        $('#activeColumnsIcon').removeClass('fa-chevron-down').addClass('fa-chevron-right');
    });
</script>
</body>
</html>
