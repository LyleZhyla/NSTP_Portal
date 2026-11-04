<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../include/user-permissions.php';
require_once __DIR__ . '/../include/college-courses.php';
require_once __DIR__ . '/../include/rotc-grade-report.php';

function assertRotcReport($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$gradeColumns = [
    ['grade_column_id' => 11, 'column_key' => 'rotc_attitude'],
    ['grade_column_id' => 12, 'column_key' => 'rotc_midterm_exam'],
    ['grade_column_id' => 13, 'column_key' => 'rotc_final_exam'],
];
$rows = [[
    'student' => [
        'student_name' => 'Dela Cruz, Juan',
        'last_name' => 'Dela Cruz',
        'first_name' => 'Juan',
        'middle_name' => 'Santos',
        'course' => 'Bachelor of Science in Information Technology',
        'course_section' => 'MS-1 Alpha',
        'rotc_ms_level' => 'MS-1',
        'gender' => 'Male',
    ],
    'scores' => [11 => 95, 12 => 45, 13 => 40],
    'attendance_count' => 14,
]];

$spreadsheet = createRotcGradeReportSpreadsheet($rows, $gradeColumns, [
    'semester' => '1st Semester',
    'school_year' => '2026-2027',
]);

assertRotcReport($spreadsheet->getSheetNames() === ['COG', 'ROG'], 'Expected COG and ROG worksheets.');
$cog = $spreadsheet->getSheetByName('COG');
$rog = $spreadsheet->getSheetByName('ROG');
assertRotcReport($cog->getCell('E12')->getValue() === '=D12/15*(50+50)*30%', 'Attendance formula does not match the ROTC template.');
assertRotcReport($cog->getCell('G12')->getValue() === '=F12/100*(50+50)*30%', 'Attitude formula does not match the ROTC template.');
assertRotcReport($cog->getCell('I12')->getValue() === '=(H12/50*50+50)*20%', 'Midterm formula does not match the ROTC template.');
assertRotcReport($cog->getCell('K12')->getValue() === '=(J12/50*50+50)*20%', 'Final exam formula does not match the ROTC template.');
assertRotcReport($cog->getCell('L12')->getValue() === '=E12+G12+I12+K12', 'Total formula is incorrect.');
assertRotcReport($cog->getCell('N12')->getValue() === '=IF(L12>=75,"PASSED","FAILED")', 'Remarks formula is incorrect.');
assertRotcReport($rog->getCell('D12')->getValue() === '=COG!L12', 'ROG total must link to COG.');
assertRotcReport($rog->getCell('E12')->getValue() === '=COG!M12', 'ROG equivalent must link to COG.');
assertRotcReport($rog->getCell('F12')->getValue() === '=COG!N12', 'ROG remarks must link to COG.');
assertRotcReport($cog->getCell('C12')->getValue() === 'BSIT', 'COG course must use its abbreviation.');
assertRotcReport($rog->getCell('C12')->getValue() === 'BSIT', 'ROG course must use its abbreviation.');
assertRotcReport((float) $rog->getCell('D12')->getOldCalculatedValue() === 93.5, 'ROG must contain the calculated COG percentage.');
assertRotcReport((float) $rog->getCell('E12')->getOldCalculatedValue() === 1.25, 'ROG must contain the calculated equivalent.');
assertRotcReport($rog->getCell('F12')->getOldCalculatedValue() === 'PASSED', 'ROG must contain the calculated remarks.');
assertRotcReport($cog->getCell('B12')->getValue() === 'DELA CRUZ, JUAN S.', 'Student name must use last-name-first format.');
assertRotcReport(count($cog->getDrawingCollection()) === 2, 'COG should contain both official logos.');
assertRotcReport(count($rog->getDrawingCollection()) === 2, 'ROG should contain both official logos.');

$filterStudents = [
    ['last_name' => 'Zulu', 'first_name' => 'Ana', 'gender' => 'Female', 'course' => 'Bachelor of Science in Agriculture', 'rotc_ms_level' => 'MS-31'],
    ['last_name' => 'Abad', 'first_name' => 'Ben', 'gender' => 'Male', 'course' => 'Bachelor of Science in Information Technology', 'rotc_ms_level' => 'MS-1'],
    ['last_name' => 'Cruz', 'first_name' => 'Cara', 'gender' => 'Female', 'course' => 'Bachelor of Science in Information Technology', 'rotc_ms_level' => 'MS-1'],
];
$filteredStudents = rotcReportFilterAndSortStudents($filterStudents, 'FEMALE', 'BSIT', 'MS-1', 'last_name_asc');
assertRotcReport(count($filteredStudents) === 1 && $filteredStudents[0]['last_name'] === 'Cruz', 'ROTC gender, course, and MS-level filters must work together.');
$sortedStudents = rotcReportFilterAndSortStudents($filterStudents, '', '', '', 'last_name_asc');
assertRotcReport(array_column($sortedStudents, 'last_name') === ['Abad', 'Cruz', 'Zulu'], 'ROTC students must sort by last name A-Z.');

$temporaryFile = tempnam(sys_get_temp_dir(), 'rotc-report-') . '.xlsx';
$writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save($temporaryFile);
$reloaded = PhpOffice\PhpSpreadsheet\IOFactory::load($temporaryFile);
assertRotcReport($reloaded->getSheetByName('COG')->getCell('L12')->getValue() === '=E12+G12+I12+K12', 'Formula was not preserved in the saved workbook.');
$requestedOutput = getenv('ROTC_REPORT_OUTPUT');
if ($requestedOutput) {
    copy($temporaryFile, $requestedOutput);
}
unlink($temporaryFile);

echo "ROTC grade report workbook tests passed.\n";
