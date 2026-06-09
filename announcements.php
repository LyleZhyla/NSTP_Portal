<?php
session_start();

require_once 'conn/conn.php';
require_once 'include/notifications.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: landing_page.php');
    exit();
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessStaffTools($currentUser['role'] ?? '')) {
    header('Location: index.php');
    exit();
}

$announcements = getRecentAnnouncements($conn, $currentUser, 30);
$role = $currentUser['role'] ?? '';
$program = normalizeProgram($currentUser['program'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Announcements - TAU NSTP</title>
    <?php include('./include/theme-loader.php'); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .announcement-body {
            white-space: pre-wrap;
            color: #374151;
        }
        .scope-note {
            border: 1px solid rgba(47, 111, 126, 0.16);
            border-radius: 8px;
            background: #f4f8fa;
            padding: 14px 16px;
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
                <h1><i class="fas fa-bullhorn mr-2"></i>Announcements</h1>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-lg-5">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-plus-circle mr-2"></i>Create Announcement</h3>
                            </div>
                            <form id="announcementForm">
                                <div class="card-body">
                                    <div class="scope-note mb-3">
                                        <strong>Recipients:</strong>
                                        <?php if ($role === 'super_admin'): ?>
                                            Students from the selected component, or all students when no component is selected.
                                        <?php elseif ($role === 'coordinator'): ?>
                                            Students under <?php echo htmlspecialchars($program ?: 'your component'); ?>.
                                        <?php else: ?>
                                            Students assigned to your handled folders.
                                        <?php endif; ?>
                                    </div>

                                    <div class="form-group">
                                        <label for="title">Title</label>
                                        <input type="text" class="form-control" id="title" name="title" maxlength="180" required>
                                    </div>

                                    <?php if ($role === 'super_admin'): ?>
                                    <div class="form-group">
                                        <label for="scope_program">Component Scope</label>
                                        <select class="form-control" id="scope_program" name="scope_program">
                                            <option value="">All Components</option>
                                            <option value="CWTS">CWTS</option>
                                            <option value="LTS">LTS</option>
                                            <option value="ROTC">ROTC</option>
                                        </select>
                                    </div>
                                    <?php endif; ?>

                                    <div class="form-group mb-0">
                                        <label for="body">Message</label>
                                        <textarea class="form-control" id="body" name="body" rows="8" required></textarea>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane mr-1"></i> Post Announcement
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-list mr-2"></i>Recent Announcements</h3>
                            </div>
                            <div class="card-body">
                                <?php if (count($announcements) === 0): ?>
                                    <div class="text-center text-muted py-5">No announcements yet</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Title</th>
                                                    <th>Scope</th>
                                                    <th>Created By</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($announcements as $announcement): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($announcement['title']); ?></strong>
                                                            <div class="announcement-body small mt-1"><?php echo htmlspecialchars($announcement['body']); ?></div>
                                                        </td>
                                                        <td>
                                                            <span class="badge badge-info">
                                                                <?php echo htmlspecialchars($announcement['scope_program'] ?: 'All'); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($announcement['creator_name'] ?: 'System'); ?></td>
                                                        <td><?php echo date('M d, Y h:i A', strtotime($announcement['created_at'])); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
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
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function() {
    $('#announcementForm').on('submit', function(event) {
        event.preventDefault();

        const submitButton = $(this).find('button[type="submit"]');
        submitButton.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Posting...');

        $.ajax({
            url: 'endpoint/create-announcement.php',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire('Posted', response.message, 'success').then(function() {
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', response.message || 'Unable to post announcement.', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Unable to post announcement.', 'error');
            },
            complete: function() {
                submitButton.prop('disabled', false).html('<i class="fas fa-paper-plane mr-1"></i> Post Announcement');
            }
        });
    });
});
</script>
</body>
</html>
