<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: landing_page.php');
    exit();
}

require_once './conn/conn.php';
require_once './include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
$role = $currentUser['role'] ?? '';
if (!$currentUser || !canAccessStaffTools($role)) {
    header('Location: profile.php');
    exit();
}

$scope = trim((string) ($_GET['scope'] ?? 'facilitator'));
$folder = trim((string) ($_GET['folder'] ?? ''));
$facilitatorId = (int) ($_GET['facilitator_id'] ?? 0);
$component = normalizeProgram($_GET['component'] ?? null);
$pageTitle = 'Folder Students';
$folderMeta = '';
$students = [];

try {
    if ($scope === 'pending' && $role === 'coordinator') {
        $program = $component ?: normalizeProgram($currentUser['program'] ?? null);
        if (!$program) {
            throw new RuntimeException('Coordinator component is missing.');
        }

        $stmt = $conn->prepare("
            SELECT s.*
            FROM tbl_student s
            LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
            WHERE s.course_section = ?
              AND (
                  s.created_by IS NULL
                  OR creator.role <> 'facilitator'
                  OR creator.program <> ?
              )
            ORDER BY s.student_name ASC
        ");
        $stmt->execute([$program, $program]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pageTitle = $program . ' Pending Assignment';
        $folderMeta = 'Students waiting for facilitator folder assignment';
    } elseif ($scope === 'coordinator' && $role === 'coordinator') {
        $program = normalizeProgram($currentUser['program'] ?? null);
        if ($facilitatorId <= 0 || $folder === '' || !$program) {
            throw new RuntimeException('Invalid facilitator folder.');
        }

        $stmt = $conn->prepare("
            SELECT full_name, username
            FROM tbl_users
            WHERE user_id = ? AND role = 'facilitator' AND program = ?
        ");
        $stmt->execute([$facilitatorId, $program]);
        $facilitator = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$facilitator) {
            throw new RuntimeException('You are not allowed to view this facilitator folder.');
        }

        $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_admin_sections WHERE user_id = ? AND course_section = ?");
        $stmt->execute([$facilitatorId, $folder]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Folder is not assigned to this facilitator.');
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM tbl_student
            WHERE created_by = ? AND course_section = ?
            ORDER BY student_name ASC
        ");
        $stmt->execute([$facilitatorId, $folder]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pageTitle = $folder;
        $folderMeta = 'Facilitator: ' . ($facilitator['full_name'] ?: $facilitator['username']);
    } elseif ($scope === 'facilitator' && $role === 'facilitator') {
        if ($folder === '') {
            throw new RuntimeException('Invalid folder.');
        }

        $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_admin_sections WHERE user_id = ? AND course_section = ?");
        $stmt->execute([$_SESSION['user_id'], $folder]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('You are not allowed to view this folder.');
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM tbl_student
            WHERE created_by = ? AND course_section = ?
            ORDER BY student_name ASC
        ");
        $stmt->execute([$_SESSION['user_id'], $folder]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pageTitle = $folder;
        $folderMeta = 'Your facilitator folder';
    } elseif ($scope === 'super_facilitator' && $role === 'super_admin') {
        if ($facilitatorId <= 0 || $folder === '') {
            throw new RuntimeException('Invalid facilitator folder.');
        }

        $stmt = $conn->prepare("
            SELECT full_name, username, program
            FROM tbl_users
            WHERE user_id = ? AND role = 'facilitator'
        ");
        $stmt->execute([$facilitatorId]);
        $facilitator = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$facilitator) {
            throw new RuntimeException('Facilitator folder not found.');
        }

        $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_admin_sections WHERE user_id = ? AND course_section = ?");
        $stmt->execute([$facilitatorId, $folder]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new RuntimeException('Folder is not assigned to this facilitator.');
        }

        $stmt = $conn->prepare("
            SELECT *
            FROM tbl_student
            WHERE created_by = ? AND course_section = ?
            ORDER BY student_name ASC
        ");
        $stmt->execute([$facilitatorId, $folder]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pageTitle = $folder;
        $folderMeta = ($facilitator['program'] ?: 'NSTP') . ' / Facilitator: ' . ($facilitator['full_name'] ?: $facilitator['username']);
    } elseif ($scope === 'system' && $role === 'super_admin') {
        if ($folder === '') {
            throw new RuntimeException('Invalid system folder.');
        }

        $stmt = $conn->prepare("
            SELECT s.*
            FROM tbl_student s
            LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
            WHERE COALESCE(NULLIF(s.course_section, ''), 'Unassigned') = ?
              AND (s.created_by IS NULL OR creator.role <> 'facilitator')
            ORDER BY s.student_name ASC
        ");
        $stmt->execute([$folder]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pageTitle = $folder;
        $folderMeta = 'System / public registration folder';
    } else {
        throw new RuntimeException('This folder view is not available for your account.');
    }
} catch (Throwable $error) {
    $pageTitle = 'Folder Unavailable';
    $folderMeta = $error->getMessage();
    $students = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle); ?> - TAU NSTP</title>
    <?php include('./include/theme-loader.php'); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .folder-hero {
            background: #fff;
            border: 1px solid #d7e4ea;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }
        .folder-hero-icon {
            width: 56px;
            height: 56px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eef7f9;
            color: #2f6f7e;
            font-size: 1.6rem;
            margin-right: 14px;
        }
        .folder-title-wrap {
            display: flex;
            align-items: center;
            min-width: 0;
        }
        .folder-title-wrap h1 {
            font-size: 1.45rem;
            margin: 0;
            line-height: 1.2;
        }
        .folder-title-wrap p {
            margin: 4px 0 0;
            color: #667784;
        }
        .student-count-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #f4f8fa;
            color: #2f6f7e;
            font-weight: 800;
        }
        .qr-thumb {
            width: 74px;
            height: 74px;
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
        <section class="content-header">
            <div class="container-fluid">
                <div class="folder-hero">
                    <div class="folder-title-wrap">
                        <span class="folder-hero-icon"><i class="fas fa-folder-open"></i></span>
                        <div>
                            <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
                            <p><?php echo htmlspecialchars($folderMeta); ?></p>
                        </div>
                    </div>
                    <div class="d-flex align-items-center flex-wrap" style="gap: 8px;">
                        <span class="student-count-pill"><i class="fas fa-users"></i><?php echo count($students); ?> students</span>
                        <?php if ($scope === 'facilitator' && $role === 'facilitator' && $folder !== ''): ?>
                            <a class="btn btn-sm btn-success" href="./endpoint/export-qr-zip.php?section=<?php echo urlencode($folder); ?>" target="_blank">
                                <i class="fas fa-file-archive mr-1"></i> Export ZIP
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-user-graduate mr-2"></i>Student List</h3>
                        <div class="card-tools">
                            <a href="masterlist.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-arrow-left mr-1"></i> Back to Folders
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (!empty($students)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width: 70px;">No.</th>
                                            <th>Student Name</th>
                                            <th>Student Number</th>
                                            <th>Original Section</th>
                                            <th>Folder</th>
                                            <th>QR</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $index => $student): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                                                <td><code><?php echo htmlspecialchars($student['student_number'] ?: 'N/A'); ?></code></td>
                                                <td><?php echo htmlspecialchars($student['original_section'] ?: 'N/A'); ?></td>
                                                <td><span class="badge badge-info"><?php echo htmlspecialchars($student['course_section']); ?></span></td>
                                                <td>
                                                    <img class="qr-thumb" src="https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=<?php echo urlencode($student['generated_code']); ?>" alt="QR">
                                                    <code class="ml-2"><?php echo htmlspecialchars($student['generated_code']); ?></code>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state text-center p-5">
                                <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                                <h5>No students in this folder yet</h5>
                                <p class="text-muted mb-0">Assigned students will appear here.</p>
                            </div>
                        <?php endif; ?>
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
</body>
</html>
