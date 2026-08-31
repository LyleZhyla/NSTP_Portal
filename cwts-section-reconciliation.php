<?php

declare(strict_types=1);

session_start();
require_once __DIR__ . '/conn/conn.php';
require_once __DIR__ . '/include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
$actorProgram = normalizeProgram($currentUser['program'] ?? null);
$isAllowed = $currentUser
    && (
        ($currentUser['role'] ?? '') === 'super_admin'
        || (($currentUser['role'] ?? '') === 'coordinator' && in_array($actorProgram, ['CWTS', 'LTS'], true))
    );
if (!$isAllowed) {
    http_response_code(403);
    exit('Unauthorized access');
}

if (empty($_SESSION['cwts_reconciliation_csrf'])) {
    $_SESSION['cwts_reconciliation_csrf'] = bin2hex(random_bytes(32));
}

$report = null;
$errorMessage = '';
$action = '';
$selectedComponent = strtoupper((string) ($_POST['component'] ?? $_GET['component'] ?? $actorProgram ?? 'CWTS'));
if (!in_array($selectedComponent, ['CWTS', 'LTS'], true)) {
    $selectedComponent = 'CWTS';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        if (!hash_equals((string) $_SESSION['cwts_reconciliation_csrf'], $submittedToken)) {
            throw new RuntimeException('The form expired. Refresh the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? 'preview');
        if (!in_array($action, ['preview', 'apply'], true)) {
            throw new InvalidArgumentException('Invalid reconciliation action.');
        }
        if (!in_array($selectedComponent, ['CWTS', 'LTS'], true)) {
            throw new InvalidArgumentException('Select CWTS or LTS.');
        }
        if (($currentUser['role'] ?? '') === 'coordinator' && $selectedComponent !== $actorProgram) {
            throw new RuntimeException('Coordinators can reconcile only their assigned component.');
        }

        $upload = $_FILES['masterlist'] ?? null;
        if (!$upload || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Select the ' . $selectedComponent . ' masterlist Excel file first.');
        }
        if ((int) ($upload['size'] ?? 0) <= 0 || (int) $upload['size'] > 10 * 1024 * 1024) {
            throw new RuntimeException('The workbook must be smaller than 10 MB.');
        }

        $originalName = (string) ($upload['name'] ?? '');
        if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('Only .xlsx masterlist files are accepted.');
        }

        $temporaryPath = (string) ($upload['tmp_name'] ?? '');
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            throw new RuntimeException('The uploaded workbook could not be verified.');
        }

        if ($action === 'apply' && trim((string) ($_POST['confirmation'] ?? '')) !== 'APPLY ' . $selectedComponent) {
            throw new RuntimeException('Type APPLY ' . $selectedComponent . ' to confirm the production update.');
        }

        if (!defined('CWTS_RECONCILIATION_WEB')) {
            define('CWTS_RECONCILIATION_WEB', true);
        }
        $GLOBALS['cwtsReconciliationArgs'] = [$temporaryPath, '--json', '--component=' . $selectedComponent];
        if ($action === 'apply') {
            $GLOBALS['cwtsReconciliationArgs'][] = '--apply';
        }
        $GLOBALS['cwtsReconciliationActorId'] = (int) $currentUser['user_id'];
        $GLOBALS['cwtsReconciliationActorRole'] = (string) $currentUser['role'];
        $GLOBALS['cwtsReconciliationComponent'] = $selectedComponent;
        $GLOBALS['cwtsReconciliationReport'] = null;

        require __DIR__ . '/scripts/reconcile-cwts-masterlist.php';
        $report = $GLOBALS['cwtsReconciliationReport'];
        if (!is_array($report)) {
            throw new RuntimeException('The reconciliation did not return a report.');
        }

        if ($action === 'apply' && !empty($report['applied'])) {
            markSharedDataChanged($conn);
            logSystemEvent(
                $conn,
                strtolower($selectedComponent) . '_masterlist_reconciled',
                sprintf(
                    'Applied exact %s masterlist roster: %d matched, %d updated, %d moved to pending, %d sections.',
                    $selectedComponent,
                    (int) ($report['matched'] ?? 0),
                    (int) ($report['updated_students'] ?? 0),
                    (int) ($report['moved_to_pending'] ?? 0),
                    (int) ($report['sheet_count'] ?? 0)
                )
            );
            $_SESSION['cwts_reconciliation_csrf'] = bin2hex(random_bytes(32));
        }
    } catch (Throwable $error) {
        $errorMessage = $error->getMessage();
    }
}

function reconciliationBadgeClass(int $value, bool $zeroIsGood = true): string
{
    $good = $zeroIsGood ? $value === 0 : $value > 0;
    return $good ? 'badge-success' : 'badge-danger';
}

$inactivityTimeoutMinutes = (int) getSystemSetting($conn, 'inactivity_timeout_minutes', '5');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($selectedComponent); ?> Section Reconciliation - TAU-NSTP</title>
    <?php include __DIR__ . '/include/theme-loader.php'; ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="include/theme.css">
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a></li>
        </ul>
        <ul class="navbar-nav ml-auto"><?php include __DIR__ . '/include/header-notifications.php'; ?></ul>
    </nav>

    <?php include __DIR__ . '/adminlte-sidebar.php'; ?>

    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-7"><h1 class="m-0"><i class="fas fa-people-arrows mr-2"></i><?php echo htmlspecialchars($selectedComponent); ?> Section Reconciliation</h1></div>
                    <div class="col-sm-5"><ol class="breadcrumb float-sm-right"><li class="breadcrumb-item"><a href="index.php">Home</a></li><li class="breadcrumb-item active">Section Reconciliation</li></ol></div>
                </div>
            </div>
        </div>

        <section class="content">
            <div class="container-fluid">
                <?php if ($errorMessage !== ''): ?>
                    <div class="alert alert-danger"><i class="fas fa-circle-exclamation mr-2"></i><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>

                <?php if (is_array($report) && $action === 'apply' && !empty($report['applied'])): ?>
                    <div class="alert alert-success">
                        <strong>Reconciliation completed.</strong>
                        <?php echo (int) ($report['updated_students'] ?? 0); ?> student assignment(s) were updated.
                        <?php echo (int) ($report['moved_to_pending'] ?? 0); ?> unlisted student(s) were moved out of section folders and into the component pending list.
                        Run Preview again to confirm that Changes Needed is zero.
                    </div>
                <?php elseif (is_array($report) && $action === 'apply' && empty($report['applied'])): ?>
                    <div class="alert alert-warning"><strong>No changes were applied.</strong> <?php echo htmlspecialchars((string) ($report['blocked_reason'] ?? 'Review the validation results below.')); ?></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-5">
                        <div class="card card-primary card-outline">
                            <div class="card-header"><h3 class="card-title"><i class="fas fa-file-excel mr-2"></i>Upload authoritative masterlist</h3></div>
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION['cwts_reconciliation_csrf']); ?>">
                                <div class="card-body">
                                    <div class="alert alert-info">
                                        The workbook is the authoritative folder roster. Listed students are placed in the exact worksheet section and facilitator; component students not listed in the workbook are removed from section folders and moved to the pending list. Preview does not change the database.
                                    </div>
                                    <div class="form-group">
                                        <label for="component">Component</label>
                                        <select class="form-control" id="component" name="component" <?php echo ($currentUser['role'] ?? '') === 'coordinator' ? 'disabled' : ''; ?>>
                                            <?php foreach (['CWTS', 'LTS'] as $componentOption): ?>
                                                <?php if (($currentUser['role'] ?? '') === 'super_admin' || $componentOption === $actorProgram): ?>
                                                    <option value="<?php echo $componentOption; ?>" <?php echo $selectedComponent === $componentOption ? 'selected' : ''; ?>><?php echo $componentOption; ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (($currentUser['role'] ?? '') === 'coordinator'): ?><input type="hidden" name="component" value="<?php echo htmlspecialchars((string) $actorProgram); ?>"><?php endif; ?>
                                    </div>
                                    <div class="form-group">
                                        <label for="masterlist"><?php echo htmlspecialchars($selectedComponent); ?> masterlist (.xlsx)</label>
                                        <input type="file" class="form-control-file" id="masterlist" name="masterlist" accept=".xlsx" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="confirmation">Apply confirmation</label>
                                        <input type="text" class="form-control" id="confirmation" name="confirmation" placeholder="Type APPLY <?php echo htmlspecialchars($selectedComponent); ?> only when applying" autocomplete="off">
                                        <small class="form-text text-muted">Always Preview first. Apply is blocked if any required match is unsafe.</small>
                                    </div>
                                    <a class="btn btn-outline-secondary" href="endpoint/backup-database.php"><i class="fas fa-download mr-1"></i>Download full DB backup</a>
                                </div>
                                <div class="card-footer d-flex justify-content-between">
                                    <button type="submit" class="btn btn-info" name="action" value="preview"><i class="fas fa-magnifying-glass mr-1"></i>Preview</button>
                                    <button type="submit" class="btn btn-danger" name="action" value="apply" onclick="return confirm('Apply the workbook assignments to the production database?');"><i class="fas fa-check-double mr-1"></i>Apply corrections</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card">
                            <div class="card-header"><h3 class="card-title"><i class="fas fa-shield-halved mr-2"></i>Required safe result</h3></div>
                            <div class="card-body">
                                <ul class="mb-0">
                                    <li>Workbook Students and Matched must be equal and greater than zero.</li>
                                    <li>Unmatched, Ambiguous, and Missing Facilitators must all be zero.</li>
                                    <li>Move to Pending shows database students currently inside a component folder but absent from the workbook; they are not deleted.</li>
                                    <li>After applying, upload the workbook again and Preview; Changes Needed must be zero.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (is_array($report)): ?>
                    <div class="row">
                        <?php
                        $metrics = [
                            ['Workbook Students', (int) ($report['workbook_students'] ?? 0), false],
                            ['Matched', (int) ($report['matched'] ?? 0), false],
                            ['Changes Needed', (int) ($report['changes_needed'] ?? 0), true],
                            ['Incoming/Recovered', (int) ($report['incoming_to_component_count'] ?? 0), true],
                            ['Move to Pending', (int) ($report['move_to_pending_count'] ?? 0), true],
                            ['Unmatched', (int) ($report['unmatched_count'] ?? 0), true],
                            ['Ambiguous', (int) ($report['ambiguous_count'] ?? 0), true],
                            ['Missing Facilitators', count($report['missing_facilitators'] ?? []), true],
                        ];
                        foreach ($metrics as [$label, $value, $zeroIsGood]):
                        ?>
                            <div class="col-lg-2 col-md-4 col-6 mb-3">
                                <div class="card h-100"><div class="card-body text-center p-3"><div class="h3 mb-1"><?php echo $value; ?></div><span class="badge <?php echo reconciliationBadgeClass($value, $zeroIsGood); ?>"><?php echo htmlspecialchars($label); ?></span></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="card">
                        <div class="card-header"><h3 class="card-title">Expected students per section</h3></div>
                        <div class="card-body table-responsive p-0">
                            <table class="table table-striped table-sm mb-0">
                                <thead><tr><th>Section</th><th>Facilitator</th><th class="text-right">Students</th></tr></thead>
                                <tbody>
                                <?php foreach (($report['section_counts'] ?? []) as $section): ?>
                                    <tr><td><?php echo htmlspecialchars((string) $section['section']); ?></td><td><?php echo htmlspecialchars((string) $section['facilitator']); ?></td><td class="text-right"><?php echo (int) $section['students']; ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if (!empty($report['missing_facilitators'])): ?>
                        <div class="card card-danger card-outline"><div class="card-header"><h3 class="card-title">Missing facilitators</h3></div><div class="card-body"><ul class="mb-0">
                            <?php foreach ($report['missing_facilitators'] as $item): ?><li><?php echo htmlspecialchars((string) $item['section']); ?> — <?php echo htmlspecialchars((string) $item['facilitator']); ?></li><?php endforeach; ?>
                        </ul></div></div>
                    <?php endif; ?>

                    <?php if (!empty($report['incoming'])): ?>
                        <div class="card card-info card-outline">
                            <div class="card-header"><h3 class="card-title">Excel-listed students recovered from outside <?php echo htmlspecialchars($selectedComponent); ?></h3></div>
                            <div class="card-body">
                                <p class="text-muted">These names were found anywhere in the student database and will be moved into the exact Excel section. Their student account and latest registration component will also be corrected.</p>
                                <?php if (!empty($report['incoming_requires_super_admin'])): ?><div class="alert alert-warning mb-0">A Super Admin must perform Apply because this includes component reassignment.</div><?php endif; ?>
                            </div>
                            <div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr><th>Student</th><th>Current component</th><th>Current section</th><th>Excel section</th></tr></thead><tbody>
                            <?php foreach (array_slice($report['incoming'], 0, 100) as $item): ?><tr><td><?php echo htmlspecialchars((string) $item['name']); ?></td><td><?php echo htmlspecialchars((string) ($item['old_component'] ?: 'Unassigned')); ?></td><td><?php echo htmlspecialchars((string) $item['old_section']); ?></td><td><?php echo htmlspecialchars((string) $item['new_section']); ?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($report['unmatched']) || !empty($report['ambiguous'])): ?>
                        <div class="card card-danger card-outline">
                            <div class="card-header"><h3 class="card-title">Records requiring review</h3></div>
                            <div class="card-body table-responsive p-0"><table class="table table-sm table-striped mb-0"><thead><tr><th>Status</th><th>Workbook name</th><th>Program</th><th>Target</th></tr></thead><tbody>
                            <?php foreach (array_slice($report['unmatched'] ?? [], 0, 100) as $item): ?><tr><td><span class="badge badge-danger">Unmatched</span></td><td><?php echo htmlspecialchars((string) $item['name']); ?></td><td><?php echo htmlspecialchars((string) $item['program']); ?></td><td><?php echo htmlspecialchars((string) $item['section']); ?></td></tr><?php endforeach; ?>
                            <?php foreach (array_slice($report['ambiguous'] ?? [], 0, 100) as $item): ?><tr><td><span class="badge badge-warning">Ambiguous</span></td><td><?php echo htmlspecialchars((string) $item['name']); ?></td><td><?php echo htmlspecialchars((string) $item['program']); ?></td><td><?php echo htmlspecialchars((string) $item['section']); ?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($report['database_only'])): ?>
                        <div class="card card-warning card-outline">
                            <div class="card-header"><h3 class="card-title">Component students absent from the workbook</h3></div>
                            <div class="card-body">
                                <p class="text-muted">Students marked “Move to pending” will be removed from their current section folder without deleting their account, registration, QR code, attendance, or grades.</p>
                            </div>
                            <div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr><th>Student</th><th>Current section</th><th>Exact-sync action</th></tr></thead><tbody>
                            <?php foreach (array_slice($report['database_only'], 0, 100) as $item): ?><tr><td><?php echo htmlspecialchars((string) $item['name']); ?></td><td><?php echo htmlspecialchars((string) $item['section']); ?></td><td><?php if (!empty($item['will_move_to_pending'])): ?><span class="badge badge-warning">Move to pending</span><?php else: ?><span class="badge badge-secondary">Already pending</span><?php endif; ?></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <?php include __DIR__ . '/footer.php'; ?>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script>
$(function() {
    $('#component').on('change', function() {
        const component = String($(this).val() || 'CWTS').toUpperCase();
        $('#confirmation').attr('placeholder', 'Type APPLY ' + component + ' only when applying');
    });
});
</script>
</body>
</html>
