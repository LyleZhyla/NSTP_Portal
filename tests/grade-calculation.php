<?php

require_once __DIR__ . '/../include/grade-calculation.php';
require_once __DIR__ . '/../include/grade-schema.php';

function assertGradeCalculation($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

assertGradeCalculation(transmuteGradeForProgram(100, 'LTS') === 1.0, 'LTS 100% becomes 1.00');
assertGradeCalculation(transmuteGradeForProgram(75, 'LTS') === 2.0, 'LTS 75% becomes 2.00');
assertGradeCalculation(transmuteGradeForProgram(50, 'LTS') === 3.0, 'LTS 50% becomes 3.00');
assertGradeCalculation(transmuteGradeForProgram(0, 'LTS') === 5.0, 'LTS 0% becomes 5.00');
assertGradeCalculation(transmuteGradeForProgram(75, 'CWTS') === 3.0, 'CWTS keeps the configured passing grade');
assertGradeCalculation(transmuteGradeForProgram(75, 'ROTC') === 3.0, 'ROTC keeps the configured passing grade');
assertGradeCalculation(transmuteGradeForProgram(85, 'CWTS') === 2.2, 'CWTS keeps piecewise transmutation');

$ltsGroupWeights = [];
foreach (ltsDefaultGradeColumns() as $column) {
    $ltsGroupWeights[$column[2]] = (float) $column[5];
}
assertGradeCalculation($ltsGroupWeights === [
    'lts_quiz' => 20.0,
    'lts_term_test' => 20.0,
    'lts_requirements' => 30.0,
    'class_standing_attendance' => 30.0,
], 'LTS default categories use the confirmed 20/20/30/30 weights');
assertGradeCalculation(array_sum($ltsGroupWeights) === 100.0, 'LTS default category weights total 100%');

echo "Grade calculation tests passed.\n";
