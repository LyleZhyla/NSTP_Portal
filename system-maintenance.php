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

$inactivityTimeoutMinutes = (int) getSystemSetting($conn, 'inactivity_timeout_minutes', '5');
$timeoutOptions = [1, 3, 5, 10, 15, 30, 60];
if (!in_array($inactivityTimeoutMinutes, $timeoutOptions, true)) {
    $inactivityTimeoutMinutes = 5;
}

function tableExists(PDO $conn, $tableName) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $stmt->execute([$tableName]);
    return (int) $stmt->fetchColumn() > 0;
}

function countTableRows(PDO $conn, $tableName, $where = '') {
    if (!tableExists($conn, $tableName)) {
        return 0;
    }

    $sql = "SELECT COUNT(*) FROM `$tableName`";
    if ($where !== '') {
        $sql .= " WHERE $where";
    }

    return (int) $conn->query($sql)->fetchColumn();
}

$counts = [
    'coordinators' => countTableRows($conn, 'tbl_users', "role = 'coordinator'"),
    'facilitators' => countTableRows($conn, 'tbl_users', "role = 'facilitator'"),
    'students' => countTableRows($conn, 'tbl_student'),
    'student_accounts' => countTableRows($conn, 'tbl_users', "role = 'student'"),
    'registrations' => countTableRows($conn, 'tbl_public_student_registrations'),
    'attendance' => countTableRows($conn, 'tbl_attendance'),
    'archived_attendance' => countTableRows($conn, 'tbl_attendance_archive'),
];

date_default_timezone_set('Asia/Manila');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Maintenance - TAU-NSTP</title>
    <?php include('./include/theme-loader.php'); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .maintenance-card {
            border-top: 3px solid #0d6efd;
        }
        .danger-card {
            border-top: 3px solid #dc3545;
        }
        .summary-box {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 8px;
            background: #fff;
            min-height: 86px;
        }
        .summary-box i {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eef5ff;
            color: #0d6efd;
        }
        .summary-box strong {
            display: block;
            font-size: 1.35rem;
            line-height: 1;
        }
        .cleanup-option {
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 12px;
            background: #fff;
        }
        .cleanup-option label {
            margin-bottom: 0;
            font-weight: 700;
        }
        .cleanup-option small {
            display: block;
            margin-left: 28px;
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
        </ul>
    </nav>

    <?php include 'adminlte-sidebar.php'; ?>

    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0"><i class="fas fa-database mr-2"></i>System Maintenance</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                            <li class="breadcrumb-item active">System Maintenance</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="summary-box">
                            <i class="fas fa-user-tie"></i>
                            <div><strong><?php echo $counts['coordinators']; ?></strong><span>Coordinators</span></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="summary-box">
                            <i class="fas fa-user-shield"></i>
                            <div><strong><?php echo $counts['facilitators']; ?></strong><span>Facilitators</span></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="summary-box">
                            <i class="fas fa-user-graduate"></i>
                            <div><strong><?php echo $counts['students']; ?></strong><span>Student Records</span></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="summary-box">
                            <i class="fas fa-clipboard-check"></i>
                            <div><strong><?php echo $counts['attendance'] + $counts['archived_attendance']; ?></strong><span>Attendance Logs</span></div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-5">
                        <div class="card maintenance-card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-clock mr-2"></i>Inactivity Auto Logout</h3>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">Choose how many minutes of inactivity are allowed before users are automatically logged out.</p>
                                <div class="input-group">
                                    <select class="custom-select" id="inactivityTimeoutSelect">
                                        <?php foreach ($timeoutOptions as $option): ?>
                                            <option value="<?php echo $option; ?>" <?php echo $option === $inactivityTimeoutMinutes ? 'selected' : ''; ?>>
                                                <?php echo $option; ?> minute<?php echo $option === 1 ? '' : 's'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-primary" id="saveTimeoutBtn">
                                            <i class="fas fa-save mr-1"></i> Save
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card maintenance-card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-download mr-2"></i>Database Backup</h3>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">Download a complete SQL backup of the current database before making major changes.</p>
                                <a href="endpoint/backup-database.php" class="btn btn-primary">
                                    <i class="fas fa-file-download mr-1"></i> Download SQL Backup
                                </a>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Delete Scope</h3>
                            </div>
                            <div class="card-body">
                                <ul class="mb-0 pl-3">
                                    <li>Super admin accounts are always protected.</li>
                                    <li>Student cleanup removes student records, student accounts, attendance, archived attendance, and public registrations.</li>
                                    <li>Staff cleanup can target coordinators and facilitators separately.</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card danger-card">
                            <div class="card-header">
                                <h3 class="card-title text-danger"><i class="fas fa-trash-alt mr-2"></i>Delete Data</h3>
                            </div>
                            <form id="cleanupForm" action="endpoint/cleanup-database.php" method="POST">
                                <div class="card-body">
                                    <div class="alert alert-warning">
                                        <strong>Important:</strong> This action is permanent. Download a backup first if you need a restore point.
                                    </div>

                                    <div class="cleanup-option">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input cleanup-check" id="deleteCoordinators" name="delete_coordinators" value="1">
                                            <label class="custom-control-label" for="deleteCoordinators">Delete coordinator accounts</label>
                                        </div>
                                        <small class="text-muted"><?php echo $counts['coordinators']; ?> coordinator account(s) will be removed.</small>
                                    </div>

                                    <div class="cleanup-option">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input cleanup-check" id="deleteFacilitators" name="delete_facilitators" value="1">
                                            <label class="custom-control-label" for="deleteFacilitators">Delete facilitator accounts</label>
                                        </div>
                                        <small class="text-muted"><?php echo $counts['facilitators']; ?> facilitator account(s) and section assignments will be removed.</small>
                                    </div>

                                    <div class="cleanup-option">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input cleanup-check" id="deleteStudents" name="delete_students" value="1">
                                            <label class="custom-control-label" for="deleteStudents">Delete student data</label>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo $counts['students']; ?> student record(s),
                                            <?php echo $counts['student_accounts']; ?> student account(s),
                                            <?php echo $counts['registrations']; ?> public registration(s), and related attendance will be removed.
                                        </small>
                                    </div>

                                    <div class="form-group mt-4 mb-0">
                                        <label for="confirmationText">Type DELETE to confirm</label>
                                        <input type="text" class="form-control" id="confirmationText" name="confirmation_text" autocomplete="off" placeholder="DELETE">
                                    </div>
                                </div>
                                <div class="card-footer text-right">
                                    <button type="submit" class="btn btn-danger">
                                        <i class="fas fa-trash mr-1"></i> Delete Selected Data
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function() {
    $('#saveTimeoutBtn').on('click', function() {
        const button = $(this);
        const originalHtml = button.html();
        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving');

        $.ajax({
            url: 'endpoint/update-inactivity-timeout.php',
            method: 'POST',
            data: { minutes: $('#inactivityTimeoutSelect').val() },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire('Saved', response.message, 'success').then(() => window.location.reload());
                } else {
                    Swal.fire('Unable to save', response.message || 'Please try again.', 'error');
                }
            },
            error: function() {
                Swal.fire('Request failed', 'The server did not return a valid response.', 'error');
            },
            complete: function() {
                button.prop('disabled', false).html(originalHtml);
            }
        });
    });

    $('#cleanupForm').on('submit', function(event) {
        event.preventDefault();

        if ($('.cleanup-check:checked').length === 0) {
            Swal.fire('No option selected', 'Choose at least one data group to delete.', 'info');
            return;
        }

        if ($('#confirmationText').val().trim() !== 'DELETE') {
            Swal.fire('Confirmation required', 'Type DELETE before continuing.', 'warning');
            return;
        }

        Swal.fire({
            title: 'Delete selected data?',
            text: 'This cannot be undone unless you restore from a backup.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc3545'
        }).then((result) => {
            if (!result.isConfirmed) {
                return;
            }

            $.ajax({
                url: $('#cleanupForm').attr('action'),
                method: 'POST',
                data: $('#cleanupForm').serialize(),
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Done', response.message, 'success').then(() => {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Unable to delete', response.message || 'Please try again.', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Request failed', 'The server did not return a valid response.', 'error');
                }
            });
        });
    });
});
</script>
</body>
</html>
