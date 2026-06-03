<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: landing_page.php");
    exit();
}

date_default_timezone_set('Asia/Manila');
include('./conn/conn.php');
require_once './include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !in_array($currentUser['role'] ?? '', ['super_admin', 'coordinator', 'facilitator'], true)) {
    header("Location: index.php");
    exit();
}

$role = $currentUser['role'];
$today = date('Y-m-d');
$defaultStartDate = date('Y-m-d', strtotime('-7 days'));
$program = normalizeProgram($currentUser['program'] ?? ($_SESSION['program'] ?? null));

function downloadablesColumnExists(PDO $conn, $tableName, $columnName) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$tableName, $columnName]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $error) {
        return false;
    }
}

function downloadablesPublicScope(array $currentUser, $program) {
    $role = $currentUser['role'] ?? '';
    $where = ["r.registrant_role = 'student'"];
    $params = [];

    if ($role === 'coordinator') {
        $where[] = 'r.component = ?';
        $params[] = $program;
    } elseif ($role === 'facilitator') {
        $where[] = "
            EXISTS (
                SELECT 1
                FROM tbl_student s
                LEFT JOIN tbl_admin_sections ads
                    ON ads.course_section = s.course_section
                   AND ads.user_id = ?
                WHERE s.student_number = r.student_number
                  AND (s.created_by = ? OR ads.admin_section_id IS NOT NULL)
            )
        ";
        $params[] = (int) $currentUser['user_id'];
        $params[] = (int) $currentUser['user_id'];
    }

    return [$where, $params];
}

function downloadablesApplyPublicFilters(array $baseWhere, array $baseParams, array $filters, array $filterColumns) {
    $where = $baseWhere;
    $params = $baseParams;

    if (!empty($filters['component'])) {
        $where[] = 'r.component = ?';
        $params[] = $filters['component'];
    }

    foreach ($filterColumns as $filterKey => $columnName) {
        if (!$columnName || empty($filters[$filterKey])) {
            continue;
        }

        $where[] = "r.$columnName = ?";
        $params[] = $filters[$filterKey];
    }

    return [$where, $params];
}

function downloadablesGroupedData(PDO $conn, $expression, array $where, array $params, $limit = 12) {
    $stmt = $conn->prepare("
        SELECT COALESCE(NULLIF(TRIM($expression), ''), 'N/A') AS label, COUNT(*) AS total
        FROM tbl_public_student_registrations r
        WHERE " . implode(' AND ', $where) . "
        GROUP BY label
        ORDER BY total DESC, label ASC
        LIMIT " . (int) $limit . "
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function downloadablesFilterOptions(PDO $conn, $columnName, array $where, array $params) {
    if (!$columnName) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT DISTINCT TRIM(r.$columnName) AS option_value
        FROM tbl_public_student_registrations r
        WHERE " . implode(' AND ', $where) . "
          AND r.$columnName IS NOT NULL
          AND TRIM(r.$columnName) <> ''
        ORDER BY option_value ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$hasGenderColumn = downloadablesColumnExists($conn, 'tbl_public_student_registrations', 'gender');
$filterColumns = [
    'gender' => $hasGenderColumn ? 'gender' : null,
    'course' => 'course',
    'college' => 'college',
    'province' => 'province',
];

$selectedFilters = [
    'component' => normalizeProgram($_GET['component'] ?? null),
    'gender' => trim((string) ($_GET['gender'] ?? '')),
    'course' => trim((string) ($_GET['course'] ?? '')),
    'college' => trim((string) ($_GET['college'] ?? '')),
    'province' => trim((string) ($_GET['province'] ?? '')),
];
$availableGraphTypes = [
    'component' => 'Enrollees per Component',
    'gender' => 'Enrollees by Gender',
    'course' => 'Top Courses',
    'college' => 'Top Colleges',
    'province' => 'Top Provinces',
];
if (!$hasGenderColumn) {
    unset($availableGraphTypes['gender']);
}
$selectedGraph = $_GET['graph'] ?? 'component';
if (!isset($availableGraphTypes[$selectedGraph])) {
    $selectedGraph = 'component';
}

if ($role === 'coordinator') {
    $selectedFilters['component'] = null;
}

if ($role === 'facilitator' && $program && $selectedFilters['component'] && $selectedFilters['component'] !== $program) {
    $selectedFilters['component'] = null;
}

[$publicScopeWhere, $publicScopeParams] = downloadablesPublicScope($currentUser, $program);
[$chartWhere, $chartParams] = downloadablesApplyPublicFilters($publicScopeWhere, $publicScopeParams, $selectedFilters, $filterColumns);

$totalEnrollmentStmt = $conn->prepare("
    SELECT COUNT(*)
    FROM tbl_public_student_registrations r
    WHERE " . implode(' AND ', $chartWhere)
);
$totalEnrollmentStmt->execute($chartParams);
$filteredEnrollmentTotal = (int) $totalEnrollmentStmt->fetchColumn();

$chartData = [
    'components' => downloadablesGroupedData($conn, 'r.component', $chartWhere, $chartParams),
    'gender' => $hasGenderColumn ? downloadablesGroupedData($conn, 'r.gender', $chartWhere, $chartParams) : [],
    'course' => downloadablesGroupedData($conn, 'r.course', $chartWhere, $chartParams),
    'college' => downloadablesGroupedData($conn, 'r.college', $chartWhere, $chartParams),
    'province' => downloadablesGroupedData($conn, 'r.province', $chartWhere, $chartParams),
];

$filterOptions = [
    'components' => downloadablesFilterOptions($conn, 'component', $publicScopeWhere, $publicScopeParams),
    'gender' => downloadablesFilterOptions($conn, $filterColumns['gender'], $publicScopeWhere, $publicScopeParams),
    'course' => downloadablesFilterOptions($conn, $filterColumns['course'], $publicScopeWhere, $publicScopeParams),
    'college' => downloadablesFilterOptions($conn, $filterColumns['college'], $publicScopeWhere, $publicScopeParams),
    'province' => downloadablesFilterOptions($conn, $filterColumns['province'], $publicScopeWhere, $publicScopeParams),
];

$formsStmt = $conn->prepare("
    SELECT DISTINCT COALESCE(NULLIF(f.form_title, ''), 'Default Public Registration') AS form_title
    FROM tbl_public_student_registrations r
    LEFT JOIN tbl_public_registration_forms f ON r.form_id = f.form_id
    WHERE r.registrant_role = 'student'
    ORDER BY form_title ASC
");
$formsStmt->execute();
$publicForms = $formsStmt->fetchAll(PDO::FETCH_COLUMN);

if ($role === 'super_admin') {
    $facilitatorStmt = $conn->prepare("
        SELECT
            u.user_id,
            u.full_name,
            u.username,
            u.program,
            COUNT(DISTINCT s.tbl_student_id) AS student_count
        FROM tbl_users u
        LEFT JOIN tbl_student s ON s.created_by = u.user_id
        WHERE u.role = 'facilitator'
        GROUP BY u.user_id, u.full_name, u.username, u.program
        ORDER BY FIELD(u.program, 'CWTS', 'LTS', 'ROTC'), u.full_name ASC, u.username ASC
    ");
    $facilitatorStmt->execute();
} elseif ($role === 'coordinator') {
    $facilitatorStmt = $conn->prepare("
        SELECT
            u.user_id,
            u.full_name,
            u.username,
            u.program,
            COUNT(DISTINCT s.tbl_student_id) AS student_count
        FROM tbl_users u
        LEFT JOIN tbl_student s ON s.created_by = u.user_id
        WHERE u.role = 'facilitator' AND u.program = ?
        GROUP BY u.user_id, u.full_name, u.username, u.program
        ORDER BY u.full_name ASC, u.username ASC
    ");
    $facilitatorStmt->execute([$program]);
} else {
    $facilitatorStmt = $conn->prepare("
        SELECT
            u.user_id,
            u.full_name,
            u.username,
            u.program,
            COUNT(DISTINCT s.tbl_student_id) AS student_count
        FROM tbl_users u
        LEFT JOIN tbl_student s ON s.created_by = u.user_id
        WHERE u.role = 'facilitator' AND u.user_id = ?
        GROUP BY u.user_id, u.full_name, u.username, u.program
        ORDER BY u.full_name ASC, u.username ASC
    ");
    $facilitatorStmt->execute([(int) $currentUser['user_id']]);
}
$facilitators = $facilitatorStmt->fetchAll(PDO::FETCH_ASSOC);

$facilitatorIds = array_map(fn($row) => (int) $row['user_id'], $facilitators);
$sectionsByFacilitator = [];
if (!empty($facilitatorIds)) {
    $placeholders = implode(',', array_fill(0, count($facilitatorIds), '?'));
    $sectionsStmt = $conn->prepare("
        SELECT user_id, course_section
        FROM tbl_admin_sections
        WHERE user_id IN ($placeholders)
        ORDER BY course_section ASC
    ");
    $sectionsStmt->execute($facilitatorIds);
    foreach ($sectionsStmt->fetchAll(PDO::FETCH_ASSOC) as $sectionRow) {
        $sectionsByFacilitator[(int) $sectionRow['user_id']][] = $sectionRow['course_section'];
    }
}

$downloadStats = [
    'students' => 0,
    'attendance' => 0,
    'archived' => 0,
    'public_registrations' => 0,
];

if ($role === 'super_admin') {
    $downloadStats['students'] = (int) $conn->query("SELECT COUNT(*) FROM tbl_student")->fetchColumn();
    $downloadStats['attendance'] = (int) $conn->query("SELECT COUNT(*) FROM tbl_attendance")->fetchColumn();
    $downloadStats['archived'] = (int) $conn->query("SELECT COUNT(*) FROM tbl_attendance_archive")->fetchColumn();
    $downloadStats['public_registrations'] = (int) $conn->query("SELECT COUNT(*) FROM tbl_public_student_registrations WHERE registrant_role = 'student'")->fetchColumn();
} elseif ($role === 'coordinator') {
    $statsStmt = $conn->prepare("
        SELECT COUNT(DISTINCT s.tbl_student_id)
        FROM tbl_student s
        LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
        WHERE (creator.role = 'facilitator' AND creator.program = ?)
           OR s.course_section = ?
    ");
    $statsStmt->execute([$program, $program]);
    $downloadStats['students'] = (int) $statsStmt->fetchColumn();

    $statsStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_attendance a
        INNER JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id
        LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
        WHERE (creator.role = 'facilitator' AND creator.program = ?)
           OR s.course_section = ?
    ");
    $statsStmt->execute([$program, $program]);
    $downloadStats['attendance'] = (int) $statsStmt->fetchColumn();

    $statsStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_attendance_archive a
        INNER JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id
        LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
        WHERE (creator.role = 'facilitator' AND creator.program = ?)
           OR s.course_section = ?
    ");
    $statsStmt->execute([$program, $program]);
    $downloadStats['archived'] = (int) $statsStmt->fetchColumn();

    $statsStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_public_student_registrations
        WHERE registrant_role = 'student' AND component = ?
    ");
    $statsStmt->execute([$program]);
    $downloadStats['public_registrations'] = (int) $statsStmt->fetchColumn();
} else {
    $statsStmt = $conn->prepare("SELECT COUNT(DISTINCT tbl_student_id) FROM tbl_student WHERE created_by = ?");
    $statsStmt->execute([(int) $currentUser['user_id']]);
    $downloadStats['students'] = (int) $statsStmt->fetchColumn();

    $statsStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_attendance a
        INNER JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id
        WHERE s.created_by = ?
    ");
    $statsStmt->execute([(int) $currentUser['user_id']]);
    $downloadStats['attendance'] = (int) $statsStmt->fetchColumn();

    $statsStmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_attendance_archive a
        INNER JOIN tbl_student s ON s.tbl_student_id = a.tbl_student_id
        WHERE s.created_by = ?
    ");
    $statsStmt->execute([(int) $currentUser['user_id']]);
    $downloadStats['archived'] = (int) $statsStmt->fetchColumn();
    $downloadStats['public_registrations'] = $filteredEnrollmentTotal;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Downloadables - TAU-NSTP</title>
    <?php include('./include/theme-loader.php'); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .download-card {
            border-top: 3px solid #0d6efd;
            height: 100%;
        }
        .download-card .card-body {
            display: flex;
            flex-direction: column;
        }
        .download-card .btn {
            margin-top: auto;
        }
        .stat-tile {
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 84px;
            padding: 14px;
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 8px;
            background: #fff;
        }
        .stat-tile i {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eef5ff;
            color: #0d6efd;
        }
        .stat-tile strong {
            display: block;
            font-size: 1.35rem;
            line-height: 1;
        }
        .muted-note {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .chart-panel {
            min-height: 360px;
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 8px;
            padding: 14px;
            background: #fff;
        }
        .chart-panel canvas {
            height: 260px !important;
            min-height: 260px;
        }
        .chart-panel-title {
            font-weight: 600;
            margin-bottom: 10px;
        }
        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: flex-end;
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
            <?php include('./include/theme-toggle.php'); ?>
        </ul>
    </nav>

    <?php include 'adminlte-sidebar.php'; ?>

    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0"><i class="fas fa-download mr-2"></i>Downloadables</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                            <li class="breadcrumb-item active">Downloadables</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-tile">
                            <i class="fas fa-user-graduate"></i>
                            <div><strong><?php echo number_format($downloadStats['students']); ?></strong><span>Student Records</span></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-tile">
                            <i class="fas fa-clipboard-check"></i>
                            <div><strong><?php echo number_format($downloadStats['attendance']); ?></strong><span>Attendance Logs</span></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-tile">
                            <i class="fas fa-archive"></i>
                            <div><strong><?php echo number_format($downloadStats['archived']); ?></strong><span>Archived Logs</span></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-tile">
                            <i class="fas fa-file-signature"></i>
                            <div><strong><?php echo number_format($downloadStats['public_registrations']); ?></strong><span>Public Registrations</span></div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-column mr-2"></i>Enrollment Graphs</h3>
                    </div>
                    <div class="card-body">
                        <form method="get" class="mb-3">
                            <div class="form-row">
                                <div class="form-group col-md-3">
                                    <label for="graphFilter">Graph to Show</label>
                                    <select class="form-control" id="graphFilter" name="graph">
                                        <?php foreach ($availableGraphTypes as $graphKey => $graphLabel): ?>
                                        <option value="<?php echo htmlspecialchars($graphKey); ?>" <?php echo $selectedGraph === $graphKey ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($graphLabel); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php if ($role === 'super_admin' || $role === 'facilitator'): ?>
                                <div class="form-group col-md-2">
                                    <label for="componentFilter">Component</label>
                                    <select class="form-control" id="componentFilter" name="component">
                                        <option value="">All Components</option>
                                        <?php foreach ($filterOptions['components'] as $componentOption): ?>
                                            <?php $normalizedComponentOption = normalizeProgram($componentOption); ?>
                                            <?php if (!$normalizedComponentOption) { continue; } ?>
                                            <option value="<?php echo htmlspecialchars($normalizedComponentOption); ?>" <?php echo $selectedFilters['component'] === $normalizedComponentOption ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($normalizedComponentOption); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <?php if ($hasGenderColumn): ?>
                                <div class="form-group col-md-2">
                                    <label for="genderFilter">Gender</label>
                                    <select class="form-control" id="genderFilter" name="gender">
                                        <option value="">All Gender</option>
                                        <?php foreach ($filterOptions['gender'] as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $selectedFilters['gender'] === $option ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($option); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="form-group col-md-3">
                                    <label for="collegeFilter">College</label>
                                    <select class="form-control" id="collegeFilter" name="college">
                                        <option value="">All Colleges</option>
                                        <?php foreach ($filterOptions['college'] as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $selectedFilters['college'] === $option ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($option); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-3">
                                    <label for="courseFilter">Course</label>
                                    <select class="form-control" id="courseFilter" name="course">
                                        <option value="">All Courses</option>
                                        <?php foreach ($filterOptions['course'] as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $selectedFilters['course'] === $option ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($option); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="provinceFilter">Province</label>
                                    <select class="form-control" id="provinceFilter" name="province">
                                        <option value="">All Provinces</option>
                                        <?php foreach ($filterOptions['province'] as $option): ?>
                                        <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $selectedFilters['province'] === $option ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($option); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="filter-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter mr-1"></i> Apply Filters
                                </button>
                                <a href="downloadables.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-rotate-left mr-1"></i> Reset
                                </a>
                                <span class="muted-note ml-auto"><?php echo number_format($filteredEnrollmentTotal); ?> enrollment record(s) found</span>
                            </div>
                        </form>

                        <div class="row">
                            <div class="col-lg-8 mb-3">
                                <div class="chart-panel">
                                    <div class="chart-panel-title"><?php echo htmlspecialchars($availableGraphTypes[$selectedGraph]); ?></div>
                                    <canvas id="selectedEnrollmentChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6 col-xl-4 mb-3">
                        <form class="card download-card" method="get" action="endpoint/download-attendance-excel.php">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-file-excel mr-2"></i>Attendance Report</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label for="attendanceDate">Attendance Date</label>
                                    <input type="date" class="form-control" id="attendanceDate" name="date" value="<?php echo htmlspecialchars($today); ?>">
                                </div>
                                <p class="muted-note">Exports the daily student attendance report in Excel format.</p>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-download mr-1"></i> Download Attendance
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="col-lg-6 col-xl-4 mb-3">
                        <form class="card download-card" method="get" action="endpoint/download-archived-attendance.php">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-archive mr-2"></i>Archived Attendance</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-row">
                                    <div class="form-group col-md-6">
                                        <label for="archiveStartDate">Start Date</label>
                                        <input type="date" class="form-control" id="archiveStartDate" name="start_date" value="<?php echo htmlspecialchars($defaultStartDate); ?>">
                                    </div>
                                    <div class="form-group col-md-6">
                                        <label for="archiveEndDate">End Date</label>
                                        <input type="date" class="form-control" id="archiveEndDate" name="end_date" value="<?php echo htmlspecialchars($today); ?>">
                                    </div>
                                </div>
                                <p class="muted-note">Exports archived attendance records for the selected date range.</p>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-download mr-1"></i> Download Archive
                                </button>
                            </div>
                        </form>
                    </div>

                    <?php if (in_array($role, ['super_admin', 'coordinator'], true)): ?>
                    <div class="col-lg-6 col-xl-4 mb-3">
                        <form class="card download-card" method="get" action="endpoint/download-public-registration-attendance.php">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-clipboard-list mr-2"></i>Public Registration Attendance</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label for="publicDate">Registration Date</label>
                                    <input type="date" class="form-control" id="publicDate" name="date" value="<?php echo htmlspecialchars($today); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="publicFormTitle">Form</label>
                                    <select class="form-control" id="publicFormTitle" name="form_title">
                                        <option value="">All Forms</option>
                                        <?php foreach ($publicForms as $formTitle): ?>
                                        <option value="<?php echo htmlspecialchars($formTitle); ?>"><?php echo htmlspecialchars($formTitle); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php if ($role === 'super_admin'): ?>
                                <div class="form-group">
                                    <label for="publicComponent">Component</label>
                                    <select class="form-control" id="publicComponent" name="component">
                                        <option value="">All Components</option>
                                        <option value="CWTS">CWTS</option>
                                        <option value="LTS">LTS</option>
                                        <option value="ROTC">ROTC</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-download mr-1"></i> Download Public Attendance
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if ($role === 'coordinator' && in_array($program, ['CWTS', 'LTS'], true)): ?>
                    <div class="col-lg-6 col-xl-4 mb-3">
                        <form class="card download-card" method="get" action="endpoint/download-ched-serial-number.php">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-file-excel mr-2"></i>CHED Serial Number</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label>NSTP Component</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($program); ?>" disabled>
                                </div>
                                <p class="muted-note">Exports all students in the selected LTS/CWTS component using the CHED serial number template.</p>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-download mr-1"></i> Download CHED Format
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div class="col-lg-6 col-xl-4 mb-3">
                        <div class="card download-card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-qrcode mr-2"></i>QR Codes ZIP</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label for="qrAdminId">Facilitator</label>
                                    <select class="form-control" id="qrAdminId">
                                        <option value="">Select Facilitator</option>
                                        <?php foreach ($facilitators as $facilitator): ?>
                                        <option value="<?php echo (int) $facilitator['user_id']; ?>">
                                            <?php echo htmlspecialchars(($facilitator['program'] ? $facilitator['program'] . ' - ' : '') . (trim($facilitator['full_name'] ?? '') ?: $facilitator['username']) . ' (' . (int) $facilitator['student_count'] . ' students)'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="qrSection">Folder</label>
                                    <select class="form-control" id="qrSection" disabled>
                                        <option value="">All Folders</option>
                                    </select>
                                </div>
                                <p class="muted-note">Downloads individual QR images, a viewer file, and student info as one ZIP.</p>
                                <button type="button" class="btn btn-info btn-block mb-2" id="previewQrBtn">
                                    <i class="fas fa-eye mr-1"></i> Preview QR Export
                                </button>
                                <button type="button" class="btn btn-success btn-block" id="downloadQrBtn">
                                    <i class="fas fa-file-archive mr-1"></i> Download QR ZIP
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php if ($role === 'super_admin'): ?>
                    <div class="col-lg-6 col-xl-4 mb-3">
                        <div class="card download-card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-database mr-2"></i>Database Backup</h3>
                            </div>
                            <div class="card-body">
                                <p class="muted-note">Downloads a SQL backup of the system database.</p>
                                <a href="endpoint/backup-database.php" class="btn btn-danger btn-block">
                                    <i class="fas fa-file-download mr-1"></i> Download SQL Backup
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const sectionsByFacilitator = <?php echo json_encode($sectionsByFacilitator); ?>;
const enrollmentCharts = <?php echo json_encode($chartData); ?>;
const selectedEnrollmentGraph = <?php echo json_encode($selectedGraph); ?>;
const chartPalette = ['#0d6efd', '#198754', '#dc3545', '#ffc107', '#6f42c1', '#20c997', '#fd7e14', '#0dcaf0', '#6c757d', '#d63384', '#2f6f7e', '#495057'];

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function chartLabels(rows) {
    return rows.map(row => row.label || 'N/A');
}

function chartTotals(rows) {
    return rows.map(row => Number(row.total || 0));
}

function renderEnrollmentChart(canvasId, rows, type) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') {
        return;
    }

    const labels = chartLabels(rows);
    const totals = chartTotals(rows);
    if (!labels.length) {
        const context = canvas.getContext('2d');
        context.font = '14px Source Sans Pro, Arial, sans-serif';
        context.fillStyle = '#6c757d';
        context.fillText('No enrollment data available for the selected filters.', 12, 36);
        return;
    }

    new Chart(canvas, {
        type: type,
        data: {
            labels: labels,
            datasets: [{
                label: 'Enrollees',
                data: totals,
                backgroundColor: labels.map((_, index) => chartPalette[index % chartPalette.length]),
                borderColor: '#ffffff',
                borderWidth: type === 'doughnut' ? 2 : 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: type === 'doughnut',
                    position: 'bottom'
                }
            },
            scales: type === 'doughnut' ? {} : {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            }
        }
    });
}

const selectedChartDataKey = selectedEnrollmentGraph === 'component' ? 'components' : selectedEnrollmentGraph;
renderEnrollmentChart(
    'selectedEnrollmentChart',
    enrollmentCharts[selectedChartDataKey] || [],
    selectedEnrollmentGraph === 'component' ? 'doughnut' : 'bar'
);

function buildQrParams() {
    const adminId = document.getElementById('qrAdminId').value;
    const section = document.getElementById('qrSection').value;
    if (!adminId) {
        Swal.fire('Missing Facilitator', 'Please select a facilitator first.', 'warning');
        return null;
    }

    const params = new URLSearchParams();
    params.append('admin_id', adminId);
    if (section) {
        params.append('section', section);
    }
    return params;
}

document.getElementById('qrAdminId').addEventListener('change', function() {
    const sectionSelect = document.getElementById('qrSection');
    const sections = sectionsByFacilitator[this.value] || [];
    sectionSelect.innerHTML = '<option value="">All Folders</option>';
    sections.forEach(function(section) {
        const option = document.createElement('option');
        option.value = section;
        option.textContent = section;
        sectionSelect.appendChild(option);
    });
    sectionSelect.disabled = sections.length === 0;
});

document.getElementById('previewQrBtn').addEventListener('click', function() {
    const params = buildQrParams();
    if (!params) {
        return;
    }

    this.disabled = true;
    this.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Loading';

    fetch('endpoint/preview-export.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            if (!data.success || !data.students.length) {
                Swal.fire('No Students', data.message || 'No students found for this export.', 'info');
                return;
            }

            const rows = data.students.slice(0, 6).map(student => `
                <tr>
                    <td>${escapeHtml(student.student_name)}</td>
                    <td>${escapeHtml(student.course_section || '')}</td>
                    <td>${escapeHtml(student.original_section || 'N/A')}</td>
                </tr>
            `).join('');

            Swal.fire({
                title: data.students.length + ' Student(s) Found',
                html: `
                    <div class="table-responsive text-left">
                        <table class="table table-sm table-bordered mb-0">
                            <thead><tr><th>Name</th><th>Folder</th><th>Original Section</th></tr></thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>
                `,
                icon: 'info',
                width: 720
            });
        })
        .catch(() => Swal.fire('Error', 'Failed to load export preview.', 'error'))
        .finally(() => {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-eye mr-1"></i> Preview QR Export';
        });
});

document.getElementById('downloadQrBtn').addEventListener('click', function() {
    const params = buildQrParams();
    if (!params) {
        return;
    }
    window.location.href = 'endpoint/export-qr-zip.php?' + params.toString();
});
</script>
</body>
</html>
