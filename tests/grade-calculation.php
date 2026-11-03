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
assertGradeCalculation(transmuteGradeForProgram(96, 'ROTC') === 1.0, 'ROTC 96% becomes 1.00');
assertGradeCalculation(transmuteGradeForProgram(93, 'ROTC') === 1.25, 'ROTC 93% becomes 1.25');
assertGradeCalculation(transmuteGradeForProgram(85, 'ROTC') === 2.0, 'ROTC 85% becomes 2.00');
assertGradeCalculation(transmuteGradeForProgram(78, 'ROTC') === 2.75, 'ROTC 78% becomes 2.75');
assertGradeCalculation(transmuteGradeForProgram(74.99, 'ROTC') === 5.0, 'ROTC score below 75% becomes 5.00');
assertGradeCalculation(gradeGroupPercentage(0, 50, 'rotc_midterm_exam', 'ROTC') === 50.0, 'ROTC exam starts at the template 50% base');
assertGradeCalculation(gradeGroupPercentage(25, 50, 'rotc_midterm_exam', 'ROTC') === 75.0, 'ROTC exam midpoint becomes 75%');
assertGradeCalculation(gradeGroupPercentage(0, 100, 'rotc_attitude', 'ROTC') === 0.0, 'ROTC attitude has no exam base conversion');
$rotcMinimumWeighted = (gradeGroupPercentage(0, 15, 'attendance', 'ROTC') * 0.30)
    + (gradeGroupPercentage(0, 100, 'rotc_attitude', 'ROTC') * 0.30)
    + (gradeGroupPercentage(0, 50, 'rotc_midterm_exam', 'ROTC') * 0.20)
    + (gradeGroupPercentage(0, 50, 'rotc_final_exam', 'ROTC') * 0.20);
assertGradeCalculation($rotcMinimumWeighted === 20.0, 'ROTC zero raw scores reproduce the template 20% exam base total');

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

$rotcDefaults = rotcDefaultGradeColumns();
$rotcGroupWeights = [];
$rotcMaximums = [];
foreach ($rotcDefaults as $column) {
    $rotcGroupWeights[$column[2]] = (float) $column[5];
    $rotcMaximums[$column[2]] = (float) $column[4];
}
assertGradeCalculation($rotcGroupWeights === [
    'rotc_attitude' => 30.0,
    'rotc_midterm_exam' => 20.0,
    'rotc_final_exam' => 20.0,
], 'ROTC score categories use the template 30/20/20 weights');
assertGradeCalculation($rotcMaximums === [
    'rotc_attitude' => 100.0,
    'rotc_midterm_exam' => 50.0,
    'rotc_final_exam' => 50.0,
], 'ROTC score categories use the template raw maximums');
assertGradeCalculation(array_sum($rotcGroupWeights) === 70.0, 'ROTC score categories leave 30% for attendance');

echo "Grade calculation tests passed.\n";
