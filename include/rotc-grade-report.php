<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

function rotcReportGender($value) {
    $gender = strtoupper(trim((string) $value));
    if (in_array($gender, ['M', 'MALE'], true)) {
        return 'MALE';
    }
    if (in_array($gender, ['F', 'FEMALE'], true)) {
        return 'FEMALE';
    }
    return 'UNSPECIFIED';
}

function rotcReportEquivalentFormula($percentageCell) {
    return '=IF(' . $percentageCell . '>=96,1.00,IF(' . $percentageCell . '>=93,1.25,IF(' . $percentageCell
        . '>=90,1.50,IF(' . $percentageCell . '>=88,1.75,IF(' . $percentageCell . '>=85,2.00,IF('
        . $percentageCell . '>=83,2.25,IF(' . $percentageCell . '>=80,2.50,IF(' . $percentageCell
        . '>=78,2.75,IF(' . $percentageCell . '>=75,3.00,5.00)))))))))';
}

function rotcReportEquivalentValue($percentage) {
    $percentage = (float) $percentage;
    foreach ([96 => 1.00, 93 => 1.25, 90 => 1.50, 88 => 1.75, 85 => 2.00, 83 => 2.25, 80 => 2.50, 78 => 2.75, 75 => 3.00] as $minimum => $equivalent) {
        if ($percentage >= $minimum) {
            return $equivalent;
        }
    }
    return 5.00;
}

function rotcReportLastName(array $student) {
    $lastName = trim((string) ($student['last_name'] ?? ''));
    if ($lastName !== '') {
        return $lastName;
    }
    $studentName = trim((string) ($student['student_name'] ?? ''));
    if (strpos($studentName, ',') !== false) {
        return trim(explode(',', $studentName, 2)[0]);
    }
    $nameParts = preg_split('/\s+/', $studentName, -1, PREG_SPLIT_NO_EMPTY);
    return $nameParts ? (string) end($nameParts) : '';
}

function rotcReportStudentName(array $student) {
    $lastName = trim((string) ($student['last_name'] ?? ''));
    $firstName = trim((string) ($student['first_name'] ?? ''));
    $middleName = trim((string) ($student['middle_name'] ?? ''));
    if ($lastName !== '' || $firstName !== '') {
        $middleInitial = $middleName !== '' ? ' ' . strtoupper(substr($middleName, 0, 1)) . '.' : '';
        return strtoupper(trim($lastName . ($firstName !== '' ? ', ' . $firstName . $middleInitial : '')));
    }
    return strtoupper(trim((string) ($student['student_name'] ?? '')));
}

function rotcReportMsLevel(array $student) {
    $msLevel = function_exists('normalizeRotcMsLevel')
        ? normalizeRotcMsLevel($student['rotc_ms_level'] ?? null)
        : null;
    if (!$msLevel && function_exists('normalizeRotcMsLevel')) {
        $msLevel = normalizeRotcMsLevel($student['course_section'] ?? null);
    }
    return $msLevel ?: 'MS-1';
}

function rotcReportFilterAndSortStudents(array $students, $genderFilter = '', $courseFilter = '', $msLevelFilter = '', $nameOrder = 'last_name_asc') {
    $genderFilter = strtoupper(trim((string) $genderFilter));
    $courseFilter = strtoupper(trim((string) $courseFilter));
    $msLevelFilter = function_exists('normalizeRotcMsLevel') ? (normalizeRotcMsLevel($msLevelFilter) ?: '') : '';
    $nameOrder = $nameOrder === 'last_name_desc' ? 'last_name_desc' : 'last_name_asc';

    $students = array_values(array_filter($students, static function ($student) use ($genderFilter, $courseFilter, $msLevelFilter) {
        if ($genderFilter !== '' && rotcReportGender($student['gender'] ?? '') !== $genderFilter) {
            return false;
        }
        $rawCourse = $student['course'] ?? $student['course_section'] ?? '';
        $courseAbbreviation = function_exists('academicCourseAbbreviation')
            ? academicCourseAbbreviation($rawCourse)
            : strtoupper(trim((string) $rawCourse));
        if ($courseFilter !== '' && strtoupper($courseAbbreviation) !== $courseFilter) {
            return false;
        }
        return $msLevelFilter === '' || rotcReportMsLevel($student) === $msLevelFilter;
    }));

    usort($students, static function ($left, $right) use ($nameOrder) {
        $comparison = strnatcasecmp(rotcReportLastName($left), rotcReportLastName($right));
        if ($comparison === 0) {
            $comparison = strnatcasecmp((string) ($left['first_name'] ?? $left['student_name'] ?? ''), (string) ($right['first_name'] ?? $right['student_name'] ?? ''));
        }
        return $nameOrder === 'last_name_desc' ? -$comparison : $comparison;
    });
    return $students;
}

function rotcReportAddHeader(Worksheet $sheet, $startRow, $lastColumn, $title, $semesterLabel, $groupLabel, $leftLogoPath, $rightLogoPath) {
    $headerEnd = $startRow + 10;
    $sheet->mergeCells("B{$startRow}:" . chr(ord($lastColumn) - 1) . $startRow);
    $sheet->setCellValue("B{$startRow}", "H E A D Q U A R T E R S\nTARLAC AGRICULTURAL UNIVERSITY ROTC UNIT\n302nd (TLC) Community Defense Center, 3RCDG, RESCOM, PA\nBrgy Malacampa, Camiling, Tarlac");
    $sheet->getStyle("B{$startRow}:" . chr(ord($lastColumn) - 1) . $startRow)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_CENTER)
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setWrapText(true);
    $sheet->getStyle("B{$startRow}:" . chr(ord($lastColumn) - 1) . $startRow)->getFont()->setSize(9);
    $sheet->getRowDimension($startRow)->setRowHeight(58);

    if (is_file($leftLogoPath)) {
        $logo = new Drawing();
        $logo->setName('TAU Seal');
        $logo->setPath($leftLogoPath);
        $logo->setHeight(54);
        $logo->setCoordinates("A{$startRow}");
        $logo->setOffsetX(5);
        $logo->setOffsetY(2);
        $logo->setWorksheet($sheet);
    }
    if (is_file($rightLogoPath)) {
        $logo = new Drawing();
        $logo->setName('ROTC Logo');
        $logo->setPath($rightLogoPath);
        $logo->setHeight(54);
        $logo->setCoordinates("{$lastColumn}{$startRow}");
        $logo->setOffsetX(4);
        $logo->setOffsetY(2);
        $logo->setWorksheet($sheet);
    }

    $dateRow = $startRow + 2;
    $titleRow = $startRow + 4;
    $semesterRow = $startRow + 5;
    $groupRow = $startRow + 8;
    $columnHeaderRow = $headerEnd;
    foreach ([$dateRow, $titleRow, $semesterRow, $groupRow] as $row) {
        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
    $sheet->setCellValue("A{$dateRow}", date('d F Y'));
    $sheet->setCellValue("A{$titleRow}", $title);
    $sheet->getStyle("A{$titleRow}:{$lastColumn}{$titleRow}")->getFont()->setBold(true)->setSize(11);
    $sheet->setCellValue("A{$semesterRow}", '(' . $semesterLabel . ')');
    $sheet->setCellValue("A{$groupRow}", $groupLabel . ' CADETS');
    $sheet->getStyle("A{$groupRow}:{$lastColumn}{$groupRow}")->getFont()->setBold(true)->setUnderline(true);

    return $columnHeaderRow;
}

function createRotcGradeReportSpreadsheet(array $rows, array $gradeColumns, array $options = []) {
    $semester = trim((string) ($options['semester'] ?? '1st Semester'));
    $schoolYear = trim((string) ($options['school_year'] ?? ''));
    $semesterLabel = $semester . ($schoolYear !== '' ? ', SY ' . $schoolYear : '');
    $leftLogoPath = $options['left_logo'] ?? dirname(__DIR__) . '/include/logos/tau-seal.png';
    $rightLogoPath = $options['right_logo'] ?? dirname(__DIR__) . '/include/logos/rotc.png';

    $scoreColumnIds = [];
    foreach ($gradeColumns as $column) {
        $scoreColumnIds[(string) ($column['column_key'] ?? '')] = (int) ($column['grade_column_id'] ?? 0);
    }

    $groups = [];
    foreach (['MS-41', 'MS-31', 'MS-1'] as $msLevel) {
        foreach (['MALE', 'FEMALE'] as $gender) {
            $groups[$msLevel . ' ' . $gender] = [];
        }
    }
    foreach ($rows as $row) {
        $student = $row['student'] ?? [];
        $msLevel = rotcReportMsLevel($student);
        $groupLabel = $msLevel . ' ' . rotcReportGender($student['gender'] ?? '');
        if (!isset($groups[$groupLabel])) {
            $groups[$groupLabel] = [];
        }
        $groups[$groupLabel][] = $row;
    }
    $groups = array_filter($groups, static fn($groupRows) => count($groupRows) > 0);
    if (!$groups) {
        $groups['MS-1 UNSPECIFIED'] = [];
    }

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getDefaultStyle()->getFont()->setName('Arial Narrow')->setSize(8);
    $cog = $spreadsheet->getActiveSheet();
    $cog->setTitle('COG');
    $rog = $spreadsheet->createSheet();
    $rog->setTitle('ROG');
    $cog->setShowGridlines(false);
    $rog->setShowGridlines(false);

    $cogWidths = ['A' => 5, 'B' => 30, 'C' => 15, 'D' => 12, 'E' => 8, 'F' => 12, 'G' => 8, 'H' => 12, 'I' => 8, 'J' => 12, 'K' => 8, 'L' => 14, 'M' => 11, 'N' => 12];
    foreach ($cogWidths as $column => $width) {
        $cog->getColumnDimension($column)->setWidth($width);
    }
    foreach (['A' => 5, 'B' => 32, 'C' => 18, 'D' => 16, 'E' => 12, 'F' => 13] as $column => $width) {
        $rog->getColumnDimension($column)->setWidth($width);
    }

    $cogRow = 1;
    $rogRow = 1;
    $cogStudentRows = [];
    foreach ($groups as $groupLabel => $groupRows) {
        if ($cogRow > 1) {
            $cog->setBreak("A{$cogRow}", Worksheet::BREAK_ROW);
            $rog->setBreak("A{$rogRow}", Worksheet::BREAK_ROW);
        }

        $cogHeader = rotcReportAddHeader($cog, $cogRow, 'N', 'COMPUTATION OF GRADES', $semesterLabel, $groupLabel, $leftLogoPath, $rightLogoPath);
        $cogHeaders = ['NR', 'NAME', 'COURSE', "ATTENDANCE\n(15)", '30%', "ATTITUDE\n(100)", '30%', "MIDTERM\nEXAM", '(20%)', "FINAL\nEXAM", '(20%)', "TOTAL\n(GRADE IN %)", 'Equivalent', 'REMARKS'];
        foreach ($cogHeaders as $index => $header) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $cog->setCellValue($column . $cogHeader, $header);
        }
        $cog->getRowDimension($cogHeader)->setRowHeight(35);
        $cog->getStyle("A{$cogHeader}:N{$cogHeader}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $rogHeader = rotcReportAddHeader($rog, $rogRow, 'F', 'REPORT OF GRADES', $semesterLabel, $groupLabel, $leftLogoPath, $rightLogoPath);
        $rogHeaders = ['NR', 'NAME', 'COURSE', "TOTAL\n(GRADE IN %)", 'Equivalent', 'REMARKS'];
        foreach ($rogHeaders as $index => $header) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1);
            $rog->setCellValue($column . $rogHeader, $header);
        }
        $rog->getRowDimension($rogHeader)->setRowHeight(35);
        $rog->getStyle("A{$rogHeader}:F{$rogHeader}")->applyFromArray([
            'font' => ['bold' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);

        $dataStart = $cogHeader + 1;
        $rogDataStart = $rogHeader + 1;
        foreach ($groupRows as $index => $row) {
            $student = $row['student'] ?? [];
            $scores = $row['scores'] ?? [];
            $dataRow = $dataStart + $index;
            $summaryRow = $rogDataStart + $index;
            $name = rotcReportStudentName($student);
            $rawCourse = trim((string) ($student['course'] ?? $student['course_section'] ?? ''));
            $course = function_exists('academicCourseAbbreviation')
                ? academicCourseAbbreviation($rawCourse)
                : strtoupper($rawCourse);
            $attitudeId = $scoreColumnIds['rotc_attitude'] ?? 0;
            $midtermId = $scoreColumnIds['rotc_midterm_exam'] ?? 0;
            $finalId = $scoreColumnIds['rotc_final_exam'] ?? 0;
            $attitude = $attitudeId ? ($scores[$attitudeId] ?? '') : '';
            $midterm = $midtermId ? ($scores[$midtermId] ?? '') : '';
            $final = $finalId ? ($scores[$finalId] ?? '') : '';

            $attendance = min(15, max(0, (int) ($row['attendance_count'] ?? 0)));
            $attitudeValue = $attitude === '' || $attitude === null ? 0 : (float) $attitude;
            $midtermValue = $midterm === '' || $midterm === null ? 0 : (float) $midterm;
            $finalValue = $final === '' || $final === null ? 0 : (float) $final;
            $attendancePoints = ($attendance / 15) * 100 * 0.30;
            $attitudePoints = ($attitudeValue / 100) * 100 * 0.30;
            $midtermPoints = (($midtermValue / 50) * 50 + 50) * 0.20;
            $finalPoints = (($finalValue / 50) * 50 + 50) * 0.20;
            $totalPercentage = $attendancePoints + $attitudePoints + $midtermPoints + $finalPoints;
            $equivalentValue = rotcReportEquivalentValue($totalPercentage);
            $remarksValue = $totalPercentage >= 75 ? 'PASSED' : 'FAILED';

            $cog->fromArray([$index + 1, $name, $course, $attendance, null, $attitude, null, $midterm, null, $final], null, "A{$dataRow}");
            $cog->setCellValue("E{$dataRow}", "=D{$dataRow}/15*(50+50)*30%");
            $cog->getCell("E{$dataRow}")->setCalculatedValue($attendancePoints);
            $cog->setCellValue("G{$dataRow}", "=F{$dataRow}/100*(50+50)*30%");
            $cog->getCell("G{$dataRow}")->setCalculatedValue($attitudePoints);
            $cog->setCellValue("I{$dataRow}", "=(H{$dataRow}/50*50+50)*20%");
            $cog->getCell("I{$dataRow}")->setCalculatedValue($midtermPoints);
            $cog->setCellValue("K{$dataRow}", "=(J{$dataRow}/50*50+50)*20%");
            $cog->getCell("K{$dataRow}")->setCalculatedValue($finalPoints);
            $cog->setCellValue("L{$dataRow}", "=E{$dataRow}+G{$dataRow}+I{$dataRow}+K{$dataRow}");
            $cog->getCell("L{$dataRow}")->setCalculatedValue($totalPercentage);
            $cog->setCellValue("M{$dataRow}", rotcReportEquivalentFormula("L{$dataRow}"));
            $cog->getCell("M{$dataRow}")->setCalculatedValue($equivalentValue);
            $cog->setCellValue("N{$dataRow}", "=IF(L{$dataRow}>=75,\"PASSED\",\"FAILED\")");
            $cog->getCell("N{$dataRow}")->setCalculatedValue($remarksValue);
            $cog->getStyle("A{$dataRow}:N{$dataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $cog->getStyle("A{$dataRow}:N{$dataRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $cog->getStyle("A{$dataRow}:A{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $cog->getStyle("C{$dataRow}:N{$dataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $cog->getStyle("E{$dataRow}:L{$dataRow}")->getNumberFormat()->setFormatCode('0.00');
            $cog->getStyle("M{$dataRow}")->getNumberFormat()->setFormatCode('0.00');

            $rog->fromArray([$index + 1, $name, $course], null, "A{$summaryRow}");
            $rog->setCellValue("D{$summaryRow}", "=COG!L{$dataRow}");
            $rog->getCell("D{$summaryRow}")->setCalculatedValue($totalPercentage);
            $rog->setCellValue("E{$summaryRow}", "=COG!M{$dataRow}");
            $rog->getCell("E{$summaryRow}")->setCalculatedValue($equivalentValue);
            $rog->setCellValue("F{$summaryRow}", "=COG!N{$dataRow}");
            $rog->getCell("F{$summaryRow}")->setCalculatedValue($remarksValue);
            $rog->getStyle("A{$summaryRow}:F{$summaryRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $rog->getStyle("A{$summaryRow}:F{$summaryRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $rog->getStyle("A{$summaryRow}:A{$summaryRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $rog->getStyle("C{$summaryRow}:F{$summaryRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $rog->getStyle("D{$summaryRow}:E{$summaryRow}")->getNumberFormat()->setFormatCode('0.00');
            $cogStudentRows[] = $dataRow;
        }

        if (!$groupRows) {
            $emptyRow = $dataStart;
            $cog->mergeCells("A{$emptyRow}:N{$emptyRow}");
            $cog->setCellValue("A{$emptyRow}", 'No ROTC grade records found.');
            $cog->getStyle("A{$emptyRow}:N{$emptyRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $cog->getStyle("A{$emptyRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $rog->mergeCells("A{$rogDataStart}:F{$rogDataStart}");
            $rog->setCellValue("A{$rogDataStart}", 'No ROTC grade records found.');
            $rog->getStyle("A{$rogDataStart}:F{$rogDataStart}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $rog->getStyle("A{$rogDataStart}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $cogRow = $dataStart + max(count($groupRows), 1) + 2;
        $rogRow = $rogDataStart + max(count($groupRows), 1) + 2;
    }

    foreach ([$cog, $rog] as $sheet) {
        $sheet->freezePane('A12');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.35)->setRight(0.25)->setBottom(0.35)->setLeft(0.25);
        $sheet->getHeaderFooter()->setOddFooter('&RPage &P of &N');
    }
    $cog->getPageSetup()->setPrintArea('A1:N' . max(1, $cogRow - 2));
    $rog->getPageSetup()->setPrintArea('A1:F' . max(1, $rogRow - 2));
    $spreadsheet->setActiveSheetIndex(0);
    return $spreadsheet;
}
