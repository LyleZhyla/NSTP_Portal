<?php
// Include database connection
require_once '../conn/conn.php';

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="attendance_report_' . date('Y-m-d_H-i-s') . '.xls"');
header('Cache-Control: max-age=0');

// Start session to get logged-in user
session_start();

// Get the selected date (if any) - you can pass this via GET parameter
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get the user ID from session (assuming user is logged in)
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// If user_id is 0, try to get from URL parameter (for testing)
if ($user_id == 0 && isset($_GET['user_id'])) {
    $user_id = $_GET['user_id'];
}

// Get user info
$user_query = "SELECT username, role FROM tbl_users WHERE user_id = :user_id";
$user_stmt = $conn->prepare($user_query);
$user_stmt->execute([':user_id' => $user_id]);
$user_info = $user_stmt->fetch(PDO::FETCH_ASSOC);

// If user is super_admin (user_id = 1), show all students grouped by section
if ($user_id == 1) {
    // Super admin - get all students grouped by section
    $student_query = "SELECT 
        s.tbl_student_id,
        s.student_name,
        s.course_section,
        u.username as created_by_name
    FROM tbl_student s
    LEFT JOIN tbl_users u ON s.created_by = u.user_id
    ORDER BY s.course_section ASC, s.student_name ASC";
    
    $student_stmt = $conn->prepare($student_query);
    $student_stmt->execute();
    $all_students = $student_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group students by section
    $students_by_section = [];
    foreach ($all_students as $student) {
        $section = $student['course_section'];
        if (!isset($students_by_section[$section])) {
            $students_by_section[$section] = [];
        }
        $students_by_section[$section][] = $student;
    }
    
    $admin_display = "SUPER ADMIN";
} else {
    // Regular admin - get sections assigned to this admin
    $sections_query = "SELECT course_section FROM tbl_admin_sections WHERE user_id = :user_id";
    $sections_stmt = $conn->prepare($sections_query);
    $sections_stmt->execute([':user_id' => $user_id]);
    $assigned_sections = $sections_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get students for each assigned section
    $students_by_section = [];
    foreach ($assigned_sections as $section) {
        $student_query = "SELECT 
            tbl_student_id,
            student_name,
            course_section
        FROM tbl_student 
        WHERE course_section = :section
        ORDER BY student_name ASC";
        
        $student_stmt = $conn->prepare($student_query);
        $student_stmt->execute([':section' => $section]);
        $section_students = $student_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($section_students)) {
            $students_by_section[$section] = $section_students;
        }
    }
    
    $admin_display = $user_info ? strtoupper($user_info['username']) : 'UNKNOWN ADMIN';
}

// Query to get attendance records for the selected date
$attendance_query = "SELECT 
    tbl_student_id,
    TIME(time_in) as attendance_time,
    time_in as full_time_in,
    status
FROM tbl_attendance 
WHERE DATE(time_in) = :selected_date
ORDER BY time_in ASC";

$attendance_stmt = $conn->prepare($attendance_query);
$attendance_stmt->execute([':selected_date' => $selected_date]);
$attendance_records = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);

// Create an associative array of attendance for quick lookup
$attendance_lookup = [];
foreach ($attendance_records as $record) {
    $attendance_lookup[$record['tbl_student_id']] = $record;
}

// Define cutoff time for late (e.g., 8:00 AM)
$cutoff_time = '08:00:00';

// Start Excel content
echo '<table border="1">';

// Title row
echo '<tr>';
echo '<th colspan="5" style="background-color: #4CAF50; color: white; font-size: 16px; font-weight: bold; text-align: center;">';
echo 'ATTENDANCE REPORT - ' . date('F j, Y', strtotime($selected_date));
echo '</th>';
echo '</tr>';

// Admin name row
echo '<tr>';
echo '<td colspan="5" style="background-color: #e3f2fd; text-align: center; font-weight: bold; font-size: 14px;">';
echo htmlspecialchars($admin_display);
echo '</td>';
echo '</tr>';

// Calculate totals for each section separately
echo '<tr></tr>'; // Empty row

// Column headers
echo '<tr style="background-color: #f2f2f2; font-weight: bold;">';
echo '<th style="width: 50px;">#</th>';
echo '<th style="width: 300px;">Student Name</th>';
echo '<th style="width: 200px;">Course & Section</th>';
echo '<th style="width: 120px;">Status</th>';
echo '<th style="width: 150px;">Time In</th>';
echo '</tr>';

// Data rows - grouped by section with numbering RESET per section
if (!empty($students_by_section)) {
    
    foreach ($students_by_section as $section => $students) {
        // Section header
        echo '<tr style="background-color: #d9edf7; font-weight: bold;">';
        echo '<td colspan="5" style="padding: 8px; font-size: 14px;">';
        echo 'SECTION: ' . strtoupper(htmlspecialchars($section)) . ' (' . count($students) . ' students)';
        echo '</td>';
        echo '</tr>';
        
        // Calculate section statistics
        $section_present = 0;
        $section_late = 0;
        $section_ontime = 0;
        
        foreach ($students as $student) {
            if (isset($attendance_lookup[$student['tbl_student_id']])) {
                $section_present++;
                $record = $attendance_lookup[$student['tbl_student_id']];
                if (isset($record['status']) && $record['status'] == 'Late') {
                    $section_late++;
                } elseif (strtotime($record['attendance_time']) > strtotime($cutoff_time)) {
                    $section_late++;
                } else {
                    $section_ontime++;
                }
            }
        }
        $section_absent = count($students) - $section_present;
        
        // Section summary right after header
        echo '<tr style="background-color: #f5f5f5;">';
        echo '<td colspan="5" style="text-align: left; padding-left: 20px; font-style: italic;">';
        echo "Summary - Present: {$section_present} | Late: {$section_late} | On Time: {$section_ontime} | Absent: {$section_absent}";
        echo '</td>';
        echo '</tr>';
        
        // Students in this section - numbering RESETS to 1 for each section
        $section_counter = 1; // Reset counter for each section
        foreach ($students as $student) {
            $student_id = $student['tbl_student_id'];
            $is_present = isset($attendance_lookup[$student_id]);
            
            echo '<tr>';
            echo '<td>' . $section_counter . '</td>'; // Use section counter that resets
            echo '<td>' . htmlspecialchars($student['student_name']) . '</td>';
            echo '<td>' . htmlspecialchars($student['course_section']) . '</td>';
            
            if ($is_present) {
                $time_in = $attendance_lookup[$student_id]['attendance_time'];
                $db_status = isset($attendance_lookup[$student_id]['status']) ? $attendance_lookup[$student_id]['status'] : '';
                
                if ($db_status == 'Late' || strtotime($time_in) > strtotime($cutoff_time)) {
                    echo '<td style="background-color: #ffcccb; color: #d32f2f; font-weight: bold;">LATE</td>';
                } else {
                    echo '<td style="background-color: #c8e6c9; color: #2e7d32; font-weight: bold;">ON TIME</td>';
                }
                echo '<td>' . date('h:i A', strtotime($time_in)) . '</td>';
            } else {
                echo '<td style="background-color: #ffebee; color: #c62828; font-weight: bold;">ABSENT</td>';
                echo '<td>-</td>';
            }
            
            echo '</tr>';
            
            $section_counter++; // Increment section counter
        }
        
        // Add empty row between sections
        echo '<tr><td colspan="5" style="height: 10px;"></td></tr>';
    }
} else {
    echo '<tr><td colspan="5" style="text-align: center; font-style: italic;">No students found</td></tr>';
}

echo '</table>';

// Optional: Add metadata
echo '<!-- Generated on: ' . date('Y-m-d H:i:s') . ' -->';
?>