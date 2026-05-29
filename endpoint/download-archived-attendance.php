<?php
// Include database connection
require_once '../conn/conn.php';

// Get parameters
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Validate dates
if (!strtotime($startDate) || !strtotime($endDate)) {
    die('Invalid date format');
}

date_default_timezone_set('Asia/Manila'); // Set timezone to Philippines

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="archived_attendance_' . $startDate . '_to_' . $endDate . '.xls"');
header('Cache-Control: max-age=0');

// Query to get archived attendance data with student information
$query = "SELECT 
    aa.tbl_attendance_archive_id as 'Archive ID',
    aa.tbl_attendance_id as 'Original ID',
    s.student_name as 'Student Name',
    s.course_section as 'Course & Section',
    aa.time_in as 'Time In',
    DATE(aa.time_in) as 'Date',
    TIME(aa.time_in) as 'Time',
    aa.archived_date as 'Archived Date'
FROM tbl_attendance_archive aa
LEFT JOIN tbl_student s ON s.tbl_student_id = aa.tbl_student_id
WHERE DATE(aa.time_in) BETWEEN :start_date AND :end_date
ORDER BY aa.time_in DESC";

$stmt = $conn->prepare($query);
$stmt->bindParam(':start_date', $startDate);
$stmt->bindParam(':end_date', $endDate);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Start Excel content
echo '<table border="1">';
echo '<tr>';
echo '<th colspan="8" style="background-color: #4CAF50; color: white; font-size: 16px; font-weight: bold; text-align: center;">';
echo 'ARCHIVED ATTENDANCE REPORT<br>';
echo 'Period: ' . date('F j, Y', strtotime($startDate)) . ' to ' . date('F j, Y', strtotime($endDate));
echo '</th>';
echo '</tr>';
echo '<tr></tr>'; // Empty row

// Column headers
echo '<tr style="background-color: #f2f2f2; font-weight: bold;">';
echo '<th style="width: 50px;">Archive ID</th>';
echo '<th style="width: 50px;">Original ID</th>';
echo '<th style="width: 200px;">Student Name</th>';
echo '<th style="width: 150px;">Course & Section</th>';
echo '<th style="width: 100px;">Date</th>';
echo '<th style="width: 100px;">Time</th>';
echo '<th style="width: 150px;">Time In</th>';
echo '<th style="width: 150px;">Archived Date</th>';
echo '</tr>';

// Data rows
if (count($results) > 0) {
    foreach ($results as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['Archive ID']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Original ID']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Student Name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Course & Section']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Date']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Time']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Time In']) . '</td>';
        echo '<td>' . htmlspecialchars($row['Archived Date']) . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="8" style="text-align: center; font-style: italic;">No archived attendance records found for the selected period</td></tr>';
}

echo '</table>';
?>
