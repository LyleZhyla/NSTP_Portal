<?php
session_start();
include('./include/theme-loader.php');
if (!isset($_SESSION['user_id'])) {
    header("Location: landing_page.php");
    exit();
}

date_default_timezone_set('Asia/Manila');
include ('./conn/conn.php');
require_once './include/user-permissions.php';

// ADD THIS LINE TO INCLUDE THE LOGO FUNCTIONS
include ('./include/logo-functions.php');

$today = date('Y-m-d');
$currentUserID = $_SESSION['user_id'];
$currentUserRole = $_SESSION['role'] ?? 'facilitator';

if (!canAccessStaffTools($currentUserRole)) {
    header("Location: profile.php");
    exit();
}

// Get statistics with role-based filtering
if ($currentUserRole === 'super_admin') {
    // Super Admin: See all records
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_student");
    $stmt->execute();
    $totalStudents = $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_attendance WHERE DATE(time_in) = :today");
    $stmt->execute(['today' => $today]);
    $todayAttendance = $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_attendance");
    $stmt->execute();
    $totalAttendance = $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_attendance_archive");
    $stmt->execute();
    $totalArchived = $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbl_users WHERE role = 'facilitator'");
    $stmt->execute();
    $totalFacilitators = $stmt->fetchColumn();

    // Recent attendance - all records
    $stmt = $conn->prepare("
        SELECT a.*, s.student_name, s.course_section, u.full_name as recorded_by 
        FROM tbl_attendance a 
        LEFT JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id 
        LEFT JOIN tbl_users u ON u.user_id = s.created_by 
        ORDER BY a.time_in DESC 
        LIMIT 8
    ");
    $stmt->execute();
    $recent = $stmt->fetchAll();

    $componentCounts = ['CWTS' => 0, 'LTS' => 0, 'ROTC' => 0, 'Unassigned' => 0];

    $stmt = $conn->prepare("
        SELECT program, COUNT(*) AS total
        FROM tbl_users
        WHERE role = 'student' AND program IN ('CWTS', 'LTS', 'ROTC')
        GROUP BY program
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $componentCounts[$row['program']] += (int) $row['total'];
    }

    $stmt = $conn->prepare("
        SELECT component, COUNT(*) AS total
        FROM tbl_public_student_registrations
        WHERE component IN ('CWTS', 'LTS', 'ROTC')
          AND (user_id IS NULL OR user_id = 0)
        GROUP BY component
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $componentCounts[$row['component']] += (int) $row['total'];
    }

    $stmt = $conn->prepare("
        SELECT course_section
        FROM tbl_student
        WHERE user_id IS NULL OR user_id = 0
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $section) {
        $program = inferProgramFromText($section);
        if ($program) {
            $componentCounts[$program]++;
        } else {
            $componentCounts['Unassigned']++;
        }
    }
} else {
    // Regular Admin: See only records they created
    
    // Count students created by this admin
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT s.tbl_student_id) as total 
        FROM tbl_student s
        WHERE s.created_by = :user_id
    ");
    $stmt->execute(['user_id' => $currentUserID]);
    $totalStudents = $stmt->fetchColumn();

    // Today's attendance for students created by this admin
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM tbl_attendance a
        INNER JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id
        WHERE DATE(a.time_in) = :today 
        AND s.created_by = :user_id
    ");
    $stmt->execute(['today' => $today, 'user_id' => $currentUserID]);
    $todayAttendance = $stmt->fetchColumn();

    // All attendance for students created by this admin
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM tbl_attendance a
        INNER JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id
        WHERE s.created_by = :user_id
    ");
    $stmt->execute(['user_id' => $currentUserID]);
    $totalAttendance = $stmt->fetchColumn();

    // Archived records for students created by this admin
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM tbl_attendance_archive a
        INNER JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id
        WHERE s.created_by = :user_id
    ");
    $stmt->execute(['user_id' => $currentUserID]);
    $totalArchived = $stmt->fetchColumn();

    // Recent attendance - only for students created by this admin
    $stmt = $conn->prepare("
        SELECT a.*, s.student_name, s.course_section 
        FROM tbl_attendance a 
        INNER JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id 
        WHERE s.created_by = :user_id 
        ORDER BY a.time_in DESC 
        LIMIT 8
    ");
    $stmt->execute(['user_id' => $currentUserID]);
    $recent = $stmt->fetchAll();
    $componentCounts = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard · TAU NSTP National Service Training Program</title>
    
    <?php echo getFaviconTags(); ?>
    
    <!-- ALTERNATIVE DIRECT FAVICON LINKS (BACKUP) -->
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="shortcut icon" href="include/logo.png">
    <link rel="apple-touch-icon" href="include/logo.png">
    
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="include/theme.css">
    
    <style>
        .small-box { border-radius: 10px; }
        .manila-time-card {
            background: linear-gradient(135deg, #198754 0%, #0f5132 100%);
            color: white;
            border-radius: 10px;
            padding: 8px 15px;
            text-align: center;
            display: inline-block;
            min-width: 200px;
        }
        .manila-time {
            font-family: 'Courier New', monospace;
            font-size: 1.4rem;
            font-weight: bold;
            display: inline-block;
            margin-left: 8px;
        }
        .recent-table {
            max-height: 400px;
            overflow-y: auto;
        }
        
        /* Header Container - Fixed positioning */
        .header-container {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            position: relative;
            z-index: 1;
            width: 100%;
        }
        
        /* Dashboard Title */
        .dashboard-title {
            display: flex;
            align-items: center;
            margin: 0;
            font-size: 1.8rem;
        }
        
        /* View Badge Styling */
        .view-badge {
            font-size: 0.85rem;
            padding: 6px 15px;
            border-radius: 30px;
            background: #e9ecef;
            color: #495057;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            border: 1px solid #dee2e6;
        }
        
        /* Quick Actions Styling - With clear indicator */
        .quick-actions-header {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: 10px;
            position: relative;
            z-index: 9999;
            background: #fff;
            padding: 5px 15px;
            border-radius: 50px;
            border: 1px solid #dfe7e2;
            box-shadow: 0 6px 18px rgba(15, 81, 50, 0.08);
        }
        
        .quick-actions-label {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding-right: 12px;
            margin-right: 5px;
            border-right: 1px solid #dfe7e2;
            color: #0f5132;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .quick-actions-label i {
            color: #f39c12;
            font-size: 1rem;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0% {
                opacity: 1;
                transform: scale(1);
            }
            50% {
                opacity: 0.8;
                transform: scale(1.15);
            }
            100% {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        /* Quick Action Icons */
        .quick-action-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #f3faf6;
            color: #0f5132;
            transition: all 0.3s ease;
            text-decoration: none !important;
            position: relative;
            box-shadow: 0 4px 12px rgba(15, 81, 50, 0.10);
            cursor: pointer;
            border: 1px solid #d7eadf;
            z-index: 10000;
        }
        
        .quick-action-icon:hover {
            transform: translateY(-5px) scale(1.08);
            box-shadow: 0 10px 24px rgba(15, 81, 50, 0.22);
            background: #198754;
            color: white;
        }
        
        .quick-action-icon:active {
            transform: translateY(-2px);
        }
        
        .quick-action-icon i {
            font-size: 1.3rem;
            pointer-events: none;
        }
        
        .quick-action-icon.special {
            background: #fff7e6;
            color: #8a5a00;
            border-color: #f3d28b;
        }
        
        .quick-action-icon.special:hover {
            background: #d97706;
            border-color: #d97706;
            color: #fff;
        }
        
        /* Click indicator badge */
        .quick-action-icon::after {
            content: '';
            position: absolute;
            top: -3px;
            right: -3px;
            width: 14px;
            height: 14px;
            background: #f39c12;
            border-radius: 50%;
            border: 2px solid white;
            opacity: 0;
            transition: opacity 0.3s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .quick-action-icon:hover::after {
            opacity: 1;
        }
        
        /* Tooltip styling - Enhanced */
        .quick-action-tooltip {
            position: absolute;
            bottom: -45px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.95);
            color: white;
            padding: 7px 16px;
            border-radius: 30px;
            font-size: 0.8rem;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease;
            pointer-events: none;
            z-index: 10001;
            font-weight: 500;
            letter-spacing: 0.3px;
            border: 1px solid rgba(255,255,255,0.2);
            box-shadow: 0 5px 20px rgba(0,0,0,0.4);
        }
        
        .quick-action-tooltip:before {
            content: '';
            position: absolute;
            top: -6px;
            left: 50%;
            transform: translateX(-50%);
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-bottom: 8px solid rgba(0,0,0,0.95);
        }
        
        .quick-action-icon:hover .quick-action-tooltip {
            opacity: 1;
            visibility: visible;
            bottom: -55px;
        }
        
        /* Role indicator in navbar */
        .role-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8f9fa;
            padding: 5px 15px;
            border-radius: 30px;
            border: 1px solid #dee2e6;
        }
        
        .role-indicator i {
            font-size: 1rem;
        }
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .quick-actions-header {
                margin-left: 0;
                width: 100%;
                justify-content: flex-start;
                flex-wrap: wrap;
                background: transparent;
                border: none;
                padding: 5px 0;
            }
            
            .quick-actions-label {
                border-right: none;
                padding-right: 0;
                margin-right: 8px;
                background: #f3faf6;
                padding: 6px 16px;
                border-radius: 30px;
                border: 1px solid #198754;
            }
        }
        
        @media (max-width: 768px) {
            .quick-action-icon {
                width: 38px;
                height: 38px;
            }
            
            .quick-action-icon i {
                font-size: 1.1rem;
            }
            
            .quick-actions-label {
                font-size: 0.75rem;
                padding: 5px 12px;
            }
            
            .quick-actions-label i {
                font-size: 0.85rem;
            }
            
            .manila-time-card {
                min-width: 150px;
                font-size: 0.9rem;
            }
            
            .manila-time {
                font-size: 1.1rem;
            }
        }
        
        /* Ensure no overlapping elements block clicks */
        .content-header {
            position: relative;
            z-index: 1;
        }
        
        .content-header .container-fluid {
            position: relative;
            z-index: 1;
        }
        
        /* Fix for any potential overlay issues */
        .row {
            position: relative;
        }
        
        /* Make sure the quick actions are above other elements */
        .col-12 {
            position: relative;
        }
    </style>

</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar with NSTP Logo and Time Display -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
            <li class="nav-item d-none d-sm-inline-block ml-2">
                <a href="landing_page.php" class="d-flex align-items-center text-decoration-none">
                    <img src="include/logo.png" alt="NSTP Logo" style="width: 30px; height: 30px; border-radius: 6px; margin-right: 8px;">
                    <span style="font-weight: 600; color: #0f5132; font-size: 14px;"> TAU NSTP</span>
                </a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <?php include './include/header-notifications.php'; ?>
            <!-- Time Display in Navbar -->
            <li class="nav-item">
                <div class="manila-time-card mr-2">
                    <i class="fas fa-clock mr-1"></i> 
                    <span id="manila-clock-time">--:--:--</span>
                </div>
            </li>
            <!-- Role Indicator -->
            <li class="nav-item">
                <div class="role-indicator">
                    <i class="fas fa-user-tag"></i>
                    <?php 
                    if ($currentUserRole === 'super_admin') {
                        echo '<span class="badge badge-danger">Super Admin</span>';
                    } elseif ($currentUserRole === 'coordinator') {
                        echo '<span class="badge badge-warning">Coordinator</span>';
                    } else {
                        echo '<span class="badge badge-primary">Facilitator</span>';
                    }
                    ?>
                </div>
            </li>
            <?php include './include/theme-toggle.php'; ?>  
             <?php include './include/theme-toggle-slider.php'; ?>
        </ul>
    </nav>

    <!-- Sidebar -->
    <?php include 'adminlte-sidebar.php'; ?>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <!-- Content Header -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-12">
                        <div class="header-container">
                            <!-- Dashboard Title with Icon -->
                            <h1 class="dashboard-title">
                                <i class="fas fa-qrcode mr-2" style="color: #198754;"></i> 
                                Dashboard
                            </h1>
                            
                            <!-- View Badge - Beside dashboard -->
                            <?php if ($currentUserRole === 'super_admin'): ?>
                                <span class="view-badge"><i class="fas fa-shield-alt text-danger mr-1"></i> Super Admin View - Showing all records</span>
                            <?php else: ?>
                                <span class="view-badge"><i class="fas fa-user text-primary mr-1"></i> Facilitator View - Showing records for your students only</span>
                            <?php endif; ?>
                            
                            <!-- Quick Actions - With clear indicator -->
                            <div class="quick-actions-header">
                                <div class="quick-actions-label">
                                    <i class="fas fa-bolt"></i>
                                    <span>Quick Actions:</span>
                                </div>
                                
                                <?php if ($currentUserRole !== 'super_admin'): ?>
                                    <a href="attendance.php" class="quick-action-icon" title="Scan Attendance">
                                        <i class="fas fa-qrcode"></i>
                                        <span class="quick-action-tooltip">Scan Attendance</span>
                                    </a>
                                <?php endif; ?>
                                
                                <a href="masterlist.php" class="quick-action-icon" title="<?php echo ($currentUserRole === 'super_admin') ? 'View All Students' : 'View My Students'; ?>">
                                    <i class="fas fa-user-graduate"></i>
                                    <span class="quick-action-tooltip"><?php echo ($currentUserRole === 'super_admin') ? 'All Students' : 'My Students'; ?></span>
                                </a>
                                
                                <a href="archive-manager.php" class="quick-action-icon" title="<?php echo ($currentUserRole === 'super_admin') ? 'Manage All Archive' : 'Manage My Archive'; ?>">
                                    <i class="fas fa-archive"></i>
                                    <span class="quick-action-tooltip"><?php echo ($currentUserRole === 'super_admin') ? 'All Archive' : 'My Archive'; ?></span>
                                </a>
                                
                                <?php if ($currentUserRole === 'super_admin'): ?>
                                    <a href="./endpoint/download-attendance-excel.php" class="quick-action-icon special" title="Export All Data">
                                <?php else: ?>
                                    <a href="./endpoint/download-attendance-excel.php?filter=my_records" class="quick-action-icon special" title="Export My Data">
                                <?php endif; ?>
                                    <i class="fas fa-file-excel"></i>
                                    <span class="quick-action-tooltip">Export Data</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <section class="content">
            <div class="container-fluid">
                <!-- Stats Row -->
                <div class="row">
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-info">
                            <div class="inner">
                                <h3><?php echo $totalStudents; ?></h3>
                                <p>
                                    <?php if ($currentUserRole === 'super_admin'): ?>
                                        Total Students
                                    <?php else: ?>
                                        My Students
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <?php if ($currentUserRole === 'super_admin'): ?>
                                <a href="masterlist.php" class="small-box-footer">View All Students <i class="fas fa-arrow-circle-right"></i></a>
                            <?php else: ?>
                                <a href="masterlist.php" class="small-box-footer">View My Students <i class="fas fa-arrow-circle-right"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-success">
                            <div class="inner">
                                <h3><?php echo $todayAttendance; ?></h3>
                                <p>
                                    <?php if ($currentUserRole === 'super_admin'): ?>
                                        Today's Attendance
                                    <?php else: ?>
                                        My Today's Attendance
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <?php if ($currentUserRole === 'super_admin'): ?>
                                <a href="masterlist.php" class="small-box-footer">View Student Records <i class="fas fa-arrow-circle-right"></i></a>
                            <?php else: ?>
                                <a href="attendance.php" class="small-box-footer">View Scanner <i class="fas fa-arrow-circle-right"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-warning">
                            <div class="inner">
                                <h3><?php echo $totalAttendance; ?></h3>
                                <p>
                                    <?php if ($currentUserRole === 'super_admin'): ?>
                                        Active Records
                                    <?php else: ?>
                                        My Active Records
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                            <?php if ($currentUserRole === 'super_admin'): ?>
                                <a href="archive-manager.php" class="small-box-footer">Review Records <i class="fas fa-arrow-circle-right"></i></a>
                            <?php else: ?>
                                <a href="archive-manager.php" class="small-box-footer">Review My Records <i class="fas fa-arrow-circle-right"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-gradient-secondary">
                            <div class="inner">
                                <h3><?php echo $currentUserRole === 'super_admin' ? $totalFacilitators : $totalArchived; ?></h3>
                                <p>
                                    <?php if ($currentUserRole === 'super_admin'): ?>
                                        Facilitators
                                    <?php else: ?>
                                        My Archived Records
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="icon">
                                <i class="fas <?php echo $currentUserRole === 'super_admin' ? 'fa-user-tie' : 'fa-archive'; ?>"></i>
                            </div>
                            <?php if ($currentUserRole === 'super_admin'): ?>
                                <a href="admin-management.php" class="small-box-footer">Manage Facilitators <i class="fas fa-arrow-circle-right"></i></a>
                            <?php else: ?>
                                <a href="archive-manager.php" class="small-box-footer">Manage My Archive <i class="fas fa-arrow-circle-right"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if ($currentUserRole === 'super_admin'): ?>
                <div class="row mt-2">
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-primary">
                            <div class="inner">
                                <h3><?php echo $componentCounts['CWTS']; ?></h3>
                                <p>CWTS Registered</p>
                            </div>
                            <div class="icon"><i class="fas fa-hands-helping"></i></div>
                            <a href="masterlist.php" class="small-box-footer">View CWTS Folder <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-success">
                            <div class="inner">
                                <h3><?php echo $componentCounts['LTS']; ?></h3>
                                <p>LTS Registered</p>
                            </div>
                            <div class="icon"><i class="fas fa-book-reader"></i></div>
                            <a href="masterlist.php" class="small-box-footer">View LTS Folder <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-danger">
                            <div class="inner">
                                <h3><?php echo $componentCounts['ROTC']; ?></h3>
                                <p>ROTC Registered</p>
                            </div>
                            <div class="icon"><i class="fas fa-shield-alt"></i></div>
                            <a href="masterlist.php" class="small-box-footer">View ROTC Folder <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-secondary">
                            <div class="inner">
                                <h3><?php echo $componentCounts['Unassigned']; ?></h3>
                                <p>No Component / Unmatched</p>
                            </div>
                            <div class="icon"><i class="fas fa-user-clock"></i></div>
                            <a href="masterlist.php" class="small-box-footer">Review Students <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Activity -->
                <div class="row mt-4">
                    <div class="col-lg-12">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-history mr-2" style="color: #198754;"></i> Recent Attendance Activity</h3>
                                <?php if ($currentUserRole === 'super_admin'): ?>
                                    <span class="badge badge-danger float-right">All Admins</span>
                                <?php else: ?>
                                    <span class="badge badge-primary float-right">My Students Only</span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive recent-table">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Student</th>
                                                <th>Course</th>
                                                <th>Time In</th>
                                                <?php if ($currentUserRole === 'super_admin'): ?>
                                                    <th>Created By</th>
                                                <?php endif; ?>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($recent) > 0): ?>
                                                <?php foreach ($recent as $record): ?>
                                                    <?php
                                                    $timeIn = new DateTime($record['time_in'], new DateTimeZone('Asia/Manila'));
                                                    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
                                                    $diff = $now->diff($timeIn);
                                                    
                                                    if ($diff->h > 0) {
                                                        $timeAgo = $diff->h . ' hours ago';
                                                    } elseif ($diff->i > 0) {
                                                        $timeAgo = $diff->i . ' minutes ago';
                                                    } else {
                                                        $timeAgo = 'Just now';
                                                    }
                                                    
                                                    $checkTime = new DateTime($record['time_in'], new DateTimeZone('Asia/Manila'));
                                                    $lateTime = new DateTime($checkTime->format('Y-m-d') . ' 08:00:00', new DateTimeZone('Asia/Manila'));
                                                    $isLate = $checkTime > $lateTime;
                                                    ?>
                                                    <tr>
                                                        <td><strong><?php echo htmlspecialchars($record['student_name']); ?></strong></td>
                                                        <td><?php echo htmlspecialchars($record['course_section']); ?></td>
                                                        <td>
                                                            <div><?php echo $timeIn->format('h:i A'); ?></div>
                                                            <small class="text-muted"><?php echo $timeAgo; ?></small>
                                                        </td>
                                                        <?php if ($currentUserRole === 'super_admin'): ?>
                                                            <td>
                                                                <?php if (isset($record['recorded_by']) && !empty($record['recorded_by'])): ?>
                                                                    <span class="badge badge-info"><?php echo htmlspecialchars($record['recorded_by']); ?></span>
                                                                <?php else: ?>
                                                                    <span class="badge badge-secondary">System</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <td>
                                                            <?php if ($isLate): ?>
                                                                <span class="badge badge-warning p-2">Late</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-success p-2">On Time</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="<?php echo ($currentUserRole === 'super_admin') ? 5 : 4; ?>" class="text-center text-muted py-4">
                                                        <i class="fas fa-clipboard-list fa-2x mb-3 d-block"></i>
                                                        No recent attendance records
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Footer - Now using the included file -->
    <?php include 'footer.php'; ?>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>

<script>
function updateManilaTime() {
    const now = new Date();
    const manilaOffset = 8 * 60;
    const localOffset = now.getTimezoneOffset();
    const manilaTime = new Date(now.getTime() + (manilaOffset + localOffset) * 60000);
    
    const hours = manilaTime.getHours();
    const minutes = manilaTime.getMinutes().toString().padStart(2, '0');
    const seconds = manilaTime.getSeconds().toString().padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    const formattedHours = (hours % 12 || 12).toString().padStart(2, '0');
    
    document.getElementById('manila-clock-time').textContent = 
        `${formattedHours}:${minutes}:${seconds} ${ampm}`;
}

updateManilaTime();
setInterval(updateManilaTime, 1000);

setTimeout(function() {
    location.reload();
}, 60000);
</script>
</body>
</html>
