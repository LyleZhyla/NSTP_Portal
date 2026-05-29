<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: landing_page.php");
    exit();
}

date_default_timezone_set('Asia/Manila');
include ('./conn/conn.php');

$currentPage = basename($_SERVER['PHP_SELF']);
$pageTitle = "QR Code Attendance System";

$pageTitles = [
    'index.php' => 'Dashboard',
    'attendance.php' => 'Attendance Scanner',
    'masterlist.php' => 'Student Masterlist',
    'archive-manager.php' => 'Archive Manager'
];

$pageTitle = $pageTitles[$currentPage] ?? 'QR Code Attendance System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $pageTitle; ?> - QR Attendance</title>
    
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --success-gradient: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --warning-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        
        .bg-gradient-primary { background: var(--primary-gradient) !important; }
        .bg-gradient-secondary { background: var(--secondary-gradient) !important; }
        .bg-gradient-success { background: var(--success-gradient) !important; }
        .bg-gradient-info { background: var(--info-gradient) !important; }
        .bg-gradient-warning { background: var(--warning-gradient) !important; }
        
        .stat-card {
            border-radius: 10px;
            color: white;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.9;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .qr-scanner-box {
            border: 3px dashed #ddd;
            border-radius: 10px;
            min-height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
        }
        
        #interactive {
            width: 100%;
            max-width: 400px;
            border-radius: 10px;
            border: 3px solid #007bff;
        }
        
        .manila-time-card {
            background: var(--primary-gradient);
            color: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
        }
        
        .manila-time {
            font-family: 'Courier New', monospace;
            font-size: 1.8rem;
            font-weight: bold;
        }
        
        .table-responsive {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .action-buttons .btn {
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .qr-detected-alert {
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
        
        .content-wrapper { background-color: #f4f6f9; }
        
        .small-box { border-radius: 10px; }
        
        .card { border-radius: 10px; }
        
        .card-header { border-top-left-radius: 10px !important; border-top-right-radius: 10px !important; }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="index.php" class="nav-link">Home</a>
            </li>
        </ul>

        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown">
                <a class="nav-link" data-toggle="dropdown" href="#">
                    <i class="far fa-user"></i>
                    <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>
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
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="index.php" class="brand-link">
            <i class="fas fa-qrcode brand-icon"></i>
            <span class="brand-text font-weight-light">QR Attendance</span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link <?= ($currentPage == 'index.php') ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="attendance.php" class="nav-link <?= ($currentPage == 'attendance.php') ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-qrcode"></i>
                            <p>Attendance Scanner</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="masterlist.php" class="nav-link <?= ($currentPage == 'masterlist.php') ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-user-graduate"></i>
                            <p>Student Masterlist</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="archive-manager.php" class="nav-link <?= ($currentPage == 'archive-manager.php') ? 'active' : '' ?>">
                            <i class="nav-icon fas fa-archive"></i>
                            <p>Archive Manager</p>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </aside>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <!-- Content Header -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0"><?php echo $pageTitle; ?></h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                            <li class="breadcrumb-item active"><?php echo $pageTitle; ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <section class="content">
            <div class="container-fluid">
