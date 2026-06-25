<?php
session_start();

require_once './conn/conn.php';
require_once './include/data-edit-requests.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    header('Location: index.php');
    exit();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $status = dataEditRequestReview(
            $conn,
            $_POST['request_id'] ?? 0,
            $currentUser,
            $_POST['action'] ?? '',
            $_POST['review_note'] ?? ''
        );
        $message = 'Data edit request ' . $status . ' successfully.';
    } catch (Throwable $reviewError) {
        $error = $reviewError->getMessage();
    }
}

$selectedStatus = strtolower(trim((string) ($_GET['status'] ?? 'pending')));
$requests = dataEditRequestList($conn, $selectedStatus);

function requestStatusBadge($status) {
    $status = strtolower((string) $status);
    if ($status === 'approved') {
        return 'success';
    }
    if ($status === 'rejected') {
        return 'danger';
    }
    return 'warning';
}

function requestValue(array $data, $key) {
    return htmlspecialchars((string) ($data[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Edit Requests - TAU NSTP</title>
    <?php include('./include/theme-loader.php'); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .request-card {
            border-top: 3px solid #198754;
        }
        .change-grid {
            display: grid;
            grid-template-columns: 110px 1fr 1fr;
            gap: 8px;
            font-size: 0.9rem;
        }
        .change-grid .label {
            font-weight: 700;
            color: #495057;
        }
        .change-grid .current {
            color: #6c757d;
        }
        .change-grid .requested {
            color: #0f5132;
            font-weight: 700;
        }
        @media (max-width: 767.98px) {
            .change-grid {
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
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0"><i class="fas fa-user-check mr-2"></i>Data Edit Requests</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                            <li class="breadcrumb-item active">Data Edit Requests</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                <?php endif; ?>

                <div class="card request-card">
                    <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
                        <h3 class="card-title mb-0">Requests</h3>
                        <div class="btn-group mt-2 mt-sm-0">
                            <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $statusKey => $statusLabel): ?>
                                <a href="data-edit-requests.php?status=<?php echo $statusKey; ?>" class="btn btn-sm btn-<?php echo $selectedStatus === $statusKey ? 'success' : 'outline-secondary'; ?>">
                                    <?php echo $statusLabel; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="requestsTable" class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>User</th>
                                        <th>Requested Changes</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th>Review</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requests as $request): ?>
                                        <?php
                                            $currentData = dataEditRequestDecode($request['current_data'] ?? '');
                                            $requestedData = dataEditRequestDecode($request['requested_data'] ?? '');
                                            $isPending = ($request['status'] ?? '') === 'pending';
                                        ?>
                                        <tr>
                                            <td data-order="<?php echo (int) strtotime($request['created_at']); ?>">
                                                <?php echo date('M d, Y h:i A', strtotime($request['created_at'])); ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($request['full_name'] ?? 'User'); ?></strong>
                                                <div class="small text-muted">@<?php echo htmlspecialchars($request['username'] ?? ''); ?></div>
                                                <span class="badge badge-secondary"><?php echo htmlspecialchars(str_replace('_', ' ', $request['role'] ?? 'user')); ?></span>
                                            </td>
                                            <td>
                                                <div class="change-grid">
                                                    <div class="label">Field</div>
                                                    <div class="label current">Current</div>
                                                    <div class="label requested">Requested</div>
                                                    <div class="label">Full Name</div>
                                                    <div class="current"><?php echo requestValue($currentData, 'full_name'); ?></div>
                                                    <div class="requested"><?php echo requestValue($requestedData, 'full_name'); ?></div>
                                                    <div class="label">Username</div>
                                                    <div class="current"><?php echo requestValue($currentData, 'username'); ?></div>
                                                    <div class="requested"><?php echo requestValue($requestedData, 'username'); ?></div>
                                                    <div class="label">Email</div>
                                                    <div class="current"><?php echo requestValue($currentData, 'email'); ?></div>
                                                    <div class="requested"><?php echo requestValue($requestedData, 'email'); ?></div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($request['reason'] ?: '-'); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo requestStatusBadge($request['status']); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($request['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($isPending): ?>
                                                    <form method="POST" class="mb-2">
                                                        <input type="hidden" name="request_id" value="<?php echo (int) $request['request_id']; ?>">
                                                        <textarea name="review_note" class="form-control form-control-sm mb-2" rows="2" placeholder="Review note"></textarea>
                                                        <button type="submit" name="action" value="approve" class="btn btn-sm btn-success">
                                                            <i class="fas fa-check mr-1"></i>Approve
                                                        </button>
                                                        <button type="submit" name="action" value="reject" class="btn btn-sm btn-outline-danger" onclick="return confirm('Reject this data edit request?');">
                                                            <i class="fas fa-times mr-1"></i>Reject
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <div class="small">
                                                        <?php echo htmlspecialchars($request['reviewer_name'] ?: 'Super Admin'); ?><br>
                                                        <?php echo $request['reviewed_at'] ? date('M d, Y h:i A', strtotime($request['reviewed_at'])) : ''; ?>
                                                    </div>
                                                    <?php if (!empty($request['review_note'])): ?>
                                                        <div class="small text-muted mt-1"><?php echo htmlspecialchars($request['review_note']); ?></div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
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
    $('#requestsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 25,
        autoWidth: false
    });
});
</script>
</body>
</html>
