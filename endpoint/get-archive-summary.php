<?php
session_start();

// Set JSON header
header('Content-Type: application/json');

// Error reporting - don't display errors in output
error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database connection
require_once '../conn/conn.php';

try {
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['role'] ?? 'facilitator';
    
    // Create archive table if it doesn't exist
    $createTableSQL = "CREATE TABLE IF NOT EXISTS tbl_attendance_archive (
        tbl_attendance_archive_id INT AUTO_INCREMENT PRIMARY KEY,
        tbl_attendance_id INT NOT NULL,
        tbl_student_id INT NOT NULL,
        time_in TIMESTAMP NOT NULL,
        status VARCHAR(50) NULL,
        archived_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_student_id (tbl_student_id),
        INDEX idx_time_in (time_in),
        INDEX idx_archived_date (archived_date)
    )";
    
    $conn->exec($createTableSQL);
    
    $summary = [];
    
    if ($role === 'super_admin') {
        // Total archived records
        $countSQL = "SELECT COUNT(*) FROM tbl_attendance_archive";
        $stmt = $conn->prepare($countSQL);
        $stmt->execute();
        $summary['total_archived'] = $stmt->fetchColumn();
        
        // Date range of archived records
        $rangeSQL = "SELECT 
            MIN(DATE(time_in)) as earliest_date,
            MAX(DATE(time_in)) as latest_date,
            COUNT(DISTINCT DATE(time_in)) as unique_days
        FROM tbl_attendance_archive";
        
        $stmt = $conn->prepare($rangeSQL);
        $stmt->execute();
        $rangeData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $summary['earliest_date'] = $rangeData['earliest_date'];
        $summary['latest_date'] = $rangeData['latest_date'];
        $summary['unique_days'] = $rangeData['unique_days'] ?? 0;
        
        // Records per day
        $dailySQL = "SELECT 
            DATE(time_in) as attendance_date,
            COUNT(*) as record_count
        FROM tbl_attendance_archive
        GROUP BY DATE(time_in)
        ORDER BY attendance_date DESC
        LIMIT 30";
        
        $stmt = $conn->prepare($dailySQL);
        $stmt->execute();
        $summary['daily_breakdown'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Current active attendance records
        $activeSQL = "SELECT COUNT(*) FROM tbl_attendance";
        $stmt = $conn->prepare($activeSQL);
        $stmt->execute();
        $summary['active_records'] = $stmt->fetchColumn();
        
    } else {
        // Get students created by this admin
        $stmt = $conn->prepare("SELECT tbl_student_id FROM tbl_student WHERE created_by = ?");
        $stmt->execute([$user_id]);
        $student_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($student_ids)) {
            $summary['total_archived'] = 0;
            $summary['earliest_date'] = null;
            $summary['latest_date'] = null;
            $summary['unique_days'] = 0;
            $summary['daily_breakdown'] = [];
            $summary['active_records'] = 0;
        } else {
            $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
            
            // Total archived records
            $countSQL = "SELECT COUNT(*) FROM tbl_attendance_archive WHERE tbl_student_id IN ($placeholders)";
            $stmt = $conn->prepare($countSQL);
            $stmt->execute($student_ids);
            $summary['total_archived'] = $stmt->fetchColumn();
            
            // Date range of archived records
            $rangeSQL = "SELECT 
                MIN(DATE(time_in)) as earliest_date,
                MAX(DATE(time_in)) as latest_date,
                COUNT(DISTINCT DATE(time_in)) as unique_days
            FROM tbl_attendance_archive 
            WHERE tbl_student_id IN ($placeholders)";
            
            $stmt = $conn->prepare($rangeSQL);
            $stmt->execute($student_ids);
            $rangeData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $summary['earliest_date'] = $rangeData['earliest_date'];
            $summary['latest_date'] = $rangeData['latest_date'];
            $summary['unique_days'] = $rangeData['unique_days'] ?? 0;
            
            // Records per day
            $dailySQL = "SELECT 
                DATE(time_in) as attendance_date,
                COUNT(*) as record_count
            FROM tbl_attendance_archive
            WHERE tbl_student_id IN ($placeholders)
            GROUP BY DATE(time_in)
            ORDER BY attendance_date DESC
            LIMIT 30";
            
            $stmt = $conn->prepare($dailySQL);
            $stmt->execute($student_ids);
            $summary['daily_breakdown'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Current active attendance records
            $activeSQL = "SELECT COUNT(*) FROM tbl_attendance WHERE tbl_student_id IN ($placeholders)";
            $stmt = $conn->prepare($activeSQL);
            $stmt->execute($student_ids);
            $summary['active_records'] = $stmt->fetchColumn();
        }
    }
    
    // Format dates for display
    $summary['latest_date'] = $summary['latest_date'] ?? '-';
    $summary['earliest_date'] = $summary['earliest_date'] ?? '-';
    $summary['total_archived'] = (int)($summary['total_archived'] ?? 0);
    $summary['active_records'] = (int)($summary['active_records'] ?? 0);
    $summary['unique_days'] = (int)($summary['unique_days'] ?? 0);
    
    echo json_encode([
        'success' => true,
        'summary' => $summary
    ]);
    
} catch (Exception $e) {
    error_log("Error in get-archive-summary.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error getting archive summary: ' . $e->getMessage()
    ]);
}
?>
