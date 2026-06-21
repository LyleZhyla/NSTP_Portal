<?php
session_start();
include('./include/theme-loader.php');
if (!isset($_SESSION['user_id'])) {
    header("Location: landing_page.php");
    exit();
}

include('./conn/conn.php');
require_once './include/user-permissions.php';

// Get user role
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'facilitator';
$user_name = $_SESSION['full_name'] ?? 'User';
if (!canAccessStaffTools($user_role)) {
    header("Location: profile.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Archive Manager - TAU-NSTP </title>
      <?php include('./include/theme-loader.php'); ?>
    <!-- 🔥 TAB LOGO - NSTP LOGO 🔥 -->
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="shortcut icon" href="include/logo.png">
    <link rel="apple-touch-icon" href="include/logo.png">
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Theme style -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <!-- rest of your head content... -->
    <style>
        .archive-stats-card {
            border-radius: 10px;
            color: white;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .archive-stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        .archive-stats-icon {
            font-size: 2.5rem;
            opacity: 0.9;
            margin-bottom: 10px;
        }
        
        .archive-stats-number {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .archive-table {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .loading {
            display: none;
        }
        
        .user-badge {
            font-size: 0.85rem;
            padding: 5px 10px;
        }
        
        .section-info {
            font-size: 0.9rem;
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 10px 15px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        
        .btn-spinner {
            position: relative;
            padding-left: 40px !important;
        }
        .btn-spinner .fa-spinner {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
        }

        .archive-manager-page .content-wrapper {
            padding-bottom: 76px;
        }

        .archive-manager-page .main-footer {
            position: fixed;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 1030;
            margin-left: 250px;
        }

        .archive-manager-page.sidebar-collapse .main-footer {
            margin-left: 4.6rem;
        }

        @media (max-width: 768px) {
            .archive-manager-page .main-footer,
            .archive-manager-page.sidebar-collapse .main-footer {
                margin-left: 0;
            }
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed archive-manager-page">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <?php include './include/header-notifications.php'; ?>
            <li class="nav-item">
                <span class="navbar-text mr-3">
                    <i class="fas fa-user-circle mr-1"></i> 
                    <?php echo htmlspecialchars($user_name); ?> (<?php echo $user_role; ?>)
                </span>
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
                            <i class="fas fa-archive mr-2"></i> Archive Manager
                        </h1>
                        <small>
                            <span class="badge badge-<?php echo ($user_role === 'super_admin') ? 'danger' : 'primary'; ?> user-badge mt-2">
                                <?php echo htmlspecialchars($user_name); ?> (<?php echo $user_role; ?>)
                            </span>
                        </small>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                            <li class="breadcrumb-item active">Archive Manager</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <section class="content">
            <div class="container-fluid">
                <!-- Info Message for Regular Admins -->
                <?php if ($user_role === 'facilitator'): ?>
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="section-info">
                            <i class="fas fa-info-circle mr-2"></i>
                            <strong>Archive Information:</strong>
                            You can only archive attendance records of students you added.
                            <br>
                            <small class="text-muted">
                                <i class="fas fa-user-check mr-1"></i>
                                Super admin can archive all records.
                            </small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="archive-stats-card bg-gradient-primary">
                            <div class="archive-stats-icon">
                                <i class="fas fa-archive"></i>
                            </div>
                            <div class="archive-stats-number" id="totalArchived">0</div>
                            <div>Total Archived</div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6">
                        <div class="archive-stats-card bg-gradient-success">
                            <div class="archive-stats-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="archive-stats-number" id="activeRecords">0</div>
                            <div>Active Records</div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6">
                        <div class="archive-stats-card bg-gradient-info">
                            <div class="archive-stats-icon">
                                <i class="fas fa-calendar-day"></i>
                            </div>
                            <div class="archive-stats-number" id="uniqueDays">0</div>
                            <div>Unique Days</div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6">
                        <div class="archive-stats-card bg-gradient-warning">
                            <div class="archive-stats-icon">
                                <i class="fas fa-history"></i>
                            </div>
                            <div class="archive-stats-number" id="latestDate">-</div>
                            <div>Latest Archive</div>
                        </div>
                    </div>
                </div>

                <!-- Archive Actions -->
                <div class="row">
                    <!-- Archive Section -->
                    <div class="col-md-6">
                        <div class="card card-primary">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-box-arrow-in-down mr-2"></i> Archive Attendance
                                </h3>
                            </div>
                            <div class="card-body">
                                <form id="archiveForm">
                                    <div class="form-group">
                                        <label for="archiveDate">
                                            <i class="fas fa-calendar mr-1"></i> Archive records up to:
                                        </label>
                                        <input type="date" class="form-control" id="archiveDate" name="archive_date" 
                                               value="<?php echo date('Y-m-d', strtotime('-1 day')); ?>" required>
                                        <small class="form-text text-muted">
                                            All attendance records on or before this date will be moved to archive
                                        </small>
                                    </div>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>
                                        <strong>Warning:</strong> This action cannot be undone. Archived records will be moved from active attendance.
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-block" id="archiveBtn">
                                        <span class="loading spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>
                                        <i class="fas fa-archive mr-2"></i> Archive Records
                                    </button>
                                </form>
                                <div id="archiveResult" class="mt-3"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Download Section -->
                    <div class="col-md-6">
                        <div class="card card-success">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-download mr-2"></i> Download Archived Records
                                </h3>
                            </div>
                            <div class="card-body">
                                <form id="downloadForm">
                                    <div class="form-group">
                                        <label for="startDate">
                                            <i class="fas fa-calendar-plus mr-1"></i> Start Date:
                                        </label>
                                        <input type="date" class="form-control" id="startDate" name="start_date" 
                                               value="<?php echo date('Y-m-d', strtotime('-7 days')); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="endDate">
                                            <i class="fas fa-calendar-check mr-1"></i> End Date:
                                        </label>
                                        <input type="date" class="form-control" id="endDate" name="end_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <button type="button" class="btn btn-success btn-block" id="downloadBtn">
                                        <i class="fas fa-file-excel mr-2"></i> Download as Excel
                                    </button>
                                </form>
                                <div id="downloadResult" class="mt-3"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($user_role === 'super_admin'): ?>
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card card-danger">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-trash-alt mr-2"></i> Delete Archived Records
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    This permanently deletes archived attendance records. Download a backup first if needed.
                                </div>
                                <button type="button" class="btn btn-danger" id="deleteAllArchivedBtn">
                                    <i class="fas fa-trash mr-1"></i> Delete All Archived Records
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Daily Breakdown -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card card-info">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-calendar-alt mr-2"></i> Daily Archive Summary
                                </h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" onclick="loadArchiveSummary()">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped" id="dailyTable">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Records Archived</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody id="dailyBreakdown">
                                            <tr>
                                                <td colspan="3" class="text-center text-muted py-4">
                                                    <i class="fas fa-spinner fa-spin mr-2"></i> Loading archive data...
                                                </td>
                                            </tr>
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

    <!-- Footer -->
       <?php include 'footer.php'; ?>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>

<script>
const currentUserRole = <?php echo json_encode($user_role); ?>;

// Load archive summary on page load
document.addEventListener('DOMContentLoaded', function() {
    loadArchiveSummary();
});

// Function to load archive summary
function loadArchiveSummary() {
    const dailyBreakdown = document.getElementById('dailyBreakdown');
    dailyBreakdown.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin mr-2"></i> Loading archive data...</td></tr>';
    
    fetch('endpoint/get-archive-summary.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.text();
        })
        .then(text => {
            try {
                const data = JSON.parse(text);
                
                if (data.success) {
                    // Update stats cards
                    document.getElementById('totalArchived').textContent = data.summary.total_archived || 0;
                    document.getElementById('activeRecords').textContent = data.summary.active_records || 0;
                    document.getElementById('uniqueDays').textContent = data.summary.unique_days || 0;
                    document.getElementById('latestDate').textContent = data.summary.latest_date || '-';
                    
                    // Update daily breakdown
                    dailyBreakdown.innerHTML = '';
                    
                    if (data.summary.daily_breakdown && data.summary.daily_breakdown.length > 0) {
                        data.summary.daily_breakdown.forEach(day => {
                            const row = document.createElement('tr');
                            row.innerHTML = `
                                <td>${formatDate(day.attendance_date) || 'N/A'}</td>
                                <td><span class="badge badge-info">${day.record_count || 0}</span></td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="downloadSingleDay('${day.attendance_date}')">
                                        <i class="fas fa-download mr-1"></i> Download
                                    </button>
                                    ${currentUserRole === 'super_admin' ? `
                                    <button class="btn btn-sm btn-danger ml-1" onclick="deleteArchivedDay('${day.attendance_date}')">
                                        <i class="fas fa-trash mr-1"></i> Delete
                                    </button>` : ''}
                                </td>
                            `;
                            dailyBreakdown.appendChild(row);
                        });
                    } else {
                        dailyBreakdown.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-4"><i class="fas fa-archive mr-2"></i> No archived records found</td></tr>';
                    }
                } else {
                    console.error('Error loading summary:', data.message);
                    dailyBreakdown.innerHTML = `<tr><td colspan="3" class="text-center text-danger py-4"><i class="fas fa-exclamation-triangle mr-2"></i> Error: ${data.message}</td></tr>`;
                }
            } catch (e) {
                console.error('Invalid JSON response:', text);
                console.error('Parse error:', e);
                dailyBreakdown.innerHTML = '<tr><td colspan="3" class="text-center text-danger py-4"><i class="fas fa-exclamation-circle mr-2"></i> Invalid server response. Please check console for details.</td></tr>';
            }
        })
        .catch(error => {
            console.error('Error loading archive summary:', error);
            dailyBreakdown.innerHTML = '<tr><td colspan="3" class="text-center text-danger py-4"><i class="fas fa-exclamation-circle mr-2"></i> Failed to load archive data. Please try again.</td></tr>';
        });
}

function deleteArchivedRecords(scope, date = '') {
    const label = scope === 'all' ? 'all archived records' : `archived records for ${formatDate(date)}`;

    Swal.fire({
        title: 'Delete archived records?',
        text: `This will permanently delete ${label}.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) {
            return;
        }

        fetch('endpoint/delete-archived-attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ scope: scope, date: date })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Deleted', data.message, 'success');
                loadArchiveSummary();
                return;
            }
            Swal.fire('Unable to delete', data.message || 'Please try again.', 'error');
        })
        .catch(error => {
            Swal.fire('Request failed', error.message, 'error');
        });
    });
}

function deleteArchivedDay(date) {
    deleteArchivedRecords('day', date);
}

const deleteAllArchivedBtn = document.getElementById('deleteAllArchivedBtn');
if (deleteAllArchivedBtn) {
    deleteAllArchivedBtn.addEventListener('click', function() {
        deleteArchivedRecords('all');
    });
}

// Format date function
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return new Date(dateString).toLocaleDateString(undefined, options);
}

// Handle archive form submission
document.getElementById('archiveForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const archiveBtn = document.getElementById('archiveBtn');
    const loading = archiveBtn.querySelector('.loading');
    const archiveDate = document.getElementById('archiveDate').value;
    const resultDiv = document.getElementById('archiveResult');
    
    if (!archiveDate) {
        Swal.fire('Warning', 'Please select a date', 'warning');
        return;
    }
    
    // Confirm archive
    Swal.fire({
        title: 'Archive Attendance Records?',
        text: `Are you sure you want to archive all attendance records on or before ${formatDate(archiveDate)}?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, archive them!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            // Show loading state
            loading.style.display = 'inline-block';
            archiveBtn.disabled = true;
            resultDiv.innerHTML = '';
            
            // Send archive request
            fetch('endpoint/archive-attendance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    archive_date: archiveDate
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.text();
            })
            .then(text => {
                try {
                    const data = JSON.parse(text);
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Success!',
                            text: data.message,
                            timer: 3000,
                            showConfirmButton: true
                        });
                        resultDiv.innerHTML = `<div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle mr-2"></i> ${data.message}
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>`;
                        loadArchiveSummary(); // Reload the summary
                    } else {
                        Swal.fire('Error', data.message, 'error');
                        resultDiv.innerHTML = `<div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle mr-2"></i> ${data.message}
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>`;
                    }
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    Swal.fire('Error', 'Invalid server response', 'error');
                    resultDiv.innerHTML = `<div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle mr-2"></i> Invalid server response. Check console for details.
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>`;
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                Swal.fire('Error', 'Failed to connect to server', 'error');
                resultDiv.innerHTML = `<div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle mr-2"></i> Error: ${error.message}
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>`;
            })
            .finally(() => {
                loading.style.display = 'none';
                archiveBtn.disabled = false;
            });
        }
    });
});

// Handle download button
document.getElementById('downloadBtn').addEventListener('click', function() {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const resultDiv = document.getElementById('downloadResult');
    
    if (!startDate || !endDate) {
        Swal.fire('Warning', 'Please select both start and end dates', 'warning');
        return;
    }
    
    if (startDate > endDate) {
        Swal.fire('Warning', 'Start date cannot be after end date', 'warning');
        return;
    }
    
    // Show loading
    const btn = this;
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Downloading...';
    resultDiv.innerHTML = '';
    
    // Trigger download
    window.location.href = `endpoint/download-archived-attendance.php?start_date=${startDate}&end_date=${endDate}`;
    
    // Reset button after delay
    setTimeout(() => {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
        resultDiv.innerHTML = `<div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle mr-2"></i> Download started!
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>`;
    }, 2000);
});

// Download single day
function downloadSingleDay(date) {
    if (date) {
        Swal.fire({
            title: 'Download Records',
            text: `Downloading attendance records for ${formatDate(date)}`,
            icon: 'info',
            showConfirmButton: false,
            timer: 1500
        });
        window.location.href = `endpoint/download-archived-attendance.php?start_date=${date}&end_date=${date}`;
    }
}

// Auto-refresh summary every 30 seconds
setInterval(loadArchiveSummary, 30000);

// Handle page visibility change
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        loadArchiveSummary();
    }
});
</script>

<!-- SweetAlert2 Custom Styles -->
<style>
.swal2-popup {
    font-size: 1.2rem;
}
.swal2-title {
    font-size: 1.5rem;
}
</style>

</body>
</html>
