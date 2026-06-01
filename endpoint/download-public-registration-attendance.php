<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/public-registration-forms.php';
require_once '../include/student-account-automation.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access');
}

$currentUser = getCurrentUserRecord($conn);
$role = $currentUser['role'] ?? '';
if (!in_array($role, ['coordinator', 'super_admin'], true)) {
    die('Unauthorized access');
}

$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!strtotime($selectedDate)) {
    die('Invalid date format');
}
$selectedDate = date('Y-m-d', strtotime($selectedDate));
$formTitleFilter = trim((string) ($_GET['form_title'] ?? ''));
$requestedComponent = normalizeProgram($_GET['component'] ?? null);
$componentFilter = null;

if ($role === 'coordinator') {
    $componentFilter = normalizeProgram($currentUser['program'] ?? null);
} elseif ($requestedComponent) {
    $componentFilter = $requestedComponent;
}

header('Content-Type: application/vnd.ms-excel');
$fileComponent = $componentFilter ? '_' . strtolower($componentFilter) : '';
header('Content-Disposition: attachment; filename="public_registration_attendance' . $fileComponent . '_' . $selectedDate . '_' . date('H-i-s') . '.xls"');
header('Cache-Control: max-age=0');

$query = "
    SELECT r.*, f.form_title
    FROM tbl_public_student_registrations r
    LEFT JOIN tbl_public_registration_forms f ON r.form_id = f.form_id
    WHERE r.registrant_role = 'student'
      AND r.student_number IS NOT NULL
      AND r.student_number <> ''
      AND DATE(r.created_at) = ?
";
$params = [$selectedDate];

if ($formTitleFilter !== '') {
    if ($formTitleFilter === 'Default Public Registration') {
        $query .= " AND (f.form_title IS NULL OR f.form_title = '')";
    } else {
        $query .= " AND f.form_title = ?";
        $params[] = $formTitleFilter;
    }
}

if ($componentFilter) {
    $query .= " AND r.component = ?";
    $params[] = $componentFilter;
}

$query .= " ORDER BY r.year_section ASC, r.last_name ASC, r.first_name ASC, r.created_at ASC";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$uniqueRows = [];
foreach ($registrations as $row) {
    $studentNumber = trim((string) ($row['student_number'] ?? ''));
    if ($studentNumber === '') {
        continue;
    }

    $key = $studentNumber . '|' . $selectedDate;
    if (!isset($uniqueRows[$key])) {
        $uniqueRows[$key] = $row;
    }
}

$studentsBySection = [];
foreach ($uniqueRows as $row) {
    $section = trim((string) ($row['year_section'] ?? ''));
    if ($section === '' || strtoupper($section) === 'N/A') {
        $section = 'Pending Section';
    }

    if (!isset($studentsBySection[$section])) {
        $studentsBySection[$section] = [];
    }
    $studentsBySection[$section][] = $row;
}

ksort($studentsBySection, SORT_NATURAL | SORT_FLAG_CASE);

function publicAttendanceName(array $row) {
    $parts = [
        $row['last_name'] ?? '',
        $row['first_name'] ?? '',
    ];
    $name = trim(implode(', ', array_filter($parts)));
    if ($name === '' || $name === ',') {
        $name = 'Student #' . ($row['student_number'] ?? '');
    }
    return $name;
}

function publicAttendanceSection(array $row) {
    $college = trim((string) ($row['college'] ?? ''));
    $course = trim((string) ($row['course'] ?? ''));
    $yearSection = trim((string) ($row['year_section'] ?? ''));

    $parts = array_filter([$college, $course, $yearSection], fn($value) => $value !== '' && strtoupper($value) !== 'N/A');
    return $parts ? implode(' | ', $parts) : 'Pending Section';
}

$cutoffTime = '08:00:00';
$reportTitle = 'PUBLIC REGISTRATION ATTENDANCE REPORT - ' . date('F j, Y', strtotime($selectedDate));
$adminDisplay = strtoupper($currentUser['username'] ?? 'ADMIN');
if ($formTitleFilter !== '') {
    $adminDisplay .= ' | FORM: ' . strtoupper($formTitleFilter);
}
if ($componentFilter) {
    $adminDisplay .= ' | COMPONENT: ' . $componentFilter;
}

echo '<table border="1">';
echo '<tr>';
echo '<th colspan="5" style="background-color: #4CAF50; color: white; font-size: 16px; font-weight: bold; text-align: center;">';
echo htmlspecialchars($reportTitle);
echo '</th>';
echo '</tr>';

echo '<tr>';
echo '<td colspan="5" style="background-color: #e3f2fd; text-align: center; font-weight: bold; font-size: 14px;">';
echo htmlspecialchars($adminDisplay);
echo '</td>';
echo '</tr>';

echo '<tr></tr>';
echo '<tr style="background-color: #f2f2f2; font-weight: bold;">';
echo '<th style="width: 50px;">#</th>';
echo '<th style="width: 300px;">Student Name</th>';
echo '<th style="width: 240px;">Course & Section</th>';
echo '<th style="width: 120px;">Status</th>';
echo '<th style="width: 150px;">Time In</th>';
echo '</tr>';

if (!empty($studentsBySection)) {
    foreach ($studentsBySection as $section => $students) {
        $sectionPresent = count($students);
        $sectionLate = 0;
        $sectionOnTime = 0;

        foreach ($students as $student) {
            $timeIn = date('H:i:s', strtotime($student['created_at']));
            if (($student['status'] ?? '') === 'Late' || strtotime($timeIn) > strtotime($cutoffTime)) {
                $sectionLate++;
            } else {
                $sectionOnTime++;
            }
        }

        echo '<tr style="background-color: #d9edf7; font-weight: bold;">';
        echo '<td colspan="5" style="padding: 8px; font-size: 14px;">';
        echo 'SECTION: ' . strtoupper(htmlspecialchars($section)) . ' (' . count($students) . ' students)';
        echo '</td>';
        echo '</tr>';

        echo '<tr style="background-color: #f5f5f5;">';
        echo '<td colspan="5" style="text-align: left; padding-left: 20px; font-style: italic;">';
        echo "Summary - Present: {$sectionPresent} | Late: {$sectionLate} | On Time: {$sectionOnTime} | Absent: 0";
        echo '</td>';
        echo '</tr>';

        $sectionCounter = 1;
        foreach ($students as $student) {
            $timeIn = date('H:i:s', strtotime($student['created_at']));
            $isLate = (($student['status'] ?? '') === 'Late' || strtotime($timeIn) > strtotime($cutoffTime));

            echo '<tr>';
            echo '<td>' . $sectionCounter . '</td>';
            echo '<td>' . htmlspecialchars(publicAttendanceName($student)) . '</td>';
            echo '<td>' . htmlspecialchars(publicAttendanceSection($student)) . '</td>';
            if ($isLate) {
                echo '<td style="background-color: #ffcccb; color: #d32f2f; font-weight: bold;">LATE</td>';
            } else {
                echo '<td style="background-color: #c8e6c9; color: #2e7d32; font-weight: bold;">ON TIME</td>';
            }
            echo '<td>' . date('h:i A', strtotime($student['created_at'])) . '</td>';
            echo '</tr>';
            $sectionCounter++;
        }

        echo '<tr><td colspan="5" style="height: 10px;"></td></tr>';
    }
} else {
    echo '<tr><td colspan="5" style="text-align: center; font-style: italic;">No public registration attendance records found</td></tr>';
}

echo '</table>';
echo '<!-- Generated on: ' . date('Y-m-d H:i:s') . ' -->';
