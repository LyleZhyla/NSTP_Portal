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

$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!strtotime($selectedDate)) {
    die('Invalid date format');
}
$selectedDate = date('Y-m-d', strtotime($selectedDate));

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessStaffTools($currentUser['role'] ?? '')) {
    die('Unauthorized access');
}

$userId = (int) $currentUser['user_id'];
$userRole = $currentUser['role'] ?? 'facilitator';
$program = normalizeProgram($currentUser['program'] ?? ($_SESSION['program'] ?? null));
$isRotcFacilitator = $userRole === 'facilitator'
    && normalizeProgram($currentUser['program'] ?? ($_SESSION['program'] ?? null)) === 'ROTC';
$facilitatorScanRestrictionEnabled = isFacilitatorScanRestrictionEnabled($conn);
$canViewAllAttendance = $userRole === 'super_admin'
    || ($userRole === 'facilitator' && !$facilitatorScanRestrictionEnabled);
$statusFilter = strtolower(trim((string) ($_GET['status_filter'] ?? 'all')));
$allowedStatusFilters = ['all', 'present', 'on_time', 'late', 'absent'];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
}

$availableColumns = [
    'number' => '#',
    'student_number' => 'Student Number',
    'student_name' => 'Student Name',
    'course_section' => 'Course & Section',
    'facilitator' => 'Facilitator',
    'status' => 'Status',
    'time_in' => 'Time In',
];
$defaultColumns = ['number', 'student_number', 'student_name', 'course_section', 'status', 'time_in'];
$requestedColumns = $_GET['columns'] ?? $defaultColumns;
if (!is_array($requestedColumns)) {
    $requestedColumns = [$requestedColumns];
}
$selectedColumns = array_values(array_intersect($requestedColumns, array_keys($availableColumns)));
if (empty($selectedColumns)) {
    $selectedColumns = $defaultColumns;
}

if ($canViewAllAttendance) {
    $studentWhere = '';
    if ($userRole !== 'super_admin' && $program === 'ROTC') {
        $studentWhere = 'WHERE ' . rotcStudentSqlCondition('s');
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
    $adminDisplay = $userRole === 'super_admin'
        ? 'SUPER ADMIN'
        : strtoupper($_SESSION['username'] ?? 'FACILITATOR');
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
    $adminDisplay = strtoupper(($program ?: 'NSTP') . ' COORDINATOR');
} else {
    $facilitatorStudentAccessCondition = "(s.created_by = :creator_user_id OR ads.user_id = :section_user_id"
        . ($isRotcFacilitator ? " OR " . rotcStudentSqlCondition('s') : "")
        . ")";
    if ($program === 'ROTC') {
        $facilitatorStudentAccessCondition = "({$facilitatorStudentAccessCondition} AND " . rotcStudentSqlCondition('s') . ")";
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
    $adminDisplay = strtoupper($_SESSION['username'] ?? 'FACILITATOR');
}

$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
$attendanceStmt = $conn->prepare("
    SELECT tbl_student_id, TIME(time_in) AS attendance_time, time_in, status
    FROM tbl_attendance
    WHERE DATE(time_in) = ?
    ORDER BY time_in ASC
");
$attendanceStmt->execute([$selectedDate]);
$attendanceLookup = [];
foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
    $attendanceLookup[(int) $record['tbl_student_id']] = $record;
}

$studentsBySection = [];
foreach ($students as $student) {
    $studentId = (int) $student['tbl_student_id'];
    $record = $attendanceLookup[$studentId] ?? null;
    $status = 'Absent';
    $statusGroup = 'Absent';
    $timeIn = '';
    if ($record) {
        $rawStatus = trim((string) ($record['status'] ?? ''));
        $status = $rawStatus !== ''
            ? $rawStatus
            : getAttendanceStatus($conn, $student['course_section'] ?? '', $record['time_in'] ?? null);
        $statusGroup = stripos($status, 'Late') === 0 ? 'Late' : 'On Time';
        $timeIn = date('h:i A', strtotime($record['time_in']));
    }

    if ($statusFilter === 'present' && !$record) {
        continue;
    }
    if ($statusFilter === 'on_time' && $statusGroup !== 'On Time') {
        continue;
    }
    if ($statusFilter === 'late' && $statusGroup !== 'Late') {
        continue;
    }
    if ($statusFilter === 'absent' && $statusGroup !== 'Absent') {
        continue;
    }

    $student['computed_status'] = $status;
    $student['computed_status_group'] = $statusGroup;
    $student['computed_time_in'] = $timeIn;
    $section = trim((string) ($student['course_section'] ?? '')) ?: 'No Section';
    $studentsBySection[$section][] = $student;
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Attendance');

$columnCount = count($selectedColumns);
$lastColumn = Coordinate::stringFromColumnIndex($columnCount);
$row = 1;

$sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
$sheet->setCellValue("A{$row}", 'ATTENDANCE REPORT - ' . date('F j, Y', strtotime($selectedDate)));
$sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
]);
$row++;

$sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
$filterLabel = ucwords(str_replace('_', ' ', $statusFilter));
$sheet->setCellValue("A{$row}", $adminDisplay . ' | STATUS: ' . strtoupper($filterLabel));
$sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
    'font' => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']],
]);
$row += 2;

foreach ($selectedColumns as $index => $columnKey) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . $row, $availableColumns[$columnKey]);
}
$sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);
$row++;

if (!empty($studentsBySection)) {
    foreach ($studentsBySection as $section => $sectionStudents) {
        $present = 0;
        $late = 0;
        $onTime = 0;
        foreach ($sectionStudents as $student) {
            if ($student['computed_status_group'] !== 'Absent') {
                $present++;
            }
            if ($student['computed_status_group'] === 'Late') {
                $late++;
            }
            if ($student['computed_status_group'] === 'On Time') {
                $onTime++;
            }
        }
        $absent = count($sectionStudents) - $present;

        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->setCellValue("A{$row}", 'SECTION: ' . strtoupper($section) . ' (' . count($sectionStudents) . ' records)');
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EDF7']],
        ]);
        $row++;

        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->setCellValue("A{$row}", "Summary - Present: {$present} | Late: {$late} | On Time: {$onTime} | Absent: {$absent}");
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFont()->setItalic(true);
        $row++;

        $counter = 1;
        foreach ($sectionStudents as $student) {
            $values = [
                'number' => $counter,
                'student_number' => $student['student_number'] ?? '',
                'student_name' => $student['student_name'] ?? '',
                'course_section' => $student['course_section'] ?? '',
                'facilitator' => $student['facilitator_name'] ?? '',
                'status' => $student['computed_status'],
                'time_in' => $student['computed_time_in'] ?: '-',
            ];

            foreach ($selectedColumns as $index => $columnKey) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . $row, $values[$columnKey]);
            }

            $statusColumnIndex = array_search('status', $selectedColumns, true);
            if ($statusColumnIndex !== false) {
                $cell = Coordinate::stringFromColumnIndex($statusColumnIndex + 1) . $row;
                $color = $student['computed_status_group'] === 'On Time' ? 'C8E6C9' : ($student['computed_status_group'] === 'Late' ? 'FFCCCB' : 'FFEBEE');
                $sheet->getStyle($cell)->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $color]],
                ]);
            }

            $row++;
            $counter++;
        }
        $row++;
    }
} else {
    $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
    $sheet->setCellValue("A{$row}", 'No attendance records found for the selected filters.');
}

$sheet->getStyle("A4:{$lastColumn}" . max($row - 1, 4))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
foreach (range(1, $columnCount) as $columnIndex) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
}

$filename = 'attendance_report_' . $selectedDate . '_' . $statusFilter . '_' . date('H-i-s') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
