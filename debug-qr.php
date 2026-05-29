<?php
session_start();
include("./conn/conn.php");

if (!isset($_SESSION['user_id'])) {
    die("Access denied");
}

// Get all students with their QR codes
$stmt = $conn->prepare("SELECT tbl_student_id, student_name, course_section, generated_code FROM tbl_student ORDER BY tbl_student_id DESC LIMIT 100");
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Test a specific QR code
$test_result = null;
$test_qr = isset($_GET['test_qr']) ? $_GET['test_qr'] : '';
if ($test_qr) {
    $test_stmt = $conn->prepare("SELECT * FROM tbl_student WHERE generated_code = ? OR generated_code LIKE ?");
    $test_stmt->execute([$test_qr, '%' . $test_qr . '%']);
    $test_result = $test_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get recent attendance
$att_stmt = $conn->prepare("SELECT * FROM tbl_attendance ORDER BY time_in DESC LIMIT 20");
$att_stmt->execute();
$attendance = $att_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>QR Code Debug</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
</head>
<body>
    <div class="container-fluid mt-4">
        <h1>QR Code Debug Page</h1>
        <p class="text-muted">Check if QR codes are properly stored in the database</p>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Test QR Code</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="mb-3">
                            <div class="input-group">
                                <input type="text" name="test_qr" class="form-control" 
                                       value="<?php echo htmlspecialchars($test_qr); ?>" 
                                       placeholder="Enter QR code to test">
                                <div class="input-group-append">
                                    <button type="submit" class="btn btn-primary">Test</button>
                                </div>
                            </div>
                        </form>
                        
                        <?php if ($test_qr): ?>
                            <div class="alert <?php echo $test_result ? 'alert-success' : 'alert-danger'; ?>">
                                <strong>QR Code: </strong> <?php echo htmlspecialchars($test_qr); ?><br>
                                <?php if ($test_result): ?>
                                    <strong>Found!</strong><br>
                                    ID: <?php echo $test_result['tbl_student_id']; ?><br>
                                    Name: <?php echo htmlspecialchars($test_result['student_name']); ?><br>
                                    Section: <?php echo htmlspecialchars($test_result['course_section']); ?><br>
                                    Stored QR: <code><?php echo htmlspecialchars($test_result['generated_code']); ?></code>
                                <?php else: ?>
                                    <strong>Not found in database</strong>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Recent Attendance (Last 20)</h5>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Student Name</th>
                                    <th>Time In</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance as $att): ?>
                                <tr>
                                    <td><?php echo $att['tbl_attendance_id']; ?></td>
                                    <td><?php echo htmlspecialchars($att['student_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('h:i A', strtotime($att['time_in'])); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo ($att['status'] == 'Late') ? 'warning' : 'success'; ?>">
                                            <?php echo $att['status'] ?? 'Unknown'; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Recent Students (Last 100)</h5>
                    </div>
                    <div class="card-body p-0" style="max-height: 600px; overflow-y: auto;">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Section</th>
                                    <th>QR Code</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo $student['tbl_student_id']; ?></td>
                                    <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['course_section']); ?></td>
                                    <td>
                                        <code><?php echo htmlspecialchars($student['generated_code']); ?></code>
                                        <a href="?test_qr=<?php echo urlencode($student['generated_code']); ?>" 
                                           class="btn btn-sm btn-outline-primary ml-2">Test</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>