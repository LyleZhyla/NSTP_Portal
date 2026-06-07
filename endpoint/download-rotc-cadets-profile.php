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

function rotcFullName(array $row) {
    $lastName = rotcCleanValue($row['last_name'] ?? '');
    $firstName = rotcCleanValue($row['first_name'] ?? '');
    $middleName = rotcCleanValue($row['middle_name'] ?? '');
    $extensionName = rotcCleanValue($row['extension_name'] ?? '');

    $name = trim($lastName . ', ' . $firstName);
    $suffix = trim($middleName . ' ' . $extensionName);
    if ($suffix !== '') {
        $name .= ' ' . $suffix;
    }

    return trim($name, ' ,') ?: ($row['student_name'] ?? '');
}

function rotcCompleteAddress(array $row) {
    $parts = [
        rotcCleanValue($row['house_no'] ?? ''),
        rotcCleanValue($row['street'] ?? ''),
        rotcCleanValue($row['barangay'] ?? ''),
        rotcCleanValue($row['city_municipality'] ?? ''),
        rotcCleanValue($row['province'] ?? ''),
    ];
    $parts = array_values(array_filter($parts, fn($part) => $part !== ''));
    return implode(', ', $parts);
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

function rotcAge($dateOfBirth) {
    if (!$dateOfBirth || !strtotime($dateOfBirth) || $dateOfBirth === '1900-01-01') {
        return '';
    }
    $birthDate = new DateTime(date('Y-m-d', strtotime($dateOfBirth)));
    return $birthDate->diff(new DateTime('today'))->y;
}

$availableColumns = [
    'number' => 'NR',
    'student_number' => 'Student Number',
    'full_name' => 'Full Name',
    'last_name' => 'LAST NAME',
    'first_name' => 'FIRST NAME',
    'middle_initial' => 'M.I',
    'middle_name' => 'Middle Name',
    'extension_name' => 'Extension Name',
    'gender' => 'GENDER',
    'date_of_birth' => 'DOB',
    'age' => 'Age',
    'place_of_birth' => 'Place of Birth',
    'blood_type' => 'BT',
    'religion' => 'RELIGION',
    'contact_number' => 'CP NR',
    'email' => 'Email',
    'complete_address' => 'Complete Address',
    'address' => 'ADDRESS',
    'province' => 'Province',
    'city_municipality' => 'City/Municipality',
    'barangay' => 'Barangay',
    'street' => 'Street',
    'house_no' => 'House No.',
    'college' => 'College',
    'course' => 'COURSE',
    'major' => 'Major',
    'year_section' => 'Year/Section',
    'folder' => 'ROTC Folder',
    'facilitator' => 'Facilitator',
    'height' => 'HEIGHT',
    'rotc_ms_level' => 'MS Level',
    'rotc_completion_proof' => 'Completion Proof',
    'beneficiary' => 'BENEFICIARY',
    'emergency_name' => 'Emergency Contact Name',
    'emergency_relationship' => 'Emergency Relationship',
    'emergency_contact_number' => 'Emergency Contact Number',
    'emergency_address' => 'Emergency Address',
    'formal_picture' => 'Formal Picture Path',
    'status' => 'Registration Status',
    'created_at' => 'Registered At',
];
$defaultColumns = ['number', 'last_name', 'first_name', 'middle_initial', 'gender', 'date_of_birth', 'course', 'address', 'religion', 'blood_type', 'height', 'rotc_ms_level', 'contact_number', 'beneficiary'];
$requestedColumns = $_GET['columns'] ?? $defaultColumns;
if (!is_array($requestedColumns)) {
    $requestedColumns = [$requestedColumns];
}
$selectedColumns = array_values(array_intersect($requestedColumns, array_keys($availableColumns)));
if (empty($selectedColumns)) {
    $selectedColumns = $defaultColumns;
}

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

$where = ["r.registrant_role = 'student'", "r.component = 'ROTC'"];
$params = [];

if ($role === 'facilitator') {
    $where[] = "s.created_by = ?";
    $params[] = $userId;
}

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

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('ROTC Cadets Profile');

$columnCount = count($selectedColumns);
$lastColumn = Coordinate::stringFromColumnIndex($columnCount);
$rowNumber = 1;

$sheet->mergeCells("A{$rowNumber}:{$lastColumn}{$rowNumber}");
$sheet->setCellValue("A{$rowNumber}", "ROTC CADETS' PROFILE");
$sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->applyFromArray([
    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '198754']],
]);
$rowNumber++;

$sheet->mergeCells("A{$rowNumber}:{$lastColumn}{$rowNumber}");
$folderLabel = $folderName !== '' ? $folderName : 'All Accessible ROTC Folders';
$statusLabel = $statusFilter !== '' ? $statusFilter : 'all statuses';
$sheet->setCellValue("A{$rowNumber}", 'Folder: ' . $folderLabel . ' | Status: ' . strtoupper($statusLabel));
$sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->applyFromArray([
    'font' => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E3F2FD']],
]);
$rowNumber += 2;

foreach ($selectedColumns as $index => $columnKey) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . $rowNumber, $availableColumns[$columnKey]);
}
$sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->applyFromArray([
    'font' => ['bold' => true],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);
$rowNumber++;

foreach ($cadets as $index => $cadet) {
    $dateOfBirth = '';
    if (!empty($cadet['date_of_birth']) && strtotime($cadet['date_of_birth']) && $cadet['date_of_birth'] !== '1900-01-01') {
        $dateOfBirth = date('m/d/Y', strtotime($cadet['date_of_birth']));
    }

    $createdAt = '';
    if (!empty($cadet['created_at']) && strtotime($cadet['created_at'])) {
        $createdAt = date('m/d/Y h:i A', strtotime($cadet['created_at']));
    }

    $values = [
        'number' => $index + 1,
        'student_number' => $cadet['student_number'] ?? '',
        'full_name' => rotcFullName($cadet),
        'last_name' => rotcCleanValue($cadet['last_name'] ?? ''),
        'first_name' => rotcCleanValue($cadet['first_name'] ?? ''),
        'middle_initial' => rotcMiddleInitial($cadet['middle_name'] ?? ''),
        'middle_name' => rotcCleanValue($cadet['middle_name'] ?? ''),
        'extension_name' => rotcCleanValue($cadet['extension_name'] ?? ''),
        'gender' => rotcCleanValue($cadet['gender'] ?? ''),
        'date_of_birth' => $dateOfBirth,
        'age' => rotcAge($cadet['date_of_birth'] ?? ''),
        'place_of_birth' => rotcCleanValue($cadet['place_of_birth'] ?? ''),
        'blood_type' => rotcCleanValue($cadet['blood_type'] ?? ''),
        'religion' => rotcCleanValue($cadet['religion'] ?? ''),
        'contact_number' => rotcCleanValue($cadet['contact_number'] ?? ''),
        'email' => rotcCleanValue($cadet['email'] ?? ''),
        'complete_address' => rotcCompleteAddress($cadet),
        'address' => rotcMunicipalityProvince($cadet),
        'province' => rotcCleanValue($cadet['province'] ?? ''),
        'city_municipality' => rotcCleanValue($cadet['city_municipality'] ?? ''),
        'barangay' => rotcCleanValue($cadet['barangay'] ?? ''),
        'street' => rotcCleanValue($cadet['street'] ?? ''),
        'house_no' => rotcCleanValue($cadet['house_no'] ?? ''),
        'college' => rotcCleanValue($cadet['college'] ?? ''),
        'course' => rotcCleanValue($cadet['course'] ?? ''),
        'major' => rotcCleanValue($cadet['major'] ?? ''),
        'year_section' => rotcCleanValue($cadet['year_section'] ?? ''),
        'folder' => rotcCleanValue($cadet['folder'] ?? ''),
        'facilitator' => rotcCleanValue($cadet['facilitator'] ?? ''),
        'height' => rotcCleanValue($cadet['height'] ?? ''),
        'rotc_ms_level' => rotcCleanValue($cadet['rotc_ms_level'] ?? ''),
        'rotc_completion_proof' => rotcCleanValue($cadet['rotc_completion_proof'] ?? ''),
        'beneficiary' => rotcCleanValue($cadet['emergency_name'] ?? ''),
        'emergency_name' => rotcCleanValue($cadet['emergency_name'] ?? ''),
        'emergency_relationship' => rotcCleanValue($cadet['emergency_relationship'] ?? ''),
        'emergency_contact_number' => rotcCleanValue($cadet['emergency_contact_number'] ?? ''),
        'emergency_address' => rotcCleanValue($cadet['emergency_address'] ?? ''),
        'formal_picture' => rotcCleanValue($cadet['formal_picture'] ?? ''),
        'status' => rotcCleanValue($cadet['status'] ?? ''),
        'created_at' => $createdAt,
    ];

    foreach ($selectedColumns as $columnIndex => $columnKey) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex + 1) . $rowNumber, $values[$columnKey]);
    }
    $rowNumber++;
}

if (empty($cadets)) {
    $sheet->mergeCells("A{$rowNumber}:{$lastColumn}{$rowNumber}");
    $sheet->setCellValue("A{$rowNumber}", 'No ROTC cadet profiles found for the selected filters.');
}

$sheet->getStyle("A4:{$lastColumn}" . max($rowNumber, 4))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
foreach (range(1, $columnCount) as $columnIndex) {
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
}

$filename = 'rotc_cadets_profile_' . date('Y-m-d_H-i-s') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
