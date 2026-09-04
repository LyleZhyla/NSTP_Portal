<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/student-component-counts.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('Unauthorized access');
}

$currentUser = getCurrentUserRecord($conn);
$role = $currentUser['role'] ?? '';
if (!$currentUser || !in_array($role, ['super_admin', 'coordinator', 'facilitator'], true)) {
    http_response_code(403);
    exit('Unauthorized access');
}

$userProgram = normalizeProgram($currentUser['program'] ?? ($_SESSION['program'] ?? null));
$requestedComponent = normalizeProgram($_GET['component'] ?? null);
if ($role === 'super_admin') {
    $components = $requestedComponent ? [$requestedComponent] : ['CWTS', 'LTS', 'ROTC'];
} else {
    if (!$userProgram) {
        http_response_code(400);
        exit('Your account does not have an assigned component.');
    }
    $components = [$userProgram];
}

function sizeSummaryNormalizeSize($value) {
    $size = strtoupper(trim((string) $value));
    $size = preg_replace('/\s+/', ' ', $size);
    $compact = str_replace([' ', '-'], '', $size);
    $aliases = [
        'EXTRASMALL' => 'XS',
        'SMALL' => 'S',
        'MEDIUM' => 'M',
        'LARGE' => 'L',
        'EXTRALARGE' => 'XL',
        'XXL' => '2XL',
        'XXXL' => '3XL',
        'XXXXL' => '4XL',
        'XXXXXL' => '5XL',
    ];
    if ($size === '') {
        return 'NOT SET';
    }
    if (isset($aliases[$compact])) {
        return $aliases[$compact];
    }
    if (preg_match('/^[2-9]XL$/', $compact)) {
        return $compact;
    }
    if (in_array($compact, ['XS', 'S', 'M', 'L', 'XL'], true)) {
        return $compact;
    }
    return $size;
}

function sizeSummaryOrder(array $sizes) {
    $preferred = ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL', 'MAY NABILI NANG SHIRT', 'NOT SET'];
    $rank = array_flip($preferred);
    usort($sizes, static function ($left, $right) use ($rank) {
        $leftRank = $rank[$left] ?? 100;
        $rightRank = $rank[$right] ?? 100;
        return $leftRank !== $rightRank ? $leftRank <=> $rightRank : strnatcasecmp($left, $right);
    });
    return $sizes;
}

function sizeSummaryColumnExists(PDO $conn, $tableName, $columnName) {
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

$accountShirtSizeExpression = sizeSummaryColumnExists($conn, 'tbl_users', 'shirt_size')
    ? 'student_user.shirt_size'
    : 'NULL';
$registrationShirtSizeExpression = sizeSummaryColumnExists($conn, 'tbl_public_student_registrations', 'shirt_size')
    ? 'registration.shirt_size'
    : 'NULL';

$stmt = $conn->query("
    SELECT
        s.tbl_student_id,
        s.user_id,
        s.student_number,
        s.student_name,
        s.course_section,
        student_user.program AS account_program,
        {$accountShirtSizeExpression} AS account_shirt_size,
        creator.role AS creator_role,
        creator.program AS creator_program,
        registration.component AS registration_component,
        {$registrationShirtSizeExpression} AS registration_shirt_size
    FROM tbl_student s
    LEFT JOIN tbl_users student_user ON student_user.user_id = s.user_id AND student_user.role = 'student'
    LEFT JOIN tbl_users creator ON creator.user_id = s.created_by
    LEFT JOIN tbl_public_student_registrations registration
      ON registration.registration_id = (
            SELECT latest_registration.registration_id
            FROM tbl_public_student_registrations latest_registration
            WHERE latest_registration.registrant_role = 'student'
              AND COALESCE(latest_registration.status, 'submitted') NOT IN ('attendance_only', 'account_deleted')
              AND (
                    (s.user_id IS NOT NULL AND latest_registration.user_id = s.user_id)
                    OR (NULLIF(TRIM(s.student_number), '') IS NOT NULL AND latest_registration.student_number = s.student_number)
              )
            ORDER BY latest_registration.registration_id DESC
            LIMIT 1
      )
    ORDER BY s.tbl_student_id DESC
");

$counts = [];
$componentTotals = [];
foreach ($components as $component) {
    $counts[$component] = [];
    $componentTotals[$component] = 0;
}

$seenIdentities = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $student) {
    $identityKey = studentManagementIdentityKey($student);
    if (isset($seenIdentities[$identityKey])) {
        continue;
    }
    $seenIdentities[$identityKey] = true;

    $component = resolveStudentComponentFromSources(
        $student['account_program'] ?? null,
        $student['registration_component'] ?? null,
        $student['course_section'] ?? null,
        $student['creator_role'] ?? null,
        $student['creator_program'] ?? null
    );
    if (!$component || !in_array($component, $components, true)) {
        continue;
    }

    $shirtSize = sizeSummaryNormalizeSize(
        trim((string) ($student['account_shirt_size'] ?? '')) !== ''
            ? $student['account_shirt_size']
            : ($student['registration_shirt_size'] ?? '')
    );
    $counts[$component][$shirtSize] = ($counts[$component][$shirtSize] ?? 0) + 1;
    $componentTotals[$component]++;
}

$allSizes = array_fill_keys(
    ['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL', 'MAY NABILI NANG SHIRT', 'NOT SET'],
    true
);
foreach ($counts as $componentCounts) {
    foreach (array_keys($componentCounts) as $size) {
        $allSizes[$size] = true;
    }
}
if (!$allSizes) {
    $allSizes['NOT SET'] = true;
}
$sizes = sizeSummaryOrder(array_keys($allSizes));

$spreadsheet = new Spreadsheet();
$summary = $spreadsheet->getActiveSheet();
$summary->setTitle('Summary');
$lastColumnIndex = count($components) + 2;
$lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);

$summary->mergeCells("A1:{$lastColumn}1");
$summary->setCellValue('A1', 'NSTP SHIRT SIZE SUMMARY');
$summary->mergeCells("A2:{$lastColumn}2");
$summary->setCellValue('A2', 'Generated: ' . date('F d, Y h:i A'));
$summary->getStyle("A1:{$lastColumn}1")->applyFromArray([
    'font' => ['bold' => true, 'size' => 15, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$summary->getStyle("A2:{$lastColumn}2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$headerRow = 4;
$summary->setCellValue('A' . $headerRow, 'Shirt Size');
foreach ($components as $index => $component) {
    $summary->setCellValue(Coordinate::stringFromColumnIndex($index + 2) . $headerRow, $component);
}
$summary->setCellValue($lastColumn . $headerRow, 'Total');

$row = $headerRow + 1;
foreach ($sizes as $size) {
    $summary->setCellValue('A' . $row, $size);
    $rowTotal = 0;
    foreach ($components as $index => $component) {
        $value = (int) ($counts[$component][$size] ?? 0);
        $summary->setCellValue(Coordinate::stringFromColumnIndex($index + 2) . $row, $value);
        $rowTotal += $value;
    }
    $summary->setCellValue($lastColumn . $row, $rowTotal);
    $row++;
}

$summary->setCellValue('A' . $row, 'GRAND TOTAL');
foreach ($components as $index => $component) {
    $summary->setCellValue(Coordinate::stringFromColumnIndex($index + 2) . $row, $componentTotals[$component]);
}
$summary->setCellValue($lastColumn . $row, array_sum($componentTotals));

$summary->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$summary->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EAD3']],
]);
$summary->getStyle("A{$headerRow}:{$lastColumn}{$row}")->applyFromArray([
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B7C4D3']]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$summary->getStyle("B" . ($headerRow + 1) . ":{$lastColumn}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$summary->getColumnDimension('A')->setWidth(26);
for ($columnIndex = 2; $columnIndex <= $lastColumnIndex; $columnIndex++) {
    $summary->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setWidth(14);
}
$summary->freezePane('B5');
$summary->setAutoFilter("A{$headerRow}:{$lastColumn}" . ($row - 1));

foreach ($components as $component) {
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle($component);
    $sheet->mergeCells('A1:C1');
    $sheet->setCellValue('A1', $component . ' SHIRT SIZE SUMMARY');
    $sheet->fromArray(['Shirt Size', 'Count', 'Percentage'], null, 'A3');
    $componentRow = 4;
    foreach ($sizes as $size) {
        $count = (int) ($counts[$component][$size] ?? 0);
        if ($count === 0) {
            continue;
        }
        $percentage = $componentTotals[$component] > 0 ? $count / $componentTotals[$component] : 0;
        $sheet->fromArray([$size, $count, $percentage], null, 'A' . $componentRow);
        $sheet->getStyle('C' . $componentRow)->getNumberFormat()->setFormatCode('0.00%');
        $componentRow++;
    }
    $sheet->fromArray(['TOTAL', $componentTotals[$component], $componentTotals[$component] > 0 ? 1 : 0], null, 'A' . $componentRow);
    $sheet->getStyle('C' . $componentRow)->getNumberFormat()->setFormatCode('0.00%');
    $sheet->getStyle('A1:C1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->getStyle('A3:C3')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
    ]);
    $sheet->getStyle("A{$componentRow}:C{$componentRow}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EAD3']],
    ]);
    $sheet->getStyle("A3:C{$componentRow}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B7C4D3']]],
    ]);
    $sheet->getColumnDimension('A')->setWidth(28);
    $sheet->getColumnDimension('B')->setWidth(14);
    $sheet->getColumnDimension('C')->setWidth(16);
    $sheet->freezePane('A4');
}

$spreadsheet->setActiveSheetIndex(0);
$scopeName = count($components) === 1 ? strtolower($components[0]) : 'all-components';
$fileName = 'shirt-size-summary-' . $scopeName . '-' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
