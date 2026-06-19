<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/attendance-settings.php';
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
if (!$currentUser || !canAccessStaffTools($currentUser['role'] ?? '')) {
    die('Unauthorized access');
}

function exportDateValue($value, $fallback = null) {
    $timestamp = strtotime((string) $value);
    if (!$timestamp) {
        return $fallback;
    }

    return date('Y-m-d', $timestamp);
}

function exportMonthValue($value) {
    return preg_match('/^\d{4}-\d{2}$/', (string) $value) ? (string) $value : date('Y-m');
}

function exportResolvePeriod() {
    $period = strtolower(trim((string) ($_GET['period'] ?? 'day')));
    if (!in_array($period, ['day', 'month', 'semester'], true)) {
        $period = 'day';
    }

    if ($period === 'month') {
        $month = exportMonthValue($_GET['month'] ?? date('Y-m'));
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        $label = date('F Y', strtotime($startDate));
    } elseif ($period === 'semester') {
        $startDate = exportDateValue($_GET['start_date'] ?? null, date('Y-06-01'));
        $endDate = exportDateValue($_GET['end_date'] ?? null, date('Y-12-31'));
        if (strtotime($startDate) > strtotime($endDate)) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }
        $label = date('F j, Y', strtotime($startDate)) . ' to ' . date('F j, Y', strtotime($endDate));
    } else {
        $startDate = exportDateValue($_GET['date'] ?? date('Y-m-d'), date('Y-m-d'));
        $endDate = $startDate;
        $label = date('F j, Y', strtotime($startDate));
    }

    return [$period, $startDate, $endDate, $label];
}

function exportStatusGroup($status) {
    return stripos((string) $status, 'Late') === 0 ? 'Late' : 'Present';
}

function exportAttendanceFill($statusGroup) {
    if ($statusGroup === 'Late') {
        return 'FFF3CD';
    }

    if ($statusGroup === 'Present') {
        return 'D1E7DD';
    }

    return 'F8D7DA';
}

[$period, $startDate, $endDate, $periodLabel] = exportResolvePeriod();

$userId = (int) $currentUser['user_id'];
$userRole = $currentUser['role'] ?? 'facilitator';
$program = normalizeProgram($currentUser['program'] ?? ($_SESSION['program'] ?? null));
ensureRotcAttendanceSchema($conn);
$isRotcFacilitator = $userRole === 'facilitator' && $program === 'ROTC';
$facilitatorScanRestrictionEnabled = isFacilitatorScanRestrictionEnabled($conn);
$canViewAllAttendance = $userRole === 'super_admin'
    || ($userRole === 'facilitator' && !$facilitatorScanRestrictionEnabled);

if ($canViewAllAttendance) {
    $studentWhere = '';
    if ($userRole !== 'super_admin' && $program === 'ROTC') {
        $studentWhere = 'WHERE ' . rotcMs1StudentSqlCondition('s');
    }

    $studentSql = "
        SELECT s.tbl_student_id, s.student_number, s.student_name, s.course_section,
               COALESCE(NULLIF(u.full_name, ''), u.username, 'Unassigned') AS facilitator_name
        FROM tbl_student s
        LEFT JOIN tbl_users u ON s.created_by = u.user_id
        {$studentWhere}
        ORDER BY s.course_section ASC, s.student_name ASC
    ";
    $studentStmt = $conn->prepare($studentSql);
    $studentStmt->execute();
    $preparedBy = $userRole === 'super_admin' ? 'SUPER ADMIN' : strtoupper($_SESSION['username'] ?? 'FACILITATOR');
} elseif ($userRole === 'coordinator') {
    if ($program === 'ROTC') {
        $studentSql = "
            SELECT s.tbl_student_id, s.student_number, s.student_name, s.course_section,
                   COALESCE(NULLIF(u.full_name, ''), u.username, 'Unassigned') AS facilitator_name
            FROM tbl_student s
            LEFT JOIN tbl_users u ON s.created_by = u.user_id
            WHERE " . rotcStudentSqlCondition('s') . "
            ORDER BY s.course_section ASC, s.student_name ASC
        ";
        $studentStmt = $conn->prepare($studentSql);
        $studentStmt->execute();
    } else {
        $studentSql = "
            SELECT s.tbl_student_id, s.student_number, s.student_name, s.course_section,
                   COALESCE(NULLIF(u.full_name, ''), u.username, 'Unassigned') AS facilitator_name
            FROM tbl_student s
            LEFT JOIN tbl_users u ON s.created_by = u.user_id
            WHERE (u.role = 'facilitator' AND u.program = :program)
               OR s.course_section = :program_section
            ORDER BY s.course_section ASC, s.student_name ASC
        ";
        $studentStmt = $conn->prepare($studentSql);
        $studentStmt->execute([
            ':program' => $program,
            ':program_section' => $program,
        ]);
    }
    $preparedBy = strtoupper(($program ?: 'NSTP') . ' COORDINATOR');
} else {
    $facilitatorStudentAccessCondition = "(s.created_by = :creator_user_id OR ads.user_id = :section_user_id"
        . ($isRotcFacilitator ? " OR " . rotcMs1StudentSqlCondition('s') : "")
        . ")";
    if ($program === 'ROTC') {
        $facilitatorStudentAccessCondition = "({$facilitatorStudentAccessCondition} AND " . rotcMs1StudentSqlCondition('s') . ")";
    }

    $studentSql = "
        SELECT DISTINCT s.tbl_student_id, s.student_number, s.student_name, s.course_section,
               COALESCE(NULLIF(u.full_name, ''), u.username, 'Facilitator') AS facilitator_name
        FROM tbl_student s
        LEFT JOIN tbl_admin_sections ads ON ads.course_section = s.course_section
        LEFT JOIN tbl_users u ON s.created_by = u.user_id
        WHERE {$facilitatorStudentAccessCondition}
        ORDER BY s.course_section ASC, s.student_name ASC
    ";
    $studentStmt = $conn->prepare($studentSql);
    $studentStmt->execute([
        ':section_user_id' => $userId,
        ':creator_user_id' => $userId,
    ]);
    $preparedBy = strtoupper($_SESSION['username'] ?? 'FACILITATOR');
}

$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
$studentIds = array_map(static fn($student) => (int) $student['tbl_student_id'], $students);

$attendanceLookup = [];
$scannedDates = [];
if (!empty($studentIds)) {
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $attendanceSql = "
        SELECT tbl_student_id, time_in, status
        FROM tbl_attendance
        WHERE tbl_student_id IN ({$placeholders})
          AND DATE(time_in) BETWEEN ? AND ?
        UNION ALL
        SELECT tbl_student_id, time_in, NULL AS status
        FROM tbl_attendance_archive
        WHERE tbl_student_id IN ({$placeholders})
          AND DATE(time_in) BETWEEN ? AND ?
        ORDER BY time_in ASC
    ";
    $params = array_merge($studentIds, [$startDate, $endDate], $studentIds, [$startDate, $endDate]);
    $attendanceStmt = $conn->prepare($attendanceSql);
    $attendanceStmt->execute($params);

    foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $studentId = (int) $record['tbl_student_id'];
        $dateKey = date('Y-m-d', strtotime($record['time_in']));
        if (isset($attendanceLookup[$studentId][$dateKey])) {
            continue;
        }

        $attendanceLookup[$studentId][$dateKey] = $record;
        $scannedDates[$dateKey] = true;
    }
}

$dateColumns = array_keys($scannedDates);
sort($dateColumns);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Attendance');
$sheet->freezePane('C5');

$staticColumns = ['Student No.', 'Name'];
$lastColumn = Coordinate::stringFromColumnIndex(count($staticColumns) + count($dateColumns));
$row = 1;

$sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
$sheet->setCellValue("A{$row}", 'ATTENDANCE MATRIX REPORT');
$sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
]);
$row++;

$sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
$sheet->setCellValue("A{$row}", strtoupper($period) . ': ' . $periodLabel . ' | Prepared for: ' . $preparedBy);
$sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
    'font' => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']],
]);
$row++;

$sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
$sheet->setCellValue(
    "A{$row}",
    empty($dateColumns)
        ? 'No facilitator scans found in the selected coverage.'
        : 'Legend: Present = Green | Late = Yellow | Absent = Red'
);
$sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
    'font' => ['italic' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$row++;

$headerRow = $row;
$sheet->setCellValue('A' . $headerRow, 'Student No.');
$sheet->setCellValue('B' . $headerRow, 'Name');
foreach ($dateColumns as $index => $dateValue) {
    $column = Coordinate::stringFromColumnIndex($index + 3);
    $sheet->setCellValue($column . $headerRow, date('M d', strtotime($dateValue)) . "\nDate and Time");
}
$sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
    'font' => ['bold' => true],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true,
    ],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(34);
$row++;

if (!empty($students)) {
    foreach ($students as $student) {
        $studentId = (int) $student['tbl_student_id'];
        $sheet->setCellValue('A' . $row, $student['student_number'] ?? '');
        $sheet->setCellValue('B' . $row, $student['student_name'] ?? '');

        foreach ($dateColumns as $index => $dateValue) {
            $column = Coordinate::stringFromColumnIndex($index + 3);
            $cell = $column . $row;
            $record = $attendanceLookup[$studentId][$dateValue] ?? null;
            $statusGroup = 'Absent';
            $cellText = 'Absent';

            if ($record) {
                $status = trim((string) ($record['status'] ?? ''));
                if ($status === '') {
                    $status = getAttendanceStatusForStudent($conn, $student, $record['time_in'] ?? null);
                }
                $statusGroup = exportStatusGroup($status);
                $cellText = $statusGroup . "\n" . date('h:i A', strtotime($record['time_in']));
            }

            $sheet->setCellValue($cell, $cellText);
            $sheet->getStyle($cell)->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => exportAttendanceFill($statusGroup)],
                ],
            ]);
        }

        $row++;
    }
} else {
    $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
    $sheet->setCellValue("A{$row}", 'No students found for this account.');
    $row++;
}

$lastDataRow = max($row - 1, $headerRow);
$sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastDataRow}")->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);
$sheet->getStyle("A" . ($headerRow + 1) . ":B{$lastDataRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$sheet->getColumnDimension('A')->setWidth(16);
$sheet->getColumnDimension('B')->setWidth(28);
if (!empty($dateColumns)) {
    foreach (range(3, count($staticColumns) + count($dateColumns)) as $columnIndex) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setWidth(16);
    }
}

$sheet->getStyle("A1:{$lastColumn}{$lastDataRow}")->getFont()->setName('Calibri')->setSize(11);

$filename = 'attendance_matrix_' . $period . '_' . $startDate . '_to_' . $endDate . '_' . date('H-i-s') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
