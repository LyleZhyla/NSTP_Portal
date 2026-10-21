<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../include/grade-score-import.php';

function assertGradeImport($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

$students = [[
    'tbl_student_id' => 10,
    'student_number' => '2026-001',
    'student_name' => 'Sample Student',
    'display_name' => 'STUDENT, Sample',
    'course_section' => 'BSIT 1-A',
]];
$columns = [
    ['grade_column_id' => 21, 'column_key' => 'quiz_1', 'label' => 'Quiz 1', 'max_score' => 20],
    ['grade_column_id' => 22, 'column_key' => 'project', 'label' => 'Project', 'max_score' => 100],
];

$spreadsheet = gradeCreateScoreTemplate($students, $columns, ['program' => 'CWTS', 'class_label' => 'BSIT 1-A']);
$sheet = $spreadsheet->getActiveSheet();
assertGradeImport($sheet->getCell('A1')->getValue() === GRADE_SCORE_TEMPLATE_TITLE, 'template title is present');
assertGradeImport($sheet->getCell('E4')->getValue() === 'grade_column_id:21', 'score column identity is preserved');
assertGradeImport($sheet->getCell('B6')->getValue() === '2026-001', 'student number stays as text');
assertGradeImport($sheet->getColumnDimension('A')->getVisible() === false, 'internal student ID is hidden');
assertGradeImport($sheet->getProtection()->getSheet() === true, 'student details and headings are protected');
assertGradeImport($sheet->getStyle('E6')->getProtection()->getLocked() === \PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_UNPROTECTED, 'yellow score cells remain editable');
assertGradeImport($sheet->getCell('E6')->getDataValidation()->getType() === \PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_DECIMAL, 'score cells include numeric validation');

$sheet->setCellValue('E6', 18.5);
$studentLookup = [10 => $students[0]];
$columnLookup = [21 => $columns[0], 22 => $columns[1]];
$temporaryFile = tempnam(sys_get_temp_dir(), 'grade_import_');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($temporaryFile);
$reloadedSpreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx')->load($temporaryFile);
unlink($temporaryFile);
$updates = gradeParseScoreImport($reloadedSpreadsheet, $studentLookup, $columnLookup);
assertGradeImport(count($updates) === 1 && $updates[0]['score'] === 18.5, 'entered scores are parsed and blank scores are skipped');
$reloadedSpreadsheet->disconnectWorksheets();

$sheet->setCellValue('E6', 21);
$rejected = false;
try {
    gradeParseScoreImport($spreadsheet, $studentLookup, $columnLookup);
} catch (InvalidArgumentException $error) {
    $rejected = strpos($error->getMessage(), 'between 0 and 20') !== false;
}
assertGradeImport($rejected, 'scores above the current maximum are rejected');

$spreadsheet->disconnectWorksheets();
echo "Grade score import tests completed.\n";
