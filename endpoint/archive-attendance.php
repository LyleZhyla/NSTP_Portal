<?php
session_start();

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

include('../conn/conn.php');

$response = ['success' => false, 'message' => ''];

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $archive_date = $input['archive_date'] ?? null;
    
    if (!$archive_date) {
        throw new Exception('Archive date is required');
    }
    
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['role'] ?? 'facilitator';
    
    $conn->beginTransaction();
    
    if ($role === 'super_admin') {
        // Super admin can archive all records
        $stmt = $conn->prepare("
            SELECT a.*, s.student_name, s.course_section 
            FROM tbl_attendance a
            JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
            WHERE DATE(a.time_in) <= ?
        ");
        $stmt->execute([$archive_date]);
        $records_to_archive = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($records_to_archive as $record) {
            // Insert into archive
            $archive_stmt = $conn->prepare("
                INSERT INTO tbl_attendance_archive (tbl_attendance_id, tbl_student_id, time_in, archived_date)
                VALUES (?, ?, ?, NOW())
            ");
            $archive_stmt->execute([
                $record['tbl_attendance_id'],
                $record['tbl_student_id'],
                $record['time_in']
            ]);
            
            // Delete from main table
            $delete_stmt = $conn->prepare("DELETE FROM tbl_attendance WHERE tbl_attendance_id = ?");
            $delete_stmt->execute([$record['tbl_attendance_id']]);
        }
        
        $archived_count = count($records_to_archive);
        
    } else {
        // Regular admin - only archive records of their students
        $stmt = $conn->prepare("
            SELECT tbl_student_id 
            FROM tbl_student 
            WHERE created_by = ?
        ");
        $stmt->execute([$user_id]);
        $student_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($student_ids)) {
            $archived_count = 0;
        } else {
            $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
            
            $stmt = $conn->prepare("
                SELECT a.*, s.student_name, s.course_section 
                FROM tbl_attendance a
                JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
                WHERE a.tbl_student_id IN ($placeholders)
                AND DATE(a.time_in) <= ?
            ");
            $params = array_merge($student_ids, [$archive_date]);
            $stmt->execute($params);
            $records_to_archive = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($records_to_archive as $record) {
                $archive_stmt = $conn->prepare("
                    INSERT INTO tbl_attendance_archive (tbl_attendance_id, tbl_student_id, time_in, archived_date)
                    VALUES (?, ?, ?, NOW())
                ");
                $archive_stmt->execute([
                    $record['tbl_attendance_id'],
                    $record['tbl_student_id'],
                    $record['time_in']
                ]);
                
                $delete_stmt = $conn->prepare("DELETE FROM tbl_attendance WHERE tbl_attendance_id = ?");
                $delete_stmt->execute([$record['tbl_attendance_id']]);
            }
            
            $archived_count = count($records_to_archive);
        }
    }
    
    $conn->commit();
    
    $response['success'] = true;
    $response['message'] = "Successfully archived $archived_count record(s)";
    
} catch (Exception $e) {
    $conn->rollBack();
    error_log("Error in archive-attendance.php: " . $e->getMessage());
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
exit();
?>  