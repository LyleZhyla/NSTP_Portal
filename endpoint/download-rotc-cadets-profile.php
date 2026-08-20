<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

$currentUser = getCurrentUserRecord($conn);
$role = $currentUser['role'] ?? '';
$program = normalizeProgram($currentUser['program'] ?? ($_SESSION['program'] ?? null));
if (!$currentUser || !in_array($role, ['super_admin', 'coordinator', 'facilitator'], true)) {
    die('Unauthorized access');
}
if ($role !== 'super_admin' && $program !== 'ROTC') {
    die('Unauthorized access');
}

$userId = (int) $currentUser['user_id'];
$folderFilter = trim((string) ($_GET['folder'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$allowedStatuses = ['', 'submitted', 'attendance_only'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

function rotcProfileColumnExists(PDO $conn, $tableName, $columnName) {
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

function rotcCleanValue($value) {
    $value = trim((string) ($value ?? ''));
    return strtoupper($value) === 'N/A' ? '' : $value;
}

function rotcMunicipalityProvince(array $row) {
    $parts = [
        rotcCleanValue($row['city_municipality'] ?? ''),
        rotcCleanValue($row['province'] ?? ''),
    ];
    $parts = array_values(array_filter($parts, fn($part) => $part !== ''));
    return implode(', ', $parts);
}

function rotcMiddleInitial($middleName) {
    $middleName = rotcCleanValue($middleName);
    return $middleName !== '' ? strtoupper(substr($middleName, 0, 1)) . '.' : '';
}

function rotcTemplateGender($value) {
    $gender = strtoupper(trim((string) $value));
    if (in_array($gender, ['M', 'MALE'], true)) {
        return 'MALE';
    }
    if (in_array($gender, ['F', 'FEMALE'], true)) {
        return 'FEMALE';
    }
    return 'UNSPECIFIED';
}

$templateHeaders = ['NR', 'LAST NAME', 'FIRST NAME', 'M.I', 'GENDER', 'DOB', 'COURSE', 'ADDRESS', 'RELIGION', 'BT', 'HEIGHT', 'CP NR', 'BENEFICIARY'];

$registrationFields = [
    'registration_id', 'student_number', 'last_name', 'extension_name', 'first_name', 'middle_name',
    'place_of_birth', 'date_of_birth', 'gender', 'religion', 'blood_type', 'contact_number', 'email',
    'province', 'city_municipality', 'barangay', 'street', 'house_no', 'emergency_name',
    'emergency_relationship', 'emergency_contact_number', 'emergency_address', 'college', 'course',
    'major', 'year_section', 'component', 'formal_picture', 'status', 'created_at',
    'height', 'rotc_ms_level', 'rotc_completion_proof',
];
$selectFields = [];
foreach ($registrationFields as $field) {
    $selectFields[] = rotcProfileColumnExists($conn, 'tbl_public_student_registrations', $field)
        ? "r.`$field`"
        : "NULL AS `$field`";
}

$folderFacilitatorId = null;
$folderName = '';
if ($folderFilter !== '') {
    if ($role === 'facilitator') {
        $folderFacilitatorId = $userId;
        $folderName = $folderFilter;
    } elseif (strpos($folderFilter, '::') !== false) {
        [$facilitatorPart, $folderPart] = explode('::', $folderFilter, 2);
        $folderFacilitatorId = (int) $facilitatorPart;
        $folderName = trim($folderPart);
    }
}

$where = ["r.registrant_role = 'student'", "r.component = 'ROTC'", "COALESCE(r.status, 'submitted') <> 'account_deleted'"];
$params = [];

if ($folderFacilitatorId && $folderName !== '') {
    $where[] = "s.created_by = ? AND s.course_section = ?";
    $params[] = $folderFacilitatorId;
    $params[] = $folderName;
}

if ($statusFilter !== '') {
    $where[] = "r.status = ?";
    $params[] = $statusFilter;
}

$query = "
    SELECT
        " . implode(",\n        ", $selectFields) . ",
        s.tbl_student_id,
        s.student_name,
        s.course_section AS folder,
        COALESCE(NULLIF(creator.full_name, ''), creator.username, 'Unassigned') AS facilitator
    FROM tbl_public_student_registrations r
    INNER JOIN (
        SELECT student_number, MAX(registration_id) AS latest_registration_id
        FROM tbl_public_student_registrations
        WHERE registrant_role = 'student'
          AND component = 'ROTC'
          AND student_number IS NOT NULL
          AND student_number <> ''
        GROUP BY student_number
    ) latest ON latest.latest_registration_id = r.registration_id
    LEFT JOIN tbl_student s ON s.student_number = r.student_number
    LEFT JOIN tbl_users creator ON creator.user_id = s.created_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.course_section ASC, r.last_name ASC, r.first_name ASC, r.student_number ASC
";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$cadets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$profileGroups = [];
foreach (['MS-41', 'MS-31', 'MS-1'] as $msLevel) {
    foreach (['MALE', 'FEMALE'] as $gender) {
        $profileGroups[$msLevel . ' ' . $gender] = [];
    }
}

foreach ($cadets as $cadet) {
    $msLevel = normalizeRotcMsLevel($cadet['rotc_ms_level'] ?? null)
        ?: normalizeRotcMsLevel($cadet['folder'] ?? null)
        ?: 'MS-1';
    $gender = rotcTemplateGender($cadet['gender'] ?? '');
    $groupLabel = $msLevel . ' ' . $gender;
    if (!isset($profileGroups[$groupLabel])) {
        $profileGroups[$groupLabel] = [];
    }
    $profileGroups[$groupLabel][] = $cadet;
}

foreach ($profileGroups as &$groupCadets) {
    usort($groupCadets, static function ($left, $right) {
        $lastNameComparison = strnatcasecmp((string) ($left['last_name'] ?? ''), (string) ($right['last_name'] ?? ''));
        if ($lastNameComparison !== 0) {
            return $lastNameComparison;
        }
        $firstNameComparison = strnatcasecmp((string) ($left['first_name'] ?? ''), (string) ($right['first_name'] ?? ''));
        if ($firstNameComparison !== 0) {
            return $firstNameComparison;
        }
        return strnatcasecmp((string) ($left['student_number'] ?? ''), (string) ($right['student_number'] ?? ''));
    });
}
unset($groupCadets);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Cadets Profile');
$sheet->setShowGridlines(false);
$spreadsheet->getDefaultStyle()->getFont()->setName('Arial Narrow')->setSize(8);

$sheet->mergeCells('C1:K1');
$sheet->setCellValue('C1', "H E A D Q U A R T E R S\nTARLAC AGRICULTURAL UNIVERSITY ROTC UNIT\n302nd (TLC) Community Defense Center, 3RCDG, RESCOM, PA\nBrgy Malacampa, Camiling, Tarlac");
$sheet->getStyle('C1:K1')->getAlignment()
    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
    ->setVertical(Alignment::VERTICAL_CENTER)
    ->setWrapText(true);
$sheet->getStyle('C1:K1')->getFont()->setName('Arial Narrow')->setSize(9);
$sheet->getRowDimension(1)->setRowHeight(58);

$leftLogoPath = dirname(__DIR__) . '/include/logos/nstp.png';
if (is_file($leftLogoPath)) {
    $leftLogo = new Drawing();
    $leftLogo->setName('TAU NSTP Logo');
    $leftLogo->setPath($leftLogoPath);
    $leftLogo->setHeight(55);
    $leftLogo->setCoordinates('A1');
    $leftLogo->setOffsetX(8);
    $leftLogo->setOffsetY(2);
    $leftLogo->setWorksheet($sheet);
}
$rightLogoPath = dirname(__DIR__) . '/include/logos/rotc.png';
if (is_file($rightLogoPath)) {
    $rightLogo = new Drawing();
    $rightLogo->setName('TAU ROTC Logo');
    $rightLogo->setPath($rightLogoPath);
    $rightLogo->setHeight(55);
    $rightLogo->setCoordinates('L1');
    $rightLogo->setOffsetX(8);
    $rightLogo->setOffsetY(2);
    $rightLogo->setWorksheet($sheet);
}

$sheet->mergeCells('A2:M2');
$sheet->setCellValue('A2', 'CADETS PROFILE');
$sheet->getStyle('A2:M2')->getFont()->setName('Arial Narrow')->setBold(true)->setSize(10);
$sheet->getStyle('A2:M2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getRowDimension(2)->setRowHeight(18);

$rowNumber = 4;
foreach ($profileGroups as $groupLabel => $groupCadets) {
    $sheet->mergeCells("A{$rowNumber}:M" . ($rowNumber + 1));
    $sheet->setCellValue("A{$rowNumber}", $groupLabel);
    $sheet->getStyle("A{$rowNumber}:M" . ($rowNumber + 1))->applyFromArray([
        'font' => ['name' => 'Arial Narrow', 'bold' => true, 'size' => 9],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
        'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN]],
    ]);
    $sheet->getRowDimension($rowNumber)->setRowHeight(9);
    $sheet->getRowDimension($rowNumber + 1)->setRowHeight(9);
    $rowNumber += 2;

    foreach ($templateHeaders as $columnIndex => $header) {
        $sheet->setCellValue([$columnIndex + 1, $rowNumber], $header);
    }
    $sheet->getStyle("A{$rowNumber}:M{$rowNumber}")->applyFromArray([
        'font' => ['name' => 'Arial Narrow', 'bold' => true, 'size' => 8],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ]);
    $sheet->getRowDimension($rowNumber)->setRowHeight(16);
    $rowNumber++;

    $rowsToWrite = $groupCadets ?: [null];
    foreach ($rowsToWrite as $cadetIndex => $cadet) {
        $values = array_fill(0, 13, '');
        if ($cadet !== null) {
            $dateOfBirth = '';
            if (!empty($cadet['date_of_birth']) && strtotime($cadet['date_of_birth']) && $cadet['date_of_birth'] !== '1900-01-01') {
                $dateOfBirth = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime($cadet['date_of_birth']));
            }
            $values = [
                $cadetIndex + 1,
                rotcCleanValue($cadet['last_name'] ?? ''),
                rotcCleanValue($cadet['first_name'] ?? ''),
                rotcMiddleInitial($cadet['middle_name'] ?? ''),
                rotcTemplateGender($cadet['gender'] ?? ''),
                $dateOfBirth,
                rotcCleanValue($cadet['course'] ?? ''),
                rotcMunicipalityProvince($cadet),
                rotcCleanValue($cadet['religion'] ?? ''),
                rotcCleanValue($cadet['blood_type'] ?? ''),
                rotcCleanValue($cadet['height'] ?? ''),
                rotcCleanValue($cadet['contact_number'] ?? ''),
                rotcCleanValue($cadet['emergency_name'] ?? ''),
            ];
        }

        foreach ($values as $columnIndex => $value) {
            if (in_array($columnIndex, [1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12], true)) {
                $sheet->setCellValueExplicit([$columnIndex + 1, $rowNumber], (string) $value, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue([$columnIndex + 1, $rowNumber], $value);
            }
        }
        if ($cadet !== null && $values[5] !== '') {
            $sheet->getStyle("F{$rowNumber}")->getNumberFormat()->setFormatCode('dd-mmm-yy');
        }
        $sheet->getStyle("A{$rowNumber}:M{$rowNumber}")->applyFromArray([
            'font' => ['name' => 'Arial Narrow', 'size' => 8],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet->getRowDimension($rowNumber)->setRowHeight(16);
        $rowNumber++;
    }
}

$signatureStartRow = $rowNumber + 2;
$sheet->setCellValue("B{$signatureStartRow}", 'Prepared By:');
$sheet->setCellValue("J{$signatureStartRow}", 'CERTIFIED CORRECT BY:');
$sheet->setCellValue('C' . ($signatureStartRow + 2), 'Ron Ryner B Nesperos');
$sheet->setCellValue('C' . ($signatureStartRow + 3), 'Sgt                    (Inf) PA');
$sheet->setCellValue('C' . ($signatureStartRow + 4), 'Admin NCO');
$sheet->setCellValue('J' . ($signatureStartRow + 2), 'WILLY   P   JAZMIN');
$sheet->setCellValue('J' . ($signatureStartRow + 3), 'LTC GSC PA (RES)');
$sheet->setCellValue('J' . ($signatureStartRow + 4), 'Commandant');
$sheet->getStyle("A{$signatureStartRow}:M" . ($signatureStartRow + 4))->getFont()->setName('Arial Narrow')->setSize(8);
$sheet->getStyle('C' . ($signatureStartRow + 2) . ':C' . ($signatureStartRow + 4))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('J' . ($signatureStartRow + 2) . ':J' . ($signatureStartRow + 4))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('J' . ($signatureStartRow + 2))->getFont()->setBold(true);

$columnWidths = ['A' => 5, 'B' => 15, 'C' => 15, 'D' => 6, 'E' => 9, 'F' => 11, 'G' => 18, 'H' => 25, 'I' => 13, 'J' => 6, 'K' => 9, 'L' => 14, 'M' => 22];
foreach ($columnWidths as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}
$sheet->freezePane('A4');
$sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
$sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
$sheet->getPageMargins()->setTop(0.3)->setRight(0.25)->setLeft(0.25)->setBottom(0.3);
$sheet->getPageSetup()->setPrintArea("A1:M" . ($signatureStartRow + 4));
$sheet->getHeaderFooter()->setOddFooter('&RPage &P of &N');

$filename = 'rotc_cadets_profile_' . date('Y-m-d_H-i-s') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
