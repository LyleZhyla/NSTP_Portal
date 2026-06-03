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
