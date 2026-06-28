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

function ensureGradeTables(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_grade_columns (
            grade_column_id INT AUTO_INCREMENT PRIMARY KEY,
            column_key VARCHAR(80) NOT NULL UNIQUE,
            label VARCHAR(160) NOT NULL,
            group_code VARCHAR(60) NOT NULL,
            group_label VARCHAR(120) NOT NULL,
            max_score DECIMAL(8,2) NOT NULL DEFAULT 0,
            weight_percent DECIMAL(8,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_grade_scores (
            grade_score_id INT AUTO_INCREMENT PRIMARY KEY,
            grade_column_id INT NOT NULL,
            tbl_student_id INT NOT NULL,
            score DECIMAL(8,2) NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_grade_score (grade_column_id, tbl_student_id),
            INDEX idx_grade_student (tbl_student_id),
            CONSTRAINT fk_grade_score_column FOREIGN KEY (grade_column_id)
                REFERENCES tbl_grade_columns (grade_column_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_grade_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_grade_column_visibility (
            grade_column_visibility_id INT AUTO_INCREMENT PRIMARY KEY,
            grade_column_id INT NOT NULL,
            user_id INT NOT NULL,
            program_scope VARCHAR(20) NOT NULL DEFAULT 'global',
            is_hidden TINYINT(1) NOT NULL DEFAULT 0,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_grade_column_visibility (grade_column_id, user_id, program_scope),
            INDEX idx_grade_column_visibility_user (user_id, program_scope),
            CONSTRAINT fk_grade_column_visibility_column FOREIGN KEY (grade_column_id)
                REFERENCES tbl_grade_columns (grade_column_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = $conn->query("SHOW COLUMNS FROM tbl_grade_columns")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('program_scope', $columns, true)) {
        $conn->exec("ALTER TABLE tbl_grade_columns ADD COLUMN program_scope VARCHAR(20) NULL AFTER column_key");
    }
    if (!in_array('updated_by', $columns, true)) {
        $conn->exec("ALTER TABLE tbl_grade_columns ADD COLUMN updated_by INT NULL AFTER created_by");
    }
    if (!in_array('updated_at', $columns, true)) {
        $conn->exec("ALTER TABLE tbl_grade_columns ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }
}

function slugGradeGroup($value) {
    $slug = strtolower(trim((string) $value));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    return $slug !== '' ? substr($slug, 0, 60) : 'additional';
}

function selectedGradeGroupLabel(array $source, $default = 'Additional Requirements') {
    $mode = trim((string) ($source['group_mode'] ?? 'existing'));
    $selected = trim((string) ($source['group_label_select'] ?? ''));
    $new = trim((string) ($source['group_label_new'] ?? ''));
    $fallback = trim((string) ($source['group_label'] ?? $default));

    if ($mode === 'new') {
        return $new !== '' ? $new : $default;
    }

    if ($selected !== '') {
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

function seedDefaultGradeColumns(PDO $conn) {
    $defaults = [
        ['bandage_head', 'Top of the head', 'bandaging', 'Bandaging Evaluation', 16, 15, 10],
        ['bandage_chest', 'Chest/Back', 'bandaging', 'Bandaging Evaluation', 16, 15, 20],
        ['bandage_hand_foot', 'Hand/Foot', 'bandaging', 'Bandaging Evaluation', 16, 15, 30],
        ['bandage_shoulder_hips', 'Shoulder/Hips (SEMI)', 'bandaging', 'Bandaging Evaluation', 16, 15, 40],
        ['bandage_elbow_knee', 'Elbow/Knee (SEMI)', 'bandaging', 'Bandaging Evaluation', 16, 15, 50],
        ['bandage_forehead', 'Forehead (narrow)', 'bandaging', 'Bandaging Evaluation', 16, 15, 60],
        ['bandage_ear_cheek_jaw', 'Ear/Cheek/Jaw (narrow)', 'bandaging', 'Bandaging Evaluation', 16, 15, 70],
        ['bandage_palm', 'Palm (narrow)', 'bandaging', 'Bandaging Evaluation', 16, 15, 80],
        ['bandage_forearm_leg', 'Forearm/Leg (narrow)', 'bandaging', 'Bandaging Evaluation', 16, 15, 90],
        ['carry_walking_assist', 'Walking assist', 'carrying', 'Carrying Evaluation', 24, 15, 110],
        ['carry_cradle', 'Cradle carry', 'carrying', 'Carrying Evaluation', 24, 15, 120],
        ['carry_pack_strap', 'Pack strap', 'carrying', 'Carrying Evaluation', 24, 15, 130],
        ['carry_firefighter', 'Firefighter', 'carrying', 'Carrying Evaluation', 24, 15, 140],
        ['carry_extremity', 'Extremity carry', 'carrying', 'Carrying Evaluation', 28, 15, 150],
        ['carry_swing', 'Swing carry', 'carrying', 'Carrying Evaluation', 28, 15, 160],
        ['carry_chair', 'Chair carry', 'carrying', 'Carrying Evaluation', 28, 15, 170],
        ['carry_hammock', 'Hammock carry', 'three_man_carry', '3-4 Man Carry', 28, 15, 190],
        ['carry_bearers', "Bearer's along side", 'three_man_carry', '3-4 Man Carry', 28, 15, 200],
        ['carry_blanket', 'Blanket carry', 'three_man_carry', '3-4 Man Carry', 28, 15, 210],
        ['carry_stretcher', 'Improvised stretcher', 'three_man_carry', '3-4 Man Carry', 28, 15, 220],
        ['spine_board', 'Spine Board Management', 'spine_board', 'Spine Board Equivalent', 32, 15, 240],
        ['cpr', 'CPR', 'cpr', 'CPR Equivalent', 40, 20, 260],
        ['proposal', 'Proposal', 'community', 'Community Immersion', 35, 40, 300],
        ['implementation', 'MRF and Beautification / Implementation', 'community', 'Community Immersion', 55, 60, 310],
    ];

    $stmt = $conn->prepare("
        INSERT IGNORE INTO tbl_grade_columns
            (column_key, label, group_code, group_label, max_score, weight_percent, sort_order, is_default, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)
    ");

    foreach ($defaults as $column) {
        $stmt->execute($column);
    }
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

function transmuteGrade($equivalentPoints, $denominator = 100) {
    $denominator = max((float) $denominator, 1);
    $grade = 5 - (4 / $denominator * (float) $equivalentPoints);
    return max(1, min(5, $grade));
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

        if ($group['has_custom'] || count($uniqueWeights) > 1) {
            $groups[$groupCode]['weight'] = array_sum($group['weights']);
        } else {
            $groups[$groupCode]['weight'] = (float) ($group['weights'][0] ?? 0);
        }
    }

    return $groups;
}

function computeStudentGradeSummary(array $gradeColumns, array $scores, array $gradeGroups, $attendanceCount, $totalMeetings, $attendanceWeight) {
    $rawTotal = 0;
    $maxTotal = 0;
    $weightedPoints = 0;
    $totalWeight = 0;
    $rawByGroup = [];

    foreach ($gradeColumns as $column) {
        $groupCode = $column['group_code'];
        if (!isset($rawByGroup[$groupCode])) {
            $rawByGroup[$groupCode] = 0;
        }

        $columnId = (int) $column['grade_column_id'];
        $score = $scores[$columnId] ?? null;
        $score = $score === null || $score === '' ? 0 : (float) $score;
        $rawByGroup[$groupCode] += $score;
        $rawTotal += $score;
        $maxTotal += (float) $column['max_score'];
    }

    foreach ($gradeGroups as $groupCode => $group) {
        $groupMax = max((float) $group['max'], 1);
        $groupPercent = (($rawByGroup[$groupCode] ?? 0) / $groupMax) * 100;
        $groupWeight = (float) $group['weight'];
        $weightedPoints += ($groupPercent / 100) * $groupWeight;
        $totalWeight += $groupWeight;
    }

    $attendanceWeight = max(0, (float) $attendanceWeight);
    if ($attendanceWeight > 0) {
        $attendancePercent = (min((int) $attendanceCount, (int) $totalMeetings) / max((int) $totalMeetings, 1)) * 100;
        $weightedPoints += ($attendancePercent / 100) * $attendanceWeight;
        $totalWeight += $attendanceWeight;
    }

    $scorePercent = $maxTotal > 0 ? ($rawTotal / $maxTotal) * 100 : 0;
    $weightedPercent = $totalWeight > 0 ? ($weightedPoints / $totalWeight) * 100 : $scorePercent;

    return [
        'raw_total' => $rawTotal,
        'max_total' => $maxTotal,
        'score_percent' => $scorePercent,
        'weighted_percent' => $weightedPercent,
        'total_weight' => $totalWeight,
        'final_grade' => transmuteGrade($weightedPercent),
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
    ];

    if (in_array($action, $columnManagementActions, true) && !$canManageColumns) {
        $errors[] = 'Only coordinators can edit grade columns and grade settings.';
    }

    if ($action === 'add_column' && $canManageColumns) {
        $label = trim($_POST['label'] ?? '');
        $groupLabel = selectedGradeGroupLabel($_POST);
        $maxScore = (float) ($_POST['max_score'] ?? 0);
        $weight = (float) ($_POST['weight_percent'] ?? 0);
        $groupLabel = $groupLabel !== '' ? $groupLabel : 'Additional Requirements';
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
                'custom_' . bin2hex(random_bytes(8)),
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
            $messages[] = 'New grade column added.';
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
        $messages[] = 'Current class record cleared. Add new columns to build a new grading sheet.';
    }

    if ($action === 'restore_default_record' && $canManageColumns) {
        unhideDefaultGradeColumnsForCurrentSheet($conn, $userId, $columnVisibilityScope);
        $messages[] = 'Default class record restored.';
    }

    if ($action === 'edit_column' && $canManageColumns) {
        $columnId = (int) ($_POST['column_id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $groupLabel = selectedGradeGroupLabel($_POST);
        $maxScore = (float) ($_POST['max_score'] ?? 0);
        $weight = (float) ($_POST['weight_percent'] ?? 0);
        $groupLabel = $groupLabel !== '' ? $groupLabel : 'Additional Requirements';

        if ($label === '') {
            $errors[] = 'Column name is required.';
        } elseif ($maxScore <= 0) {
            $errors[] = 'Max score must be greater than zero.';
        } elseif ($weight <= 0) {
            $errors[] = 'Weight percent must be greater than zero.';
        } else {
            $activeColumnLookup = [];
            foreach ($gradeColumns as $column) {
                $activeColumnLookup[(int) $column['grade_column_id']] = true;
            }

            if (!isset($activeColumnLookup[$columnId])) {
                $errors[] = 'Column not found in the current class record.';
            } else {
                $stmt = $conn->prepare("
                    UPDATE tbl_grade_columns
                    SET label = ?, group_code = ?, group_label = ?, max_score = ?, weight_percent = ?, updated_by = ?
                    WHERE grade_column_id = ?
                ");
                $stmt->execute([$label, slugGradeGroup($groupLabel), $groupLabel, $maxScore, $weight, $userId, $columnId]);
                $messages[] = 'Grade column updated.';
            }
        }
    }

    if ($action === 'settings' && $canManageColumns) {
        $meetings = max(1, (int) ($_POST['total_meetings'] ?? 11));
        setGradeSetting($conn, $totalMeetingsKey, (string) $meetings, $userId);
        $settingsColumns = $_POST['columns'] ?? [];

        if (is_array($settingsColumns)) {
            $activeColumnLookup = [];
            foreach ($gradeColumns as $column) {
                $activeColumnLookup[(int) $column['grade_column_id']] = true;
            }

            $updateColumn = $conn->prepare("
                UPDATE tbl_grade_columns
                SET label = ?, group_code = ?, group_label = ?, max_score = ?, weight_percent = ?, updated_by = ?
                WHERE grade_column_id = ?
            ");

            foreach ($settingsColumns as $columnId => $columnSettings) {
                $columnId = (int) $columnId;
                if (!isset($activeColumnLookup[$columnId]) || !is_array($columnSettings)) {
                    continue;
                }

                $label = trim((string) ($columnSettings['label'] ?? ''));
                $groupLabel = trim((string) ($columnSettings['group_label'] ?? ''));
                $maxScore = (float) ($columnSettings['max_score'] ?? 0);
                $weight = (float) ($columnSettings['weight_percent'] ?? 0);

                if ($label === '' || $groupLabel === '' || $maxScore <= 0 || $weight <= 0) {
                    $errors[] = 'Skipped one column in settings because it has incomplete or invalid values.';
                    continue;
                }

                $updateColumn->execute([
                    $label,
                    slugGradeGroup($groupLabel),
                    $groupLabel,
                    $maxScore,
                    $weight,
                    $userId,
                    $columnId,
                ]);
            }
        }

        $messages[] = 'Grade settings updated.';
    }

    if ($action === 'save_scores' && $canComputeGrades) {
        $scores = $_POST['scores'] ?? [];
        $columnLookup = [];
        foreach ($gradeColumns as $column) {
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
$gradeCategoryOptions = [];
foreach ($gradeColumns as $column) {
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
$gradeGroups = buildGradeGroups($gradeColumns);
$scoreWeight = array_sum(array_map(function ($group) {
    return (float) $group['weight'];
}, $gradeGroups));
$attendanceWeight = max(0, 100 - $scoreWeight);
$totalConfiguredWeight = $scoreWeight + $attendanceWeight;
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

        .class-record-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .class-record-actions .btn {
            min-height: 34px;
            border-radius: 6px;
            font-weight: 700;
        }

        .grade-card {
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .grade-table-wrap {
            max-height: calc(100vh - 260px);
            overflow: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background: #fff;
        }

        .grade-table {
            min-width: 2200px;
            margin-bottom: 0;
        }

        .grade-table th,
        .grade-table td {
            vertical-align: middle !important;
            white-space: nowrap;
            font-size: 0.84rem;
        }

        .grade-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #f8f9fa;
        }

        .grade-table .sticky-name {
            position: sticky;
            left: 0;
            z-index: 7;
            background: #fff;
            min-width: 260px;
            max-width: 260px;
            white-space: normal;
            box-shadow: 1px 0 0 #dee2e6;
        }

        .grade-table thead .sticky-name {
            background: #f8f9fa;
            z-index: 9;
        }

        .score-input {
            width: 74px;
            min-width: 74px;
            text-align: center;
            padding-left: 6px;
            padding-right: 6px;
        }

        .computed-cell {
            background: #f7fbff;
            font-weight: 700;
        }

        .final-cell {
            background: #e8f5e9;
            color: #155724;
            font-weight: 800;
        }

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

            .class-record-tools-header,
            .class-record-actions {
                align-items: stretch;
                flex-direction: column;
            }

            .class-record-actions .btn,
            .class-record-actions form {
                width: 100%;
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
                    <div>
                        <h1 class="mb-1"><i class="fas fa-calculator mr-2"></i>NSTP Grade Computation</h1>
                        <div class="text-muted">
                            <?php echo htmlspecialchars(ucfirst($userRole)); ?> view
                            <?php if ($currentProgram): ?>
                                · <?php echo htmlspecialchars($currentProgram); ?>
                            <?php endif; ?>
                            · <?php echo count($students); ?> student(s)
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

                <div class="card grade-card grade-folder-card">
                    <div class="card-body py-3">
                        <form method="GET" class="row align-items-end">
                            <div class="col-lg-7 col-md-8 mb-2 mb-md-0">
                                <label class="mb-1 font-weight-bold" for="grade_folder">
                                    <i class="fas fa-folder-open mr-1"></i>Class Record Folder
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
                            <div class="col-lg-5 col-md-4">
                                <div class="text-muted small">Current class record is filtered by folder.</div>
                                <strong><?php echo htmlspecialchars($selectedGradeFolderLabel); ?></strong>
                                <?php if ($selectedGradeFolder === '' && count($gradeFolderOptions) > 1): ?>
                                    <div class="small text-danger">Select one folder first so students do not get mixed.</div>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box grade-card">
                            <span class="info-box-icon bg-info"><i class="fas fa-users"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Students</span>
                                <span class="info-box-number"><?php echo count($students); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box grade-card">
                            <span class="info-box-icon bg-success"><i class="fas fa-calendar-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Attendance Meetings</span>
                                <span class="info-box-number"><?php echo (int) $totalMeetings; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box grade-card">
                            <span class="info-box-icon bg-warning"><i class="fas fa-table-columns"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Score Columns</span>
                                <span class="info-box-number"><?php echo count($gradeColumns); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box grade-card">
                            <span class="info-box-icon bg-primary"><i class="fas fa-weight-hanging"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Configured Weight</span>
                                <span class="info-box-number"><?php echo formatGradeNumber($totalConfiguredWeight, 0); ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($canManageColumns): ?>
                    <div class="card grade-card">
                        <div class="card-header class-record-tools-header">
                            <div>
                                <button type="button"
                                        class="btn btn-link btn-sm p-0 collapse-toggle"
                                        data-toggle="collapse"
                                        data-target="#activeColumnsPanel"
                                        aria-expanded="false"
                                        aria-controls="activeColumnsPanel">
                                    <i class="fas fa-chevron-right mr-1" id="activeColumnsIcon"></i>
                                    <i class="fas fa-list mr-2"></i>Class Record Columns
                                </button>
                                <div class="small text-muted mt-1">Coordinator controls for the active grading sheet.</div>
                            </div>
                            <div class="class-record-actions">
                                <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addColumnModal">
                                    <i class="fas fa-plus mr-1"></i>Add Column
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
                            <?php if (empty($gradeColumns)): ?>
                                <span class="text-muted">No columns yet. Add a column to start a new class record.</span>
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
                                <?php foreach ($gradeColumns as $column): ?>
                                    <span class="column-chip">
                                        <input
                                            type="checkbox"
                                            class="grade-column-checkbox"
                                            value="<?php echo (int) $column['grade_column_id']; ?>">
                                        <?php echo htmlspecialchars($column['label']); ?>
                                        <small class="text-muted">
                                            /<?php echo formatGradeNumber($column['max_score'], 0); ?>
                                            · <?php echo formatGradeNumber($column['weight_percent'], 0); ?>%
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
                                            data-weight-percent="<?php echo htmlspecialchars((string) $column['weight_percent']); ?>">
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
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <h3 class="card-title mb-0"><i class="fas fa-file-signature mr-2"></i>Class Record</h3>
                            <?php if ($canComputeGrades): ?>
                                <button class="btn btn-success btn-sm ml-auto">
                                    <i class="fas fa-save mr-1"></i>Save Scores
                                </button>
                            <?php else: ?>
                                <span class="badge badge-secondary ml-auto">
                                    <i class="fas fa-eye mr-1"></i>View Only
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-0">
                            <div class="grade-table-wrap">
                                <table class="table table-bordered table-hover grade-table">
                                    <thead>
                                        <tr>
                                            <th class="sticky-name">Student</th>
                                            <th>Folder</th>
                                            <th>Facilitator</th>
                                            <?php foreach ($gradeColumns as $column): ?>
                                                <th>
                                                    <?php echo htmlspecialchars($column['label']); ?>
                                                    <span class="small-label">
                                                        <?php echo htmlspecialchars($column['group_label']); ?>
                                                        · /<?php echo formatGradeNumber($column['max_score'], 0); ?>
                                                    </span>
                                                </th>
                                            <?php endforeach; ?>
                                            <th class="computed-cell">Attendance <span class="small-label">/ <?php echo (int) $totalMeetings; ?></span></th>
                                            <th class="computed-cell">Total Score</th>
                                            <th class="computed-cell">Max Score</th>
                                            <th class="computed-cell">Score %</th>
                                            <th class="computed-cell">Weighted %</th>
                                            <th class="final-cell">Final Grade</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($students)): ?>
                                            <tr>
                                                <td colspan="<?php echo count($gradeColumns) + 9; ?>" class="text-center text-muted py-5">
                                                    <i class="fas fa-user-graduate fa-2x d-block mb-2"></i>
                                                    No students available for this account.
                                                </td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($students as $student): ?>
                                            <?php
                                            $studentId = (int) $student['tbl_student_id'];
                                            $studentDisplayName = gradeDisplayStudentName($student);
                                            $attendanceCount = min($totalMeetings, $attendanceCounts[$studentId] ?? 0);
                                            $summary = computeStudentGradeSummary(
                                                $gradeColumns,
                                                $scoresByStudent[$studentId] ?? [],
                                                $gradeGroups,
                                                $attendanceCount,
                                                $totalMeetings,
                                                $attendanceWeight
                                            );
                                            ?>
                                            <tr class="grade-row" data-attendance-count="<?php echo (int) $attendanceCount; ?>">
                                                <td class="sticky-name">
                                                    <strong><?php echo htmlspecialchars($studentDisplayName); ?></strong>
                                                    <?php if (!empty($student['student_number'])): ?>
                                                        <span class="small-label"><?php echo htmlspecialchars($student['student_number']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($student['course_section']); ?></td>
                                                <td><?php echo htmlspecialchars($student['facilitator_name']); ?></td>
                                                <?php foreach ($gradeColumns as $column): ?>
                                                    <?php
                                                    $columnId = (int) $column['grade_column_id'];
                                                    $value = $scoresByStudent[$studentId][$columnId] ?? '';
                                                    ?>
                                                    <td>
                                                        <input
                                                            type="number"
                                                            class="form-control form-control-sm score-input"
                                                            name="scores[<?php echo $studentId; ?>][<?php echo $columnId; ?>]"
                                                            value="<?php echo htmlspecialchars((string) $value); ?>"
                                                            min="0"
                                                            max="<?php echo htmlspecialchars((string) $column['max_score']); ?>"
                                                            data-max="<?php echo htmlspecialchars((string) $column['max_score']); ?>"
                                                            data-group="<?php echo htmlspecialchars($column['group_code']); ?>"
                                                            step="0.01"
                                                            <?php echo $canComputeGrades ? '' : 'readonly disabled'; ?>>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td class="computed-cell"><?php echo (int) $attendanceCount; ?></td>
                                                <td class="computed-cell js-raw-total"><?php echo formatGradeNumber($summary['raw_total']); ?></td>
                                                <td class="computed-cell js-max-total"><?php echo formatGradeNumber($summary['max_total']); ?></td>
                                                <td class="computed-cell js-score-percent"><?php echo formatGradeNumber($summary['score_percent']); ?>%</td>
                                                <td class="computed-cell js-weighted-percent"><?php echo formatGradeNumber($summary['weighted_percent']); ?>%</td>
                                                <td class="final-cell js-final-grade"><?php echo formatGradeNumber($summary['final_grade']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer text-muted">
                            Formula is based on the active grading sheet: each category computes earned score over max score, applies its configured weight, and attendance automatically fills the remaining percentage. If attendance is the only active basis, it counts as 100%.
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
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus mr-2"></i>Add Grade Column</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Column Name</label>
                    <input type="text" name="label" class="form-control" maxlength="160" placeholder="Example: Reflection Paper" required>
                </div>
                <div class="form-group">
                    <label>Category / Component</label>
                    <select name="group_label_select" id="addColumnGroupSelect" class="form-control">
                        <?php if (empty($gradeCategoryOptions)): ?>
                            <option value="Additional Requirements">Additional Requirements</option>
                        <?php else: ?>
                            <?php foreach ($gradeCategoryOptions as $categoryLabel): ?>
                                <option value="<?php echo htmlspecialchars($categoryLabel); ?>"><?php echo htmlspecialchars($categoryLabel); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <div class="mt-2">
                        <select name="group_mode" id="addColumnGroupMode" class="form-control">
                            <option value="existing">Use selected category</option>
                            <option value="new">Create new category</option>
                        </select>
                    </div>
                    <input type="text" name="group_label_new" id="addColumnGroupNew" class="form-control mt-2 d-none" maxlength="120" placeholder="New category name">
                    <small class="text-muted">Columns with the same category are grouped when computing the weighted percentage.</small>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Max Score</label>
                        <input type="number" name="max_score" class="form-control" min="1" step="0.01" value="100" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Weight Percent</label>
                        <input type="number" name="weight_percent" class="form-control" min="1" step="0.01" value="10" required>
                    </div>
                </div>
                <div class="alert alert-info mb-0">
                    Added columns are scoped to your current component<?php echo $currentProgram ? ' (' . htmlspecialchars($currentProgram) . ')' : ''; ?> and are included in the weighted grade.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button class="btn btn-primary"><i class="fas fa-plus mr-1"></i>Add Column</button>
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
                    <label>Column Name</label>
                    <input type="text" name="label" id="editColumnLabel" class="form-control" maxlength="160" required>
                </div>
                <div class="form-group">
                    <label>Category / Component</label>
                    <select name="group_label_select" id="editColumnGroupSelect" class="form-control">
                        <?php if (empty($gradeCategoryOptions)): ?>
                            <option value="Additional Requirements">Additional Requirements</option>
                        <?php else: ?>
                            <?php foreach ($gradeCategoryOptions as $categoryLabel): ?>
                                <option value="<?php echo htmlspecialchars($categoryLabel); ?>"><?php echo htmlspecialchars($categoryLabel); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <div class="mt-2">
                        <select name="group_mode" id="editColumnGroupMode" class="form-control">
                            <option value="existing">Use selected category</option>
                            <option value="new">Create new category</option>
                        </select>
                    </div>
                    <input type="text" name="group_label_new" id="editColumnGroupNew" class="form-control mt-2 d-none" maxlength="120" placeholder="New category name">
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Max Score</label>
                        <input type="number" name="max_score" id="editColumnMaxScore" class="form-control" min="1" step="0.01" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Weight Percent</label>
                        <input type="number" name="weight_percent" id="editColumnWeightPercent" class="form-control" min="1" step="0.01" required>
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
                <div class="form-group">
                    <label>Total Attendance Meetings</label>
                    <input type="number" name="total_meetings" class="form-control" min="1" value="<?php echo (int) $totalMeetings; ?>" required>
                    <small class="text-muted">Used for Attendance Equivalence. The template you provided uses 11 meetings.</small>
                </div>
                <?php if (!empty($gradeColumns)): ?>
                    <datalist id="gradeCategoryList">
                        <?php foreach ($gradeCategoryOptions as $categoryLabel): ?>
                            <option value="<?php echo htmlspecialchars($categoryLabel); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-3">
                            <thead class="thead-light">
                                <tr>
                                    <th style="min-width: 220px;">Column</th>
                                    <th style="min-width: 190px;">Category / Component</th>
                                    <th style="width: 120px;">Max Score</th>
                                    <th style="width: 140px;">Weight %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gradeColumns as $column): ?>
                                    <?php $columnId = (int) $column['grade_column_id']; ?>
                                    <tr>
                                        <td>
                                            <input
                                                type="text"
                                                name="columns[<?php echo $columnId; ?>][label]"
                                                class="form-control form-control-sm"
                                                maxlength="160"
                                                value="<?php echo htmlspecialchars($column['label']); ?>"
                                                required>
                                        </td>
                                        <td>
                                            <input
                                                type="text"
                                                name="columns[<?php echo $columnId; ?>][group_label]"
                                                class="form-control form-control-sm"
                                                maxlength="120"
                                                list="gradeCategoryList"
                                                value="<?php echo htmlspecialchars($column['group_label']); ?>"
                                                required>
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                name="columns[<?php echo $columnId; ?>][max_score]"
                                                class="form-control form-control-sm"
                                                min="1"
                                                step="0.01"
                                                value="<?php echo htmlspecialchars((string) $column['max_score']); ?>"
                                                required>
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                name="columns[<?php echo $columnId; ?>][weight_percent]"
                                                class="form-control form-control-sm"
                                                min="1"
                                                step="0.01"
                                                value="<?php echo htmlspecialchars((string) $column['weight_percent']); ?>"
                                                required>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <div class="alert alert-info mb-0">
                    Attendance automatically uses the remaining percentage after active score columns. With no score columns, attendance is 100%.
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
const gradeGroups = <?php echo json_encode($gradeGroups); ?>;
const attendanceWeight = <?php echo json_encode((float) $attendanceWeight); ?>;
const totalMeetings = <?php echo json_encode((int) $totalMeetings); ?>;

function formatNumber(value) {
    return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function transmuteClient(percent) {
    const grade = 5 - (4 / 100 * Number(percent || 0));
    return Math.max(1, Math.min(5, grade));
}

function refreshGradeRow($row) {
    const rawByGroup = {};
    let rawTotal = 0;
    let maxTotal = 0;
    let weightedPoints = 0;
    let totalWeight = 0;

    $row.find('.score-input').each(function() {
        const group = $(this).data('group');
        const max = Number($(this).data('max') || 0);
        const score = this.value === '' ? 0 : Number(this.value || 0);
        rawByGroup[group] = (rawByGroup[group] || 0) + score;
        rawTotal += score;
        maxTotal += max;
    });

    Object.keys(gradeGroups).forEach(function(groupCode) {
        const group = gradeGroups[groupCode];
        const groupMax = Math.max(Number(group.max || 0), 1);
        const groupWeight = Number(group.weight || 0);
        const groupPercent = ((rawByGroup[groupCode] || 0) / groupMax) * 100;
        weightedPoints += (groupPercent / 100) * groupWeight;
        totalWeight += groupWeight;
    });

    if (attendanceWeight > 0) {
        const attendanceCount = Math.min(Number($row.data('attendance-count') || 0), totalMeetings);
        const attendancePercent = (attendanceCount / Math.max(totalMeetings, 1)) * 100;
        weightedPoints += (attendancePercent / 100) * attendanceWeight;
        totalWeight += attendanceWeight;
    }

    const scorePercent = maxTotal > 0 ? (rawTotal / maxTotal) * 100 : 0;
    const weightedPercent = totalWeight > 0 ? (weightedPoints / totalWeight) * 100 : scorePercent;

    $row.find('.js-raw-total').text(formatNumber(rawTotal));
    $row.find('.js-max-total').text(formatNumber(maxTotal));
    $row.find('.js-score-percent').text(formatNumber(scorePercent) + '%');
    $row.find('.js-weighted-percent').text(formatNumber(weightedPercent) + '%');
    $row.find('.js-final-grade').text(formatNumber(transmuteClient(weightedPercent)));
}

$('.score-input').on('input', function() {
    const max = parseFloat(this.max || '0');
    const value = parseFloat(this.value || '0');
    if (max > 0 && value > max) {
        this.value = max;
    }
    refreshGradeRow($(this).closest('.grade-row'));
});

function syncCategoryMode(prefix) {
    const mode = $('#' + prefix + 'ColumnGroupMode').val();
    const $newInput = $('#' + prefix + 'ColumnGroupNew');
    const useNew = mode === 'new';
    $newInput.toggleClass('d-none', !useNew).prop('required', useNew);
    if (!useNew) {
        $newInput.val('');
    }
}

$('#addColumnGroupMode, #editColumnGroupMode').on('change', function() {
    syncCategoryMode(this.id.indexOf('edit') === 0 ? 'edit' : 'add');
});

$('.edit-column-btn').on('click', function() {
    const groupLabel = String($(this).data('group-label') || '');
    $('#editColumnId').val($(this).data('column-id'));
    $('#editColumnLabel').val($(this).data('label'));
    $('#editColumnMaxScore').val($(this).data('max-score'));
    $('#editColumnWeightPercent').val($(this).data('weight-percent'));

    if ($('#editColumnGroupSelect option').filter(function() { return this.value === groupLabel; }).length) {
        $('#editColumnGroupMode').val('existing');
        $('#editColumnGroupSelect').val(groupLabel);
        $('#editColumnGroupNew').val('');
    } else {
        $('#editColumnGroupMode').val('new');
        $('#editColumnGroupNew').val(groupLabel);
    }
    syncCategoryMode('edit');
});

syncCategoryMode('add');
syncCategoryMode('edit');

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
