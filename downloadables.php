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
$selectedChartDataKey = $selectedGraph === 'component' ? 'components' : $selectedGraph;
$selectedChartRows = $chartData[$selectedChartDataKey] ?? [];
$topChartRow = $selectedChartRows[0] ?? null;
$activeFilterLabels = [];
if (!empty($selectedFilters['component'])) {
    $activeFilterLabels[] = 'Component: ' . $selectedFilters['component'];
}
foreach (['gender' => 'Gender', 'college' => 'College', 'course' => 'Course', 'province' => 'Province'] as $filterKey => $filterLabel) {
    if (!empty($selectedFilters[$filterKey])) {
        $activeFilterLabels[] = $filterLabel . ': ' . $selectedFilters[$filterKey];
    }
}

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

$gradeExportFolders = [];
if ($role === 'super_admin') {
    $gradeFolderStmt = $conn->prepare("
        SELECT ads.user_id AS facilitator_id,
               ads.course_section,
               COALESCE(NULLIF(u.full_name, ''), u.username) AS facilitator_name,
               u.program,
               COUNT(s.tbl_student_id) AS student_count
        FROM tbl_admin_sections ads
        INNER JOIN tbl_users u ON u.user_id = ads.user_id
        LEFT JOIN tbl_student s
            ON s.created_by = ads.user_id
           AND s.course_section = ads.course_section
        WHERE u.role = 'facilitator'
        GROUP BY ads.user_id, ads.course_section, u.full_name, u.username, u.program
        ORDER BY FIELD(u.program, 'CWTS', 'LTS', 'ROTC'), facilitator_name ASC, ads.course_section ASC
    ");
    $gradeFolderStmt->execute();
} elseif ($role === 'coordinator') {
    $gradeFolderStmt = $conn->prepare("
        SELECT ads.user_id AS facilitator_id,
               ads.course_section,
               COALESCE(NULLIF(u.full_name, ''), u.username) AS facilitator_name,
               u.program,
               COUNT(s.tbl_student_id) AS student_count
        FROM tbl_admin_sections ads
        INNER JOIN tbl_users u ON u.user_id = ads.user_id
        LEFT JOIN tbl_student s
            ON s.created_by = ads.user_id
           AND s.course_section = ads.course_section
        WHERE u.role = 'facilitator' AND u.program = ?
        GROUP BY ads.user_id, ads.course_section, u.full_name, u.username, u.program
        ORDER BY facilitator_name ASC, ads.course_section ASC
    ");
    $gradeFolderStmt->execute([$program]);
} else {
    $gradeFolderStmt = $conn->prepare("
        SELECT ads.user_id AS facilitator_id,
               ads.course_section,
               COALESCE(NULLIF(u.full_name, ''), u.username) AS facilitator_name,
               u.program,
               COUNT(s.tbl_student_id) AS student_count
        FROM tbl_admin_sections ads
        INNER JOIN tbl_users u ON u.user_id = ads.user_id
        LEFT JOIN tbl_student s
            ON s.created_by = ads.user_id
           AND s.course_section = ads.course_section
        WHERE ads.user_id = ?
        GROUP BY ads.user_id, ads.course_section, u.full_name, u.username, u.program
        ORDER BY ads.course_section ASC
    ");
    $gradeFolderStmt->execute([(int) $currentUser['user_id']]);
}

foreach ($gradeFolderStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $folderKey = $role === 'facilitator'
        ? $row['course_section']
        : ((int) $row['facilitator_id'] . '::' . $row['course_section']);
    $gradeExportFolders[] = [
        'key' => $folderKey,
        'label' => trim(($row['program'] ? $row['program'] . ' - ' : '') . ($row['facilitator_name'] ?: 'Facilitator') . ' / ' . $row['course_section']),
        'student_count' => (int) $row['student_count'],
    ];
}

$canDownloadRotcProfiles = $role === 'super_admin' || normalizeProgram($currentUser['program'] ?? null) === 'ROTC';
$rotcProfileFolders = [];
if ($canDownloadRotcProfiles) {
    if ($role === 'super_admin') {
        $rotcFolderStmt = $conn->prepare("
            SELECT ads.user_id AS facilitator_id,
                   ads.course_section,
                   COALESCE(NULLIF(u.full_name, ''), u.username) AS facilitator_name,
                   COUNT(s.tbl_student_id) AS student_count
            FROM tbl_admin_sections ads
            INNER JOIN tbl_users u ON u.user_id = ads.user_id
            LEFT JOIN tbl_student s
                ON s.created_by = ads.user_id
               AND s.course_section = ads.course_section
            WHERE u.role = 'facilitator' AND u.program = 'ROTC'
            GROUP BY ads.user_id, ads.course_section, u.full_name, u.username
            ORDER BY facilitator_name ASC, ads.course_section ASC
        ");
        $rotcFolderStmt->execute();
    } elseif ($role === 'coordinator') {
        $rotcFolderStmt = $conn->prepare("
            SELECT ads.user_id AS facilitator_id,
                   ads.course_section,
                   COALESCE(NULLIF(u.full_name, ''), u.username) AS facilitator_name,
                   COUNT(s.tbl_student_id) AS student_count
            FROM tbl_admin_sections ads
            INNER JOIN tbl_users u ON u.user_id = ads.user_id
            LEFT JOIN tbl_student s
                ON s.created_by = ads.user_id
               AND s.course_section = ads.course_section
            WHERE u.role = 'facilitator' AND u.program = 'ROTC'
            GROUP BY ads.user_id, ads.course_section, u.full_name, u.username
            ORDER BY facilitator_name ASC, ads.course_section ASC
        ");
        $rotcFolderStmt->execute();
    } else {
        $rotcFolderStmt = $conn->prepare("
            SELECT ads.user_id AS facilitator_id,
                   ads.course_section,
                   COALESCE(NULLIF(u.full_name, ''), u.username) AS facilitator_name,
                   COUNT(s.tbl_student_id) AS student_count
            FROM tbl_admin_sections ads
            INNER JOIN tbl_users u ON u.user_id = ads.user_id
            LEFT JOIN tbl_student s
                ON s.created_by = ads.user_id
               AND s.course_section = ads.course_section
            WHERE ads.user_id = ?
            GROUP BY ads.user_id, ads.course_section, u.full_name, u.username
            ORDER BY ads.course_section ASC
        ");
        $rotcFolderStmt->execute([(int) $currentUser['user_id']]);
    }

    foreach ($rotcFolderStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $folderKey = $role === 'facilitator'
            ? $row['course_section']
            : ((int) $row['facilitator_id'] . '::' . $row['course_section']);
        $rotcProfileFolders[] = [
            'key' => $folderKey,
            'label' => trim(($row['facilitator_name'] ?: 'Facilitator') . ' / ' . $row['course_section']),
            'student_count' => (int) $row['student_count'],
        ];
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
            border: 1px solid #dce3ec;
            border-radius: 8px;
            padding: 16px;
            background: #ffffff;
        }
        .chart-panel canvas {
            height: 310px !important;
            min-height: 310px;
        }
        .chart-panel-title {
            font-weight: 600;
            margin-bottom: 4px;
        }
        .graph-view-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 8px;
            margin-bottom: 16px;
        }
        .graph-view-option {
            border: 1px solid #dce3ec;
            border-radius: 8px;
            padding: 10px 12px;
            color: #364152;
            background: #f8fafc;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .graph-view-option:hover {
            color: #1f5f8b;
            border-color: #9cb8d8;
            background: #f2f6fb;
            text-decoration: none;
        }
        .graph-view-option.active {
            background: #e8f0f8;
            border-color: #7ea3c8;
            color: #183b5b;
            font-weight: 600;
        }
        .graph-help {
            border-left: 4px solid #7ea3c8;
            background: #f7f9fc;
            padding: 12px 14px;
            border-radius: 6px;
            margin-bottom: 16px;
        }
        .graph-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }
        .graph-summary-item {
            border: 1px solid #dce3ec;
            border-radius: 8px;
            padding: 12px;
            background: #f9fbfd;
        }
        .graph-summary-item span {
            display: block;
            color: #6c757d;
            font-size: 0.85rem;
            margin-bottom: 4px;
        }
        .graph-summary-item strong {
            font-size: 1.15rem;
            line-height: 1.2;
        }
        .active-filter-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }
        .active-filter-list span {
            border: 1px solid #dce3ec;
            background: #f8fafc;
            border-radius: 999px;
            padding: 4px 9px;
            font-size: 0.85rem;
            color: #495057;
        }
        .chart-ranking {
            max-height: 360px;
            overflow: auto;
        }
        .chart-ranking table {
            margin-bottom: 0;
        }
        .chart-ranking .rank-number {
            width: 36px;
            color: #6c757d;
            font-weight: 600;
        }
        .chart-percent {
            color: #6c757d;
            font-size: 0.85rem;
            white-space: nowrap;
        }
        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        @media (max-width: 767.98px) {
            .chart-panel canvas {
                height: 260px !important;
                min-height: 260px;
            }
            .filter-actions .muted-note {
                width: 100%;
                margin-left: 0 !important;
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
                        <div class="graph-help">
                            <strong>How to read this graph:</strong>
                            Choose what you want to compare, then use filters to narrow the list. The bars show how many enrollees belong to each group; the table beside it shows the exact count and percentage.
                        </div>

                        <div class="graph-view-grid" aria-label="Enrollment graph views">
                            <?php foreach ($availableGraphTypes as $graphKey => $graphLabel): ?>
                                <?php
                                    $graphQuery = array_merge($_GET, ['graph' => $graphKey]);
                                    $graphUrl = 'downloadables.php?' . http_build_query($graphQuery);
                                ?>
                                <a class="graph-view-option <?php echo $selectedGraph === $graphKey ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($graphUrl); ?>">
                                    <i class="fas <?php echo $graphKey === 'component' ? 'fa-layer-group' : ($graphKey === 'gender' ? 'fa-venus-mars' : ($graphKey === 'course' ? 'fa-graduation-cap' : ($graphKey === 'college' ? 'fa-university' : 'fa-map-marked-alt'))); ?>"></i>
                                    <span><?php echo htmlspecialchars($graphLabel); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <form method="get" class="mb-3">
                            <input type="hidden" name="graph" value="<?php echo htmlspecialchars($selectedGraph); ?>">
                            <div class="form-row">
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

                        <div class="graph-summary">
                            <div class="graph-summary-item">
                                <span>Total in Current View</span>
                                <strong><?php echo number_format($filteredEnrollmentTotal); ?> enrollees</strong>
                            </div>
                            <div class="graph-summary-item">
                                <span>Highest Group</span>
                                <strong><?php echo $topChartRow ? htmlspecialchars($topChartRow['label']) . ' (' . number_format((int) $topChartRow['total']) . ')' : 'No data'; ?></strong>
                            </div>
                            <div class="graph-summary-item">
                                <span>Active Filters</span>
                                <strong><?php echo count($activeFilterLabels) ? count($activeFilterLabels) . ' applied' : 'None'; ?></strong>
                                <?php if (count($activeFilterLabels)): ?>
                                <div class="active-filter-list">
                                    <?php foreach ($activeFilterLabels as $activeFilterLabel): ?>
                                    <span><?php echo htmlspecialchars($activeFilterLabel); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-lg-8 mb-3">
                                <div class="chart-panel">
                                    <div class="chart-panel-title"><?php echo htmlspecialchars($availableGraphTypes[$selectedGraph]); ?></div>
                                    <div class="muted-note mb-2">Each value represents enrolled students that match the selected filters.</div>
                                    <canvas id="selectedEnrollmentChart"></canvas>
                                </div>
                            </div>
                            <div class="col-lg-4 mb-3">
                                <div class="chart-panel">
                                    <div class="chart-panel-title">Exact Counts</div>
                                    <div class="muted-note mb-2">Use this list when you need the numbers behind the graph.</div>
                                    <div class="chart-ranking">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Group</th>
                                                    <th class="text-right">Enrollees</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($selectedChartRows)): ?>
                                                    <?php foreach ($selectedChartRows as $rowIndex => $chartRow): ?>
                                                        <?php
                                                            $rowTotal = (int) $chartRow['total'];
                                                            $rowPercent = $filteredEnrollmentTotal > 0 ? round(($rowTotal / $filteredEnrollmentTotal) * 100, 1) : 0;
                                                        ?>
                                                        <tr>
                                                            <td class="rank-number"><?php echo $rowIndex + 1; ?></td>
                                                            <td><?php echo htmlspecialchars($chartRow['label']); ?></td>
                                                            <td class="text-right">
                                                                <strong><?php echo number_format($rowTotal); ?></strong>
                                                                <div class="chart-percent"><?php echo number_format($rowPercent, 1); ?>%</div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="3" class="text-center text-muted py-4">No enrollment data for the selected filters.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12 mb-3">
                        <form class="card download-card collapsed-card" method="get" action="endpoint/download-attendance-excel.php">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-file-excel mr-2"></i>Attendance Report</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label for="attendancePeriod">Coverage</label>
                                    <select class="form-control" id="attendancePeriod" name="period">
                                        <option value="day">Today / Specific Day</option>
                                        <option value="month">Whole Month</option>
                                        <option value="semester">Whole Semester / Date Range</option>
                                    </select>
                                </div>

                                <div class="form-group attendance-period-field" id="attendanceDayField">
                                    <label for="attendanceDate">Date</label>
                                    <input type="date" class="form-control" id="attendanceDate" name="date" value="<?php echo htmlspecialchars($today); ?>">
                                </div>

                                <div class="form-group attendance-period-field d-none" id="attendanceMonthField">
                                    <label for="attendanceMonth">Month</label>
                                    <input type="month" class="form-control" id="attendanceMonth" name="month" value="<?php echo htmlspecialchars(date('Y-m')); ?>">
                                </div>

                                <div class="attendance-period-field d-none" id="attendanceSemesterField">
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label for="attendanceStartDate">Start Date</label>
                                            <input type="date" class="form-control" id="attendanceStartDate" name="start_date" value="<?php echo htmlspecialchars(date('Y-06-01')); ?>">
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label for="attendanceEndDate">End Date</label>
                                            <input type="date" class="form-control" id="attendanceEndDate" name="end_date" value="<?php echo htmlspecialchars(date('Y-12-31')); ?>">
                                        </div>
                                    </div>
                                </div>

                                <p class="muted-note">Exports an attendance matrix. Only dates with facilitator scans will appear; absent, present, and late cells are color coded.</p>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-download mr-1"></i> Download Attendance
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="col-12 mb-3">
                        <form class="card download-card collapsed-card" method="get" action="endpoint/download-grades-excel.php">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-award mr-2"></i>Student Grades</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if ($role === 'super_admin'): ?>
                                <div class="form-group">
                                    <label for="gradeComponent">Component</label>
                                    <select class="form-control" id="gradeComponent" name="component">
                                        <option value="">All Components</option>
                                        <option value="CWTS">CWTS</option>
                                        <option value="LTS">LTS</option>
                                        <option value="ROTC">ROTC</option>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="form-group">
                                    <label for="gradeFolder">Folder</label>
                                    <select class="form-control" id="gradeFolder" name="grade_folder">
                                        <option value="">All Accessible Folders</option>
                                        <?php foreach ($gradeExportFolders as $folder): ?>
                                        <option value="<?php echo htmlspecialchars($folder['key']); ?>">
                                            <?php echo htmlspecialchars($folder['label'] . ' (' . $folder['student_count'] . ' students)'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="gradeResultFilter">Records to Download</label>
                                    <select class="form-control" id="gradeResultFilter" name="result_filter">
                                        <option value="all">All Students</option>
                                        <option value="passed">Passed Only</option>
                                        <option value="failed">Failed Only</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Data to Include</label>
                                    <div class="row">
                                        <?php
                                        $gradeExportColumnOptions = [
                                            'number' => '#',
                                            'student_number' => 'Student No.',
                                            'student_name' => 'Name',
                                            'course_section' => 'Section',
                                            'facilitator' => 'Facilitator',
                                            'attendance_count' => 'Attendance',
                                            'raw_total' => 'Raw Total',
                                            'score_percent' => 'Score %',
                                            'weighted_percent' => 'Weighted %',
                                            'final_grade' => 'Final Grade',
                                            'result' => 'Result',
                                        ];
                                        $gradeDefaults = ['number', 'student_number', 'student_name', 'course_section', 'attendance_count', 'weighted_percent', 'final_grade', 'result'];
                                        foreach ($gradeExportColumnOptions as $columnKey => $columnLabel):
                                        ?>
                                        <div class="col-6">
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="gradeColumn_<?php echo htmlspecialchars($columnKey); ?>" name="columns[]" value="<?php echo htmlspecialchars($columnKey); ?>" <?php echo in_array($columnKey, $gradeDefaults, true) ? 'checked' : ''; ?>>
                                                <label class="custom-control-label" for="gradeColumn_<?php echo htmlspecialchars($columnKey); ?>"><?php echo htmlspecialchars($columnLabel); ?></label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="custom-control custom-switch mb-3">
                                    <input type="checkbox" class="custom-control-input" id="includeGradeScores" name="include_scores" value="1">
                                    <label class="custom-control-label" for="includeGradeScores">Include individual score columns</label>
                                </div>
                                <p class="muted-note">Passed means final grade is 3.00 or better.</p>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-download mr-1"></i> Download Grades
                                </button>
                            </div>
                        </form>
                    </div>

                    <?php if ($canDownloadRotcProfiles): ?>
                    <div class="col-12 mb-3">
                        <form class="card download-card collapsed-card" method="get" action="endpoint/download-rotc-cadets-profile.php">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-id-card mr-2"></i>ROTC Cadets' Profile</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label for="rotcProfileFolder">Folder</label>
                                    <select class="form-control" id="rotcProfileFolder" name="folder">
                                        <option value="">All Accessible ROTC Folders</option>
                                        <?php foreach ($rotcProfileFolders as $folder): ?>
                                        <option value="<?php echo htmlspecialchars($folder['key']); ?>">
                                            <?php echo htmlspecialchars($folder['label'] . ' (' . $folder['student_count'] . ' cadets)'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="rotcProfileStatus">Registration Status</label>
                                    <select class="form-control" id="rotcProfileStatus" name="status">
                                        <option value="">All Statuses</option>
                                        <option value="submitted">Submitted</option>
                                        <option value="attendance_only">Attendance Only</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Data to Include</label>
                                    <div class="row">
                                        <?php
                                        $rotcProfileColumns = [
                                            'number' => 'NR',
                                            'student_number' => 'Student No.',
                                            'full_name' => 'Full Name',
                                            'last_name' => 'LAST NAME',
                                            'first_name' => 'FIRST NAME',
                                            'middle_initial' => 'M.I',
                                            'middle_name' => 'Middle Name',
                                            'extension_name' => 'Extension',
                                            'gender' => 'GENDER',
                                            'date_of_birth' => 'DOB',
                                            'age' => 'Age',
                                            'place_of_birth' => 'Birth Place',
                                            'blood_type' => 'BT',
                                            'religion' => 'RELIGION',
                                            'contact_number' => 'CP NR',
                                            'email' => 'Email',
                                            'complete_address' => 'Complete Address',
                                            'address' => 'ADDRESS',
                                            'college' => 'College',
                                            'course' => 'COURSE',
                                            'major' => 'Major',
                                            'year_section' => 'Year/Section',
                                            'folder' => 'ROTC Folder',
                                            'facilitator' => 'Facilitator',
                                            'height' => 'HEIGHT',
                                            'rotc_ms_level' => 'MS Level',
                                            'rotc_completion_proof' => 'Completion Proof',
                                            'beneficiary' => 'BENEFICIARY',
                                            'emergency_name' => 'Emergency Name',
                                            'emergency_relationship' => 'Emergency Relation',
                                            'emergency_contact_number' => 'Emergency Contact',
                                            'emergency_address' => 'Emergency Address',
                                            'formal_picture' => 'Picture Path',
                                            'status' => 'Status',
                                            'created_at' => 'Registered At',
                                        ];
                                        $rotcProfileDefaults = ['number', 'last_name', 'first_name', 'middle_initial', 'gender', 'date_of_birth', 'course', 'address', 'religion', 'blood_type', 'height', 'rotc_ms_level', 'contact_number', 'beneficiary'];
                                        foreach ($rotcProfileColumns as $columnKey => $columnLabel):
                                        ?>
                                        <div class="col-6">
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox" class="custom-control-input" id="rotcProfileColumn_<?php echo htmlspecialchars($columnKey); ?>" name="columns[]" value="<?php echo htmlspecialchars($columnKey); ?>" <?php echo in_array($columnKey, $rotcProfileDefaults, true) ? 'checked' : ''; ?>>
                                                <label class="custom-control-label" for="rotcProfileColumn_<?php echo htmlspecialchars($columnKey); ?>"><?php echo htmlspecialchars($columnLabel); ?></label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <p class="muted-note">Downloads selected ROTC cadet profile fields as an Excel .xlsx file.</p>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-download mr-1"></i> Download Cadets' Profile
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div class="col-12 mb-3">
                        <form class="card download-card collapsed-card" method="get" action="endpoint/download-archived-attendance.php">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-archive mr-2"></i>Archived Attendance</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
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

                    <?php if (($role === 'coordinator' && in_array($program, ['CWTS', 'LTS'], true)) || $role === 'super_admin'): ?>
                    <div class="col-12 mb-3">
                        <form class="card download-card collapsed-card" method="get" action="endpoint/download-ched-serial-number.php">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-file-excel mr-2"></i>CHED Serial Number</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="form-group">
                                    <label for="chedComponent">NSTP Component</label>
                                    <?php if ($role === 'super_admin'): ?>
                                        <select class="form-control" id="chedComponent" name="component" required>
                                            <option value="CWTS">CWTS</option>
                                            <option value="LTS">LTS</option>
                                        </select>
                                    <?php else: ?>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($program); ?>" disabled>
                                    <?php endif; ?>
                                </div>
                                <p class="muted-note">Exports passed students in the selected LTS/CWTS component using the CHED serial number template.</p>
                                <button type="submit" class="btn btn-success btn-block">
                                    <i class="fas fa-download mr-1"></i> Download CHED Format
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div class="col-12 mb-3">
                        <div class="card download-card collapsed-card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-qrcode mr-2"></i>QR Codes ZIP</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
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
                    <div class="col-12 mb-3">
                        <div class="card download-card collapsed-card">
                            <div class="card-header">
                                <h3 class="card-title"><i class="fas fa-database mr-2"></i>Database Backup</h3>
                                <div class="card-tools">
                                    <button type="button" class="btn btn-tool" data-card-widget="collapse" title="Collapse">
                                        <i class="fas fa-plus"></i>
                                    </button>
                                </div>
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
const chartPalette = ['#4f7da8', '#6fa08a', '#c98f5a', '#8b80b6', '#d5b15f', '#5e9aa6', '#b97883', '#78906d', '#9b8a78', '#6f7f98', '#a284a6', '#8793a1'];

function updateAttendancePeriodFields() {
    const periodSelect = document.getElementById('attendancePeriod');
    if (!periodSelect) {
        return;
    }

    document.querySelectorAll('.attendance-period-field').forEach(field => field.classList.add('d-none'));

    if (periodSelect.value === 'month') {
        document.getElementById('attendanceMonthField').classList.remove('d-none');
    } else if (periodSelect.value === 'semester') {
        document.getElementById('attendanceSemesterField').classList.remove('d-none');
    } else {
        document.getElementById('attendanceDayField').classList.remove('d-none');
    }
}

const attendancePeriod = document.getElementById('attendancePeriod');
if (attendancePeriod) {
    updateAttendancePeriodFields();
    attendancePeriod.addEventListener('change', updateAttendancePeriodFields);
}

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
