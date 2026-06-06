<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

$currentUser = getCurrentUserRecord($conn);
$role = $currentUser['role'] ?? '';
if (!in_array($role, ['super_admin', 'coordinator', 'facilitator'], true)) {
    die('Unauthorized access');
}

$userId = (int) $currentUser['user_id'];
$program = normalizeProgram($currentUser['program'] ?? ($_SESSION['program'] ?? null));
$currentProgram = $role === 'super_admin' ? normalizeProgram($_GET['component'] ?? null) : $program;
$settingScope = $currentProgram ? strtolower($currentProgram) : 'global';
$columnVisibilityScope = $currentProgram ?: 'global';

function exportEnsureGradeTables(PDO $conn) {
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

function exportGradeSetting(PDO $conn, $key, $default) {
    $stmt = $conn->prepare("SELECT setting_value FROM tbl_grade_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : $value;
}

function exportTransmuteGrade($equivalentPoints, $denominator = 100) {
    $denominator = max((float) $denominator, 1);
    $grade = 5 - (4 / $denominator * (float) $equivalentPoints);
    return max(1, min(5, $grade));
}

function exportBuildGradeGroups(array $gradeColumns) {
    $groups = [];
    foreach ($gradeColumns as $column) {
        $groupCode = $column['group_code'];
        if (!isset($groups[$groupCode])) {
            $groups[$groupCode] = [
                'label' => $column['group_label'],
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

function exportComputeGradeSummary(array $gradeColumns, array $scores, array $gradeGroups, $attendanceCount, $totalMeetings, $attendanceWeight) {
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
        'raw_total' => $rawTotal,
        'max_total' => $maxTotal,
        'score_percent' => $scorePercent,
        'weighted_percent' => $weightedPercent,
        'final_grade' => exportTransmuteGrade($weightedPercent),
    ];
}

exportEnsureGradeTables($conn);

$folderKey = trim((string) ($_GET['grade_folder'] ?? ''));
$selectedFacilitatorId = null;
$selectedFolder = '';
$selectedFolderLabel = 'All Accessible Students';

if ($role === 'facilitator') {
    $selectedFacilitatorId = $userId;
    $selectedFolder = $folderKey;
} elseif (strpos($folderKey, '::') !== false) {
    [$facilitatorPart, $folderPart] = explode('::', $folderKey, 2);
    $selectedFacilitatorId = (int) $facilitatorPart;
    $selectedFolder = trim($folderPart);
}

$sheetOwnerId = ($role === 'coordinator' && $selectedFacilitatorId)
    ? (int) $selectedFacilitatorId
    : $userId;
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
$columnsStmt->execute([$currentProgram, $sheetOwnerId, $sheetOwnerId, $columnVisibilityScope]);
$gradeColumns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
$gradeColumnIds = array_map('intval', array_column($gradeColumns, 'grade_column_id'));

$studentWhere = [];
$studentParams = [];
if ($role === 'super_admin') {
    if ($currentProgram) {
        $studentWhere[] = "(creator.program = ? OR s.course_section = ?)";
        $studentParams[] = $currentProgram;
        $studentParams[] = $currentProgram;
    }
} elseif ($role === 'coordinator') {
    $studentWhere[] = "(creator.program = ? OR s.course_section = ?)";
    $studentParams[] = $program;
    $studentParams[] = $program;
} else {
    $studentWhere[] = "s.created_by = ?";
    $studentParams[] = $userId;
}

if ($selectedFacilitatorId && $selectedFolder !== '') {
    $studentWhere[] = "s.created_by = ? AND s.course_section = ?";
    $studentParams[] = $selectedFacilitatorId;
    $studentParams[] = $selectedFolder;
    $selectedFolderLabel = $selectedFolder;
}

$studentSql = "
    SELECT s.*, COALESCE(NULLIF(creator.full_name, ''), creator.username, 'Unassigned') AS facilitator_name
    FROM tbl_student s
    LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
    WHERE " . ($studentWhere ? implode(' AND ', $studentWhere) : '1 = 1') . "
    ORDER BY s.course_section ASC, s.student_name ASC
";
$studentStmt = $conn->prepare($studentSql);
$studentStmt->execute($studentParams);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
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

$gradeGroups = exportBuildGradeGroups($gradeColumns);
$totalMeetings = max(1, (int) exportGradeSetting($conn, 'total_meetings_' . $settingScope, '11'));
$scoreWeight = array_sum(array_map(fn($group) => (float) $group['weight'], $gradeGroups));
$attendanceWeight = max(0, 100 - $scoreWeight);
$resultFilter = strtolower(trim((string) ($_GET['result_filter'] ?? 'all')));
if (!in_array($resultFilter, ['all', 'passed', 'failed'], true)) {
    $resultFilter = 'all';
}

$baseColumns = [
    'number' => '#',
    'student_number' => 'Student Number',
    'student_name' => 'Student Name',
    'course_section' => 'Course & Section',
    'facilitator' => 'Facilitator',
    'attendance_count' => 'Attendance',
    'raw_total' => 'Raw Total',
    'score_percent' => 'Score %',
    'weighted_percent' => 'Weighted %',
    'final_grade' => 'Final Grade',
    'result' => 'Result',
];
$defaultColumns = ['number', 'student_number', 'student_name', 'course_section', 'attendance_count', 'weighted_percent', 'final_grade', 'result'];
$requestedColumns = $_GET['columns'] ?? $defaultColumns;
if (!is_array($requestedColumns)) {
    $requestedColumns = [$requestedColumns];
}
$selectedBaseColumns = array_values(array_intersect($requestedColumns, array_keys($baseColumns)));
if (empty($selectedBaseColumns)) {
    $selectedBaseColumns = $defaultColumns;
}
$includeGradeColumns = isset($_GET['include_scores']) && $_GET['include_scores'] === '1';

$rows = [];
foreach ($students as $student) {
    $studentId = (int) $student['tbl_student_id'];
    $scores = $scoresByStudent[$studentId] ?? [];
    $summary = exportComputeGradeSummary($gradeColumns, $scores, $gradeGroups, $attendanceCounts[$studentId] ?? 0, $totalMeetings, $attendanceWeight);
    $passed = (float) $summary['final_grade'] <= 3.0;

    if ($resultFilter === 'passed' && !$passed) {
        continue;
    }
    if ($resultFilter === 'failed' && $passed) {
        continue;
    }

    $rows[] = [
        'student' => $student,
        'scores' => $scores,
        'summary' => $summary,
        'result' => $passed ? 'Passed' : 'Failed',
        'attendance_count' => $attendanceCounts[$studentId] ?? 0,
    ];
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Grades');

$headers = [];
foreach ($selectedBaseColumns as $columnKey) {
    $headers[] = $baseColumns[$columnKey];
}
if ($includeGradeColumns) {
    foreach ($gradeColumns as $column) {
        $headers[] = $column['label'] . ' / ' . number_format((float) $column['max_score'], 0);
    }
}

$columnCount = max(count($headers), 1);
$lastColumn = Coordinate::stringFromColumnIndex($columnCount);
$rowNumber = 1;
$titleProgram = $currentProgram ? ' - ' . $currentProgram : '';
$sheet->mergeCells("A{$rowNumber}:{$lastColumn}{$rowNumber}");
$sheet->setCellValue("A{$rowNumber}", 'STUDENT GRADES EXPORT' . $titleProgram);
$sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
]);
$rowNumber++;

$sheet->mergeCells("A{$rowNumber}:{$lastColumn}{$rowNumber}");
$sheet->setCellValue("A{$rowNumber}", 'Folder: ' . $selectedFolderLabel . ' | Result: ' . strtoupper($resultFilter));
$sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->applyFromArray([
    'font' => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']],
]);
$rowNumber += 2;

foreach ($headers as $index => $header) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . $rowNumber, $header);
}
$sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);
$rowNumber++;

foreach ($rows as $index => $row) {
    $student = $row['student'];
    $summary = $row['summary'];
    $values = [
        'number' => $index + 1,
        'student_number' => $student['student_number'] ?? '',
        'student_name' => $student['student_name'] ?? '',
        'course_section' => $student['course_section'] ?? '',
        'facilitator' => $student['facilitator_name'] ?? '',
        'attendance_count' => (int) $row['attendance_count'] . ' / ' . $totalMeetings,
        'raw_total' => number_format((float) $summary['raw_total'], 2) . ' / ' . number_format((float) $summary['max_total'], 2),
        'score_percent' => number_format((float) $summary['score_percent'], 2),
        'weighted_percent' => number_format((float) $summary['weighted_percent'], 2),
        'final_grade' => number_format((float) $summary['final_grade'], 2),
        'result' => $row['result'],
    ];

    $columnIndex = 1;
    foreach ($selectedBaseColumns as $columnKey) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex) . $rowNumber, $values[$columnKey]);
        $columnIndex++;
    }
    if ($includeGradeColumns) {
        foreach ($gradeColumns as $column) {
            $columnId = (int) $column['grade_column_id'];
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex) . $rowNumber, $row['scores'][$columnId] ?? '');
            $columnIndex++;
        }
    }

    $resultColumnIndex = array_search('result', $selectedBaseColumns, true);
    if ($resultColumnIndex !== false) {
        $cell = Coordinate::stringFromColumnIndex($resultColumnIndex + 1) . $rowNumber;
        $sheet->getStyle($cell)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $row['result'] === 'Passed' ? 'C8E6C9' : 'FFCCCB'],
            ],
        ]);
    }
    $rowNumber++;
}

if (empty($rows)) {
    $sheet->mergeCells("A{$rowNumber}:{$lastColumn}{$rowNumber}");
    $sheet->setCellValue("A{$rowNumber}", 'No students found for the selected filters.');
}

$sheet->getStyle("A4:{$lastColumn}" . max($rowNumber, 4))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
foreach (range(1, $columnCount) as $columnIndex) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
}

$filename = 'student_grades_' . $resultFilter . '_' . date('Y-m-d_H-i-s') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
