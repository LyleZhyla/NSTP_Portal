<?php
require_once __DIR__ . '/../include/grade-schema.php';

function assertGradeSheetSync($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$conn = new PDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$conn->exec("CREATE TABLE tbl_users (user_id INTEGER PRIMARY KEY, role TEXT, program TEXT)");
$conn->exec("CREATE TABLE tbl_grade_columns (
    grade_column_id INTEGER PRIMARY KEY,
    column_key TEXT,
    program_scope TEXT,
    label TEXT,
    group_code TEXT,
    group_label TEXT,
    max_score NUMERIC,
    weight_percent NUMERIC,
    sort_order INTEGER,
    is_default INTEGER,
    is_active INTEGER,
    created_by INTEGER
)");
$conn->exec("CREATE TABLE tbl_grade_column_visibility (
    grade_column_id INTEGER,
    user_id INTEGER,
    program_scope TEXT,
    is_hidden INTEGER
)");

$conn->exec("INSERT INTO tbl_users VALUES
    (1, 'super_admin', NULL),
    (8, 'coordinator', 'LTS'),
    (9, 'coordinator', 'LTS'),
    (20, 'facilitator', 'LTS'),
    (21, 'facilitator', 'LTS'),
    (30, 'coordinator', 'CWTS')");
$conn->exec("INSERT INTO tbl_grade_columns VALUES
    (1, 'default_hidden', NULL, 'Hidden default', 'default', 'Default', 10, 10, 10, 1, 1, NULL),
    (2, 'first_lts_coordinator', 'LTS', 'First coordinator item', 'activity', 'Activity', 100, 60, 20, 0, 1, 8),
    (3, 'new_lts_coordinator', 'LTS', 'New coordinator item', 'project', 'Project', 100, 40, 30, 0, 1, 9),
    (4, 'selected_facilitator', 'LTS', 'Selected facilitator attendance', 'attendance', 'Attendance', 10, 10, 40, 0, 1, 20),
    (5, 'other_facilitator', 'LTS', 'Other facilitator attendance', 'attendance', 'Attendance', 10, 10, 50, 0, 1, 21),
    (6, 'cwts_coordinator', 'CWTS', 'CWTS item', 'activity', 'Activity', 100, 100, 60, 0, 1, 30)");
$conn->exec("INSERT INTO tbl_grade_column_visibility VALUES (1, 9, 'LTS', 1)");

$columns = loadComponentGradeColumns($conn, 'LTS', 8, 20, 'LTS');
$keys = array_column($columns, 'column_key');

assertGradeSheetSync(in_array('first_lts_coordinator', $keys, true), 'primary LTS coordinator column is shared');
assertGradeSheetSync(in_array('new_lts_coordinator', $keys, true), 'new column from another LTS coordinator is shared');
assertGradeSheetSync(in_array('selected_facilitator', $keys, true), 'selected facilitator private column is included');
assertGradeSheetSync(!in_array('other_facilitator', $keys, true), 'another facilitator private column stays isolated');
assertGradeSheetSync(!in_array('cwts_coordinator', $keys, true), 'another component coordinator column stays isolated');
assertGradeSheetSync(!in_array('default_hidden', $keys, true), 'a column hidden by an LTS coordinator is hidden for all LTS views');
