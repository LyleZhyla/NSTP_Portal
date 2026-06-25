<?php
session_start();

require_once 'conn/conn.php';
require_once 'include/user-permissions.php';
require_once 'include/attendance-settings.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: landing_page.php');
    exit();
}

if (($_SESSION['role'] ?? '') !== 'student') {
    header('Location: index.php');
    exit();
}

$userId = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM tbl_users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("
    SELECT s.*, r.last_name, r.extension_name, r.first_name, r.middle_name, r.place_of_birth,
           r.date_of_birth, r.gender, r.religion, r.blood_type, r.contact_number, r.email,
           r.province, r.city_municipality, r.barangay, r.street, r.house_no,
           r.emergency_name, r.emergency_relationship, r.emergency_contact_number, r.emergency_address,
           r.student_number AS registration_student_number, r.college, r.course, r.major, r.year_section,
           r.component, r.formal_picture, r.created_at AS registration_date
    FROM tbl_student s
    LEFT JOIN tbl_public_student_registrations r ON r.student_number = s.student_number
    WHERE s.user_id = ?
    ORDER BY r.created_at DESC
    LIMIT 1
");
$stmt->execute([$userId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$latestAttendance = null;
$todayAttendance = null;
$attendanceDayActive = false;
$attendanceRecords = [];
$attendanceSummary = [
    'present' => 0,
    'late' => 0,
    'absent_today' => 0,
];

function dashboardAttendanceDisplay($status, $timeIn = null) {
    $rawStatus = trim((string) $status);
    $statusText = 'Present';
    $badgeClass = 'success';
    $session = '';

    if (stripos($rawStatus, 'Late') === 0) {
        $statusText = 'Late';
        $badgeClass = 'warning';
    } elseif (stripos($rawStatus, 'Absent') === 0) {
        $statusText = 'Absent';
        $badgeClass = 'danger';
    }

    if (strpos($rawStatus, '-') !== false) {
        $parts = array_map('trim', explode('-', $rawStatus, 2));
        $session = $parts[1] ?? '';
    } elseif ($timeIn) {
        $session = ((int) date('G', strtotime($timeIn)) < 12) ? 'Morning' : 'Afternoon';
    }

    return [
        'status' => $statusText,
        'badge' => $badgeClass,
        'session' => $session,
    ];
}

if ($student) {
    $latestAttendance = studentAttendanceTimeline($conn, $student, 1)[0] ?? null;

    $todayAttendance = studentAttendanceTimeline($conn, $student, 1, date('Y-m-d'))[0] ?? null;
    $attendanceDayActive = hasAttendanceForStudentScopeOnDate($conn, $student);

    $attendanceRecords = studentAttendanceTimeline($conn, $student, 30);
    $summaryCounts = studentAttendanceHistoricalSummary($conn, $student);
    $attendanceSummary['late'] = (int) ($summaryCounts['late'] ?? 0);
    $attendanceSummary['present'] = (int) ($summaryCounts['present'] ?? 0);
    $attendanceSummary['absent_today'] = (!$todayAttendance && $attendanceDayActive) ? 1 : 0;
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
    <style>
        .qr-card {
            border: 1px solid rgba(47, 111, 126, 0.18);
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
        }
        .qr-card-header {
            background: #2f6f7e;
            color: #fff;
            padding: 18px 22px;
        }
        .qr-profile {
            width: 132px;
            height: 132px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #dbe8ed;
            background: #f8fafc;
        }
        .qr-detail-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
        }
        .qr-detail-value {
            color: #1f2937;
            font-weight: 600;
        }
        .download-actions .btn {
            min-width: 126px;
        }
        .detail-card {
            border: 1px solid rgba(47, 111, 126, 0.14);
            border-radius: 8px;
            padding: 18px;
            height: 100%;
            background: #fff;
        }
        .detail-card h6 {
            font-weight: 700;
            color: #2f6f7e;
        }
        .detail-row {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,0,0,0.06);
        }
        .detail-row:last-child {
            border-bottom: 0;
        }
        .detail-label {
            color: #6b7280;
            font-weight: 700;
            font-size: 0.8rem;
        }
        .detail-value {
            color: #1f2937;
        }
        @media (max-width: 575.98px) {
            .detail-row {
                grid-template-columns: 1fr;
                gap: 2px;
            }
        }
    </style>
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
            <?php include './include/header-notifications.php'; ?>
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
                        <div class="small-box bg-success">
                            <div class="inner">
                                <h3><?php echo $attendanceSummary['present']; ?></h3>
                                <p>Present</p>
                            </div>
                            <div class="icon"><i class="fas fa-check-circle"></i></div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-12">
                        <div class="small-box bg-warning">
                            <div class="inner">
                                <h3><?php echo $attendanceSummary['late']; ?></h3>
                                <p>Late</p>
                            </div>
                            <div class="icon"><i class="fas fa-exclamation-circle"></i></div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-12">
                        <div class="small-box bg-danger">
                            <div class="inner">
                                <h3><?php echo $attendanceSummary['absent_today']; ?></h3>
                                <p>Absent Today</p>
                            </div>
                            <div class="icon"><i class="fas fa-times-circle"></i></div>
                        </div>
                    </div>
                </div>

                <?php if ($student): ?>
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
                        <h3 class="card-title mb-0"><i class="fas fa-id-card mr-2"></i>NSTP ID</h3>
                        <div class="download-actions mt-2 mt-sm-0">
                            <a href="endpoint/download-student-qr.php?format=png" class="btn btn-sm btn-primary">
                                <i class="fas fa-file-image mr-1"></i> PNG
                            </a>
                            <a href="endpoint/download-student-qr.php?format=jpg" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-download mr-1"></i> JPG
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-calendar-check mr-2"></i>My Attendance Details</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($student): ?>
                            <?php
                                $todayDisplay = $todayAttendance
                                    ? dashboardAttendanceDisplay($todayAttendance['status'] ?? '', $todayAttendance['time_in'] ?? null)
                                    : ($attendanceDayActive ? [
                                        'status' => 'Absent',
                                        'badge' => 'danger',
                                        'session' => ((int) date('G') < 12) ? 'Morning' : 'Afternoon',
                                    ] : [
                                        'status' => 'No Attendance',
                                        'badge' => 'secondary',
                                        'session' => '',
                                    ]);
                            ?>
                            <div class="detail-card mb-4">
                                <div class="d-flex align-items-center justify-content-between flex-wrap">
                                    <div>
                                        <h6 class="mb-1">Today's Attendance</h6>
                                        <div class="text-muted small"><?php echo date('F d, Y'); ?></div>
                                    </div>
                                    <span class="badge badge-<?php echo $todayDisplay['badge']; ?> p-2 mt-2 mt-sm-0">
                                        <?php echo htmlspecialchars($todayDisplay['status']); ?>
                                    </span>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-md-4 mb-2 mb-md-0">
                                        <span class="qr-detail-label">Time</span>
                                        <span class="qr-detail-value">
                                            <?php echo $todayAttendance ? date('h:i A', strtotime($todayAttendance['time_in'])) : ($attendanceDayActive ? 'No scan' : 'No attendance today'); ?>
                                        </span>
                                    </div>
                                    <div class="col-md-4 mb-2 mb-md-0">
                                        <span class="qr-detail-label">Session</span>
                                        <span class="qr-detail-value"><?php echo htmlspecialchars($todayDisplay['session'] ?: 'N/A'); ?></span>
                                    </div>
                                    <div class="col-md-4">
                                        <span class="qr-detail-label">Latest Attendance</span>
                                        <span class="qr-detail-value">
                                            <?php echo $latestAttendance ? date('M d, Y h:i A', strtotime($latestAttendance['time_in'])) : 'No attendance yet'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Session</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!$todayAttendance && $attendanceDayActive): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y'); ?></td>
                                                <td>No scan</td>
                                                <td><?php echo htmlspecialchars($todayDisplay['session']); ?></td>
                                                <td><span class="badge badge-danger">Absent</span></td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($attendanceRecords as $attendanceRecord): ?>
                                            <?php $attendanceDisplay = dashboardAttendanceDisplay($attendanceRecord['status'] ?? '', $attendanceRecord['time_in'] ?? null); ?>
                                            <tr>
                                                <td><?php echo date('M d, Y', strtotime($attendanceRecord['time_in'])); ?></td>
                                                <td><?php echo date('h:i A', strtotime($attendanceRecord['time_in'])); ?></td>
                                                <td><?php echo htmlspecialchars($attendanceDisplay['session'] ?: 'N/A'); ?></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $attendanceDisplay['badge']; ?>">
                                                        <?php echo htmlspecialchars($attendanceDisplay['status']); ?>
                                                    </span>
                                                    <?php if (!empty($attendanceRecord['is_archived'])): ?>
                                                        <span class="badge badge-secondary ml-1">Archived</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <?php if (count($attendanceRecords) === 0 && !(!$todayAttendance && $attendanceDayActive)): ?>
                                            <tr>
                                                <td colspan="4" class="text-center text-muted py-4">
                                                    No attendance records yet
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-2"></i>
                                Choose your NSTP component first so your attendance record can be created.
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
