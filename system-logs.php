<?php
session_start();
include('./include/theme-loader.php');
include('./conn/conn.php');
require_once './include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || $currentUser['role'] !== 'super_admin') {
    header("Location: index.php");
    exit();
}

ensureSystemLogsTable($conn);
$stmt = $conn->prepare("
    SELECT log_id, username, role, action, details, ip_address, created_at
    FROM tbl_system_logs
    ORDER BY created_at DESC
    LIMIT 500
");
$stmt->execute();
$systemLogs = $stmt->fetchAll();

date_default_timezone_set('Asia/Manila');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Logs - TAU-NSTP</title>
    <?php include('./include/theme-loader.php'); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .logs-card {
            border-top: 3px solid #6c757d;
        }
        .log-details {
            max-width: 420px;
            white-space: normal;
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
    </nav>

    <?php include 'adminlte-sidebar.php'; ?>

    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0"><i class="fas fa-clipboard-list mr-2"></i>System Logs</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                            <li class="breadcrumb-item active">System Logs</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <section class="content">
            <div class="container-fluid">
                <div class="card logs-card">
                    <div class="card-header">
                        <h3 class="card-title">Activity History</h3>
                        <span class="badge badge-danger float-right">Super Admin Only</span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="logsTable" class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>Date/Time</th>
                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Action</th>
                                        <th>Details</th>
                                        <th>IP Address</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($systemLogs as $log): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y h:i A', strtotime($log['created_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($log['username'] ?: 'System'); ?></td>
                                            <td><span class="badge badge-secondary"><?php echo htmlspecialchars($log['role'] ?: 'system'); ?></span></td>
                                            <td><code><?php echo htmlspecialchars($log['action']); ?></code></td>
                                            <td class="log-details"><?php echo htmlspecialchars($log['details'] ?: '-'); ?></td>
                                            <td><?php echo htmlspecialchars($log['ip_address'] ?: '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <?php include 'footer.php'; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script>
$(function() {
    $('#logsTable').DataTable({
        paging: true,
        lengthChange: true,
        searching: true,
        ordering: true,
        info: true,
        autoWidth: false,
        responsive: true,
        order: [[0, 'desc']]
    });
});
</script>
</body>
</html>
