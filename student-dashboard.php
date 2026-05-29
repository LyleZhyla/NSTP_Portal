<?php
session_start();

require_once 'conn/conn.php';
require_once 'include/user-permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: landing_page.php');
    exit();
}

if (($_SESSION['role'] ?? '') !== 'student') {
    header('Location: index.php');
    exit();
}

$userId = $_SESSION['user_id'];
$componentSelectionEnabled = isComponentSelectionEnabled($conn);

$stmt = $conn->prepare("SELECT * FROM tbl_users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT * FROM tbl_student WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$attendanceCount = 0;
$latestAttendance = null;
if ($student) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_attendance WHERE tbl_student_id = ?");
    $stmt->execute([$student['tbl_student_id']]);
    $attendanceCount = (int) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT * FROM tbl_attendance WHERE tbl_student_id = ? ORDER BY time_in DESC LIMIT 1");
    $stmt->execute([$student['tbl_student_id']]);
    $latestAttendance = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Dashboard - TAU NSTP</title>
    <?php include('./include/theme-loader.php'); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="include/theme.css">
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <?php include './include/theme-toggle.php'; ?>
            <?php include './include/theme-toggle-slider.php'; ?>
        </ul>
    </nav>

    <?php include 'adminlte-sidebar.php'; ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <h1><i class="fas fa-tachometer-alt mr-2"></i>Student Dashboard</h1>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-lg-4 col-12">
                        <div class="small-box bg-info">
                            <div class="inner">
                                <h3><?php echo htmlspecialchars($user['program'] ?? 'None'); ?></h3>
                                <p>Selected Component</p>
                            </div>
                            <div class="icon"><i class="fas fa-layer-group"></i></div>
                            <a href="component.php" class="small-box-footer">Manage Component <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    <div class="col-lg-4 col-12">
                        <div class="small-box bg-success">
                            <div class="inner">
                                <h3><?php echo $attendanceCount; ?></h3>
                                <p>Number of Attendance</p>
                            </div>
                            <div class="icon"><i class="fas fa-calendar-check"></i></div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-12">
                        <div class="small-box <?php echo $componentSelectionEnabled ? 'bg-warning' : 'bg-secondary'; ?>">
                            <div class="inner">
                                <h3><?php echo $componentSelectionEnabled ? 'Open' : 'Closed'; ?></h3>
                                <p>Component Selection</p>
                            </div>
                            <div class="icon"><i class="fas <?php echo $componentSelectionEnabled ? 'fa-unlock' : 'fa-lock'; ?>"></i></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-qrcode mr-2"></i>My QR Code</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($student): ?>
                            <?php $qrImage = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($student['generated_code']); ?>
                            <div class="row align-items-center">
                                <div class="col-md-4 text-center mb-3 mb-md-0">
                                    <img src="<?php echo htmlspecialchars($qrImage); ?>" alt="Student QR Code" class="img-thumbnail" style="max-width: 240px;">
                                </div>
                                <div class="col-md-8">
                                    <p><strong>Name:</strong> <?php echo htmlspecialchars($student['student_name']); ?></p>
                                    <p><strong>Component:</strong> <?php echo htmlspecialchars($student['course_section']); ?></p>
                                    <p><strong>QR Code:</strong> <code><?php echo htmlspecialchars($student['generated_code']); ?></code></p>
                                    <p><strong>Latest Attendance:</strong> <?php echo $latestAttendance ? date('F d, Y h:i A', strtotime($latestAttendance['time_in'])) : 'No attendance yet'; ?></p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-2"></i>
                                Choose your NSTP component first to generate your QR code.
                                <a href="component.php" class="alert-link">Go to Component</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <?php include 'footer.php'; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
</body>
</html>
