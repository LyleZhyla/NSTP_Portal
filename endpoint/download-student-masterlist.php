<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
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
$folderKey = $format === 'pdf'
    ? ''
    : trim((string) ($_GET['student_folder'] ?? ''));
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
        s.student_number,
        s.student_name,
        COALESCE(NULLIF(s.original_section, ''), 'N/A') AS program,
        COALESCE(NULLIF(s.course_section, ''), 'Unassigned') AS course_section,
        {$facilitatorNameExpression} AS facilitator_name
    FROM tbl_student s
    LEFT JOIN tbl_users creator ON creator.user_id = s.created_by
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

function masterlistBuildSheet(Worksheet $sheet, array $sheetStudents, $scopeLabel) {
    $facilitatorLabel = masterlistFacilitatorLabel($sheetStudents);

    $sheet->mergeCells('A1:D1');
    $sheet->setCellValue('A1', 'STUDENT MASTERLIST');
    $sheet->mergeCells('A2:D2');
    $sheet->setCellValue('A2', $scopeLabel);
    $sheet->mergeCells('A3:D3');
    $sheet->setCellValue('A3', 'Facilitator: ' . $facilitatorLabel);
    $sheet->mergeCells('A4:D4');
    $sheet->setCellValue('A4', 'Generated: ' . date('F j, Y g:i A') . ' | Total Students: ' . count($sheetStudents));

    $headers = ['No.', 'Student Name', 'Program', 'Assigned Section'];
    foreach ($headers as $index => $header) {
        $sheet->setCellValue([$index + 1, 6], $header);
    }

    $rowNumber = 7;
    foreach ($sheetStudents as $index => $student) {
        $sheet->setCellValue([1, $rowNumber], $index + 1);
        $sheet->setCellValueExplicit([2, $rowNumber], (string) ($student['student_name'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([3, $rowNumber], (string) ($student['program'] ?? 'N/A'), DataType::TYPE_STRING);
        $sheet->setCellValueExplicit([4, $rowNumber], (string) ($student['course_section'] ?? 'Unassigned'), DataType::TYPE_STRING);
        $rowNumber++;
    }

    $lastDataRow = max(6, $rowNumber - 1);
    $sheet->getStyle('A1:D1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E78']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->getStyle('A2:D4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A2:D3')->getFont()->setBold(true);
    $sheet->getStyle('A3:D3')->applyFromArray([
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
    $sheet->getStyle('A6:D6')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("A6:D{$lastDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B7B7B7');
    if ($rowNumber > 7) {
        $sheet->getStyle("A7:A{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C7:D{$lastDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
    $sheet->getStyle("A6:D{$lastDataRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

    $sheet->getColumnDimension('A')->setWidth(7);
    $sheet->getColumnDimension('B')->setWidth(34);
    $sheet->getColumnDimension('C')->setWidth(30);
    $sheet->getColumnDimension('D')->setWidth(22);
    $sheet->getRowDimension(1)->setRowHeight(25);
    $sheet->freezePane('A7');
    $sheet->setAutoFilter("A6:D{$lastDataRow}");
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.4)->setRight(0.3)->setLeft(0.3)->setBottom(0.4);
    $sheet->getHeaderFooter()->setOddFooter('&LGenerated by QR Attendance System&RPage &P of &N');
    $sheet->getPageSetup()->setPrintArea("A1:D{$lastDataRow}");
}

function masterlistEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function masterlistBuildPdf(array $sheetStudents, $scopeLabel) {
    $facilitatorLabel = masterlistFacilitatorLabel($sheetStudents);
    $rows = '';
    foreach ($sheetStudents as $index => $student) {
        $rows .= '<tr>'
            . '<td class="number">' . ($index + 1) . '</td>'
            . '<td class="student-name">' . masterlistEscape($student['student_name'] ?? '') . '</td>'
            . '<td>' . masterlistEscape($student['program'] ?? 'N/A') . '</td>'
            . '<td class="center">' . masterlistEscape($student['course_section'] ?? 'Unassigned') . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td class="center" colspan="4">No students found for this section.</td></tr>';
    }

    $html = '<!doctype html><html><head><meta charset="UTF-8"><style>'
        . '@page { margin: 28px 28px 45px 28px; }'
        . 'body { font-family: DejaVu Sans, sans-serif; color: #111; font-size: 9px; margin: 0; }'
        . '.title { background: #1F4E78; color: #fff; font-weight: bold; font-size: 16px; text-align: center; padding: 5px 0; }'
        . '.scope { font-weight: bold; font-size: 10px; text-align: center; padding: 3px 0; }'
        . '.facilitator { color: #7F6000; background: #FFF2CC; border: 2px solid #D6B656; font-weight: bold; font-size: 12px; text-align: center; padding: 5px 0; }'
        . '.generated { text-align: center; font-size: 10px; padding: 4px 0 14px; }'
        . 'table { width: 100%; border-collapse: collapse; table-layout: fixed; }'
        . 'thead { display: table-header-group; }'
        . 'tr { page-break-inside: avoid; }'
        . 'th { background: #4472C4; color: #fff; font-weight: bold; text-align: center; border: 1px solid #B7B7B7; padding: 3px 2px; }'
        . 'td { border: 1px solid #B7B7B7; padding: 2px 3px; vertical-align: middle; line-height: 1.15; white-space: normal; word-wrap: break-word; overflow-wrap: break-word; }'
        . 'td.student-name { word-break: break-word; }'
        . '.number { width: 7%; text-align: center; }'
        . '.student { width: 38%; }'
        . '.program { width: 32%; }'
        . '.section { width: 23%; }'
        . '.center { text-align: center; }'
        . '</style></head><body>'
        . '<div class="title">STUDENT MASTERLIST</div>'
        . '<div class="scope">' . masterlistEscape($scopeLabel) . '</div>'
        . '<div class="facilitator">Facilitator: ' . masterlistEscape($facilitatorLabel) . '</div>'
        . '<div class="generated">Generated: ' . masterlistEscape(date('F j, Y g:i A'))
        . ' | Total Students: ' . count($sheetStudents) . '</div>'
        . '<table><thead><tr>'
        . '<th class="number">No.</th><th class="student">Student Name</th>'
        . '<th class="program">Program</th><th class="section">Assigned Section</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . '</body></html>';

    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);
    $dompdf = new Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $canvas = $dompdf->getCanvas();
    $canvas->page_text(28, 810, 'Generated by QR Attendance System', $font, 8, [0, 0, 0]);
    $canvas->page_text(500, 810, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0, 0, 0]);
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
            'content' => masterlistBuildPdf($sheetStudents, $sheetScopeLabel),
        ];
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
    masterlistBuildSheet($sheet, $sheetStudents, $sheetScopeLabel);
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
