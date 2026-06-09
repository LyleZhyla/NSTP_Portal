<?php
session_start();

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'coordinator') {
    die('Unauthorized access');
}

$coordinatorProgram = normalizeProgram($currentUser['program'] ?? ($_SESSION['program'] ?? null));
if (!in_array($coordinatorProgram, ['CWTS', 'LTS'], true)) {
    die('Unauthorized access');
}

function chedTableExists(PDO $conn, $tableName) {
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

function chedColumnExists(PDO $conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$tableName, $columnName]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $error) {
        return false;
    }
}

function chedClean($value) {
    $value = trim((string) ($value ?? ''));
    return strtoupper($value) === 'N/A' ? '' : $value;
}

function chedPlaceholderEmail($email) {
    $email = trim((string) $email);
    return stripos($email, '@no-email.tau-nstp.local') !== false ? '' : $email;
}

function chedSplitStudentName(array $student) {
    $lastName = chedClean($student['reg_last_name'] ?? '');
    $firstName = chedClean($student['reg_first_name'] ?? '');
    $middleName = chedClean($student['reg_middle_name'] ?? '');

    if ($lastName !== '' || $firstName !== '' || $middleName !== '') {
        return [$lastName, $firstName, $middleName];
    }

    $name = trim((string) ($student['student_name'] ?? ''));
    if (strpos($name, ',') !== false) {
        [$lastName, $rest] = array_map('trim', explode(',', $name, 2));
        $parts = preg_split('/\s+/', $rest);
        $firstName = array_shift($parts) ?: '';
        $middleName = implode(' ', $parts);
        return [$lastName, $firstName, $middleName];
    }

    $parts = preg_split('/\s+/', $name);
    $lastName = count($parts) > 1 ? array_pop($parts) : '';
    $firstName = implode(' ', $parts);
    return [$lastName, $firstName, ''];
}

function chedAddress(array $student) {
    $parts = [
        chedClean($student['reg_house_no'] ?? ''),
        chedClean($student['reg_street'] ?? ''),
        chedClean($student['reg_barangay'] ?? ''),
        chedClean($student['reg_city_municipality'] ?? ''),
        chedClean($student['reg_province'] ?? ''),
    ];
    $parts = array_values(array_filter($parts, fn($part) => $part !== ''));
    return implode(', ', $parts);
}

function chedEnsureGradeTables(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_grade_columns (
            grade_column_id INT AUTO_INCREMENT PRIMARY KEY,
            column_key VARCHAR(80) NOT NULL UNIQUE,
            program_scope VARCHAR(20) NULL,
            label VARCHAR(160) NOT NULL,
            group_code VARCHAR(60) NOT NULL,
            group_label VARCHAR(120) NOT NULL,
            max_score DECIMAL(8,2) NOT NULL DEFAULT 0,
            weight_percent DECIMAL(8,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
            INDEX idx_grade_student (tbl_student_id)
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
            INDEX idx_grade_column_visibility_user (user_id, program_scope)
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

function chedSeedDefaultGradeColumns(PDO $conn) {
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

function chedGradeSetting(PDO $conn, $key, $default) {
    $stmt = $conn->prepare("SELECT setting_value FROM tbl_grade_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : $value;
}

function chedTransmuteGrade($equivalentPoints, $denominator = 100) {
    $denominator = max((float) $denominator, 1);
    $grade = 5 - (4 / $denominator * (float) $equivalentPoints);
    return max(1, min(5, $grade));
}

function chedBuildGradeGroups(array $gradeColumns) {
    $groups = [];
    foreach ($gradeColumns as $column) {
        $groupCode = $column['group_code'];
        if (!isset($groups[$groupCode])) {
            $groups[$groupCode] = [
                'max' => 0,
                'weights' => [],
                'weight' => 0,
                'has_custom' => false,
            ];
        }
        $weight = (float) $column['weight_percent'];
        $groups[$groupCode]['max'] += (float) $column['max_score'];
        $groups[$groupCode]['weights'][] = $weight;
        $groups[$groupCode]['has_custom'] = $groups[$groupCode]['has_custom'] || ((int) $column['is_default'] === 0);
    }

    foreach ($groups as $groupCode => $group) {
        $uniqueWeights = array_values(array_unique(array_map(fn($weight) => number_format((float) $weight, 4, '.', ''), $group['weights'])));
        $groups[$groupCode]['weight'] = ($group['has_custom'] || count($uniqueWeights) > 1)
            ? array_sum($group['weights'])
            : (float) ($group['weights'][0] ?? 0);
    }
    return $groups;
}

function chedComputeGradeSummary(array $gradeColumns, array $scores, array $gradeGroups, $attendanceCount, $totalMeetings, $attendanceWeight) {
    $rawTotal = 0;
    $maxTotal = 0;
    $weightedPoints = 0;
    $totalWeight = 0;
    $rawByGroup = [];

    foreach ($gradeColumns as $column) {
        $groupCode = $column['group_code'];
        $rawByGroup[$groupCode] = $rawByGroup[$groupCode] ?? 0;
        $columnId = (int) $column['grade_column_id'];
        $score = $scores[$columnId] ?? null;
        $score = $score === null || $score === '' ? 0 : (float) $score;
        $rawByGroup[$groupCode] += $score;
        $rawTotal += $score;
        $maxTotal += (float) $column['max_score'];
    }

    foreach ($gradeGroups as $groupCode => $group) {
        $groupPercent = (($rawByGroup[$groupCode] ?? 0) / max((float) $group['max'], 1)) * 100;
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
        'weighted_percent' => $weightedPercent,
        'final_grade' => chedTransmuteGrade($weightedPercent),
    ];
}

$role = $currentUser['role'] ?? '';
$component = $coordinatorProgram;

if (!$component) {
    die('Invalid NSTP component for this CHED template');
}

$templatePath = dirname(__DIR__) . '/include/templates/CHED format for LTS and CWTS Serial Number.xlsx';
if (!is_file($templatePath)) {
    die('CHED template file is missing');
}

$registrationFields = [
    'last_name', 'first_name', 'middle_name', 'gender', 'date_of_birth',
    'province', 'city_municipality', 'barangay', 'street', 'house_no',
    'contact_number', 'email', 'course', 'year_section', 'component',
];

$selectRegistrationFields = [];
$hasRegistrationTable = chedTableExists($conn, 'tbl_public_student_registrations');
foreach ($registrationFields as $field) {
    if ($hasRegistrationTable && chedColumnExists($conn, 'tbl_public_student_registrations', $field)) {
        $selectRegistrationFields[] = "r.`$field` AS reg_$field";
    } else {
        $selectRegistrationFields[] = "NULL AS reg_$field";
    }
}

$registrationJoin = '';
if ($hasRegistrationTable) {
    $registrationJoin = "
        LEFT JOIN (
            SELECT r1.*
            FROM tbl_public_student_registrations r1
            INNER JOIN (
                SELECT student_number, MAX(registration_id) AS latest_registration_id
                FROM tbl_public_student_registrations
                WHERE student_number IS NOT NULL AND student_number <> ''
                GROUP BY student_number
            ) latest ON latest.latest_registration_id = r1.registration_id
        ) r ON r.student_number = s.student_number
    ";
}

$registrationComponentWhere = '';
if ($hasRegistrationTable && chedColumnExists($conn, 'tbl_public_student_registrations', 'component')) {
    $registrationComponentWhere = " OR r.component = :registration_component";
}

$studentQuery = "
    SELECT
        s.tbl_student_id,
        s.student_number,
        s.student_name,
        s.original_section,
        s.course_section,
        s.generated_code,
        creator.role AS creator_role,
        creator.program AS creator_program,
        " . implode(",\n        ", $selectRegistrationFields) . "
    FROM tbl_student s
    LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
    $registrationJoin
    WHERE (creator.role = 'facilitator' AND creator.program = :component)
       OR ((s.created_by IS NULL OR creator.role <> 'facilitator') AND s.course_section = :component_section)
       $registrationComponentWhere
    ORDER BY
        COALESCE(NULLIF(s.original_section, ''), NULLIF(s.course_section, ''), '') ASC,
        s.student_name ASC
";

$stmt = $conn->prepare($studentQuery);
$queryParams = [
    ':component' => $component,
    ':component_section' => $component,
];
if ($registrationComponentWhere !== '') {
    $queryParams[':registration_component'] = $component;
}
$stmt->execute($queryParams);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

chedEnsureGradeTables($conn);
chedSeedDefaultGradeColumns($conn);

$settingScope = strtolower($component);
$columnVisibilityScope = $component;
$sheetOwnerId = (int) $currentUser['user_id'];
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
$columnsStmt->execute([$component, $sheetOwnerId, $sheetOwnerId, $columnVisibilityScope]);
$gradeColumns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
$gradeColumnIds = array_map('intval', array_column($gradeColumns, 'grade_column_id'));
$studentIds = array_map('intval', array_column($students, 'tbl_student_id'));

$scoresByStudent = [];
if ($studentIds && $gradeColumnIds) {
    $studentPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
    $columnPlaceholders = implode(',', array_fill(0, count($gradeColumnIds), '?'));
    $stmt = $conn->prepare("
        SELECT tbl_student_id, grade_column_id, score
        FROM tbl_grade_scores
        WHERE tbl_student_id IN ($studentPlaceholders)
          AND grade_column_id IN ($columnPlaceholders)
    ");
    $stmt->execute(array_merge($studentIds, $gradeColumnIds));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $scoresByStudent[(int) $row['tbl_student_id']][(int) $row['grade_column_id']] = $row['score'];
    }
}

$attendanceCounts = [];
if ($studentIds) {
    $studentPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
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
    $stmt->execute(array_merge($studentIds, $studentIds));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $attendanceCounts[(int) $row['tbl_student_id']] = (int) $row['attendance_count'];
    }
}

$gradeGroups = chedBuildGradeGroups($gradeColumns);
$totalMeetings = max(1, (int) chedGradeSetting($conn, 'total_meetings_' . $settingScope, '11'));
$scoreWeight = array_sum(array_map(fn($group) => (float) $group['weight'], $gradeGroups));
$attendanceWeight = max(0, 100 - $scoreWeight);
$students = array_values(array_filter($students, function ($student) use ($gradeColumns, $gradeGroups, $scoresByStudent, $attendanceCounts, $totalMeetings, $attendanceWeight) {
    $studentId = (int) $student['tbl_student_id'];
    $summary = chedComputeGradeSummary(
        $gradeColumns,
        $scoresByStudent[$studentId] ?? [],
        $gradeGroups,
        $attendanceCounts[$studentId] ?? 0,
        $totalMeetings,
        $attendanceWeight
    );

    return (float) $summary['final_grade'] <= 3.0;
}));

$spreadsheet = IOFactory::load($templatePath);
$sheet = $spreadsheet->getSheet(0);
$sheet->setTitle('SN ' . date('Y') . ' ' . $component);
$sheet->setCellValue('I12', 'NSTP Component : ' . $component);

$baseStartRow = 18;
$baseRows = 40;
$studentCount = count($students);
$extraRows = max(0, $studentCount - $baseRows);

if ($extraRows > 0) {
    $sheet->insertNewRowBefore($baseStartRow + $baseRows, $extraRows);
    for ($row = $baseStartRow + $baseRows; $row < $baseStartRow + $baseRows + $extraRows; $row++) {
        $sheet->duplicateStyle($sheet->getStyle('A' . ($baseStartRow + $baseRows - 1) . ':K' . ($baseStartRow + $baseRows - 1)), 'A' . $row . ':K' . $row);
        $sheet->getRowDimension($row)->setRowHeight($sheet->getRowDimension($baseStartRow + $baseRows - 1)->getRowHeight());
    }
}

$totalDataRows = max($baseRows, $studentCount);
for ($i = 0; $i < $totalDataRows; $i++) {
    $row = $baseStartRow + $i;
    $sheet->setCellValue('A' . $row, $i + 1);
    $sheet->setCellValue('B' . $row, '');
    $sheet->setCellValue('C' . $row, '');
    $sheet->setCellValue('D' . $row, '');
    $sheet->setCellValue('E' . $row, '');
    $sheet->setCellValue('F' . $row, '');
    $sheet->setCellValue('G' . $row, '');
    $sheet->setCellValue('H' . $row, '');
    $sheet->setCellValue('I' . $row, '');
    $sheet->setCellValue('J' . $row, '');
    $sheet->setCellValue('K' . $row, '');
}

$maleCount = 0;
$femaleCount = 0;

foreach ($students as $index => $student) {
    $row = $baseStartRow + $index;
    [$lastName, $firstName, $middleName] = chedSplitStudentName($student);
    $gender = chedClean($student['reg_gender'] ?? '');
    $genderUpper = strtoupper($gender);
    if (strpos($genderUpper, 'MALE') === 0 && strpos($genderUpper, 'FEMALE') !== 0) {
        $maleCount++;
    } elseif (strpos($genderUpper, 'FEMALE') === 0) {
        $femaleCount++;
    }

    $dateOfBirth = chedClean($student['reg_date_of_birth'] ?? '');
    if ($dateOfBirth !== '' && strtotime($dateOfBirth)) {
        $dateOfBirth = date('m/d/Y', strtotime($dateOfBirth));
    }

    $course = chedClean($student['reg_course'] ?? '');
    $yearSection = chedClean($student['reg_year_section'] ?? '');
    if ($course === '') {
        $course = chedClean($student['original_section'] ?? '') ?: chedClean($student['course_section'] ?? '');
    }
    if ($yearSection !== '' && stripos($course, $yearSection) === false) {
        $course .= ' - ' . $yearSection;
    }

    $sheet->setCellValue('A' . $row, $index + 1);
    $sheet->setCellValue('B' . $row, '');
    $sheet->setCellValue('C' . $row, $lastName);
    $sheet->setCellValue('D' . $row, $firstName);
    $sheet->setCellValue('E' . $row, $middleName);
    $sheet->setCellValue('F' . $row, $course);
    $sheet->setCellValue('G' . $row, $gender);
    $sheet->setCellValue('H' . $row, $dateOfBirth);
    $sheet->setCellValue('I' . $row, chedAddress($student));
    $sheet->setCellValue('J' . $row, chedClean($student['reg_contact_number'] ?? ''));
    $sheet->setCellValue('K' . $row, chedPlaceholderEmail($student['reg_email'] ?? ''));
}

$totalsRow = 69 + $extraRows;
$sheet->setCellValue('C' . $totalsRow, 'Grad Total: ' . $studentCount);
$sheet->setCellValue('D' . $totalsRow, 'Male:');
$sheet->setCellValue('E' . $totalsRow, $maleCount);
$sheet->setCellValue('D' . ($totalsRow + 1), 'Female:');
$sheet->setCellValue('E' . ($totalsRow + 1), $femaleCount);

$filename = 'CHED_' . $component . '_serial_number_' . date('Y-m-d_H-i-s') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
