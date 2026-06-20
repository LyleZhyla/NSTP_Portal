<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: landing_page.php');
    exit();
}

require_once './conn/conn.php';
require_once './include/user-permissions.php';
require_once './include/automatic-sectioning.php';
require_once './include/section-folders.php';

$currentUser = getCurrentUserRecord($conn);
$role = $currentUser['role'] ?? '';
if (!$currentUser || !canAccessStaffTools($role)) {
    header('Location: profile.php');
    exit();
}
ensureRotcAttendanceSchema($conn);

$scope = trim((string) ($_GET['scope'] ?? 'facilitator'));
$folder = trim((string) ($_GET['folder'] ?? ''));
$facilitatorId = (int) ($_GET['facilitator_id'] ?? 0);
$component = normalizeProgram($_GET['component'] ?? null);
$pageTitle = 'Folder Students';
$folderMeta = '';
$students = [];
$facilitatorFolderCards = [];

function folderStudentsTableExists(PDO $conn, $tableName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$tableName]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $error) {
        return false;
    }
}

function attachLatestRegistrationDetails(PDO $conn, array $students) {
    if (empty($students) || !folderStudentsTableExists($conn, 'tbl_public_student_registrations')) {
        return $students;
    }

    $studentNumbers = [];
    foreach ($students as $student) {
        $studentNumber = trim((string) ($student['student_number'] ?? ''));
        if ($studentNumber !== '') {
            $studentNumbers[$studentNumber] = true;
        }
    }

    if (empty($studentNumbers)) {
        return $students;
    }

    $studentNumbers = array_keys($studentNumbers);
    $placeholders = implode(',', array_fill(0, count($studentNumbers), '?'));
    $stmt = $conn->prepare("
        SELECT r.*
        FROM tbl_public_student_registrations r
        INNER JOIN (
            SELECT student_number, MAX(registration_id) AS latest_registration_id
            FROM tbl_public_student_registrations
            WHERE student_number IN ($placeholders)
            GROUP BY student_number
        ) latest ON latest.latest_registration_id = r.registration_id
    ");
    $stmt->execute($studentNumbers);

    $registrations = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $registration) {
        $registrations[(string) $registration['student_number']] = $registration;
    }

    foreach ($students as &$student) {
        $studentNumber = (string) ($student['student_number'] ?? '');
        $student['_registration'] = $registrations[$studentNumber] ?? [];
    }
    unset($student);

    return $students;
}

function detailValue(array $student, $key) {
    $registration = $student['_registration'] ?? [];

    if (array_key_exists($key, $registration)) {
        return $registration[$key];
    }

    return $student[$key] ?? '';
}

function displayDetailValue($value) {
    $value = trim((string) ($value ?? ''));
    return $value === '' ? 'N/A' : $value;
}

try {
    if ($scope === 'component' && $role === 'super_admin') {
        $program = $component;
        if (!$program) {
            throw new RuntimeException('Invalid component folder.');
        }

        if ($program === 'ROTC') {
            $rotcCondition = rotcStudentSqlCondition('s');
            $stmt = $conn->prepare("
                SELECT s.*
                FROM tbl_student s
                LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
                WHERE {$rotcCondition}
                   OR (creator.role = 'facilitator' AND creator.program = 'ROTC')
                ORDER BY
                    COALESCE(NULLIF(creator.full_name, ''), creator.username, 'Pending/System') ASC,
                    s.course_section ASC,
                    s.student_name ASC
            ");
            $stmt->execute();
        } else {
        $stmt = $conn->prepare("
            SELECT s.*
            FROM tbl_student s
            LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
            WHERE (creator.role = 'facilitator' AND creator.program = ?)
               OR (
                    (s.created_by IS NULL OR creator.role <> 'facilitator')
                    AND (s.course_section = ? OR s.course_section LIKE ?)
               )
            ORDER BY
                COALESCE(NULLIF(creator.full_name, ''), creator.username, 'Pending/System') ASC,
                s.course_section ASC,
                s.student_name ASC
        ");
        $stmt->execute([$program, $program, autoSectionFolderPrefix($program) . ' %']);
        }
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pageTitle = $program;
        $folderMeta = 'Component folder';
    } elseif ($scope === 'rotc_all' && $role === 'facilitator') {
        if (normalizeProgram($currentUser['program'] ?? null) !== 'ROTC') {
            throw new RuntimeException('You are not allowed to view ROTC students.');
        }

        $rotcCondition = rotcStudentSqlCondition('s');
        $stmt = $conn->prepare("
            SELECT s.*
            FROM tbl_student s
            WHERE {$rotcCondition}
            ORDER BY s.student_name ASC
        ");
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pageTitle = 'All ROTC Students';
        $folderMeta = 'Visible to all ROTC facilitators';
    } elseif ($scope === 'student_folder' && in_array($role, ['super_admin', 'coordinator'], true)) {
        if ($folder === '') {
            throw new RuntimeException('Invalid student folder.');
        }

        syncSectionFoldersFromExisting($conn);

        $stmt = $conn->prepare("
            SELECT program
            FROM tbl_section_folders
            WHERE course_section = ?
            LIMIT 1
        ");
        $stmt->execute([$folder]);
        $folderProgram = normalizeProgram($stmt->fetchColumn());

        if (!$folderProgram) {
            throw new RuntimeException('Folder not found.');
        }

        if ($role === 'coordinator' && normalizeProgram($currentUser['program'] ?? null) !== $folderProgram) {
            throw new RuntimeException('You are not allowed to view this folder.');
        }

        $stmt = $conn->prepare("
            SELECT s.*
            FROM tbl_student s
            WHERE s.course_section = ?
            ORDER BY s.student_name ASC
        ");
        $stmt->execute([$folder]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pageTitle = $folder;
        $folderMeta = $folderProgram . ' Student Folder';
    } elseif ($scope === 'pending' && $role === 'coordinator') {
        $program = $component ?: normalizeProgram($currentUser['program'] ?? null);
        if (!$program) {
            throw new RuntimeException('Coordinator component is missing.');
        }

        $stmt = $conn->prepare("
            SELECT s.*
            FROM tbl_student s
            LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
            WHERE (s.course_section = ? OR s.course_section LIKE ?)
              AND (
                  s.created_by IS NULL
                  OR creator.role <> 'facilitator'
                  OR creator.program <> ?
              )
            ORDER BY s.student_name ASC
        ");
        $stmt->execute([$program, autoSectionFolderPrefix($program) . ' %', $program]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pageTitle = $program . ' Pending Assignment';
        $folderMeta = 'Students waiting for facilitator folder assignment';
    } elseif ($scope === 'coordinator_facilitator' && $role === 'coordinator') {
        $program = normalizeProgram($currentUser['program'] ?? null);
        if ($facilitatorId <= 0 || !$program) {
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
            throw new RuntimeException('You are not allowed to view this facilitator.');
        }

        $stmt = $conn->prepare("
            SELECT
                ads.admin_section_id,
                ads.course_section,
                COUNT(s.tbl_student_id) AS student_count
            FROM tbl_admin_sections ads
            LEFT JOIN tbl_student s
                ON s.created_by = ads.user_id
               AND s.course_section = ads.course_section
            WHERE ads.user_id = ?
            GROUP BY ads.admin_section_id, ads.course_section
            ORDER BY ads.course_section ASC
        ");
        $stmt->execute([$facilitatorId]);
        $facilitatorFolderCards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $conn->prepare("
            SELECT *
            FROM tbl_student
            WHERE created_by = ?
            ORDER BY course_section ASC, student_name ASC
        ");
        $stmt->execute([$facilitatorId]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $pageTitle = $facilitator['full_name'] ?: $facilitator['username'];
        $folderMeta = $program . ' Facilitator';
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

$students = attachLatestRegistrationDetails($conn, $students);

$detailColumns = [
    'student_name' => 'Student Name',
    'student_number' => 'Student Number',
    'formal_picture' => 'Formal Picture',
    'last_name' => 'Last Name',
    'extension_name' => 'Extension Name',
    'first_name' => 'First Name',
    'middle_name' => 'Middle Name',
    'place_of_birth' => 'Place of Birth',
    'date_of_birth' => 'Date of Birth',
    'gender' => 'Gender',
    'religion' => 'Religion',
    'blood_type' => 'Blood Type',
    'contact_number' => 'Contact Number',
    'email' => 'Email',
    'province' => 'Province',
    'city_municipality' => 'City/Municipality',
    'barangay' => 'Barangay',
    'street' => 'Street',
    'house_no' => 'House No.',
    'emergency_name' => 'Emergency Name',
    'emergency_relationship' => 'Emergency Relationship',
    'emergency_contact_number' => 'Emergency Contact',
    'emergency_address' => 'Emergency Address',
    'college' => 'College',
    'course' => 'Program',
    'major' => 'Major',
    'year_section' => 'Year/Section',
    'component' => 'Component',
    'rotc_ms_level' => 'ROTC MS Level',
    'course_section' => 'Folder Name',
    'generated_code' => 'QR Code',
    'status' => 'Registration Status',
    'created_at' => 'Registered At',
];
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
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .folder-hero {
            background: #fff;
            border: 1px solid #dfe7e2;
            border-radius: 8px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }
        .folder-hero-icon {
            width: 46px;
            height: 46px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f3faf6;
            color: #198754;
            font-size: 1.25rem;
            margin-right: 10px;
        }
        .folder-title-wrap {
            display: flex;
            align-items: center;
            min-width: 0;
        }
        .folder-title-wrap h1 {
            font-size: 1.25rem;
            margin: 0;
            line-height: 1.2;
        }
        .folder-title-wrap p {
            margin: 4px 0 0;
            color: #5f7168;
        }
        .student-count-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #f3faf6;
            color: #198754;
            font-weight: 800;
        }
        .folder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 12px;
        }
        .folder-box {
            display: block;
            height: 100%;
            border: 1px solid #e1e8e4;
            border-radius: 8px;
            padding: 13px 14px;
            color: #1f2937;
            background: #fff;
            transition: transform 0.16s ease, box-shadow 0.16s ease;
        }
        .folder-box:hover {
            color: #1f2937;
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(31, 41, 55, 0.1);
        }
        .folder-box-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f3faf6;
            color: #198754;
            margin-bottom: 8px;
        }
        .folder-box-title {
            display: block;
            font-weight: 800;
            margin-bottom: 6px;
        }
        .folder-box-meta,
        .folder-box-count {
            display: block;
            color: #5f7168;
            font-size: 0.88rem;
        }
        .folder-box-count {
            margin-top: 10px;
            font-weight: 800;
            color: #198754;
        }
        .qr-thumb {
            width: 74px;
            height: 74px;
        }
        .column-picker {
            border-bottom: 1px solid #edf2f5;
            padding: 14px 18px;
            background: #fbfdfe;
        }
        .column-picker-toggle {
            color: #198754;
            font-weight: 800;
            text-decoration: none;
        }
        .column-picker-toggle:hover {
            color: #0f5132;
            text-decoration: none;
        }
        .column-picker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 8px 14px;
        }
        .detail-photo {
            width: 54px;
            height: 54px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #dfe7e2;
            background: #f8fafc;
        }
        .student-detail-table th,
        .student-detail-table td {
            white-space: nowrap;
            vertical-align: middle;
        }
        .student-detail-table td.detail-long {
            min-width: 220px;
            white-space: normal;
        }
        .folder-group-row td {
            background: #f3faf6;
            color: #198754;
            font-weight: 800;
            border-top: 2px solid #d7eadf;
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
                <?php if ($scope === 'coordinator_facilitator' && $role === 'coordinator'): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-folder-tree mr-2"></i>Facilitator Folders</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($facilitatorFolderCards)): ?>
                            <div class="folder-grid">
                                <?php foreach ($facilitatorFolderCards as $folderCard): ?>
                                    <a class="folder-box" href="folder-students.php?scope=coordinator&facilitator_id=<?php echo (int) $facilitatorId; ?>&folder=<?php echo urlencode($folderCard['course_section']); ?>">
                                        <span class="folder-box-icon"><i class="fas fa-folder"></i></span>
                                        <span class="folder-box-title"><?php echo htmlspecialchars($folderCard['course_section']); ?></span>
                                        <span class="folder-box-meta">Open this folder to view only its students.</span>
                                        <span class="folder-box-count"><i class="fas fa-users mr-1"></i><?php echo (int) $folderCard['student_count']; ?> students</span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-2"></i>
                                This facilitator has no assigned folders yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

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
                            <div class="column-picker">
                                <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap: 8px;">
                                    <button type="button"
                                            class="btn btn-link btn-sm p-0 column-picker-toggle"
                                            data-toggle="collapse"
                                            data-target="#visibleDetailsPanel"
                                            aria-expanded="false"
                                            aria-controls="visibleDetailsPanel">
                                        <i class="fas fa-chevron-right mr-1" id="visibleDetailsIcon"></i>
                                        <i class="fas fa-columns mr-1"></i> Visible Details
                                    </button>
                                    <div>
                                        <button type="button" class="btn btn-xs btn-outline-primary" id="showAllColumns">Show All</button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary" id="hideOptionalColumns">Basic Only</button>
                                    </div>
                                </div>
                                <div class="collapse mt-3" id="visibleDetailsPanel">
                                    <div class="column-picker-grid">
                                        <?php foreach ($detailColumns as $columnKey => $columnLabel): ?>
                                            <div class="form-check mb-0">
                                                <input class="form-check-input detail-column-toggle"
                                                       type="checkbox"
                                                       value="<?php echo htmlspecialchars($columnKey); ?>"
                                                       id="toggle_<?php echo htmlspecialchars($columnKey); ?>"
                                                       checked>
                                                <label class="form-check-label" for="toggle_<?php echo htmlspecialchars($columnKey); ?>">
                                                    <?php echo htmlspecialchars($columnLabel); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 student-detail-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 70px;">No.</th>
                                            <?php foreach ($detailColumns as $columnKey => $columnLabel): ?>
                                                <th class="detail-col detail-col-<?php echo htmlspecialchars($columnKey); ?>" data-column="<?php echo htmlspecialchars($columnKey); ?>">
                                                    <?php echo htmlspecialchars($columnLabel); ?>
                                                </th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $index => $student): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <?php foreach ($detailColumns as $columnKey => $columnLabel): ?>
                                                    <?php
                                                        $value = detailValue($student, $columnKey);
                                                        $displayValue = displayDetailValue($value);
                                                        $longColumns = ['street', 'emergency_address', 'course', 'college'];
                                                        $cellClass = in_array($columnKey, $longColumns, true) ? ' detail-long' : '';
                                                    ?>
                                                    <td class="detail-col detail-col-<?php echo htmlspecialchars($columnKey); ?><?php echo $cellClass; ?>" data-column="<?php echo htmlspecialchars($columnKey); ?>">
                                                        <?php if ($columnKey === 'formal_picture'): ?>
                                                            <?php if ($displayValue !== 'N/A'): ?>
                                                                <a href="<?php echo htmlspecialchars($displayValue); ?>" target="_blank">
                                                                    <img class="detail-photo" src="<?php echo htmlspecialchars($displayValue); ?>" alt="Formal Picture">
                                                                </a>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        <?php elseif ($columnKey === 'generated_code'): ?>
                                                            <?php if ($displayValue !== 'N/A'): ?>
                                                                <button type="button" class="btn btn-link p-0 d-inline-flex align-items-center"
                                                                        onclick="showFolderStudentQrModal(<?= htmlspecialchars(json_encode($displayValue), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($student['student_name'] ?? 'Student'), ENT_QUOTES, 'UTF-8') ?>)">
                                                                    <img class="qr-thumb" src="https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=<?php echo urlencode($displayValue); ?>" alt="QR">
                                                                </button>
                                                                <code class="ml-2"><?php echo htmlspecialchars($displayValue); ?></code>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        <?php elseif ($columnKey === 'course_section'): ?>
                                                            <span class="badge badge-info"><?php echo htmlspecialchars($displayValue); ?></span>
                                                        <?php elseif ($columnKey === 'rotc_ms_level'): ?>
                                                            <span class="badge badge-warning"><?php echo htmlspecialchars($displayValue); ?></span>
                                                        <?php elseif ($columnKey === 'student_number'): ?>
                                                            <code><?php echo htmlspecialchars($displayValue); ?></code>
                                                        <?php else: ?>
                                                            <?php echo htmlspecialchars($displayValue); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
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

<div class="modal fade" id="folderStudentQrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-qrcode mr-2"></i>
                    <span id="folderStudentQrModalTitle">Student QR</span>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body text-center">
                <img id="folderStudentQrModalImg" class="img-thumbnail mb-3" src="" alt="Student QR Code" style="max-width: 300px;">
                <div>
                    <code id="folderStudentQrModalCode"></code>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script>
$(function() {
    const basicColumns = new Set(['student_name', 'student_number', 'formal_picture', 'component', 'rotc_ms_level', 'course_section', 'generated_code']);
    const studentDetailTable = $('.student-detail-table').length
        ? $('.student-detail-table').DataTable({
            paging: true,
            lengthChange: true,
            searching: true,
            ordering: true,
            info: true,
            autoWidth: false,
            responsive: false,
            pageLength: 25,
            order: [[1, 'asc']]
        })
        : null;

    function setColumnVisible(columnKey, visible) {
        $('.detail-col-' + columnKey).toggle(visible);
        if (studentDetailTable) {
            studentDetailTable.columns.adjust();
        }
    }

    $('.detail-column-toggle').on('change', function() {
        setColumnVisible(this.value, this.checked);
    });

    $('#showAllColumns').on('click', function() {
        $('.detail-column-toggle').prop('checked', true).each(function() {
            setColumnVisible(this.value, true);
        });
    });

    $('#hideOptionalColumns').on('click', function() {
        $('.detail-column-toggle').each(function() {
            const visible = basicColumns.has(this.value);
            this.checked = visible;
            setColumnVisible(this.value, visible);
        });
    });

    $('#visibleDetailsPanel')
        .on('show.bs.collapse', function() {
            $('#visibleDetailsIcon').removeClass('fa-chevron-right').addClass('fa-chevron-down');
        })
        .on('hide.bs.collapse', function() {
            $('#visibleDetailsIcon').removeClass('fa-chevron-down').addClass('fa-chevron-right');
        });
});

function getFolderStudentQrImageUrl(qrCode, size) {
    return `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&data=${encodeURIComponent(qrCode)}`;
}

function showFolderStudentQrModal(qrCode, studentName = 'Student') {
    const safeCode = String(qrCode || '').trim();
    const safeName = String(studentName || 'Student').trim();

    if (!safeCode) {
        return;
    }

    document.getElementById('folderStudentQrModalTitle').textContent = `${safeName} QR`;
    document.getElementById('folderStudentQrModalCode').textContent = safeCode;
    document.getElementById('folderStudentQrModalImg').src = getFolderStudentQrImageUrl(safeCode, 260);
    $('#folderStudentQrModal').modal('show');
}
</script>
</body>
</html>
