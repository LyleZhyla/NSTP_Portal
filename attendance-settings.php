<?php
session_start();
include('./include/theme-loader.php');
include('./conn/conn.php');
require_once './include/user-permissions.php';
require_once './include/attendance-settings.php';
require_once './include/automatic-sectioning.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !in_array($currentUser['role'] ?? '', ['super_admin', 'coordinator'], true)) {
    header("Location: index.php");
    exit();
}

$componentSelectionEnabled = isComponentSelectionEnabled($conn);
$openStudentComponents = getOpenStudentComponents($conn);
$openRotcMsLevels = getOpenRotcMsLevels($conn);
$facilitatorScanRestrictionEnabled = isFacilitatorScanRestrictionEnabled($conn);
$attendanceCutoffs = getAttendanceCutoffs($conn);
$cutoffComponents = attendanceCutoffComponentsForUser($currentUser);
$absentNotificationGraceHours = getAbsentNotificationGraceHours($conn);
$autoSectionComponent = ($currentUser['role'] ?? '') === 'coordinator' ? normalizeProgram($currentUser['program'] ?? null) : null;
$autoSectionEnabledForCurrentUser = $autoSectionComponent !== 'ROTC';
$autoSectionMaxStudents = getAutoSectionMaxStudents($conn, $autoSectionComponent);
$selectedComponentCount = 0;
if (($currentUser['role'] ?? '') === 'super_admin') {
    try {
        $rotcMsLevelColumnStmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tbl_public_student_registrations'
              AND COLUMN_NAME = 'rotc_ms_level'
        ");
        $rotcMsLevelColumnStmt->execute();
        $excludeAdvancedRotcSql = (int) $rotcMsLevelColumnStmt->fetchColumn() > 0
            ? "AND UPPER(REPLACE(COALESCE(r.rotc_ms_level, ''), ' ', '-')) NOT IN ('MS-31', 'MS31', 'MS-41', 'MS41')"
            : "";

        $selectedComponentStmt = $conn->prepare("
            SELECT COUNT(DISTINCT u.user_id)
            FROM tbl_users u
            LEFT JOIN tbl_public_student_registrations r
              ON r.user_id = u.user_id
              OR (u.username REGEXP '^[0-9]{10}$' AND r.student_number = u.username)
            WHERE u.role = 'student'
              AND (
                u.program IS NOT NULL
                OR (r.component IS NOT NULL AND r.component <> '')
              )
              {$excludeAdvancedRotcSql}
        ");
        $selectedComponentStmt->execute();
        $selectedComponentCount = (int) $selectedComponentStmt->fetchColumn();
    } catch (Throwable $countError) {
        $selectedComponentCount = 0;
    }
}

date_default_timezone_set('Asia/Manila');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance Settings - TAU-NSTP</title>
    <?php include('./include/theme-loader.php'); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .settings-card {
            border-top: 3px solid #0d6efd;
        }
        .setting-summary {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px;
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 8px;
            background: #fff;
            min-height: 96px;
        }
        .setting-summary i {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eef5ff;
            color: #0d6efd;
            flex: 0 0 44px;
        }
        .setting-summary strong {
            display: block;
            font-size: 1rem;
        }
        .cutoff-row {
            display: grid;
            grid-template-columns: 1fr 150px 150px;
            gap: 14px;
            align-items: end;
            padding: 14px 0;
            border-bottom: 1px solid rgba(0,0,0,0.08);
        }
        .cutoff-row:last-child {
            border-bottom: 0;
        }
        .cutoff-label strong {
            display: block;
            font-size: 0.95rem;
        }
        @media (max-width: 575.98px) {
            .setting-summary {
                align-items: flex-start;
            }
            .cutoff-row {
                grid-template-columns: 1fr;
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
        </ul>
    </nav>

    <?php include 'adminlte-sidebar.php'; ?>

    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0"><i class="fas fa-user-clock mr-2"></i>Attendance Settings</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                            <li class="breadcrumb-item active">Attendance Settings</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <?php if (($currentUser['role'] ?? '') === 'super_admin'): ?>
                    <div class="col-lg-5">
                        <div class="card settings-card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-layer-group mr-2"></i>Student Component Selection</h3>
                            </div>
                            <div class="card-body">
                                <div class="setting-summary">
                                    <i class="fas fa-toggle-on"></i>
                                    <div class="flex-fill">
                                        <strong id="componentSelectionStatus"><?php echo count($openStudentComponents); ?> component(s) open for student registration.</strong>
                                        <span class="text-muted small">Open components individually. Closed components will not appear on the public form.</span>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <?php foreach (['CWTS', 'LTS', 'ROTC'] as $selectionComponent): ?>
                                    <?php $componentOpen = in_array($selectionComponent, $openStudentComponents, true); ?>
                                    <div class="d-flex align-items-center justify-content-between border rounded p-3 mb-2">
                                        <strong><?php echo htmlspecialchars($selectionComponent); ?></strong>
                                        <div class="custom-control custom-switch">
                                            <input type="checkbox" class="custom-control-input component-selection-toggle"
                                                   id="componentSelection<?php echo htmlspecialchars($selectionComponent); ?>"
                                                   data-component="<?php echo htmlspecialchars($selectionComponent); ?>"
                                                   <?php echo $componentOpen ? 'checked' : ''; ?>>
                                            <label class="custom-control-label font-weight-bold" for="componentSelection<?php echo htmlspecialchars($selectionComponent); ?>">
                                                <?php echo $componentOpen ? 'Open' : 'Closed'; ?>
                                            </label>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    <div class="ml-3 mt-1 mb-3 pl-3 border-left" id="rotcMsSelectionSettings">
                                        <span class="text-muted small d-block mb-2">Choose which ROTC MS levels students may select.</span>
                                        <?php foreach (getRotcMsLevels() as $selectionMsLevel): ?>
                                        <?php $msLevelOpen = in_array($selectionMsLevel, $openRotcMsLevels, true); ?>
                                        <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
                                            <strong class="small"><?php echo htmlspecialchars($selectionMsLevel); ?></strong>
                                            <div class="custom-control custom-switch">
                                                <input type="checkbox" class="custom-control-input rotc-ms-selection-toggle"
                                                       id="componentSelection<?php echo htmlspecialchars(str_replace('-', '', $selectionMsLevel)); ?>"
                                                       data-ms-level="<?php echo htmlspecialchars($selectionMsLevel); ?>"
                                                       <?php echo $msLevelOpen ? 'checked' : ''; ?>>
                                                <label class="custom-control-label font-weight-bold small" for="componentSelection<?php echo htmlspecialchars(str_replace('-', '', $selectionMsLevel)); ?>">
                                                    <?php echo $msLevelOpen ? 'Open' : 'Closed'; ?>
                                                </label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="mt-3 p-3 border rounded">
                                    <div class="d-flex align-items-center justify-content-between flex-wrap">
                                        <div class="mb-2 mb-sm-0">
                                            <strong>Reset selected components</strong>
                                            <span class="text-muted small d-block">
                                                <?php echo (int) $selectedComponentCount; ?> student account(s) currently have a selected component.
                                            </span>
                                        </div>
                                        <button type="button" class="btn btn-outline-danger" id="resetStudentComponentsBtn">
                                            <i class="fas fa-rotate-left mr-1"></i> Reset Components
                                        </button>
                                    </div>
                                    <p class="text-muted small mb-0 mt-2">
                                        This clears student component choices and closes selection. Students can choose again only after you reopen it.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="col-lg-<?php echo ($currentUser['role'] ?? '') === 'super_admin' ? '7' : '6'; ?>">
                        <div class="card settings-card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-qrcode mr-2"></i>Facilitator Scan Restriction</h3>
                            </div>
                            <div class="card-body">
                                <div class="setting-summary">
                                    <i class="fas fa-user-shield"></i>
                                    <div class="flex-fill">
                                        <strong id="scanRestrictionStatus">
                                            Facilitator restriction is <?php echo $facilitatorScanRestrictionEnabled ? 'active' : 'off'; ?>.
                                        </strong>
                                        <span class="text-muted small">Turn this on after common module so facilitators can scan only students assigned to them.</span>
                                    </div>
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox" class="custom-control-input" id="scanRestrictionToggle" <?php echo $facilitatorScanRestrictionEnabled ? 'checked' : ''; ?>>
                                        <label class="custom-control-label font-weight-bold" for="scanRestrictionToggle">
                                            <?php echo $facilitatorScanRestrictionEnabled ? 'Active' : 'Off'; ?>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($autoSectionEnabledForCurrentUser): ?>
                    <div class="col-lg-12">
                        <div class="card settings-card">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-folder-tree mr-2"></i>Automatic Folder Sectioning
                                    <?php if ($autoSectionComponent): ?>
                                        <span class="badge badge-primary ml-2"><?php echo htmlspecialchars($autoSectionComponent); ?></span>
                                    <?php endif; ?>
                                </h3>
                            </div>
                            <form id="autoSectionForm">
                                <div class="card-body">
                                    <div class="row align-items-end">
                                        <div class="col-md-5">
                                            <label for="autoSectionMaxStudents">Maximum students per folder</label>
                                            <select class="form-control" id="autoSectionMaxStudents" name="max_students">
                                                <?php foreach (autoSectionMaxOptions() as $option): ?>
                                                    <option value="<?php echo (int) $option; ?>" <?php echo $autoSectionMaxStudents === $option ? 'selected' : ''; ?>>
                                                        <?php echo (int) $option; ?> students
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-7 mt-3 mt-md-0">
                                            <button type="submit" class="btn btn-primary" id="saveAutoSectionBtn">
                                                <i class="fas fa-save mr-1"></i> Save Maximum
                                            </button>
                                            <button type="button" class="btn btn-outline-primary ml-2" id="rebuildAutoSectionBtn">
                                                <i class="fas fa-sync-alt mr-1"></i> Rebuild Existing Folders
                                            </button>
                                        </div>
                                    </div>
                                    <p class="text-muted small mb-0 mt-3">
                                        Students stay in the component pending list until you click rebuild. Rebuild creates folders by course and section, then continues to the next folder when the maximum is reached.
                                        <?php echo $autoSectionComponent ? 'This setting applies to your component only.' : 'This is the default setting for automatic folders.'; ?>
                                    </p>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="col-lg-12">
                        <div class="card settings-card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-envelope mr-2"></i>Attendance Email Notifications</h3>
                            </div>
                            <div class="card-body">
                                <div class="row align-items-end">
                                    <div class="col-md-4">
                                        <label for="attendanceEmailDate">Attendance Date</label>
                                        <input type="date" class="form-control" id="attendanceEmailDate" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="col-md-8 mt-3 mt-md-0">
                                        <button type="button" class="btn btn-warning attendance-email-btn" data-type="late">
                                            <i class="fas fa-clock mr-1"></i> Send Late Emails
                                        </button>
                                        <button type="button" class="btn btn-danger attendance-email-btn ml-2" data-type="absent">
                                            <i class="fas fa-user-times mr-1"></i> Send Absent Emails
                                        </button>
                                    </div>
                                </div>
                                <p class="text-muted small mb-0 mt-3">
                                    Scanning only records and displays the attendance status; it does not send a Late email automatically.
                                    Absent emails are generated only for students whose grace period has passed and who had a scheduled attendance session.
                                    <?php if (($currentUser['role'] ?? '') === 'coordinator'): ?>
                                        Only students under your <?php echo htmlspecialchars(normalizeProgram($currentUser['program'] ?? null) ?? 'assigned'); ?> component are included.
                                    <?php else: ?>
                                        As super admin, all eligible students are included.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($cutoffComponents)): ?>
                    <div class="col-lg-12">
                        <div class="card settings-card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-clock mr-2"></i>Late Start Times</h3>
                            </div>
                            <form id="attendanceCutoffForm">
                                <div class="card-body">
                                    <p class="text-muted">Set what time late attendance starts for each morning and afternoon session.</p>
                                    <?php if (($currentUser['role'] ?? '') === 'super_admin'): ?>
                                        <div class="setting-summary mb-3">
                                            <i class="fas fa-user-times"></i>
                                            <div class="flex-fill">
                                                <strong>Absent notification delay</strong>
                                                <span class="text-muted small">
                                                    Students become eligible for a manual Absent email this many hours after their morning late start time.
                                                </span>
                                            </div>
                                            <div style="width: 160px;">
                                                <label class="small mb-1" for="absent_notification_grace_hours">Hours</label>
                                                <input
                                                    type="number"
                                                    class="form-control"
                                                    id="absent_notification_grace_hours"
                                                    name="absent_notification_grace_hours"
                                                    min="1"
                                                    max="24"
                                                    step="1"
                                                    value="<?php echo (int) $absentNotificationGraceHours; ?>"
                                                    required
                                                >
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php foreach ($cutoffComponents as $component): ?>
                                        <?php $key = strtolower($component); ?>
                                        <div class="cutoff-row">
                                            <div class="cutoff-label">
                                                <strong><?php echo htmlspecialchars(attendanceComponentLabel($component)); ?></strong>
                                                <span class="text-muted small">
                                                    <?php echo strpos($component, 'ROTC_') === 0 ? 'Applies by ROTC MS level.' : 'Applies to matching student folders.'; ?>
                                                </span>
                                            </div>
                                            <div>
                                                <label class="small mb-1" for="<?php echo $key; ?>_morning">Morning Late Starts</label>
                                                <input type="time" class="form-control" id="<?php echo $key; ?>_morning" name="<?php echo $key; ?>_morning" value="<?php echo htmlspecialchars($attendanceCutoffs[$component]['morning']); ?>" required>
                                            </div>
                                            <div>
                                                <label class="small mb-1" for="<?php echo $key; ?>_afternoon">Afternoon Late Starts</label>
                                                <input type="time" class="form-control" id="<?php echo $key; ?>_afternoon" name="<?php echo $key; ?>_afternoon" value="<?php echo htmlspecialchars($attendanceCutoffs[$component]['afternoon']); ?>" required>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="card-footer text-right">
                                    <?php if (($currentUser['role'] ?? '') === 'super_admin'): ?>
                                    <button type="button" class="btn btn-warning mr-2" id="recalculateTodayAttendanceBtn">
                                        <i class="fas fa-rotate mr-1"></i> Recalculate Today's Attendance
                                    </button>
                                    <?php endif; ?>
                                    <button type="submit" class="btn btn-primary" id="saveCutoffsBtn">
                                        <i class="fas fa-save mr-1"></i> Save Late Times
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <?php if (($currentUser['role'] ?? '') === 'super_admin') include 'footer.php'; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function() {
    function syncComponentSelectionState(response) {
        const openComponents = response.open_components || [];
        const openRotcLevels = response.open_rotc_ms_levels || [];
        $('.component-selection-toggle').each(function() {
            const toggle = $(this);
            const isOpen = openComponents.includes(toggle.data('component'));
            toggle.prop('checked', isOpen);
            toggle.next('label').text(isOpen ? 'Open' : 'Closed');
        });
        $('.rotc-ms-selection-toggle').each(function() {
            const toggle = $(this);
            const isOpen = openRotcLevels.includes(toggle.data('ms-level'));
            toggle.prop('checked', isOpen);
            toggle.next('label').text(isOpen ? 'Open' : 'Closed');
        });
        $('#componentSelectionStatus').text(openComponents.length + ' component(s) open for student registration.');
    }

    $('.component-selection-toggle').on('change', function() {
        const toggle = $(this);
        const enabled = toggle.is(':checked') ? 1 : 0;
        const component = toggle.data('component');

        $.ajax({
            url: 'endpoint/toggle-component-selection.php',
            method: 'POST',
            data: { enabled: enabled, component: component },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    syncComponentSelectionState(response);
                    Swal.fire({
                        icon: 'success',
                        title: response.enabled ? 'Selection Open' : 'Selection Closed',
                        text: response.message,
                        timer: 1800,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire('Error', response.message, 'error');
                    toggle.prop('checked', !enabled);
                }
            },
            error: function() {
                Swal.fire('Error', 'Failed to update component selection setting.', 'error');
                toggle.prop('checked', !enabled);
            }
        });
    });

    $('.rotc-ms-selection-toggle').on('change', function() {
        const toggle = $(this);
        const enabled = toggle.is(':checked') ? 1 : 0;

        $.ajax({
            url: 'endpoint/toggle-component-selection.php',
            method: 'POST',
            data: { enabled: enabled, component: 'ROTC', rotc_ms_level: toggle.data('ms-level') },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    syncComponentSelectionState(response);
                    Swal.fire({
                        icon: 'success',
                        title: response.enabled ? 'MS Level Open' : 'MS Level Closed',
                        text: response.message,
                        timer: 1800,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire('Error', response.message, 'error');
                    toggle.prop('checked', !enabled);
                }
            },
            error: function() {
                Swal.fire('Error', 'Failed to update the ROTC MS level setting.', 'error');
                toggle.prop('checked', !enabled);
            }
        });
    });

    $('#scanRestrictionToggle').on('change', function() {
        const enabled = $(this).is(':checked') ? 1 : 0;

        $.ajax({
            url: 'endpoint/toggle-facilitator-scan-restriction.php',
            method: 'POST',
            data: { enabled: enabled },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#scanRestrictionToggle').next('label').text(response.enabled ? 'Active' : 'Off');
                    $('#scanRestrictionStatus').text('Facilitator restriction is ' + (response.enabled ? 'active' : 'off') + '.');
                    Swal.fire({
                        icon: 'success',
                        title: response.enabled ? 'Restriction Active' : 'Restriction Off',
                        text: response.message,
                        timer: 1800,
                        showConfirmButton: false
                    });
                } else {
                    Swal.fire('Error', response.message, 'error');
                    $('#scanRestrictionToggle').prop('checked', !enabled);
                }
            },
            error: function() {
                Swal.fire('Error', 'Failed to update facilitator scan restriction.', 'error');
                $('#scanRestrictionToggle').prop('checked', !enabled);
            }
        });
    });

    $('#resetStudentComponentsBtn').on('click', function() {
        const button = $(this);
        const originalHtml = button.html();

        Swal.fire({
            icon: 'warning',
            title: 'Reset all selected components?',
            html: `
                <div class="text-left">
                    <p>This will remove saved NSTP component choices from student accounts and registrations.</p>
                    <p class="mb-0 text-muted">Component selection will also be closed. Students can choose again only after you reopen the toggle.</p>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Reset Components',
            cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (!result.isConfirmed) {
                return;
            }

            button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Resetting');

            $.ajax({
                url: 'endpoint/reset-student-components.php',
                method: 'POST',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('.component-selection-toggle').prop('checked', false);
                        $('.component-selection-toggle').next('label').text('Closed');
                        $('.rotc-ms-selection-toggle').prop('checked', false);
                        $('.rotc-ms-selection-toggle').next('label').text('Closed');
                        $('#componentSelectionStatus').text('0 component(s) open for student registration.');
                        Swal.fire('Reset Complete', response.message, 'success').then(function() {
                            window.location.reload();
                        });
                    } else {
                        Swal.fire('Unable to reset', response.message || 'Please try again.', 'error');
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
    });

    $('#attendanceCutoffForm').on('submit', function(event) {
        event.preventDefault();
        const button = $('#saveCutoffsBtn');
        const originalHtml = button.html();
        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving');

        $.ajax({
            url: 'endpoint/update-attendance-cutoffs.php',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire('Saved', response.message, 'success');
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

    $('#recalculateTodayAttendanceBtn').on('click', function() {
        const button = $(this);
        const originalHtml = button.html();
        Swal.fire({
            icon: 'warning',
            title: "Recalculate today's attendance?",
            text: 'Today\'s Late and On Time statuses will be corrected using the currently saved cutoff times.',
            showCancelButton: true,
            confirmButtonText: 'Recalculate Today'
        }).then(function(result) {
            if (!result.isConfirmed) return;
            button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Recalculating');
            $.ajax({
                url: 'endpoint/recalculate-todays-attendance.php',
                method: 'POST',
                dataType: 'json',
                success: function(response) {
                    Swal.fire(response.success ? 'Attendance Corrected' : 'Unable to Recalculate', response.message || 'Please try again.', response.success ? 'success' : 'error');
                },
                error: function() {
                    Swal.fire('Request Failed', 'Unable to recalculate today\'s attendance.', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).html(originalHtml);
                }
            });
        });
    });

    $('.attendance-email-btn').on('click', function() {
        const button = $(this);
        const type = button.data('type');
        const typeLabel = type === 'late' ? 'Late' : 'Absent';
        const attendanceDate = $('#attendanceEmailDate').val();
        const originalHtml = button.html();

        if (!attendanceDate) {
            Swal.fire('Select a date', 'Choose the attendance date first.', 'warning');
            return;
        }

        Swal.fire({
            icon: 'question',
            title: `Send pending ${typeLabel} emails?`,
            text: `Only unsent ${typeLabel.toLowerCase()} attendance emails for ${attendanceDate} will be processed.`,
            showCancelButton: true,
            confirmButtonText: `Send ${typeLabel} Emails`
        }).then(function(result) {
            if (!result.isConfirmed) return;

            $('.attendance-email-btn').prop('disabled', true);
            button.html('<i class="fas fa-spinner fa-spin mr-1"></i> Sending');

            $.ajax({
                url: 'endpoint/send-attendance-status-emails.php',
                method: 'POST',
                data: { type: type, date: attendanceDate },
                dataType: 'json',
                timeout: 120000,
                success: function(response) {
                    if (!response.success) {
                        Swal.fire('Unable to send', response.message || 'Please try again.', 'error');
                        return;
                    }

                    const summary = response.summary || {};
                    Swal.fire({
                        icon: summary.sent > 0 ? 'success' : 'info',
                        title: summary.sent > 0 ? 'Emails Sent' : 'No Pending Emails',
                        html: `
                            <div class="text-left">
                                <div><strong>Sent:</strong> ${summary.sent || 0}</div>
                                <div><strong>No valid email:</strong> ${summary.no_email || 0}</div>
                                <div><strong>Failed:</strong> ${summary.failed || 0}</div>
                            </div>
                        `
                    });
                },
                error: function() {
                    Swal.fire('Request failed', 'The email batch did not finish. Unsent records remain pending and can be retried.', 'error');
                },
                complete: function() {
                    $('.attendance-email-btn').prop('disabled', false);
                    button.html(originalHtml);
                }
            });
        });
    });

    $('#autoSectionForm').on('submit', function(event) {
        event.preventDefault();
        const button = $('#saveAutoSectionBtn');
        const originalHtml = button.html();
        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving');

        $.ajax({
            url: 'endpoint/update-auto-section-settings.php',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire('Saved', response.message, 'success');
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

    $('#rebuildAutoSectionBtn').on('click', function() {
        const button = $(this);
        const originalHtml = button.html();

        Swal.fire({
            icon: 'question',
            title: 'Rebuild automatic folders?',
            text: 'Existing system/public student folders will be recalculated using the selected maximum.',
            showCancelButton: true,
            confirmButtonText: 'Rebuild',
            cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (!result.isConfirmed) {
                return;
            }

            button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Rebuilding');

            $.ajax({
                url: 'endpoint/rebuild-auto-section-folders.php',
                method: 'POST',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Done', response.message, 'success');
                    } else {
                        Swal.fire('Unable to rebuild', response.message || 'Please try again.', 'error');
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
    });
});
</script>
</body>
</html>
