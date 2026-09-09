<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

$currentUser = getCurrentUserRecord($conn);
$role = $currentUser['role'] ?? '';
if (!$currentUser || !in_array($role, ['super_admin', 'coordinator', 'facilitator'], true)) {
    die('Unauthorized access');
}

$userId = (int) $currentUser['user_id'];
$program = normalizeProgram($currentUser['program'] ?? ($_SESSION['program'] ?? null));
$selectedComponent = $role === 'super_admin'
    ? normalizeProgram($_GET['component'] ?? null)
    : $program;
$format = strtolower(trim((string) ($_GET['format'] ?? 'xlsx')));
if (!in_array($format, ['xlsx', 'pdf'], true)) {
    $format = 'xlsx';
}
$folderKey = trim((string) ($_GET['student_folder'] ?? ''));
$fieldDefinitions = [
    'student_number' => ['label' => 'Student Number', 'width' => 20],
    'student_name' => ['label' => 'Full Name', 'width' => 34],
    'last_name' => ['label' => 'Last Name', 'width' => 22],
    'extension_name' => ['label' => 'Extension Name', 'width' => 16],
    'first_name' => ['label' => 'First Name', 'width' => 22],
    'middle_name' => ['label' => 'Middle Name', 'width' => 22],
    'place_of_birth' => ['label' => 'Place of Birth', 'width' => 30],
    'date_of_birth' => ['label' => 'Date of Birth', 'width' => 16],
    'age' => ['label' => 'Age', 'width' => 9],
    'gender' => ['label' => 'Gender', 'width' => 14],
    'religion' => ['label' => 'Religion', 'width' => 18],
    'blood_type' => ['label' => 'Blood Type', 'width' => 12],
    'height' => ['label' => 'Height', 'width' => 12],
    'contact_number' => ['label' => 'Contact Number', 'width' => 20],
    'email' => ['label' => 'Email Address', 'width' => 30],
    'full_address' => ['label' => 'Complete Address', 'width' => 45],
    'house_no' => ['label' => 'House No.', 'width' => 14],
    'street' => ['label' => 'Street', 'width' => 24],
    'barangay' => ['label' => 'Barangay', 'width' => 22],
    'city_municipality' => ['label' => 'City / Municipality', 'width' => 24],
    'province' => ['label' => 'Province', 'width' => 22],
    'emergency_name' => ['label' => 'Emergency Contact Name', 'width' => 28],
    'emergency_relationship' => ['label' => 'Relationship', 'width' => 18],
    'emergency_contact_number' => ['label' => 'Emergency Contact Number', 'width' => 24],
    'emergency_address' => ['label' => 'Emergency Contact Address', 'width' => 40],
    'college' => ['label' => 'College', 'width' => 30],
    'course' => ['label' => 'Course', 'width' => 30],
    'major' => ['label' => 'Major', 'width' => 24],
    'year_section' => ['label' => 'Year and Section', 'width' => 18],
    'component' => ['label' => 'NSTP Component', 'width' => 18],
    'rotc_ms_level' => ['label' => 'ROTC MS Level', 'width' => 16],
    'shirt_size' => ['label' => 'Shirt Size', 'width' => 12],
    'program' => ['label' => 'Original Program / Section', 'width' => 30],
    'course_section' => ['label' => 'Assigned Folder / Section', 'width' => 24],
    'facilitator_name' => ['label' => 'Facilitator', 'width' => 28],
    'generated_code' => ['label' => 'QR Identifier', 'width' => 28],
    'formal_picture' => ['label' => 'Formal Picture File', 'width' => 38],
    'registration_status' => ['label' => 'Registration Status', 'width' => 20],
    'registration_date' => ['label' => 'Registration Date', 'width' => 22],
];
$defaultFields = ['student_name', 'program', 'course_section'];
$requestedFields = $_GET['data_fields'] ?? $defaultFields;
if (!is_array($requestedFields)) {
    $requestedFields = [$requestedFields];
}
$selectedFields = [];
foreach ($requestedFields as $requestedField) {
    $requestedField = trim((string) $requestedField);
    if (isset($fieldDefinitions[$requestedField]) && !in_array($requestedField, $selectedFields, true)) {
        $selectedFields[] = $requestedField;
    }
}
if (!$selectedFields) {
    die('Please select at least one student data field.');
}
$selectedFacilitatorId = null;
$selectedSection = '';
$selectedFacilitatorName = '';

if ($folderKey !== '') {
    if ($role === 'facilitator') {
        $selectedFacilitatorId = $userId;
        $selectedSection = $folderKey;
    } elseif (strpos($folderKey, '::') !== false) {
        [$facilitatorPart, $sectionPart] = explode('::', $folderKey, 2);
        $selectedFacilitatorId = (int) $facilitatorPart;
        $selectedSection = trim($sectionPart);
    }

    if (!$selectedFacilitatorId || $selectedSection === '') {
        die('Invalid student folder.');
    }

    $accessSql = "
        SELECT COALESCE(NULLIF(u.full_name, ''), u.username) AS facilitator_name
        FROM tbl_admin_sections ads
        INNER JOIN tbl_users u ON u.user_id = ads.user_id
        WHERE ads.user_id = :facilitator_id
          AND ads.course_section = :course_section
          AND u.role = 'facilitator'
    ";
    $accessParams = [
        ':facilitator_id' => $selectedFacilitatorId,
        ':course_section' => $selectedSection,
    ];

    if ($role === 'coordinator') {
        $accessSql .= ' AND u.program = :program';
        $accessParams[':program'] = $program;
    } elseif ($role === 'facilitator') {
        $accessSql .= ' AND u.user_id = :current_user_id';
        $accessParams[':current_user_id'] = $userId;
    }

    $accessSql .= ' LIMIT 1';
    $accessStmt = $conn->prepare($accessSql);
    $accessStmt->execute($accessParams);
    $selectedFacilitatorName = (string) $accessStmt->fetchColumn();
    if ($selectedFacilitatorName === '') {
        die('You do not have access to the selected student folder.');
    }
}

$facilitatorNameExpression = "
    COALESCE(
        CASE
            WHEN creator.role = 'facilitator'
            THEN COALESCE(NULLIF(creator.full_name, ''), creator.username)
            ELSE NULL
        END,
        (
            SELECT COALESCE(NULLIF(assigned.full_name, ''), assigned.username)
            FROM tbl_admin_sections section_assignment
            INNER JOIN tbl_users assigned ON assigned.user_id = section_assignment.user_id
            WHERE section_assignment.course_section = s.course_section
              AND assigned.role = 'facilitator'
            ORDER BY section_assignment.assigned_at DESC, section_assignment.admin_section_id DESC
            LIMIT 1
        ),
        'Unassigned'
    )
";

$componentExpression = "
    COALESCE(
        NULLIF(creator.program, ''),
        (
            SELECT NULLIF(component_owner.program, '')
            FROM tbl_admin_sections component_assignment
            INNER JOIN tbl_users component_owner ON component_owner.user_id = component_assignment.user_id
            WHERE component_assignment.course_section = s.course_section
              AND component_owner.role = 'facilitator'
            ORDER BY component_assignment.assigned_at DESC, component_assignment.admin_section_id DESC
            LIMIT 1
        ),
        CASE
            WHEN UPPER(s.course_section) LIKE '%CWTS%' THEN 'CWTS'
            WHEN UPPER(s.course_section) LIKE '%LTS%' THEN 'LTS'
            WHEN UPPER(s.course_section) LIKE '%ROTC%' THEN 'ROTC'
            ELSE NULL
        END
    )
";

$studentSql = "
    SELECT
        s.tbl_student_id,
        COALESCE(NULLIF(r.student_number, ''), s.student_number, '') AS student_number,
        s.student_name,
        COALESCE(r.last_name, '') AS last_name,
        COALESCE(r.extension_name, '') AS extension_name,
        COALESCE(r.first_name, '') AS first_name,
        COALESCE(r.middle_name, '') AS middle_name,
        COALESCE(r.place_of_birth, '') AS place_of_birth,
        COALESCE(DATE_FORMAT(r.date_of_birth, '%Y-%m-%d'), '') AS date_of_birth,
        CASE WHEN r.date_of_birth IS NULL THEN '' ELSE TIMESTAMPDIFF(YEAR, r.date_of_birth, CURDATE()) END AS age,
        COALESCE(r.gender, '') AS gender,
        COALESCE(r.religion, '') AS religion,
        COALESCE(r.blood_type, '') AS blood_type,
        COALESCE(r.height, '') AS height,
        COALESCE(r.contact_number, '') AS contact_number,
        COALESCE(NULLIF(r.email, ''), student_user.email, '') AS email,
        TRIM(CONCAT_WS(', ', NULLIF(r.house_no, ''), NULLIF(r.street, ''), NULLIF(r.barangay, ''), NULLIF(r.city_municipality, ''), NULLIF(r.province, ''))) AS full_address,
        COALESCE(r.house_no, '') AS house_no,
        COALESCE(r.street, '') AS street,
        COALESCE(r.barangay, '') AS barangay,
        COALESCE(r.city_municipality, '') AS city_municipality,
        COALESCE(r.province, '') AS province,
        COALESCE(r.emergency_name, '') AS emergency_name,
        COALESCE(r.emergency_relationship, '') AS emergency_relationship,
        COALESCE(r.emergency_contact_number, '') AS emergency_contact_number,
        COALESCE(r.emergency_address, '') AS emergency_address,
        COALESCE(r.college, '') AS college,
        COALESCE(r.course, '') AS course,
        COALESCE(r.major, '') AS major,
        COALESCE(r.year_section, '') AS year_section,
        COALESCE(NULLIF(r.component, ''), {$componentExpression}, '') AS component,
        COALESCE(r.rotc_ms_level, '') AS rotc_ms_level,
        COALESCE(NULLIF(student_user.shirt_size, ''), r.shirt_size, '') AS shirt_size,
        COALESCE(NULLIF(s.original_section, ''), 'N/A') AS program,
        COALESCE(NULLIF(s.course_section, ''), 'Unassigned') AS course_section,
        {$facilitatorNameExpression} AS facilitator_name,
        COALESCE(s.generated_code, '') AS generated_code,
        COALESCE(r.formal_picture, '') AS formal_picture,
        COALESCE(r.status, '') AS registration_status,
        COALESCE(DATE_FORMAT(r.created_at, '%Y-%m-%d %H:%i:%s'), '') AS registration_date
    FROM tbl_student s
    LEFT JOIN tbl_users creator ON creator.user_id = s.created_by
    LEFT JOIN tbl_users student_user ON student_user.user_id = s.user_id
    LEFT JOIN tbl_public_student_registrations r ON r.registration_id = (
        SELECT r2.registration_id
        FROM tbl_public_student_registrations r2
        WHERE r2.registrant_role = 'student'
          AND COALESCE(r2.status, 'submitted') <> 'account_deleted'
          AND (
              (s.user_id IS NOT NULL AND r2.user_id = s.user_id)
              OR (NULLIF(s.student_number, '') IS NOT NULL AND r2.student_number = s.student_number)
          )
        ORDER BY r2.registration_id DESC
        LIMIT 1
    )
";
$studentParams = [];
$studentWhere = [];

if ($selectedFacilitatorId && $selectedSection !== '') {
    $studentWhere[] = 's.created_by = :selected_facilitator_id';
    $studentWhere[] = 's.course_section = :selected_section';
    $studentParams[':selected_facilitator_id'] = $selectedFacilitatorId;
    $studentParams[':selected_section'] = $selectedSection;
} elseif ($role === 'coordinator') {
    if ($program === 'ROTC') {
        $studentWhere[] = rotcStudentSqlCondition('s');
    } else {
        $studentWhere[] = "(\n            (creator.role = 'facilitator' AND creator.program = :program)\n            OR s.course_section = :program_section\n            OR s.course_section LIKE :program_folder_prefix\n        )";
        $studentParams[':program'] = $program;
        $studentParams[':program_section'] = $program;
        $studentParams[':program_folder_prefix'] = $program . ' %';
    }
} elseif ($role === 'facilitator') {
    $studentWhere[] = "
        (s.created_by = :creator_user_id
           OR EXISTS (
                SELECT 1
                FROM tbl_admin_sections accessible_section
                WHERE accessible_section.user_id = :section_user_id
                  AND accessible_section.course_section = s.course_section
           ))
    ";
    $studentParams[':creator_user_id'] = $userId;
    $studentParams[':section_user_id'] = $userId;
}

if ($selectedComponent) {
    $studentWhere[] = "({$componentExpression}) = :selected_component";
    $studentParams[':selected_component'] = $selectedComponent;
}

if ($studentWhere) {
    $studentSql .= ' WHERE ' . implode(' AND ', $studentWhere);
}

$studentSql .= ' ORDER BY s.course_section ASC, s.student_name ASC, s.tbl_student_id ASC';
$studentStmt = $conn->prepare($studentSql);
$studentStmt->execute($studentParams);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

function masterlistSafeSheetTitle($title, array &$usedTitles) {
    $title = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '-', (string) $title), " \t\n\r\0\x0B'");
    $title = $title !== '' ? $title : 'Unassigned';
    $baseTitle = substr($title, 0, 31);
    $sheetTitle = $baseTitle;
    $suffixNumber = 2;

    while (isset($usedTitles[strtolower($sheetTitle)])) {
        $suffix = ' (' . $suffixNumber . ')';
        $sheetTitle = substr($baseTitle, 0, 31 - strlen($suffix)) . $suffix;
        $suffixNumber++;
    }

    $usedTitles[strtolower($sheetTitle)] = true;
    return $sheetTitle;
}

function masterlistSafeFilename($name, $fallback = 'Student Masterlist') {
    $name = preg_replace('/[<>:"\\\/|?*\x00-\x1F]/u', '-', trim((string) $name));
    $name = trim(preg_replace('/\s+/', ' ', $name), " .\t\n\r\0\x0B");
    return $name !== '' ? $name : $fallback;
}

function masterlistFacilitatorLabel(array $sheetStudents) {
    $facilitatorNames = [];
    foreach ($sheetStudents as $student) {
        $facilitatorName = trim((string) ($student['facilitator_name'] ?? ''));
        if ($facilitatorName !== '' && strcasecmp($facilitatorName, 'Unassigned') !== 0) {
            $facilitatorNames[strtolower($facilitatorName)] = $facilitatorName;
        }
    }
    return $facilitatorNames
        ? implode(', ', array_values($facilitatorNames))
        : 'Unassigned';
}

function masterlistBuildSheet(Worksheet $sheet, array $sheetStudents, $scopeLabel, array $selectedFields, array $fieldDefinitions) {
    $facilitatorLabel = masterlistFacilitatorLabel($sheetStudents);
    $lastColumnIndex = count($selectedFields) + 1;
    $lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);

    $sheet->mergeCells("A1:{$lastColumn}1");
    $sheet->setCellValue('A1', 'STUDENT MASTERLIST');
    $sheet->mergeCells("A2:{$lastColumn}2");
    $sheet->setCellValue('A2', $scopeLabel);
    $sheet->mergeCells("A3:{$lastColumn}3");
    $sheet->setCellValue('A3', 'Facilitator: ' . $facilitatorLabel);
    $sheet->mergeCells("A4:{$lastColumn}4");
    $sheet->setCellValue('A4', 'Generated: ' . date('F j, Y g:i A') . ' | Total Students: ' . count($sheetStudents));

    $headers = ['No.'];
    foreach ($selectedFields as $field) {
        $headers[] = $fieldDefinitions[$field]['label'];
    }
    foreach ($headers as $index => $header) {
        $sheet->setCellValue([$index + 1, 6], $header);
    }

    $rowNumber = 7;
    foreach ($sheetStudents as $index => $student) {
        $sheet->setCellValue([1, $rowNumber], $index + 1);
        foreach ($selectedFields as $fieldIndex => $field) {
            $sheet->setCellValueExplicit(
                [$fieldIndex + 2, $rowNumber],
                (string) ($student[$field] ?? ''),
                DataType::TYPE_STRING
            );
        }
        $rowNumber++;
    }

    $lastDataRow = max(6, $rowNumber - 1);
    $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->getStyle("A2:{$lastColumn}4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A2:{$lastColumn}3")->getFont()->setBold(true);
    $sheet->getStyle("A3:{$lastColumn}3")->applyFromArray([
        'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '7F6000']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
        'borders' => [
            'outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'D6B656']],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);
    $sheet->getRowDimension(3)->setRowHeight(22);
    $sheet->getStyle("A6:{$lastColumn}6")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("A6:{$lastColumn}{$lastDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B7B7B7');
    if ($rowNumber > 7) {
        $sheet->getStyle("A7:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
    $sheet->getStyle("A6:{$lastColumn}{$lastDataRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

    $sheet->getColumnDimension('A')->setWidth(7);
    foreach ($selectedFields as $fieldIndex => $field) {
        $column = Coordinate::stringFromColumnIndex($fieldIndex + 2);
        $sheet->getColumnDimension($column)->setWidth($fieldDefinitions[$field]['width']);
    }
    $sheet->getRowDimension(1)->setRowHeight(25);
    $sheet->freezePane('A7');
    $sheet->setAutoFilter("A6:{$lastColumn}{$lastDataRow}");
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.4)->setRight(0.3)->setLeft(0.3)->setBottom(0.4);
    $sheet->getHeaderFooter()->setOddFooter('&LGenerated by QR Attendance System&RPage &P of &N');
    $sheet->getPageSetup()->setPrintArea("A1:{$lastColumn}{$lastDataRow}");
}

function masterlistEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function masterlistBuildPdf(array $sheetStudents, $scopeLabel, array $selectedFields, array $fieldDefinitions) {
    $facilitatorLabel = masterlistFacilitatorLabel($sheetStudents);
    $columnCount = count($selectedFields) + 1;
    $pageSize = $columnCount > 9 ? 'A3' : 'A4';
    $pageOrientation = $columnCount > 4 ? 'landscape' : 'portrait';
    $bodyFontSize = $columnCount > 14 ? 6 : ($columnCount > 8 ? 7 : 9);
    $rows = '';
    foreach ($sheetStudents as $index => $student) {
        $rows .= '<tr><td class="number">' . ($index + 1) . '</td>';
        foreach ($selectedFields as $field) {
            $rows .= '<td>' . masterlistEscape($student[$field] ?? '') . '</td>';
        }
        $rows .= '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td class="center" colspan="' . $columnCount . '">No students found for this section.</td></tr>';
    }

    $headerCells = '<th class="number">No.</th>';
    foreach ($selectedFields as $field) {
        $headerCells .= '<th>' . masterlistEscape($fieldDefinitions[$field]['label']) . '</th>';
    }

    $html = '<!doctype html><html><head><meta charset="UTF-8"><style>'
        . '@page { margin: 28px 28px 45px 28px; }'
        . 'body { font-family: DejaVu Sans, sans-serif; color: #111; font-size: ' . $bodyFontSize . 'px; margin: 0; }'
        . '.title { background: #1F4E78; color: #fff; font-weight: bold; font-size: 16px; text-align: center; padding: 5px 0; }'
        . '.scope { font-weight: bold; font-size: 10px; text-align: center; padding: 3px 0; }'
        . '.facilitator { color: #7F6000; background: #FFF2CC; border: 2px solid #D6B656; font-weight: bold; font-size: 12px; text-align: center; padding: 5px 0; }'
        . '.generated { text-align: center; font-size: 10px; padding: 4px 0 14px; }'
        . 'table { width: 100%; border-collapse: collapse; table-layout: fixed; }'
        . 'thead { display: table-header-group; }'
        . 'tr { page-break-inside: avoid; }'
        . 'th { background: #4472C4; color: #fff; font-weight: bold; text-align: center; border: 1px solid #B7B7B7; padding: 3px 2px; }'
        . 'td { border: 1px solid #B7B7B7; padding: 2px 3px; vertical-align: middle; line-height: 1.15; white-space: normal; word-wrap: break-word; overflow-wrap: break-word; }'
        . '.number { width: 7%; text-align: center; }'
        . '.center { text-align: center; }'
        . '</style></head><body>'
        . '<div class="title">STUDENT MASTERLIST</div>'
        . '<div class="scope">' . masterlistEscape($scopeLabel) . '</div>'
        . '<div class="facilitator">Facilitator: ' . masterlistEscape($facilitatorLabel) . '</div>'
        . '<div class="generated">Generated: ' . masterlistEscape(date('F j, Y g:i A'))
        . ' | Total Students: ' . count($sheetStudents) . '</div>'
        . '<table><thead><tr>' . $headerCells . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . '</body></html>';

    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);
    $dompdf = new Dompdf($options);
    $dompdf->setPaper($pageSize, $pageOrientation);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $canvas = $dompdf->getCanvas();
    $footerY = $canvas->get_height() - 20;
    $canvas->page_text(28, $footerY, 'Generated by QR Attendance System', $font, 8, [0, 0, 0]);
    $canvas->page_text($canvas->get_width() - 110, $footerY, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0, 0, 0]);
    return $dompdf->output();
}

$baseScopeLabel = $role === 'super_admin' ? 'All Students' : 'All Accessible Students';
if ($selectedComponent) {
    $baseScopeLabel .= ' | Component: ' . $selectedComponent;
}

$separateBySection = $format === 'pdf' || in_array($selectedComponent, ['CWTS', 'LTS'], true);
$studentSheetGroups = [];
if ($separateBySection) {
    foreach ($students as $student) {
        $sectionName = trim((string) ($student['course_section'] ?? '')) ?: 'Unassigned';
        $studentSheetGroups[$sectionName][] = $student;
    }
}
if (!$studentSheetGroups) {
    $fallbackGroupName = $selectedSection !== '' ? $selectedSection : 'Student Masterlist';
    $studentSheetGroups[$fallbackGroupName] = $students;
}

if ($format === 'pdf') {
    $pdfFiles = [];
    foreach ($studentSheetGroups as $sectionName => $sheetStudents) {
        $sheetScopeLabel = $baseScopeLabel . ' | Section: ' . $sectionName;
        $pdfFiles[] = [
            'filename' => masterlistSafeFilename($sectionName) . '.pdf',
            'content' => masterlistBuildPdf($sheetStudents, $sheetScopeLabel, $selectedFields, $fieldDefinitions),
        ];
    }

    if ($selectedSection !== '' && count($pdfFiles) === 1) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $pdfName = masterlistSafeFilename($selectedSection) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $pdfName . '"');
        header('Content-Length: ' . strlen($pdfFiles[0]['content']));
        header('Cache-Control: max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $pdfFiles[0]['content'];
        exit();
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Cache-Control: max-age=0');
    header('X-Content-Type-Options: nosniff');

    $temporaryZip = tempnam(sys_get_temp_dir(), 'student-masterlists-');
    if ($temporaryZip === false) {
        throw new RuntimeException('Unable to prepare the PDF download.');
    }
    $zip = new ZipArchive();
    if ($zip->open($temporaryZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($temporaryZip);
        throw new RuntimeException('Unable to create the PDF archive.');
    }
    foreach ($pdfFiles as $pdfFile) {
        $zip->addFromString($pdfFile['filename'], $pdfFile['content']);
    }
    $zip->close();

    $zipName = masterlistSafeFilename(
        ($selectedComponent ? $selectedComponent . ' ' : '') . 'Section Masterlists'
    ) . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($temporaryZip));
    readfile($temporaryZip);
    unlink($temporaryZip);
    exit();
}

$spreadsheet = new Spreadsheet();
$usedSheetTitles = [];
$sheetIndex = 0;
foreach ($studentSheetGroups as $sectionName => $sheetStudents) {
    $sheet = $sheetIndex === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
    $sheet->setTitle(masterlistSafeSheetTitle($separateBySection ? $sectionName : 'Student Masterlist', $usedSheetTitles));
    $sheetScopeLabel = $baseScopeLabel;
    if ($separateBySection) {
        $sheetScopeLabel .= ' | Section: ' . $sectionName;
    }
    masterlistBuildSheet($sheet, $sheetStudents, $sheetScopeLabel, $selectedFields, $fieldDefinitions);
    $sheetIndex++;
}
$spreadsheet->setActiveSheetIndex(0);

if ($selectedSection !== '') {
    $filename = masterlistSafeFilename($selectedSection) . '.xlsx';
} else {
    $filenameParts = ['student-masterlist'];
    if ($selectedComponent) {
        $filenameParts[] = strtolower($selectedComponent);
    }
    $filenameParts[] = date('Y-m-d');
    $filename = implode('-', $filenameParts) . '.xlsx';
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('X-Content-Type-Options: nosniff');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
$spreadsheet->disconnectWorksheets();
exit();
