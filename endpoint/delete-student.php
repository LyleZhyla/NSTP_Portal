<?php
session_start(); // Add session start
include('../conn/conn.php');
require_once '../include/user-permissions.php';

if (!isset($_SESSION['user_id'])) {
    echo "
        <script>
            alert('Unauthorized access!');
            window.location.href = 'http://localhost/qr-code-attendance-system/index.php';
        </script>
    ";
    exit();
}

if (isset($_GET['student'])) {
    $studentId = $_GET['student'];
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'] ?? 'facilitator';

    try {
        // First, check if student exists and user has permission
        $checkStmt = $conn->prepare("SELECT tbl_student_id, student_name, created_by FROM tbl_student WHERE tbl_student_id = ?");
        $checkStmt->execute([$studentId]);
        
        if ($checkStmt->rowCount() === 0) {
            echo "
                <script>
                    alert('Student not found!');
                    window.location.href = 'http://localhost/qr-code-attendance-system/masterlist.php';
                </script>
            ";
            exit();
        }
        
        $student = $checkStmt->fetch();

        if ($userRole === 'facilitator') {
            echo "
                <script>
                    alert('Facilitators can only export student data!');
                    window.location.href = 'http://localhost/qr-code-attendance-system/masterlist.php';
                </script>
            ";
            exit();
        }
        
        // Super admin can delete any student. Other staff can only delete students they created.
        if ($userRole !== 'super_admin' && $student['created_by'] != $userId) {
            echo "
                <script>
                    alert('You do not have permission to delete this student!');
                    window.location.href = 'http://localhost/qr-code-attendance-system/masterlist.php';
                </script>
            ";
            exit();
        }

        if (function_exists('ensureSystemLogsTable')) {
            ensureSystemLogsTable($conn);
        }

        $conn->beginTransaction();

        $archiveStmt = $conn->prepare("DELETE FROM tbl_attendance_archive WHERE tbl_student_id = ?");
        $archiveStmt->execute([$studentId]);

        $attendanceStmt = $conn->prepare("DELETE FROM tbl_attendance WHERE tbl_student_id = ?");
        $attendanceStmt->execute([$studentId]);

        // Delete student if permission granted
        $query = "DELETE FROM tbl_student WHERE tbl_student_id = ?";
        $stmt = $conn->prepare($query);
        $query_execute = $stmt->execute([$studentId]);

        if ($query_execute) {
            if (function_exists('logSystemEvent')) {
                logSystemEvent($conn, 'student_deleted', 'Deleted student record ID ' . $studentId . ': ' . ($student['student_name'] ?? 'Unknown'));
            }
            $conn->commit();
            markSharedDataChanged($conn);
            echo "
                <script>
                    alert('Student deleted successfully!');
                    window.location.href = 'http://localhost/qr-code-attendance-system/masterlist.php';
                </script>
            ";
        } else {
            $conn->rollBack();
            echo "
                <script>
                    alert('Failed to delete student!');
                    window.location.href = 'http://localhost/qr-code-attendance-system/masterlist.php';
                </script>
            ";
        }

    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo "
            <script>
                alert('Error: " . addslashes($e->getMessage()) . "');
                window.location.href = 'http://localhost/qr-code-attendance-system/masterlist.php';
            </script>
        ";
    }
}

?>
