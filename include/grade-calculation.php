<?php

function usesLtsGradeTransmutation($program) {
    return strtoupper(trim((string) $program)) === 'LTS';
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

    if (usesLtsGradeTransmutation($program)) {
        return round(5 - (4 * ($percentage / 100)), 2);
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
