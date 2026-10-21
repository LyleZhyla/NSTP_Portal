<?php

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;

const GRADE_SCORE_TEMPLATE_TITLE = 'STUDENT CLASS RECORD SCORE IMPORT TEMPLATE';
const GRADE_SCORE_TEMPLATE_VERSION = '1';
const GRADE_SCORE_TEMPLATE_HEADER_ROW = 5;
const GRADE_SCORE_TEMPLATE_FIRST_DATA_ROW = 6;
const GRADE_SCORE_TEMPLATE_FIRST_SCORE_COLUMN = 5;

function gradeImportIsSystemAttendanceColumn(array $column): bool {
    return strpos((string) ($column['column_key'] ?? ''), 'system_attendance_') === 0;
}

function gradeImportEditableColumns(array $gradeColumns, bool $allowSystemAttendance): array {
    return array_values(array_filter($gradeColumns, function (array $column) use ($allowSystemAttendance): bool {
        return !gradeImportIsSystemAttendanceColumn($column) || $allowSystemAttendance;
    }));
}

function gradeCreateScoreTemplate(array $students, array $columns, array $context = [], array $scoresByStudent = []): Spreadsheet {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Score Import');

    $lastScoreColumnIndex = max(4, GRADE_SCORE_TEMPLATE_FIRST_SCORE_COLUMN + count($columns) - 1);
    $lastScoreColumn = Coordinate::stringFromColumnIndex($lastScoreColumnIndex);
    $layoutLastColumn = Coordinate::stringFromColumnIndex(max(6, $lastScoreColumnIndex));
    $lastDataRow = max(GRADE_SCORE_TEMPLATE_FIRST_DATA_ROW, GRADE_SCORE_TEMPLATE_FIRST_DATA_ROW + count($students) - 1);

    $sheet->setCellValue('A1', GRADE_SCORE_TEMPLATE_TITLE);
    $sheet->mergeCells("A1:{$layoutLastColumn}1");
    $sheet->setCellValue('A2', 'Template Version');
    $sheet->setCellValue('B2', GRADE_SCORE_TEMPLATE_VERSION);
    $sheet->setCellValue('C2', 'Program');
    $sheet->setCellValue('D2', (string) ($context['program'] ?? 'NSTP'));
    $sheet->setCellValue('E2', 'Class Record');
    $sheet->setCellValue('F2', (string) ($context['class_label'] ?? ''));
    $sheet->setCellValue('A3', 'Existing scores are included. Enter or update scores only in the yellow cells. Leave a score blank to keep its current value. Do not edit student details, hidden IDs, or column headings.');
    $sheet->mergeCells("A3:{$layoutLastColumn}3");

    $sheet->fromArray(['student_id', '', '', ''], null, 'A4');
    $headers = ['Student ID', 'Student Number', 'Student Name', 'Course & Section'];
    foreach ($columns as $offset => $column) {
        $columnIndex = GRADE_SCORE_TEMPLATE_FIRST_SCORE_COLUMN + $offset;
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex) . '4', 'grade_column_id:' . (int) $column['grade_column_id']);
        $headers[] = (string) $column['label'] . ' (Max ' . rtrim(rtrim(number_format((float) $column['max_score'], 2, '.', ''), '0'), '.') . ')';
    }
    $sheet->fromArray($headers, null, 'A' . GRADE_SCORE_TEMPLATE_HEADER_ROW);

    foreach ($students as $index => $student) {
        $row = GRADE_SCORE_TEMPLATE_FIRST_DATA_ROW + $index;
        $studentId = (int) $student['tbl_student_id'];
        $sheet->setCellValueExplicit("A{$row}", (string) (int) $student['tbl_student_id'], DataType::TYPE_STRING);
        $sheet->setCellValueExplicit("B{$row}", (string) ($student['student_number'] ?? ''), DataType::TYPE_STRING);
        $sheet->setCellValue("C{$row}", (string) ($student['display_name'] ?? $student['student_name'] ?? ''));
        $sheet->setCellValue("D{$row}", (string) ($student['course_section'] ?? ''));
        foreach ($columns as $offset => $column) {
            $columnId = (int) $column['grade_column_id'];
            $savedScore = $scoresByStudent[$studentId][$columnId] ?? null;
            if ($savedScore === null || $savedScore === '') {
                continue;
            }
            $columnLetter = Coordinate::stringFromColumnIndex(GRADE_SCORE_TEMPLATE_FIRST_SCORE_COLUMN + $offset);
            $sheet->setCellValue("{$columnLetter}{$row}", (float) $savedScore);
        }
    }

    $sheet->getStyle("A1:{$layoutLastColumn}1")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 14],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '166534']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->getStyle("A2:{$layoutLastColumn}3")->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'ECFDF3']],
        'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle('A' . GRADE_SCORE_TEMPLATE_HEADER_ROW . ":{$lastScoreColumn}" . GRADE_SCORE_TEMPLATE_HEADER_ROW)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F4D2A']],
        'alignment' => ['wrapText' => true, 'horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'A7C4B2']]],
    ]);
    $sheet->getStyle("A6:{$lastScoreColumn}{$lastDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D8E2DC');
    if ($columns && $students) {
        $scoreStart = Coordinate::stringFromColumnIndex(GRADE_SCORE_TEMPLATE_FIRST_SCORE_COLUMN);
        $sheet->getStyle("{$scoreStart}6:{$lastScoreColumn}{$lastDataRow}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF3CD']],
            'protection' => ['locked' => Protection::PROTECTION_UNPROTECTED],
        ]);
        foreach ($columns as $offset => $column) {
            $columnLetter = Coordinate::stringFromColumnIndex(GRADE_SCORE_TEMPLATE_FIRST_SCORE_COLUMN + $offset);
            for ($row = GRADE_SCORE_TEMPLATE_FIRST_DATA_ROW; $row <= $lastDataRow; $row++) {
                $validation = $sheet->getCell("{$columnLetter}{$row}")->getDataValidation();
                $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_DECIMAL);
                $validation->setOperator(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::OPERATOR_BETWEEN);
                $validation->setAllowBlank(true);
                $validation->setShowErrorMessage(true);
                $validation->setErrorTitle('Invalid score');
                $validation->setError('Enter a number from 0 to ' . (float) $column['max_score'] . '.');
                $validation->setFormula1('0');
                $validation->setFormula2((string) (float) $column['max_score']);
            }
        }
    }

    $sheet->getColumnDimension('A')->setVisible(false);
    $sheet->getRowDimension(4)->setVisible(false);
    $sheet->getColumnDimension('B')->setWidth(20);
    $sheet->getColumnDimension('C')->setWidth(34);
    $sheet->getColumnDimension('D')->setWidth(24);
    for ($columnIndex = GRADE_SCORE_TEMPLATE_FIRST_SCORE_COLUMN; $columnIndex <= $lastScoreColumnIndex; $columnIndex++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setWidth(20);
    }
    $sheet->getRowDimension(1)->setRowHeight(26);
    $sheet->getRowDimension(3)->setRowHeight(36);
    $sheet->getRowDimension(GRADE_SCORE_TEMPLATE_HEADER_ROW)->setRowHeight(38);
    $sheet->freezePane('E' . GRADE_SCORE_TEMPLATE_FIRST_DATA_ROW);
    $sheet->setAutoFilter('B' . GRADE_SCORE_TEMPLATE_HEADER_ROW . ":{$lastScoreColumn}" . GRADE_SCORE_TEMPLATE_HEADER_ROW);
    $sheet->getProtection()->setSheet(true);
    $sheet->getProtection()->setPassword('nstp-score-template');
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(GRADE_SCORE_TEMPLATE_HEADER_ROW, GRADE_SCORE_TEMPLATE_HEADER_ROW);

    return $spreadsheet;
}

function gradeParseScoreImport(Spreadsheet $spreadsheet, array $studentLookup, array $columnLookup): array {
    $sheet = $spreadsheet->getActiveSheet();
    if (trim((string) $sheet->getCell('A1')->getValue()) !== GRADE_SCORE_TEMPLATE_TITLE
        || trim((string) $sheet->getCell('B2')->getValue()) !== GRADE_SCORE_TEMPLATE_VERSION) {
        throw new InvalidArgumentException('Use the score template downloaded from this class record.');
    }

    $highestRow = $sheet->getHighestDataRow();
    $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    if ($highestRow > 5000 || $highestColumnIndex > 250) {
        throw new InvalidArgumentException('The import file is too large.');
    }

    $importColumns = [];
    $seenColumns = [];
    for ($columnIndex = GRADE_SCORE_TEMPLATE_FIRST_SCORE_COLUMN; $columnIndex <= $highestColumnIndex; $columnIndex++) {
        $marker = trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex) . '4')->getValue());
        if ($marker === '') {
            continue;
        }
        if (!preg_match('/^grade_column_id:(\d+)$/', $marker, $matches)) {
            throw new InvalidArgumentException('The template score columns were changed. Download a fresh template and try again.');
        }
        $columnId = (int) $matches[1];
        if (isset($seenColumns[$columnId]) || !isset($columnLookup[$columnId])) {
            throw new InvalidArgumentException('A score item in the file is duplicated or is no longer available in this class record.');
        }
        $seenColumns[$columnId] = true;
        $importColumns[$columnIndex] = $columnId;
    }
    if (!$importColumns) {
        throw new InvalidArgumentException('No editable score columns were found in the template.');
    }

    $updates = [];
    $errors = [];
    $seenStudents = [];
    for ($row = GRADE_SCORE_TEMPLATE_FIRST_DATA_ROW; $row <= $highestRow; $row++) {
        $studentIdValue = trim((string) $sheet->getCell("A{$row}")->getValue());
        $hasScore = false;
        foreach (array_keys($importColumns) as $columnIndex) {
            $value = $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex) . $row)->getValue();
            if ($value !== null && trim((string) $value) !== '') {
                $hasScore = true;
                break;
            }
        }
        if ($studentIdValue === '' && !$hasScore) {
            continue;
        }
        if (!ctype_digit($studentIdValue) || !isset($studentLookup[(int) $studentIdValue])) {
            $errors[] = "Row {$row}: student is not part of the active class record.";
            continue;
        }
        $studentId = (int) $studentIdValue;
        if (isset($seenStudents[$studentId])) {
            $errors[] = "Row {$row}: student appears more than once.";
            continue;
        }
        $seenStudents[$studentId] = true;
        $expectedNumber = trim((string) ($studentLookup[$studentId]['student_number'] ?? ''));
        $fileNumber = trim((string) $sheet->getCell("B{$row}")->getValue());
        if ($expectedNumber !== $fileNumber) {
            $errors[] = "Row {$row}: student number does not match the template student.";
            continue;
        }

        foreach ($importColumns as $columnIndex => $columnId) {
            $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex) . $row);
            $value = $cell->getValue();
            if ($value === null || trim((string) $value) === '') {
                continue;
            }
            if ($cell->isFormula() || !is_numeric($value) || !is_finite((float) $value)) {
                $errors[] = "Row {$row}: " . $columnLookup[$columnId]['label'] . ' must be a number.';
                continue;
            }
            $score = (float) $value;
            $maxScore = (float) $columnLookup[$columnId]['max_score'];
            if ($score < 0 || $score > $maxScore) {
                $errors[] = "Row {$row}: " . $columnLookup[$columnId]['label'] . " must be between 0 and {$maxScore}.";
                continue;
            }
            $updates[] = ['student_id' => $studentId, 'column_id' => $columnId, 'score' => round($score, 2)];
        }
    }

    if ($errors) {
        $shown = array_slice($errors, 0, 8);
        if (count($errors) > count($shown)) {
            $shown[] = (count($errors) - count($shown)) . ' more error(s) were found.';
        }
        throw new InvalidArgumentException(implode(' ', $shown));
    }
    if (!$updates) {
        throw new InvalidArgumentException('No scores were entered. Add at least one score in a yellow cell.');
    }

    return $updates;
}
