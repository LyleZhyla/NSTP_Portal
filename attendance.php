<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: landing_page.php");
    exit();
}

date_default_timezone_set('Asia/Manila');
include ('./conn/conn.php');
include('./include/logo-functions.php');
require_once './include/user-permissions.php';
require_once './include/attendance-settings.php';

// Get current admin info
$admin_id = $_SESSION['user_id'];
$admin_role = $_SESSION['role'] ?? 'facilitator';
if (!canAccessStaffTools($admin_role)) {
    header("Location: profile.php");
    exit();
}
$currentUser = getCurrentUserRecord($conn);
ensureRotcAttendanceSchema($conn);
$facilitatorScanRestrictionEnabled = isFacilitatorScanRestrictionEnabled($conn);
$canViewAllAttendance = $admin_role === 'super_admin'
    || ($admin_role === 'facilitator' && !$facilitatorScanRestrictionEnabled);
$attendanceAccess = studentAttendanceAccessSqlForUser($currentUser ?: ['role' => $admin_role, 'user_id' => $admin_id], 's');
$attendanceAccessCondition = $attendanceAccess['condition'];
$attendanceAccessParams = $attendanceAccess['params'];

if ($admin_role === 'super_admin') {
    header("Location: admin-management.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance Scanner - TAU-NSTP</title>
    
    <!-- 🔥 TAB LOGO - NSTP LOGO 🔥 -->
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="shortcut icon" href="include/logo.png">
    <link rel="apple-touch-icon" href="include/logo.png">
    <?php include('./include/theme-loader.php'); ?>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Source+Sans+Pro:wght@300;400;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <!-- AdminLTE -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        /* RESET TO MATCH OTHER PAGES - CONSISTENT SIZING */
        body {
            font-family: 'Source Sans Pro', sans-serif;
            font-size: 0.95rem;
        }
        
        .content-wrapper {
            background-color: #f4f6f9;
        }
        
        .content-header h1 {
            font-size: 1.8rem;
            font-weight: 400;
        }
        
        .content-header h1 i {
            color: #198754;
        }
        
        /* SCANNER CONTAINER - REDUCED SIZE */
        .scanner-container {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        #qr-reader {
            width: 100%;
            border-radius: 8px;
            border: 2px solid #198754;
            overflow: hidden;
            display: none;
            background: #000;
        }
        
        #qr-reader__scan_region {
            background: #000;
            min-height: 280px;
        }
        
        #qr-reader__scan_region video {
            width: 100%;
            height: auto;
            object-fit: cover;
        }
        
        #qr-reader__dashboard {
            background: #f8f9fa;
            padding: 8px;
        }
        
        .scanner-placeholder {
            width: 100%;
            height: 280px;
            background: linear-gradient(135deg, #0f5132, #198754);
            border-radius: 8px;
            border: 2px solid #198754;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-align: center;
        }
        
        .scanner-placeholder i {
            font-size: 3rem;
            margin-bottom: 10px;
        }
        
        .scanner-placeholder h5 {
            font-size: 1.1rem;
            margin-bottom: 5px;
        }
        
        .qr-detected-box {
            background: #0f5132;
            color: white;
            border-radius: 8px;
            padding: 20px;
            animation: fadeIn 0.5s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .camera-controls {
            display: flex;
            gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        
        /* BUTTONS - CONSISTENT WITH OTHER PAGES */
        .btn {
            border-radius: 20px;
            padding: 6px 18px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .btn-sm {
            padding: 4px 12px;
            font-size: 0.85rem;
        }
        
        .btn-success {
            background: #198754;
            border: none;
        }
        
        .btn-success:hover {
            background: #0f5132;
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: #dc3545;
            border: none;
        }
        
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        
        .btn-primary {
            background: #0f5132;
            border: none;
        }
        
        .btn-primary:hover {
            background: #198754;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(44, 62, 80, 0.2);
        }
        
        .btn-info {
            background: #198754;
            border: none;
        }
        
        .btn-info:hover {
            background: #0f5132;
            transform: translateY(-2px);
        }
        
        .btn-light {
            background: white;
            color: #0f5132;
            border: none;
        }
        
        .btn-light:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
        }
        
        .btn-outline-light {
            border: 1px solid white;
            color: white;
        }
        
        .btn-outline-light:hover {
            background: rgba(255,255,255,0.1);
            transform: translateY(-2px);
        }
        
        .btn-outline-secondary {
            border: 1px solid #6c757d;
            color: #6c757d;
        }
        
        .btn-outline-secondary:hover {
            background: #6c757d;
            color: white;
            transform: translateY(-2px);
        }
        
        /* INFO BOX - COMPACT VERSION */
        .info-box {
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 15px;
            min-height: 80px;
        }
        
        .info-box-icon {
            border-radius: 8px 0 0 8px;
            width: 70px;
            font-size: 1.8rem;
        }
        
        .info-box-content {
            padding: 12px 15px;
        }
        
        .info-box-text {
            font-size: 0.85rem;
            margin-bottom: 2px;
        }
        
        .info-box-number {
            font-size: 1.4rem;
            font-weight: 600;
        }
        
        .bg-gradient-info {
            background: #0f5132;
        }
        
        .bg-gradient-success {
            background: #198754;
        }
        
        .bg-gradient-warning {
            background: linear-gradient(135deg, #ffc107, #e0a800);
        }
        
        /* CARD STYLING - MATCH OTHER PAGES */
        .card {
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e9ecef;
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #e9ecef;
            padding: 12px 20px;
        }
        
        .card-header .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 0;
            color: #0f5132;
        }
        
        .card-header .card-title i {
            color: #198754;
        }

        .attendance-records-card .card-header {
            background: #f8fafc;
            border-bottom: 1px solid #dce8e0;
        }

        .attendance-records-card .card-title {
            color: #0f5132 !important;
        }

        .attendance-records-card .btn-group-custom {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .attendance-records-card .btn-group-custom .btn {
            border-radius: 6px;
            color: #fff !important;
            font-weight: 600;
            line-height: 1.2;
        }

        .attendance-records-card .btn-export {
            background: #198754 !important;
            border-color: #198754 !important;
        }

        .attendance-records-card .btn-refresh {
            background: #0f766e !important;
            border-color: #0f766e !important;
        }

        .attendance-records-card .btn-archive {
            background: #475569 !important;
            border-color: #475569 !important;
        }

        .attendance-records-card .btn-export:hover {
            background: #0f5132 !important;
            border-color: #0f5132 !important;
        }

        .attendance-records-card .btn-refresh:hover {
            background: #115e59 !important;
            border-color: #115e59 !important;
        }

        .attendance-records-card .btn-archive:hover {
            background: #334155 !important;
            border-color: #334155 !important;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .card-footer {
            background-color: #fff;
            border-top: 1px solid #e9ecef;
            padding: 12px 20px;
        }
        
        /* TABLE STYLES */
        .table {
            font-size: 0.9rem;
        }
        
        .table thead th {
            border-bottom: 2px solid #e9ecef;
            color: #495057;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .status-badge {
            font-size: 0.8rem;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .badge-success {
            background: #198754;
            color: white;
        }
        
        .badge-warning {
            background: #ffc107;
            color: #212529;
        }
        
        /* EMPTY STATE */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
            width: 100%;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.4;
        }
        
        .empty-state h5 {
            font-size: 1.1rem;
            margin-bottom: 8px;
        }
        
        /* BREADCRUMB */
        .breadcrumb {
            background: transparent;
            padding: 0;
            margin: 0;
        }
        
        .breadcrumb-item a {
            color: #6c757d;
        }
        
        .breadcrumb-item.active {
            color: #0f5132;
        }
        
        /* MODAL STYLES */
        .modal-content {
            border-radius: 10px;
            border: none;
        }

        .modal {
            z-index: 10650 !important;
        }

        .modal-backdrop {
            z-index: 10640 !important;
        }
        
        .modal-header {
            border-radius: 10px 10px 0 0;
            padding: 15px 20px;
        }
        
        .modal-header.bg-primary {
            background: linear-gradient(135deg, #0f5132, #198754) !important;
        }
        
        .modal-title {
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #e9ecef;
        }
        
        /* FORM CONTROLS */
        .form-control {
            border-radius: 20px;
            border: 1px solid #e9ecef;
            padding: 8px 15px;
            font-size: 0.9rem;
        }
        
        .form-control:focus {
            border-color: #198754;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25);
        }
        
        .input-group-text {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 20px 0 0 20px;
            color: #6c757d;
        }
        
        .input-group-append .input-group-text {
            border-radius: 0 20px 20px 0;
        }
        
        /* SELECT2 CUSTOM */
        .select2-container--bootstrap4 .select2-selection {
            border-radius: 20px !important;
            border: 1px solid #e9ecef !important;
            padding: 5px 15px;
        }
        
        .select2-container--bootstrap4.select2-container--focus .select2-selection {
            border-color: #198754 !important;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25) !important;
        }
        
        /* SCANNER STATUS */
        .scanner-status {
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 15px;
            display: none;
            font-size: 0.9rem;
        }
        
        /* QR CONTENT BOX */
        .qr-content-box {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            padding: 10px;
            margin: 15px 0;
            font-family: 'Courier New', monospace;
            word-break: break-all;
            font-size: 0.85rem;
        }
        
        .student-info {
            font-size: 1rem;
            font-weight: 600;
            margin: 10px 0;
        }
        
        .attendance-time {
            font-size: 0.85rem;
            opacity: 0.9;
        }
        
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .content-header h1 {
                font-size: 1.5rem;
            }
            
            .info-box-icon {
                width: 60px;
                font-size: 1.5rem;
            }
            
            .info-box-number {
                font-size: 1.2rem;
            }
            
            .scanner-placeholder {
                height: 220px;
            }
            
            #qr-reader__scan_region {
                min-height: 220px;
            }
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button">
                    <i class="fas fa-bars"></i>
                </a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="index.php" class="nav-link">Home</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="attendance.php" class="nav-link">Attendance</a>
            </li>
        </ul>
        
        <ul class="navbar-nav ml-auto">
            <?php include './include/header-notifications.php'; ?>
            <li class="nav-item">
                <a class="nav-link" href="#" data-toggle="tooltip" title="Manila Time">
                    <i class="far fa-clock"></i>
                    <span id="current-time"><?php echo date('h:i A'); ?></span>
                </a>
            </li>
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'facilitator'); ?>
                </a>
                <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <div class="dropdown-divider"></div>
                    <a href="./endpoint/logout.php" class="dropdown-item">
                        <i class="fas fa-sign-out-alt mr-2"></i> Logout
                    </a>
                </div>
            </li>
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
                    <div class="col-sm-6">
                        <h1 class="m-0">
                            <i class="fas fa-qrcode mr-2"></i>Attendance Scanner
                        </h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                            <li class="breadcrumb-item active">Attendance Scanner</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <!-- Main Content -->
<section class="content">
    <div class="container-fluid">
        <!-- Scanner Status Alert -->
        <div class="row">
            <div class="col-12">
                <div class="scanner-status alert alert-info" id="scannerStatus">
                    <i class="fas fa-info-circle mr-2"></i>
                    <span id="statusMessage">Scanner ready. Click "Start Scanner" to begin.</span>
                </div>
            </div>
        </div>

        <!-- STATISTICS CARDS - TOP LANDSCAPE LAYOUT -->
        <div class="row mb-4">
            <div class="col-lg-4 col-md-4 col-12">
                <div class="info-box bg-gradient-info">
                    <span class="info-box-icon">
                        <i class="fas fa-users"></i>
                    </span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Present Today</span>
                        <span class="info-box-number" id="totalPresentCount">
                            <?php
                            if ($canViewAllAttendance) {
                                $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_attendance WHERE DATE(time_in) = CURDATE()");
                                $stmt->execute();
                            } else {
                                $stmt = $conn->prepare("
                                    SELECT COUNT(*) 
                                    FROM tbl_attendance a
                                    INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
                                    LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
                                    WHERE DATE(a.time_in) = CURDATE() 
                                    AND {$attendanceAccessCondition}
                                ");
                                $stmt->execute($attendanceAccessParams);
                            }
                            echo $stmt->fetchColumn();
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-4 col-12">
                <div class="info-box bg-gradient-success">
                    <span class="info-box-icon">
                        <i class="fas fa-clock"></i>
                    </span>
                    <div class="info-box-content">
                        <span class="info-box-text">On Time</span>
                        <span class="info-box-number" id="onTimeCount">
                            <?php
                            if ($canViewAllAttendance) {
                                $stmt = $conn->prepare("
                                    SELECT COUNT(*) 
                                    FROM tbl_attendance 
                                    WHERE DATE(time_in) = CURDATE() 
                                    AND (status IS NULL OR status = '' OR status LIKE 'On Time%')
                                ");
                                $stmt->execute();
                            } else {
                                $stmt = $conn->prepare("
                                    SELECT COUNT(*) 
                                    FROM tbl_attendance a
                                    INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
                                    LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
                                    WHERE DATE(a.time_in) = CURDATE() 
                                    AND (a.status IS NULL OR a.status = '' OR a.status LIKE 'On Time%')
                                    AND {$attendanceAccessCondition}
                                ");
                                $stmt->execute($attendanceAccessParams);
                            }
                            echo $stmt->fetchColumn();
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4 col-md-4 col-12">
                <div class="info-box bg-gradient-warning">
                    <span class="info-box-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </span>
                    <div class="info-box-content">
                        <span class="info-box-text">Late</span>
                        <span class="info-box-number" id="lateCount">
                            <?php
                            if ($canViewAllAttendance) {
                                $stmt = $conn->prepare("
                                    SELECT COUNT(*) 
                                    FROM tbl_attendance 
                                    WHERE DATE(time_in) = CURDATE() 
                                    AND status LIKE 'Late%'
                                ");
                                $stmt->execute();
                            } else {
                                $stmt = $conn->prepare("
                                    SELECT COUNT(*) 
                                    FROM tbl_attendance a
                                    INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
                                    LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
                                    WHERE DATE(a.time_in) = CURDATE() 
                                    AND a.status LIKE 'Late%'
                                    AND {$attendanceAccessCondition}
                                ");
                                $stmt->execute($attendanceAccessParams);
                            }
                            echo $stmt->fetchColumn();
                            ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column: Scanner -->
            <div class="col-lg-4">
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-camera mr-2"></i>QR Code Scanner
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Scanner Section -->
                        <div id="scannerSection">
                            <div class="text-center mb-3">
                                <h6 style="font-size: 0.95rem; font-weight: 600;">Position QR code within frame</h6>
                                <p class="text-muted small">Ensure good lighting for better scanning</p>
                            </div>
                            
                            <!-- QR Reader Container -->
                            <div id="qr-reader"></div>
                            
                            <!-- Placeholder when scanner is off -->
                            <div id="scannerPlaceholder" class="scanner-placeholder">
                                <div>
                                    <i class="fas fa-qrcode"></i>
                                    <h5>Scanner is Off</h5>
                                    <p class="mb-0 small">Click "Start Scanner" to begin</p>
                                </div>
                            </div>
                            
                            <!-- Camera Controls -->
                            <div class="camera-controls mt-3">
                                <button class="btn btn-success" onclick="startScanner()" id="startBtn">
                                    <i class="fas fa-play mr-2"></i>Start Scanner
                                </button>
                                <button class="btn btn-danger" onclick="stopScanner()" id="stopBtn" style="display: none;">
                                    <i class="fas fa-stop mr-2"></i>Stop Scanner
                                </button>
                            </div>
                        </div>
                            
                        <!-- QR Detected Section -->
                        <div id="qrDetectedSection" style="display: none;">
                            <div class="qr-detected-box text-center">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <h5 style="font-size: 1.1rem;">QR Code Detected!</h5>
                                <div class="student-info" id="studentInfo">Student QR code successfully scanned</div>
                                
                                <div class="qr-content-box">
                                    <small>QR Code:</small>
                                    <div id="qrContent" class="font-weight-bold small"></div>
                                </div>
                                
                                <form action="./endpoint/add-attendance.php" method="POST" id="attendanceForm">
                                    <input type="hidden" id="detectedQrCode" name="qr_code">
                                    <div class="mt-3">
                                        <button type="submit" class="btn btn-light btn-sm mr-2">
                                            <i class="fas fa-check mr-1"></i>Confirm Attendance
                                        </button>
                                        <button type="button" class="btn btn-outline-light btn-sm" onclick="resumeScanner()">
                                            <i class="fas fa-redo mr-1"></i>Scan Again
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Attendance Success Section -->
                        <div id="attendanceSuccessSection" style="display: none;">
                            <div class="qr-detected-box text-center" style="background: #0f5132;">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <h5 style="font-size: 1.1rem;">Attendance Recorded!</h5>
                                <div class="student-info" id="successStudentInfo"></div>
                                <div class="attendance-time" id="successTime"></div>
                                <div class="mt-2">
                                    <span class="badge badge-light" id="successStatus"></span>
                                </div>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-light btn-sm" onclick="resumeScanner()">
                                        <i class="fas fa-qrcode mr-1"></i>Scan Next Student
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Manual Entry Button -->
                        <div class="mt-3">
                            <button class="btn btn-outline-secondary btn-sm btn-block" data-toggle="modal" data-target="#manualEntryModal">
                                <i class="fas fa-keyboard mr-2"></i>Manual Entry
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Attendance List -->
            <div class="col-lg-8">
                <div class="card attendance-records-card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-list-check mr-2"></i>Today's Attendance Records
                        </h3>
                        <div class="card-tools">
                            <div class="btn-group-custom">
                                <button type="button" class="btn btn-sm btn-export" data-toggle="modal" data-target="#exportAttendanceModal" title="Export to Excel">
                                    <i class="fas fa-file-excel mr-1"></i>Export
                                </button>
                                <button type="button" class="btn btn-sm btn-refresh" onclick="refreshTable()" title="Refresh">
                                    <i class="fas fa-sync-alt mr-1"></i>Refresh
                                </button>
                                <a href="archive-manager.php" class="btn btn-sm btn-archive" title="View Archive">
                                    <i class="fas fa-archive mr-1"></i>Archive
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover" id="attendanceTable">
    <thead>
        <tr>
            <th>#</th>
            <th>Student Name</th>
            <th>Course & Section</th>
            <th>Time In</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody id="attendanceTableBody">
        <?php
        // Fetch attendance records with JOIN to get student info
        if ($canViewAllAttendance) {
            $stmt = $conn->prepare("
                SELECT a.tbl_attendance_id, a.tbl_student_id, a.time_in, a.status,
                       s.student_number, s.student_name, s.course_section 
                FROM tbl_attendance a 
                LEFT JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id 
                WHERE DATE(a.time_in) = CURDATE()
                ORDER BY a.time_in DESC
            ");
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("
                SELECT a.tbl_attendance_id, a.tbl_student_id, a.time_in, a.status,
                       s.student_number, s.student_name, s.course_section 
                FROM tbl_attendance a 
                INNER JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id
                LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
                WHERE DATE(a.time_in) = CURDATE() 
                AND {$attendanceAccessCondition}
                ORDER BY a.time_in DESC
            ");
            $stmt->execute($attendanceAccessParams);
        }
        $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        
        <?php if (count($attendanceRecords) > 0): ?>
            <?php $counter = 1; ?>
            <?php foreach ($attendanceRecords as $record): ?>
                <?php
                $attendanceID = $record["tbl_attendance_id"];
                $studentName = $record["student_name"] ?? 'Unknown Student';
                $studentCourse = $record["course_section"] ?? 'N/A';
                $timeIn = $record["time_in"];
                $status = $record["status"] ?? '';
                
                // Determine status if not set
                if (empty($status) && !empty($timeIn)) {
                    $status = getAttendanceStatusForStudent($conn, $record, $timeIn);
                }
                
                $statusClass = (stripos($status, 'Late') === 0) ? 'warning' : 'success';
                ?>
                <tr id="attendance-row-<?= $attendanceID ?>">
                    <td><?= $counter++ ?></td>
                    <td>
                        <strong><?= htmlspecialchars($studentName) ?></strong>
                    </td>
                    <td><?= htmlspecialchars($studentCourse) ?></td>
                    <td>
                        <div><?= !empty($timeIn) ? date('h:i A', strtotime($timeIn)) : 'N/A' ?></div>
                        <small class="text-muted"><?= !empty($timeIn) ? date('M d, Y', strtotime($timeIn)) : '' ?></small>
                    </td>
                    <td>
                        <span class="badge badge-<?= $statusClass ?> status-badge">
                            <?= $status ?>
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-danger btn-sm" onclick="deleteAttendance(<?= $attendanceID ?>)" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
                            
                            <?php if (count($attendanceRecords) === 0): ?>
                                <div class="empty-state w-100" id="emptyState">
                                    <i class="fas fa-clipboard-list"></i>
                                    <h5>No attendance records for today</h5>
                                    <p class="text-muted small">Start scanning QR codes to record attendance</p>
                                    <button class="btn btn-primary btn-sm mt-2" onclick="startScanner()">
                                        <i class="fas fa-qrcode mr-2"></i>Start Scanner
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-footer">
                        <div class="row">
                            <div class="col-sm-6">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Showing <span id="recordCount"><?= count($attendanceRecords) ?></span> records
                                </small>
                            </div>
                            <div class="col-sm-6 text-right">
                                <small class="text-muted">
                                    Last updated: <span id="lastUpdatedTime"><?= date('h:i:s A') ?></span>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
    </div>

    <!-- Footer -->
        <!-- Footer -->
    <?php include 'footer.php'; ?>
</div>

<!-- Export Attendance Modal -->
<div class="modal fade" id="exportAttendanceModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title text-white">
                    <i class="fas fa-file-excel mr-2"></i>Download Attendance
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form action="./endpoint/download-attendance-excel.php" method="GET" id="exportAttendanceForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="exportPeriod">Coverage:</label>
                        <select class="form-control" id="exportPeriod" name="period">
                            <option value="day">Today / Specific Day</option>
                            <option value="month">Whole Month</option>
                            <option value="semester">Whole Semester / Date Range</option>
                        </select>
                    </div>

                    <div class="form-group export-period-field" id="exportDayField">
                        <label for="exportDate">Date:</label>
                        <input type="date" class="form-control" id="exportDate" name="date" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group export-period-field d-none" id="exportMonthField">
                        <label for="exportMonth">Month:</label>
                        <input type="month" class="form-control" id="exportMonth" name="month" value="<?php echo date('Y-m'); ?>">
                    </div>

                    <div class="export-period-field d-none" id="exportSemesterField">
                        <div class="form-group">
                            <label for="exportStartDate">Start Date:</label>
                            <input type="date" class="form-control" id="exportStartDate" name="start_date" value="<?php echo date('Y-06-01'); ?>">
                        </div>
                        <div class="form-group mb-0">
                            <label for="exportEndDate">End Date:</label>
                            <input type="date" class="form-control" id="exportEndDate" name="end_date" value="<?php echo date('Y-12-31'); ?>">
                        </div>
                    </div>

                    <div class="mt-3 small text-muted">
                        Only dates with facilitator scans will appear. Cells are colored by attendance status: Absent red, Present green, Late yellow.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-download mr-2"></i>Download Excel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manual Entry Modal -->
<div class="modal fade" id="manualEntryModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title text-white">
                    <i class="fas fa-user-plus mr-2"></i>Manual Attendance Entry
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form action="./endpoint/manual-attendance.php" method="POST" id="manualEntryForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="studentSelect">Select Student:</label>
                        <select class="form-control select2" id="studentSelect" name="student_id" style="width: 100%;" required>
                            <option value="">-- Select a student --</option>
                            <?php
                            if ($canViewAllAttendance) {
                                $stmt = $conn->prepare("
                                    SELECT tbl_student_id, student_name, course_section 
                                    FROM tbl_student 
                                    ORDER BY student_name
                                ");
                                $stmt->execute();
                            } else {
                                $stmt = $conn->prepare("
                                    SELECT DISTINCT s.tbl_student_id, s.student_name, s.course_section 
                                    FROM tbl_student s
                                    LEFT JOIN tbl_admin_sections ads ON s.course_section = ads.course_section
                                    WHERE {$attendanceAccessCondition}
                                    ORDER BY s.student_name
                                ");
                                $stmt->execute($attendanceAccessParams);
                            }
                            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (count($students) > 0):
                                foreach ($students as $student):
                            ?>
                            <option value="<?= $student['tbl_student_id'] ?>">
                                <?= htmlspecialchars($student['student_name']) ?> - <?= htmlspecialchars($student['course_section']) ?>
                            </option>
                            <?php 
                                endforeach;
                            else:
                            ?>
                            <option value="" disabled>No students found in your section</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="manualTime">Time In:</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">
                                    <i class="far fa-clock"></i>
                                </span>
                            </div>
                            <input type="datetime-local" class="form-control" id="manualTime" name="time_in" 
                                   value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                        </div>
                        <small class="form-text text-muted">Current time will be used if not specified</small>
                    </div>
                    <div class="form-group">
                        <label for="notes">Notes (Optional):</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2" 
                                  placeholder="Add any notes about this attendance..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save mr-2"></i>Save Attendance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>

<script>
    // Global variables
    let html5QrcodeScanner = null;
    let isScanning = false;
    const adminId = <?= $admin_id ?>;
    const adminRole = '<?= $admin_role ?>';
    let dataTable = null;
    
    // Initialize on document ready
    $(document).ready(function() {
        console.log('Document ready - initializing...');
        
        // Initialize Select2
        $('.select2').select2({
            theme: 'bootstrap4',
            placeholder: 'Select an option',
            allowClear: true
        });
        
        // Update time display
        updateTime();
        setInterval(updateTime, 1000);
        
        // Initialize DataTable if there are records
        <?php if (count($attendanceRecords) > 0): ?>
        try {
            dataTable = $('#attendanceTable').DataTable({
                "paging": true,
                "lengthChange": false,
                "searching": true,
                "ordering": true,
                "info": true,
                "autoWidth": false,
                "responsive": true,
                "order": [[3, 'desc']],
                "columnDefs": [
                    { "orderable": false, "targets": 5 }
                ],
                "language": {
                    "emptyTable": "No attendance records available"
                }
            });
            console.log('DataTable initialized successfully');
        } catch (e) {
            console.error('DataTable initialization error:', e);
        }
        <?php endif; ?>
        
        // Initialize UI state - scanner OFF by default
        $('#qr-reader').hide();
        $('#scannerPlaceholder').show();
        $('#startBtn').show();
        $('#stopBtn').hide();
        $('#qrDetectedSection').hide();
        $('#attendanceSuccessSection').hide();
        updateExportPeriodFields();
        $('#exportPeriod').on('change', updateExportPeriodFields);
        
        // Check if library is loaded
        if (typeof Html5QrcodeScanner === 'undefined') {
            console.error('Html5QrcodeScanner is not defined! Library failed to load.');
            showStatus('danger', 'QR Scanner library failed to load. Please refresh the page.');
        } else if (!window.isSecureContext) {
            showStatus('danger', 'Camera access requires HTTPS. Please open this page using your secure https:// domain.');
        } else {
            console.log('Html5QrcodeScanner library loaded successfully!');
            showStatus('info', 'Scanner ready. Click "Start Scanner" to begin.');
        }
    });
    
    // Update time display
    function updateTime() {
        const now = new Date();
        const hours = now.getHours();
        const minutes = now.getMinutes().toString().padStart(2, '0');
        const ampm = hours >= 12 ? 'PM' : 'AM';
        const formattedHours = (hours % 12 || 12).toString().padStart(2, '0');
        
        $('#current-time').text(`${formattedHours}:${minutes} ${ampm}`);
    }

    function updateExportPeriodFields() {
        const period = $('#exportPeriod').val();
        $('.export-period-field').addClass('d-none');

        if (period === 'month') {
            $('#exportMonthField').removeClass('d-none');
        } else if (period === 'semester') {
            $('#exportSemesterField').removeClass('d-none');
        } else {
            $('#exportDayField').removeClass('d-none');
        }
    }
    
    // Start scanner
    function startScanner() {
        console.log('Start scanner clicked');
        
        if (typeof Html5QrcodeScanner === 'undefined') {
            showStatus('danger', 'QR Scanner library not loaded. Please refresh the page.');
            return;
        }

        if (!window.isSecureContext) {
            showStatus('danger', 'Camera access requires HTTPS. Please open this page using your secure https:// domain.');
            return;
        }
        
        if (isScanning) {
            showStatus('info', 'Scanner is already running');
            return;
        }
        
        $('#scannerPlaceholder').hide();
        $('#qr-reader').show();
        showStatus('info', 'Initializing camera...');
        
        try {
            if (html5QrcodeScanner) {
                try {
                    html5QrcodeScanner.clear();
                } catch (e) {
                    console.log('Error clearing existing scanner:', e);
                }
                html5QrcodeScanner = null;
            }
            
            html5QrcodeScanner = new Html5QrcodeScanner(
                "qr-reader", 
                { 
                    fps: 10, 
                    qrbox: { width: 200, height: 200 },
                    rememberLastUsedCamera: true,
                    showTorchButtonIfSupported: true,
                    aspectRatio: 1.0,
                    supportedScanTypes: [Html5QrcodeScanType.SCAN_TYPE_CAMERA]
                },
                false
            );
            
            html5QrcodeScanner.render(onScanSuccess, onScanError);
            isScanning = true;
            
            $('#startBtn').hide();
            $('#stopBtn').show();
            showStatus('success', 'Scanner active - Position QR code within frame');
            console.log('Scanner started successfully');
            
        } catch (error) {
            console.error('Scanner start error:', error);
            showStatus('danger', 'Failed to start scanner: ' + error.message);
            
            $('#qr-reader').hide();
            $('#scannerPlaceholder').show();
            $('#startBtn').show();
            $('#stopBtn').hide();
            isScanning = false;
        }
    }
    
    // Stop scanner
    function stopScanner() {
        console.log('Stop scanner clicked');
        
        if (html5QrcodeScanner) {
            try {
                html5QrcodeScanner.clear();
                html5QrcodeScanner = null;
                console.log('Scanner stopped successfully');
            } catch (error) {
                console.error('Error clearing scanner:', error);
            }
        }
        
        isScanning = false;
        
        $('#qr-reader').hide();
        $('#scannerPlaceholder').show();
        $('#startBtn').show();
        $('#stopBtn').hide();
        showStatus('info', 'Scanner stopped');
    }
    
    // Handle successful scan
   function onScanSuccess(decodedText, decodedResult) {
    console.log('QR Code scanned:', decodedText);
    
    if (html5QrcodeScanner) {
        try {
            html5QrcodeScanner.pause();
            console.log('Scanner paused');
        } catch (error) {
            console.error('Error pausing scanner:', error);
        }
    }
    
    playSuccessSound();
    showStatus('success', 'QR Code detected! Validating...');
    
    // Clean the QR code - remove any whitespace
    const cleanQrCode = decodedText.trim();
    
    // Show the scanned QR code for debugging
    console.log('Cleaned QR code:', cleanQrCode);
    
    $.ajax({
        url: './endpoint/validate-student.php',
        method: 'POST',
        data: { 
            qr_code: cleanQrCode,
            admin_id: adminId
        },
        dataType: 'json',
        timeout: 10000,
        success: function(response) {
            console.log('Validation response:', response);
            
            if (response.valid) {
                if (response.already_attended) {
                    // Student already attended today
                    showStatus('warning', 'Student already attended today');
                    $('#studentInfo').text(response.student_name + ' - ' + response.course_section);
                    $('#qrContent').text(cleanQrCode);
                    $('#detectedQrCode').val(cleanQrCode);
                    
                    $('#scannerSection').hide();
                    $('#qrDetectedSection').show();
                    $('#attendanceSuccessSection').hide();
                    
                    // Show a warning message
                    showToast('warning', 'Already Scanned', response.student_name + ' has already scanned today');
                    
                    // Automatically resume scanning after 3 seconds
                    setTimeout(() => {
                        resumeScanner();
                    }, 3000);
                } else {
                    // New attendance
                    $('#detectedQrCode').val(cleanQrCode);
                    $('#qrContent').text(cleanQrCode);
                    $('#studentInfo').text(response.student_name + ' - ' + response.course_section);
                    
                    $('#scannerSection').hide();
                    $('#qrDetectedSection').show();
                    $('#attendanceSuccessSection').hide();
                    
                    showStatus('success', 'Student found: ' + response.student_name);
                    
                    // Submit right after validation so the attendance list updates immediately.
                    setTimeout(() => {
                        $('#attendanceForm').submit();
                    }, 250);
                }
            } else {
                console.warn('Student validation failed:', response.message);
                showStatus('warning', response.message || 'Student not found');
                showToast('error', 'Invalid QR', response.message || 'Student not found');
                
                // Show the QR code that wasn't found
                $('#qrContent').text(cleanQrCode);
                $('#studentInfo').text('Student not found');
                
                $('#scannerSection').hide();
                $('#qrDetectedSection').show();
                $('#attendanceSuccessSection').hide();
                
                // Automatically resume scanning after 3 seconds
                setTimeout(() => {
                    resumeScanner();
                }, 3000);
            }
        },
        error: function(xhr, status, error) {
            console.error('Validation AJAX error:', status, error);
            console.error('Response:', xhr.responseText);
            showStatus('danger', 'Error validating student. Please try again.');
            showToast('error', 'Error', 'Network error. Please try again.');
            
            setTimeout(() => {
                resumeScanner();
            }, 2000);
        }
    });
}
    
    function onScanError(errorMessage) {
        // Ignore scan errors
    }
    
    // Handle attendance form submission
    $('#attendanceForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        
        $.ajax({
            url: './endpoint/add-attendance.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            timeout: 10000,
            success: function(response) {
                console.log('Attendance response:', response);
                
                if (response.success) {
                    $('#qrDetectedSection').hide();
                    $('#attendanceSuccessSection').show();
                    $('#successStudentInfo').text(response.student_name);
                    $('#successTime').text('Time: ' + response.time);
                    $('#successStatus').text(response.status).removeClass('badge-success badge-warning').addClass(
                        response.status && response.status.indexOf('Late') === 0 ? 'badge-warning' : 'badge-success'
                    );
                    
                    showToast('success', 'Success!', response.message);
                    
                    refreshAttendanceData().always(function() {
                        setTimeout(() => {
                            resumeScanner();
                        }, 800);
                    });
                    
                } else {
                    showToast('error', 'Error', response.message);
                    
                    if (response.message && response.message.includes('already attended')) {
                        setTimeout(() => {
                            resumeScanner();
                        }, 2000);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Attendance submission error:', error);
                showToast('error', 'Error', 'Network error. Please try again.');
                
                setTimeout(() => {
                    resumeScanner();
                }, 2000);
            }
        });
    });
    
    // Refresh attendance data via AJAX
    function refreshAttendanceData() {
        return $.ajax({
            url: './endpoint/get-attendance-data.php',
            method: 'GET',
            dataType: 'json',
            timeout: 10000,
            success: function(response) {
                console.log('Attendance data refreshed:', response);
                
                if (response.success) {
                    $('#totalPresentCount').text(response.statistics.total);
                    $('#onTimeCount').text(response.statistics.on_time);
                    $('#lateCount').text(response.statistics.late);
                    
                    if ($.fn.DataTable && $.fn.DataTable.isDataTable('#attendanceTable')) {
                        try {
                            $('#attendanceTable').DataTable().clear().destroy();
                        } catch (e) {
                            console.error('Error destroying existing DataTable:', e);
                        }
                    }
                    dataTable = null;
                    
                    if (response.records && response.records.length > 0) {
                        $('#emptyState').hide();
                        
                        let html = '';
                        let counter = 1;
                        
                        response.records.forEach(function(record) {
                            const statusClass = record.status && record.status.indexOf('Late') === 0 ? 'warning' : 'success';
                            
                            html += `<tr id="attendance-row-${record.id}">`;
                            html += `<td>${counter++}</td>`;
                            html += `<td><strong>${escapeHtml(record.student_name || 'Unknown')}</strong></td>`;
                            html += `<td>${escapeHtml(record.course_section || 'N/A')}</td>`;
                            html += `<td><div>${record.time_formatted || 'N/A'}</div><small class="text-muted">${record.date_formatted || ''}</small></td>`;
                            html += `<td><span class="badge badge-${statusClass} status-badge">${record.status || 'Unknown'}</span></td>`;
                            html += `<td><button class="btn btn-danger btn-sm" onclick="deleteAttendance(${record.id})" title="Delete"><i class="fas fa-trash"></i></button></td>`;
                            html += `</tr>`;
                        });
                        
                        $('#attendanceTableBody').html(html);
                        
                        try {
                            dataTable = $('#attendanceTable').DataTable({
                                "paging": true,
                                "lengthChange": false,
                                "searching": true,
                                "ordering": true,
                                "info": true,
                                "autoWidth": false,
                                "responsive": true,
                                "order": [[3, 'desc']],
                                "columnDefs": [
                                    { "orderable": false, "targets": 5 }
                                ]
                            });
                        } catch (e) {
                            console.error('Error reinitializing DataTable:', e);
                        }
                        
                        $('#recordCount').text(response.records.length);
                        
                    } else {
                        $('#attendanceTableBody').html('');
                        if ($('#emptyState').length === 0) {
                            $('.table-responsive').append(`
                                <div class="empty-state w-100" id="emptyState">
                                    <i class="fas fa-clipboard-list"></i>
                                    <h5>No attendance records for today</h5>
                                    <p class="text-muted small">Start scanning QR codes to record attendance</p>
                                    <button class="btn btn-primary btn-sm mt-2" onclick="startScanner()">
                                        <i class="fas fa-qrcode mr-2"></i>Start Scanner
                                    </button>
                                </div>
                            `);
                        } else {
                            $('#emptyState').show();
                        }
                        $('#recordCount').text('0');
                    }
                    
                    $('#lastUpdatedTime').text(formatCurrentTimeWithSeconds());
                } else {
                    console.error('Error refreshing data:', response.error);
                    showToast('error', 'Error', response.error || 'Failed to refresh data');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error refreshing attendance data:', status, error);
                showToast('error', 'Error', 'Failed to refresh attendance data. Please refresh the page.');
            }
        });
    }
    
    function formatCurrentTimeWithSeconds() {
        const now = new Date();
        let hours = now.getHours();
        const minutes = now.getMinutes().toString().padStart(2, '0');
        const seconds = now.getSeconds().toString().padStart(2, '0');
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = (hours % 12 || 12).toString().padStart(2, '0');
        return `${hours}:${minutes}:${seconds} ${ampm}`;
    }
    
    // Helper function to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
    // Resume scanner
    function resumeScanner() {
        console.log('Resume scanner called');
        
        $('#qrDetectedSection').hide();
        $('#attendanceSuccessSection').hide();
        $('#scannerSection').show();
        $('#detectedQrCode').val('');
        $('#qrContent').text('');
        $('#studentInfo').text('Student QR code successfully scanned');
        
        if (html5QrcodeScanner) {
            try {
                html5QrcodeScanner.resume();
                console.log('Scanner resumed successfully');
                isScanning = true;
                
                $('#qr-reader').show();
                $('#scannerPlaceholder').hide();
                $('#startBtn').hide();
                $('#stopBtn').show();
                showStatus('success', 'Scanner resumed - Position QR code within frame');
            } catch (error) {
                console.error('Error resuming scanner:', error);
                startScanner();
            }
        } else {
            startScanner();
        }
    }
    
    // Delete attendance
    function deleteAttendance(id) {
        if (confirm('Are you sure you want to delete this attendance record?')) {
            const deleteBtn = $(`button[onclick="deleteAttendance(${id})"]`);
            const originalHtml = deleteBtn.html();
            deleteBtn.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
            
            $.ajax({
                url: './endpoint/delete-attendance.php',
                method: 'POST',
                data: { 
                    attendance_id: id,
                    admin_id: adminId
                },
                dataType: 'json',
                timeout: 10000,
                success: function(response) {
                    console.log('Delete response:', response);
                    
                    if (response.success) {
                        showToast('success', 'Success', 'Attendance record deleted successfully.');
                        
                        if (dataTable) {
                            try {
                                dataTable.row(`#attendance-row-${id}`).remove().draw();
                            } catch (e) {
                                $(`#attendance-row-${id}`).remove();
                            }
                        } else {
                            $(`#attendance-row-${id}`).remove();
                        }
                        
                        refreshAttendanceData();
                        
                    } else {
                        showToast('error', 'Error', response.message || 'Error deleting record');
                        deleteBtn.html(originalHtml).prop('disabled', false);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Delete AJAX error:', error);
                    showToast('error', 'Error', 'Network error. Please try again.');
                    deleteBtn.html(originalHtml).prop('disabled', false);
                }
            });
        }
    }
    
    // Refresh table
    function refreshTable() {
        showToast('info', 'Refreshing', 'Updating attendance records...');
        refreshAttendanceData();
    }
    
    // Show status message
    function showStatus(type, message) {
        const statusDiv = $('#scannerStatus');
        const messageSpan = $('#statusMessage');
        
        statusDiv.removeClass('alert-info alert-success alert-warning alert-danger');
        statusDiv.addClass(`alert-${type}`);
        
        let icon = 'info-circle';
        if (type === 'success') icon = 'check-circle';
        if (type === 'warning') icon = 'exclamation-triangle';
        if (type === 'danger') icon = 'times-circle';
        
        messageSpan.html(`<i class="fas fa-${icon} mr-2"></i>${message}`);
        statusDiv.show();
    }
    
    // Play success sound
    function playSuccessSound() {
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.value = 800;
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.2);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.2);
        } catch (e) {
            console.log('Audio context not supported');
        }
    }
    
    // Show toast notification
    function showToast(type, title, message) {
        $('.custom-toast').remove();
        
        const bgColor = type === 'success' ? 'bg-success' : type === 'error' ? 'bg-danger' : 'bg-info';
        
        const toastHtml = `
            <div class="custom-toast toast fade show" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 280px;">
                <div class="toast-header ${bgColor} text-white">
                    <strong class="mr-auto">${title}</strong>
                    <button type="button" class="ml-2 mb-1 close text-white" onclick="$(this).closest('.toast').remove()">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="toast-body small">
                    ${message}
                </div>
            </div>
        `;
        
        $('body').append(toastHtml);
        
        setTimeout(() => {
            $('.custom-toast').remove();
        }, 3000);
    }
    
    // Handle manual entry form submission
    $('#manualEntryForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        
        $.ajax({
            url: './endpoint/manual-attendance.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            timeout: 10000,
            success: function(response) {
                if (response.success) {
                    showToast('success', 'Success', response.message || 'Attendance recorded successfully');
                    $('#manualEntryModal').modal('hide');
                    refreshAttendanceData();
                } else {
                    showToast('error', 'Error', response.message || 'Error recording attendance');
                }
            },
            error: function(xhr, status, error) {
                console.error('Manual entry error:', error);
                showToast('error', 'Error', 'Network error. Please try again.');
            }
        });
    });
    
    // Clean up on page unload
    $(window).on('beforeunload', function() {
        if (html5QrcodeScanner) {
            try {
                html5QrcodeScanner.clear();
            } catch (error) {
                console.error('Error during cleanup:', error);
            }
        }
    });
</script>
</body>
</html>
