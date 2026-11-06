<?php

function normalizeGradeProgram($program) {
    $program = strtoupper(trim((string) $program));
    if (preg_match('/(^|[^A-Z0-9])(CWTS|LTS|ROTC)([^A-Z0-9]|$)/', $program, $matches)) {
        return $matches[2];
    }

    return null;
}

function usesLinearGradeTransmutation($program) {
    return in_array(normalizeGradeProgram($program), ['CWTS', 'LTS'], true);
}

function usesRotcGradeTemplate($program) {
    return normalizeGradeProgram($program) === 'ROTC';
}

function gradeGroupPercentage($earned, $maximum, $groupCode, $program = null) {
    $maximum = (float) $maximum;
    if ($maximum <= 0) {
        return 0.0;
    }

    $percentage = max(0, min(100, ((float) $earned / $maximum) * 100));
    if (usesRotcGradeTemplate($program) && in_array((string) $groupCode, ['rotc_midterm_exam', 'rotc_final_exam'], true)) {
        return 50 + ($percentage * 0.5);
    }

    return (float) $percentage;
}

function transmuteGradeForProgram(
    $percentage,
    $program = null,
    $passingPercentage = 75,
    $highestGrade = 1,
    $passingGrade = 3,
    $failingGrade = 5
) {
    $percentage = max(0, min(100, (float) $percentage));

    if (usesRotcGradeTemplate($program)) {
        foreach ([
            96 => 1.00,
            93 => 1.25,
            90 => 1.50,
            88 => 1.75,
            85 => 2.00,
            83 => 2.25,
            80 => 2.50,
            78 => 2.75,
            75 => 3.00,
        ] as $minimum => $grade) {
            if ($percentage >= $minimum) {
                return $grade;
            }
        }
        return 5.00;
    }

    if (usesLinearGradeTransmutation($program)) {
        // Excel equivalent: =5-(4/100*percentage)
        return round(5 - ((4 / 100) * $percentage), 2);
    }

    $passingPercentage = max(0.01, min(99.99, (float) $passingPercentage));
    $highestGrade = (float) $highestGrade;
    $passingGrade = (float) $passingGrade;
    $failingGrade = (float) $failingGrade;

    if ($percentage < $passingPercentage) {
        $grade = $failingGrade
            - ($percentage / $passingPercentage)
            * ($failingGrade - $passingGrade);
    } else {
        $range = max(0.01, 100 - $passingPercentage);
        $grade = $passingGrade
            - (($percentage - $passingPercentage) / $range)
            * ($passingGrade - $highestGrade);
    }

    return round(max($highestGrade, min($failingGrade, $grade)), 2);
}
