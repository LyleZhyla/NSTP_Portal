<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: landing_page.php");
    exit();
}

require_once './conn/conn.php';
require_once './include/user-permissions.php';
require_once './include/public-registration-forms.php';
require_once './include/performance-migrations.php';

$currentUser = getCurrentUserRecord($conn);
$role = $currentUser['role'] ?? '';

if (!in_array($role, ['coordinator', 'super_admin'], true)) {
    header("Location: index.php");
    exit();
}

runPublicRegistrationPerformanceMigration($conn);

function ensurePublicRegistrationTableForView(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_public_student_registrations (
            registration_id INT AUTO_INCREMENT PRIMARY KEY,
            form_id INT NULL,
            user_id INT NULL,
            registrant_role VARCHAR(20) NOT NULL DEFAULT 'student',
            last_name VARCHAR(100) NOT NULL,
            extension_name VARCHAR(30) NULL,
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(100) NOT NULL,
            place_of_birth VARCHAR(255) NOT NULL,
            date_of_birth DATE NOT NULL,
            gender VARCHAR(30) NOT NULL DEFAULT 'N/A',
            religion VARCHAR(120) NOT NULL DEFAULT 'N/A',
            blood_type VARCHAR(20) NOT NULL DEFAULT 'N/A',
            contact_number VARCHAR(30) NOT NULL DEFAULT 'N/A',
            email VARCHAR(150) NOT NULL,
            province VARCHAR(120) NOT NULL,
            city_municipality VARCHAR(120) NOT NULL,
            barangay VARCHAR(120) NOT NULL,
            street VARCHAR(180) NOT NULL,
            house_no VARCHAR(80) NOT NULL,
            emergency_name VARCHAR(150) NOT NULL DEFAULT 'N/A',
            emergency_relationship VARCHAR(80) NOT NULL DEFAULT 'N/A',
            emergency_contact_number VARCHAR(30) NOT NULL DEFAULT 'N/A',
            emergency_address VARCHAR(255) NOT NULL DEFAULT 'N/A',
            student_number VARCHAR(10) NULL,
            college VARCHAR(150) NOT NULL,
            course VARCHAR(150) NOT NULL,
            major VARCHAR(120) NOT NULL DEFAULT 'N/A',
            year_section VARCHAR(40) NOT NULL,
            component VARCHAR(20) NULL,
            rotc_ms_level VARCHAR(20) NULL,
            formal_picture VARCHAR(255) NOT NULL,
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'submitted',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_form_id (form_id),
            INDEX idx_email (email),
            INDEX idx_course_year (college, course, year_section),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    ensurePublicRegistrationFormsTable($conn);

    try {
        $columnChecks = [
            'form_id' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN form_id INT NULL AFTER registration_id",
            'registrant_role' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN registrant_role VARCHAR(20) NOT NULL DEFAULT 'student' AFTER user_id",
            'religion' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN religion VARCHAR(120) NOT NULL DEFAULT 'N/A' AFTER date_of_birth",
            'gender' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN gender VARCHAR(30) NOT NULL DEFAULT 'N/A' AFTER date_of_birth",
            'blood_type' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN blood_type VARCHAR(20) NOT NULL DEFAULT 'N/A' AFTER religion",
            'contact_number' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN contact_number VARCHAR(30) NOT NULL DEFAULT 'N/A' AFTER blood_type",
            'emergency_name' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN emergency_name VARCHAR(150) NOT NULL DEFAULT 'N/A' AFTER house_no",
            'emergency_relationship' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN emergency_relationship VARCHAR(80) NOT NULL DEFAULT 'N/A' AFTER emergency_name",
            'emergency_contact_number' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN emergency_contact_number VARCHAR(30) NOT NULL DEFAULT 'N/A' AFTER emergency_relationship",
            'emergency_address' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN emergency_address VARCHAR(255) NOT NULL DEFAULT 'N/A' AFTER emergency_contact_number",
            'student_number' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN student_number VARCHAR(10) NULL AFTER house_no",
            'college' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN college VARCHAR(150) NOT NULL DEFAULT '' AFTER student_number",
            'major' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN major VARCHAR(120) NOT NULL DEFAULT 'N/A' AFTER course",
            'component' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN component VARCHAR(20) NULL AFTER year_section",
            'rotc_ms_level' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN rotc_ms_level VARCHAR(20) NULL AFTER component",
        ];

        foreach ($columnChecks as $columnName => $alterSql) {
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'tbl_public_student_registrations'
                  AND COLUMN_NAME = ?
            ");
            $stmt->execute([$columnName]);
            if ((int) $stmt->fetchColumn() === 0) {
                $conn->exec($alterSql);
            }
        }

        $conn->exec("ALTER TABLE tbl_public_student_registrations MODIFY student_number VARCHAR(10) NULL");
        $conn->exec("ALTER TABLE tbl_public_student_registrations MODIFY course VARCHAR(150) NOT NULL");
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tbl_public_student_registrations'
              AND COLUMN_NAME = 'account_username'
        ");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) {
            $conn->exec("ALTER TABLE tbl_public_student_registrations DROP COLUMN account_username");
        }
    } catch (Throwable $error) {
        // Keep the page available even if an older database needs manual migration.
    }
}

$publicForms = getPublicRegistrationForms($conn);
$fieldOptions = getPublicRegistrationFieldOptions();

$allowedPageSizes = [10, 25, 50, 100];
$pageSize = (int) ($_GET['per_page'] ?? 25);
if (!in_array($pageSize, $allowedPageSizes, true)) {
    $pageSize = 25;
}
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$selectedFormTitle = trim((string) ($_GET['form_title'] ?? ''));
$selectedComponent = normalizeProgram($_GET['component'] ?? null);
$selectedAccountStatus = (string) ($_GET['account_status'] ?? '');
if (!in_array($selectedAccountStatus, ['', 'opened', 'not_opened', 'no_account'], true)) {
    $selectedAccountStatus = '';
}
$registrationSearch = trim((string) ($_GET['search'] ?? ''));
$program = $role === 'coordinator' ? normalizeProgram($currentUser['program'] ?? null) : null;

$registrationJoins = "
    FROM tbl_public_student_registrations r
    LEFT JOIN tbl_users linked_user ON linked_user.user_id = r.user_id
    LEFT JOIN tbl_users matched_user
      ON r.user_id IS NULL
     AND r.registrant_role = 'student'
     AND matched_user.role = 'student'
     AND matched_user.username = r.student_number
    LEFT JOIN tbl_public_registration_forms f ON r.form_id = f.form_id
";
$baseRegistrationWhere = ["COALESCE(r.status, 'submitted') NOT IN ('attendance_only', 'account_deleted')"];
$baseRegistrationParams = [];
if ($role === 'coordinator') {
    $baseRegistrationWhere[] = 'r.component = ?';
    $baseRegistrationParams[] = $program;
} elseif ($selectedComponent) {
    $baseRegistrationWhere[] = 'r.component = ?';
    $baseRegistrationParams[] = $selectedComponent;
}

$filteredRegistrationWhere = $baseRegistrationWhere;
$filteredRegistrationParams = $baseRegistrationParams;
if ($selectedFormTitle !== '') {
    $filteredRegistrationWhere[] = "COALESCE(NULLIF(f.form_title, ''), 'Unlinked QR Form') = ?";
    $filteredRegistrationParams[] = $selectedFormTitle;
}
if ($registrationSearch !== '') {
    $filteredRegistrationWhere[] = "(
        r.student_number LIKE ? OR r.email LIKE ? OR r.last_name LIKE ? OR
        r.first_name LIKE ? OR CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name) LIKE ?
    )";
    $searchPattern = '%' . $registrationSearch . '%';
    array_push($filteredRegistrationParams, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $searchPattern);
}

$accountIdExpression = 'COALESCE(linked_user.user_id, matched_user.user_id)';
$savedLastLoginExpression = 'COALESCE(linked_user.last_login_at, matched_user.last_login_at)';
$fallbackLastLoginExpression = "(
    SELECT MAX(login_log.created_at)
    FROM tbl_system_logs login_log
    WHERE login_log.action = 'user_login'
      AND login_log.user_id = {$accountIdExpression}
)";

$loadRegistrationPage = static function ($lastLoginExpression) use (
    $conn, $registrationJoins, $filteredRegistrationWhere, $filteredRegistrationParams,
    $selectedAccountStatus, $accountIdExpression, $pageSize, &$currentPage
) {
    $where = $filteredRegistrationWhere;
    if ($selectedAccountStatus === 'opened') {
        $where[] = "{$accountIdExpression} IS NOT NULL AND {$lastLoginExpression} IS NOT NULL";
    } elseif ($selectedAccountStatus === 'not_opened') {
        $where[] = "{$accountIdExpression} IS NOT NULL AND {$lastLoginExpression} IS NULL";
    } elseif ($selectedAccountStatus === 'no_account') {
        $where[] = "{$accountIdExpression} IS NULL";
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) {$registrationJoins} WHERE {$whereSql}");
    $countStmt->execute($filteredRegistrationParams);
    $filteredTotal = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($filteredTotal / $pageSize));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $pageSize;

    $query = "
        SELECT r.*,
               COALESCE(linked_user.username, matched_user.username) AS username,
               COALESCE(linked_user.full_name, matched_user.full_name) AS full_name,
               COALESCE(linked_user.role, matched_user.role) AS account_role,
               {$accountIdExpression} AS resolved_user_id,
               f.form_title,
               {$lastLoginExpression} AS last_login_at,
               (
                   SELECT COUNT(*)
                   FROM tbl_public_student_registrations duplicate_registration
                   WHERE duplicate_registration.registrant_role = 'student'
                     AND duplicate_registration.student_number = r.student_number
                     AND COALESCE(duplicate_registration.status, 'submitted') NOT IN ('attendance_only', 'account_deleted')
               ) AS duplicate_submission_count
        {$registrationJoins}
        WHERE {$whereSql}
        ORDER BY r.created_at DESC, r.registration_id DESC
        LIMIT {$pageSize} OFFSET {$offset}
    ";
    $stmt = $conn->prepare($query);
    $stmt->execute($filteredRegistrationParams);
    return [$stmt->fetchAll(PDO::FETCH_ASSOC), $filteredTotal, $totalPages];
};

$usingSavedLoginActivity = true;
try {
    [$registrations, $filteredRegistrationCount, $totalRegistrationPages] = $loadRegistrationPage($savedLastLoginExpression);
} catch (PDOException $error) {
    $usingSavedLoginActivity = false;
    [$registrations, $filteredRegistrationCount, $totalRegistrationPages] = $loadRegistrationPage($fallbackLastLoginExpression);
}

$totalRegistrationStmt = $conn->prepare(
    'SELECT COUNT(*) ' . $registrationJoins . ' WHERE ' . implode(' AND ', $baseRegistrationWhere)
);
$totalRegistrationStmt->execute($baseRegistrationParams);
$totalRegistrations = (int) $totalRegistrationStmt->fetchColumn();

$duplicateSql = "
    SELECT COALESCE(SUM(duplicate_group.submission_count - 1), 0)
    FROM (
        SELECT r.student_number, COUNT(*) AS submission_count
        FROM tbl_public_student_registrations r
        WHERE " . implode(' AND ', $baseRegistrationWhere) . "
          AND r.registrant_role = 'student'
          AND r.student_number IS NOT NULL
          AND r.student_number <> ''
        GROUP BY r.student_number
        HAVING COUNT(*) > 1
    ) duplicate_group
";
$duplicateStmt = $conn->prepare($duplicateSql);
$duplicateStmt->execute($baseRegistrationParams);
$duplicateSubmissionCount = (int) $duplicateStmt->fetchColumn();

$studentAccountWhere = "u.role = 'student'";
$studentAccountParams = [];
if ($role === 'coordinator') {
    $studentAccountWhere .= ' AND u.program = ?';
    $studentAccountParams[] = normalizeProgram($currentUser['program'] ?? null);
}

$studentAccountStmt = $conn->prepare("SELECT COUNT(*) FROM tbl_users u WHERE {$studentAccountWhere}");
$studentAccountStmt->execute($studentAccountParams);
$totalStudentAccounts = (int) $studentAccountStmt->fetchColumn();

try {
    $openedAccountStmt = $conn->prepare("SELECT COUNT(*) FROM tbl_users u WHERE {$studentAccountWhere} AND u.last_login_at IS NOT NULL");
    $openedAccountStmt->execute($studentAccountParams);
    $openedStudentAccounts = (int) $openedAccountStmt->fetchColumn();
} catch (PDOException $error) {
    $openedAccountStmt = $conn->prepare("
        SELECT COUNT(DISTINCT u.user_id)
        FROM tbl_users u
        INNER JOIN tbl_system_logs l ON l.user_id = u.user_id AND l.action = 'user_login'
        WHERE {$studentAccountWhere}
    ");
    $openedAccountStmt->execute($studentAccountParams);
    $openedStudentAccounts = (int) $openedAccountStmt->fetchColumn();
}
$notOpenedStudentAccounts = max(0, $totalStudentAccounts - $openedStudentAccounts);
$registrationPageUrl = static function ($page) {
    $query = $_GET;
    $query['page'] = max(1, (int) $page);
    return 'student-registrations.php?' . http_build_query($query);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Public Student Registrations - TAU NSTP</title>
    <?php include('./include/theme-loader.php'); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .registration-photo {
            width: 56px;
            height: 56px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #d8e7eb;
            background: #fff;
        }
        .detail-photo {
            width: 170px;
            height: 170px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #d8e7eb;
            background: #fff;
        }
        .detail-label {
            display: block;
            font-size: .78rem;
            font-weight: 700;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: .02em;
        }
        .detail-value {
            display: block;
            font-weight: 600;
            color: #263238;
            word-break: break-word;
        }
        .public-link-box {
            border: 1px solid #d8e7eb;
            background: #f7fbfc;
            border-radius: 8px;
            padding: 12px 14px;
        }
        .stat-card {
            border-radius: 8px;
        }
        .qr-form-card {
            border: 1px solid #d8e7eb;
            border-radius: 8px;
            padding: 14px;
            height: 100%;
            background: #fff;
        }
        .qr-form-img {
            width: 118px;
            height: 118px;
            border: 1px solid #d8e7eb;
            border-radius: 8px;
            background: #fff;
            padding: 6px;
        }
        .field-pill {
            display: inline-block;
            margin: 2px;
            padding: 4px 8px;
            border-radius: 8px;
            background: #eef6f8;
            color: #31535d;
            font-size: .78rem;
            font-weight: 700;
        }
        .table-filter-bar {
            border: 1px solid #d8e7eb;
            background: #f7fbfc;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 16px;
        }
        .public-action-group {
            display: flex;
            flex-wrap: nowrap;
            align-items: stretch;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 2px;
        }
        .public-action-group .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            margin: 0;
            white-space: nowrap;
        }
        .registration-filter-actions {
            display: flex;
            flex-wrap: nowrap;
            align-items: stretch;
            justify-content: flex-start;
            gap: 8px;
            width: 100%;
            overflow-x: auto;
            padding-bottom: 2px;
        }
        .registration-filter-actions .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            margin: 0;
            white-space: nowrap;
            flex: 0 0 auto;
        }
        .registration-row-actions {
            display: inline-flex;
            align-items: center;
            justify-content: flex-start;
            gap: 6px;
            white-space: nowrap;
        }
        .registration-row-actions .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            padding: 0;
            margin: 0;
        }
        .qr-pagination-bar {
            border-top: 1px solid #e3edf1;
            padding-top: 12px;
            margin-top: 4px;
        }
        .public-qr-btn,
        .public-qr-btn:hover,
        .public-qr-btn:focus,
        .public-qr-btn:active,
        .public-qr-btn i {
            color: #fff !important;
        }
        .modal[id^="detailsModal"] {
            z-index: 2060 !important;
        }
        .modal-backdrop.show {
            z-index: 2050 !important;
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
                    <div class="col-sm-8">
                        <h1><i class="fas fa-clipboard-list mr-2"></i>Public Student Registrations</h1>
                    </div>
                    <div class="col-sm-4">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                            <li class="breadcrumb-item active">Public Registrations</li>
                        </ol>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-md-3">
                        <div class="small-box bg-info stat-card">
                            <div class="inner">
                                <h3><?php echo (int) $totalStudentAccounts; ?></h3>
                                <p>Total Student Accounts</p>
                            </div>
                            <div class="icon"><i class="fas fa-user-graduate"></i></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="small-box bg-success stat-card">
                            <div class="inner">
                                <h3><?php echo (int) $openedStudentAccounts; ?></h3>
                                <p>Accounts Opened</p>
                            </div>
                            <div class="icon"><i class="fas fa-right-to-bracket"></i></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="small-box bg-warning stat-card">
                            <div class="inner">
                                <h3><?php echo (int) $notOpenedStudentAccounts; ?></h3>
                                <p>Not Yet Opened</p>
                            </div>
                            <div class="icon"><i class="fas fa-user-clock"></i></div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="small-box bg-danger stat-card">
                            <div class="inner">
                                <h3><?php echo (int) $duplicateSubmissionCount; ?></h3>
                                <p>Duplicate Submissions</p>
                            </div>
                            <div class="icon"><i class="fas fa-clone"></i></div>
                        </div>
                    </div>
                </div>
                <div class="alert alert-light border">
                    <i class="fas fa-circle-info mr-1 text-info"></i>
                    Student totals are based on actual student accounts, not raw registration submissions.
                    “Opened” means the student has at least one recorded successful login.
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-qrcode mr-2"></i>Public Registration QR Forms</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-sm btn-primary" data-toggle="modal" data-target="#formModalNew">
                                <i class="fas fa-plus mr-1"></i> New QR Form
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-filter-bar">
                            <div class="row align-items-end">
                                <div class="col-md-4">
                                    <label for="qrRoleFilter" class="mb-1">Filter by QR Type</label>
                                    <select class="form-control" id="qrRoleFilter">
                                        <option value="">All QR types</option>
                                        <option value="student">Student</option>
                                        <option value="facilitator">Facilitator</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mt-3 mt-md-0">
                                    <label for="qrSortFilter" class="mb-1">Sort QR Forms</label>
                                    <select class="form-control" id="qrSortFilter">
                                        <option value="latest">Latest Created</option>
                                        <option value="oldest">Oldest Created</option>
                                    </select>
                                </div>
                                <div class="col-md-2 mt-3 mt-md-0">
                                    <span class="detail-label">Visible QR Forms</span>
                                    <span class="detail-value">
                                        <span id="visibleQrFormsCount"><?php echo count($publicForms); ?></span>
                                        of <?php echo count($publicForms); ?>
                                    </span>
                                </div>
                                <div class="col-md-2 mt-3 mt-md-0 text-md-right">
                                    <button type="button" class="btn btn-outline-secondary" id="clearQrFilters">
                                        <i class="fas fa-filter-circle-xmark mr-1"></i> Clear QR Filter
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="row" id="qrFormsGrid">
                            <?php if (empty($publicForms)): ?>
                                <div class="col-12">
                                    <div class="alert alert-info mb-0">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        No public registration QR forms yet. Click New QR Form to create one.
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($publicForms as $formRow): ?>
                                <?php
                                    $formFields = decodePublicRegistrationFields($formRow['field_config']);
                                    $formRole = normalizePublicRegistrationRole($formRow['registration_role'] ?? 'student');
                                    $publicUrl = 'http://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/public-registration.php?form=' . urlencode($formRow['form_slug']);
                                    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=600x600&data=' . urlencode($publicUrl);
                                ?>
                                <div class="col-lg-6 mb-3 qr-form-item" data-role="<?php echo htmlspecialchars($formRole); ?>" data-created="<?php echo (int) strtotime($formRow['created_at'] ?? 'now'); ?>">
                                    <div class="qr-form-card">
                                        <div class="row align-items-center">
                                            <div class="col-sm-4 text-center mb-3 mb-sm-0">
                                                <img class="qr-form-img" src="<?php echo htmlspecialchars($qrUrl); ?>" alt="Public registration QR code">
                                            </div>
                                            <div class="col-sm-8">
                                                <div class="d-flex align-items-center justify-content-between">
                                                    <h5 class="mb-1"><?php echo htmlspecialchars($formRow['form_title']); ?></h5>
                                                    <?php if ((int) $formRow['is_active'] === 1): ?>
                                                        <span class="badge badge-success">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">Inactive</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="mb-2">
                                                    <span class="badge badge-<?php echo $formRole === 'facilitator' ? 'primary' : 'info'; ?>">
                                                        QR for <?php echo htmlspecialchars(ucfirst($formRole)); ?>
                                                    </span>
                                                </div>
                                                <div class="public-link-box mb-2">
                                                    <a href="<?php echo htmlspecialchars($publicUrl); ?>" target="_blank"><?php echo htmlspecialchars($publicUrl); ?></a>
                                                </div>
                                                <div class="mb-2">
                                                    <?php foreach ($fieldOptions as $fieldKey => $fieldLabel): ?>
                                                        <?php if (!empty($formFields[$fieldKey])): ?>
                                                            <span class="field-pill"><?php echo htmlspecialchars($fieldLabel); ?></span>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="public-action-group">
                                                    <button type="button"
                                                            class="btn btn-sm btn-warning edit-public-form"
                                                            data-modal-id="formModal<?php echo (int) $formRow['form_id']; ?>"
                                                            aria-controls="formModal<?php echo (int) $formRow['form_id']; ?>">
                                                        <i class="fas fa-edit mr-1"></i> Edit
                                                    </button>
                                                    <a class="btn btn-sm btn-info public-qr-btn" href="endpoint/download-public-registration-qr.php?form=<?php echo urlencode($formRow['form_slug']); ?>">
                                                        <i class="fas fa-download mr-1"></i> Download QR
                                                    </a>
                                                    <button type="button"
                                                            class="btn btn-sm btn-danger delete-public-form"
                                                            data-id="<?php echo (int) $formRow['form_id']; ?>"
                                                            data-title="<?php echo htmlspecialchars($formRow['form_title']); ?>">
                                                        <i class="fas fa-trash mr-1"></i> Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="alert alert-info mb-0" id="qrFormsEmpty" style="display:none;">
                            <i class="fas fa-info-circle mr-1"></i>
                            No QR forms match the selected filters.
                        </div>
                        <div class="qr-pagination-bar d-flex flex-column flex-md-row justify-content-between align-items-md-center" id="qrPaginationBar">
                            <div class="text-muted small mb-2 mb-md-0">
                                Showing <span id="qrFormsStart">0</span>-<span id="qrFormsEnd">0</span>
                                of <span id="qrFormsTotal"><?php echo count($publicForms); ?></span> QR forms
                            </div>
                            <nav aria-label="QR form pagination">
                                <ul class="pagination pagination-sm mb-0" id="qrFormsPagination"></ul>
                            </nav>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-table mr-2"></i>Submitted Public Registrations</h3>
                    </div>
                    <div class="card-body">
                        <form class="table-filter-bar" method="get" action="student-registrations.php">
                            <div class="row mb-3">
                                <div class="col-md-8">
                                    <label for="registrationSearch" class="mb-1">Search Registrations</label>
                                    <div class="input-group">
                                        <input type="search" class="form-control" id="registrationSearch" name="search" value="<?php echo htmlspecialchars($registrationSearch); ?>" placeholder="Student number, name, or email">
                                        <div class="input-group-append">
                                            <button type="submit" class="btn btn-info"><i class="fas fa-search mr-1"></i> Search</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-2 mt-3 mt-md-0">
                                    <label for="registrationPageSize" class="mb-1">Rows per Page</label>
                                    <select class="form-control" id="registrationPageSize" name="per_page">
                                        <?php foreach ($allowedPageSizes as $allowedPageSize): ?>
                                        <option value="<?php echo $allowedPageSize; ?>" <?php echo $pageSize === $allowedPageSize ? 'selected' : ''; ?>><?php echo $allowedPageSize; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mt-3 mt-md-0 d-flex align-items-end">
                                    <a href="student-registrations.php" class="btn btn-outline-secondary btn-block"><i class="fas fa-rotate-left mr-1"></i> Reset</a>
                                </div>
                            </div>
                            <div class="row align-items-end">
                                <div class="col-md-3">
                                    <label for="formTitleFilter" class="mb-1">Filter by Form Title</label>
                                    <select class="form-control" id="formTitleFilter" name="form_title">
                                        <option value="">All public registration forms</option>
                                        <?php foreach ($publicForms as $formRow): ?>
                                            <option value="<?php echo htmlspecialchars($formRow['form_title']); ?>" <?php echo $selectedFormTitle === $formRow['form_title'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($formRow['form_title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mt-3 mt-md-0">
                                    <label for="componentFilter" class="mb-1">Component</label>
                                    <select class="form-control" id="componentFilter" name="component" <?php echo $role === 'coordinator' ? 'disabled' : ''; ?>>
                                        <?php if ($role === 'coordinator'): ?>
                                            <option value="<?php echo htmlspecialchars(normalizeProgram($currentUser['program'] ?? null) ?? ''); ?>" selected>
                                                <?php echo htmlspecialchars(normalizeProgram($currentUser['program'] ?? null) ?? 'Component'); ?>
                                            </option>
                                        <?php else: ?>
                                            <option value="">All Components</option>
                                            <option value="CWTS" <?php echo $selectedComponent === 'CWTS' ? 'selected' : ''; ?>>CWTS</option>
                                            <option value="LTS" <?php echo $selectedComponent === 'LTS' ? 'selected' : ''; ?>>LTS</option>
                                            <option value="ROTC" <?php echo $selectedComponent === 'ROTC' ? 'selected' : ''; ?>>ROTC</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mt-3 mt-md-0">
                                    <label for="accountStatusFilter" class="mb-1">Account Status</label>
                                    <select class="form-control" id="accountStatusFilter" name="account_status">
                                        <option value="">All statuses</option>
                                        <option value="opened" <?php echo $selectedAccountStatus === 'opened' ? 'selected' : ''; ?>>Opened</option>
                                        <option value="not_opened" <?php echo $selectedAccountStatus === 'not_opened' ? 'selected' : ''; ?>>Not opened</option>
                                        <option value="no_account" <?php echo $selectedAccountStatus === 'no_account' ? 'selected' : ''; ?>>No account</option>
                                    </select>
                                </div>
                                <div class="col-md-2 mt-3 mt-md-0">
                                    <span class="detail-label">Visible Submissions</span>
                                    <span class="detail-value">
                                        <?php echo count($registrations); ?> on this page<br>
                                        <small><?php echo number_format($filteredRegistrationCount); ?> filtered / <?php echo number_format($totalRegistrations); ?> total</small>
                                    </span>
                                </div>
                                <div class="col-12 mt-3 d-flex align-items-end">
                                    <div class="registration-filter-actions">
                                        <button type="submit" class="btn btn-info"><i class="fas fa-filter mr-1"></i> Apply</button>
                                        <a href="student-registrations.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-filter-circle-xmark mr-1"></i> Clear Filter
                                        </a>
                                        <?php if ($role === 'super_admin'): ?>
                                        <button type="button" class="btn btn-outline-primary" id="sendFacilitatorAccountEmailsBtn">
                                            <i class="fas fa-user-tie mr-1"></i> Send Facilitator Emails
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-primary" id="sendAccountEmailsBtn">
                                            <i class="fas fa-user-graduate mr-1"></i> Send Student Emails
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-hover" id="registrationsTable">
                                <thead>
                                    <tr>
                                        <th>Photo</th>
                                        <th>Form</th>
                                        <th>Type</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Student No.</th>
                                        <th>Academic Info</th>
                                        <th>Component</th>
                                        <th>Address</th>
                                        <th>Email Status</th>
                                        <th>Account Status</th>
                                        <th>Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $detailsModalsHtml = ''; ?>
                                    <?php foreach ($registrations as $row): ?>
                                        <?php
                                            $registrationId = (int) $row['registration_id'];
                                            $registrantRole = $row['registrant_role'] ?? 'student';
                                            if ($registrantRole === 'facilitator') {
                                                $fullName = trim($row['full_name'] ?: $row['first_name'] ?: $row['last_name']);
                                            } else {
                                                $fullName = trim($row['last_name'] . ', ' . $row['first_name'] . ' ' . ($row['middle_name'] === 'N/A' ? '' : $row['middle_name']) . ' ' . ($row['extension_name'] ?? ''));
                                            }
                                            if (($row['first_name'] ?? '') === 'Student' && ($row['last_name'] ?? '') === ($row['student_number'] ?? '')) {
                                                $fullName = 'Student #' . $row['student_number'];
                                            }
                                            $displayEmail = (strpos((string) $row['email'], '@no-email.tau-nstp.local') !== false) ? 'N/A' : $row['email'];
                                            $studentNumberForEmail = preg_replace('/\D/', '', (string) ($row['student_number'] ?? ''));
                                            $hasValidCredentialEmail = $displayEmail !== 'N/A'
                                                && filter_var($row['email'] ?? '', FILTER_VALIDATE_EMAIL);
                                            $canSendStudentCredentials = $role === 'super_admin'
                                                && $registrantRole === 'student'
                                                && preg_match('/^\d{10}$/', $studentNumberForEmail);
                                            $canSendStudentCredentials = $canSendStudentCredentials || (
                                                $role === 'coordinator'
                                                && $registrantRole === 'student'
                                                && preg_match('/^\d{10}$/', $studentNumberForEmail)
                                                && $hasValidCredentialEmail
                                            );
                                            $credentialButtonTitle = $role === 'super_admin'
                                                ? 'Generate/View temporary password'
                                                : 'Send login credentials';
                                            $address = $row['house_no'] . ' ' . $row['street'] . ', ' . $row['barangay'] . ', ' . $row['city_municipality'] . ', ' . $row['province'];
                                            if (trim(str_replace(['N/A', ',', ' '], '', $address)) === '') {
                                                $address = 'N/A';
                                            }
                                            $dobDisplay = (!empty($row['date_of_birth']) && $row['date_of_birth'] !== '1900-01-01') ? date('m/d/Y', strtotime($row['date_of_birth'])) : 'N/A';
                                            $photoPath = $row['formal_picture'] ?: 'include/logo.png';
                                            $rotcMsLevel = trim((string) ($row['rotc_ms_level'] ?? ''));
                                            $studentSubmissionCount = (int) ($row['duplicate_submission_count'] ?? 0);
                                            $isDuplicateSubmission = $registrantRole === 'student' && $studentSubmissionCount > 1;
                                            $hasAccount = !empty($row['resolved_user_id']);
                                            $hasOpenedAccount = $hasAccount && !empty($row['last_login_at']);
                                            $accountStatus = $hasOpenedAccount ? 'Opened' : ($hasAccount ? 'Not opened' : 'No account');
                                        ?>
                                        <tr>
                                            <td><img class="registration-photo" src="<?php echo htmlspecialchars($photoPath); ?>" alt="Formal picture"></td>
                                            <td><?php echo htmlspecialchars($row['form_title'] ?: 'Unlinked QR Form'); ?></td>
                                            <td>
                                                <span class="badge badge-<?php echo $registrantRole === 'facilitator' ? 'primary' : 'info'; ?>">
                                                    <?php echo htmlspecialchars(ucfirst($registrantRole)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($fullName); ?></strong>
                                                <small class="d-block text-muted">DOB: <?php echo htmlspecialchars($dobDisplay); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($displayEmail); ?></td>
                                            <td><code><?php echo htmlspecialchars($row['student_number'] ?: 'N/A'); ?></code></td>
                                            <td>
                                                <span class="badge badge-info d-block mb-1"><?php echo htmlspecialchars($row['college'] ?? 'N/A'); ?></span>
                                                <span class="badge badge-primary"><?php echo htmlspecialchars($row['course']); ?></span>
                                                <span class="badge badge-light border"><?php echo htmlspecialchars($row['major'] ?? 'N/A'); ?></span>
                                                <span class="badge badge-secondary"><?php echo htmlspecialchars($row['year_section']); ?></span>
                                            </td>
                                            <td>
                                                <?php if (!empty($row['component'])): ?>
                                                    <span class="badge badge-success"><?php echo htmlspecialchars($row['component']); ?></span>
                                                    <?php if (($row['component'] ?? '') === 'ROTC' && $rotcMsLevel !== ''): ?>
                                                        <span class="badge badge-warning d-block mt-1">MS Level: <?php echo htmlspecialchars($rotcMsLevel); ?></span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">Not selected</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($address); ?></td>
                                            <td>
                                                <?php if ((int) $row['email_sent'] === 1): ?>
                                                    <span class="badge badge-success">Email sent</span>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">Email not sent</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-search="<?php echo htmlspecialchars($accountStatus); ?>">
                                                <?php if ($hasOpenedAccount): ?>
                                                    <span class="badge badge-success">Opened</span>
                                                    <small class="d-block text-muted mt-1">
                                                        Last: <?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($row['last_login_at']))); ?>
                                                    </small>
                                                <?php elseif ($hasAccount): ?>
                                                    <span class="badge badge-warning">Not opened</span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">No account</span>
                                                <?php endif; ?>
                                                <?php if ($isDuplicateSubmission): ?>
                                                    <span class="badge badge-danger d-block mt-1"><?php echo (int) $studentSubmissionCount; ?> submissions</span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-order="<?php echo (int) strtotime($row['created_at']); ?>"><?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($row['created_at']))); ?></td>
                                            <td>
                                                <div class="registration-row-actions">
                                                    <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#detailsModal<?php echo $registrationId; ?>" title="View details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($canSendStudentCredentials): ?>
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm <?php echo $role === 'super_admin' ? 'btn-primary' : 'btn-success'; ?> resend-student-credentials"
                                                            data-registration-id="<?php echo (int) $registrationId; ?>"
                                                            data-name="<?php echo htmlspecialchars($fullName); ?>"
                                                            data-email="<?php echo htmlspecialchars($displayEmail); ?>"
                                                            data-can-view="<?php echo $role === 'super_admin' ? '1' : '0'; ?>"
                                                            title="<?php echo htmlspecialchars($credentialButtonTitle); ?>">
                                                            <i class="fas <?php echo $role === 'super_admin' ? 'fa-key' : 'fa-envelope'; ?>"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($role === 'super_admin' && $registrantRole === 'student' && !empty($row['resolved_user_id']) && ($row['account_role'] ?? '') === 'student'): ?>
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-danger delete-student-account"
                                                            data-user-id="<?php echo (int) $row['resolved_user_id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($fullName); ?>"
                                                            title="Delete student account">
                                                            <i class="fas fa-user-times"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>

                                        <?php ob_start(); ?>
                                        <div class="modal fade" id="detailsModal<?php echo $registrationId; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-user-graduate mr-2"></i><?php echo htmlspecialchars($fullName); ?>
                                                            <span class="badge badge-<?php echo $registrantRole === 'facilitator' ? 'primary' : 'info'; ?> ml-2"><?php echo htmlspecialchars(ucfirst($registrantRole)); ?></span>
                                                        </h5>
                                                        <button type="button" class="close" data-dismiss="modal">
                                                            <span>&times;</span>
                                                        </button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row">
                                                            <div class="col-md-4 text-center mb-3">
                                                                <img class="detail-photo" src="<?php echo htmlspecialchars($photoPath); ?>" alt="Formal picture">
                                                                <span class="detail-label mt-3">Login Username</span>
                                                                <span class="detail-value"><code><?php echo htmlspecialchars($row['username'] ?: $row['student_number']); ?></code></span>
                                                            </div>
                                                            <div class="col-md-8">
                                                                <div class="row">
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Last Name</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['last_name']); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Extension Name</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['extension_name'] ?: 'N/A'); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">First Name</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['first_name']); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Middle Name</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['middle_name']); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Place of Birth</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['place_of_birth']); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Date of Birth</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($dobDisplay); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Religion</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['religion'] ?? 'N/A'); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Gender</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['gender'] ?? 'N/A'); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Blood Type</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['blood_type'] ?? 'N/A'); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Contact Number</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['contact_number'] ?? 'N/A'); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Email</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($displayEmail); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Student Number</span>
                                                                        <span class="detail-value"><code><?php echo htmlspecialchars($row['student_number'] ?? ''); ?></code></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Academic Information</span>
                                                                        <span class="detail-value">
                                                                            <?php echo htmlspecialchars(($row['college'] ?? 'N/A') . ' | ' . $row['course'] . ' | Major: ' . ($row['major'] ?? 'N/A') . ' | ' . $row['year_section']); ?>
                                                                        </span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">NSTP Component</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['component'] ?: 'Not selected'); ?></span>
                                                                    </div>
                                                                    <?php if (($row['component'] ?? '') === 'ROTC'): ?>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">ROTC MS Level</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($rotcMsLevel !== '' ? $rotcMsLevel : 'Not set'); ?></span>
                                                                    </div>
                                                                    <?php endif; ?>
                                                                    <div class="col-12 mb-3">
                                                                        <span class="detail-label">Complete Address</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($address); ?></span>
                                                                    </div>
                                                                    <div class="col-12"><hr></div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Emergency Contact Name</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['emergency_name'] ?? 'N/A'); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Relationship</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['emergency_relationship'] ?? 'N/A'); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Emergency Contact Number</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['emergency_contact_number'] ?? 'N/A'); ?></span>
                                                                    </div>
                                                                    <div class="col-md-6 mb-3">
                                                                        <span class="detail-label">Emergency Address</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($row['emergency_address'] ?? 'N/A'); ?></span>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php $detailsModalsHtml .= ob_get_clean(); ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($totalRegistrationPages > 1): ?>
                        <nav class="mt-3" aria-label="Registration pages">
                            <ul class="pagination pagination-sm justify-content-center mb-0">
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($registrationPageUrl($currentPage - 1)); ?>">Previous</a>
                                </li>
                                <?php
                                $pageStart = max(1, $currentPage - 2);
                                $pageEnd = min($totalRegistrationPages, $currentPage + 2);
                                for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++):
                                ?>
                                <li class="page-item <?php echo $pageNumber === $currentPage ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($registrationPageUrl($pageNumber)); ?>"><?php echo $pageNumber; ?></a>
                                </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $currentPage >= $totalRegistrationPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo htmlspecialchars($registrationPageUrl($currentPage + 1)); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                        <?php echo $detailsModalsHtml; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <?php include 'footer.php'; ?>
</div>

<?php
function renderPublicFormModal($modalId, $title, $fieldOptions, $formRow = null) {
    $fields = $formRow ? decodePublicRegistrationFields($formRow['field_config']) : getDefaultPublicRegistrationFields();
    $formId = $formRow ? (int) $formRow['form_id'] : 0;
    $formTitle = $formRow ? $formRow['form_title'] : '';
    $formRole = normalizePublicRegistrationRole($formRow['registration_role'] ?? 'student');
    $isActive = !$formRow || (int) $formRow['is_active'] === 1;
?>
<div class="modal fade" id="<?php echo htmlspecialchars($modalId); ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content public-form-settings" method="POST" action="endpoint/save-public-registration-form.php">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-qrcode mr-2"></i><?php echo htmlspecialchars($title); ?></h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="form_id" value="<?php echo $formId; ?>">
                <div class="form-group">
                    <label>Form Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="form_title" value="<?php echo htmlspecialchars($formTitle); ?>" placeholder="e.g., CWTS Batch 1 Registration" required>
                </div>
                <div class="form-group">
                    <label>QR Registration Type <span class="text-danger">*</span></label>
                    <select class="form-control public-registration-role" name="registration_role" required>
                        <option value="student" <?php echo $formRole === 'student' ? 'selected' : ''; ?>>Student</option>
                        <option value="facilitator" <?php echo $formRole === 'facilitator' ? 'selected' : ''; ?>>Facilitator</option>
                    </select>
                </div>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle mr-1"></i>
                    Choose whether this QR is for student registrations/attendance or facilitator account creation.
                </div>
                <div class="custom-control custom-checkbox mb-3">
                    <input class="custom-control-input public-select-all" type="checkbox" id="<?php echo htmlspecialchars($modalId); ?>_select_all">
                    <label class="custom-control-label font-weight-bold" for="<?php echo htmlspecialchars($modalId); ?>_select_all">
                        Select All Fields
                    </label>
                </div>
                <label class="font-weight-bold">Fields to show in public registration</label>
                <div class="row">
                    <?php foreach ($fieldOptions as $fieldKey => $fieldLabel): ?>
                    <div class="col-md-6">
                        <div class="custom-control custom-checkbox mb-2">
                            <input class="custom-control-input public-field-check" type="checkbox" id="<?php echo htmlspecialchars($modalId . '_' . $fieldKey); ?>" name="fields[<?php echo htmlspecialchars($fieldKey); ?>]" <?php echo !empty($fields[$fieldKey]) ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="<?php echo htmlspecialchars($modalId . '_' . $fieldKey); ?>">
                                <?php echo htmlspecialchars($fieldLabel); ?>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <hr>
                <div class="custom-control custom-switch">
                    <input class="custom-control-input" type="checkbox" id="<?php echo htmlspecialchars($modalId); ?>_active" name="is_active" <?php echo $isActive ? 'checked' : ''; ?>>
                    <label class="custom-control-label" for="<?php echo htmlspecialchars($modalId); ?>_active">Active and usable by public registrants</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-1"></i> Save Form
                </button>
            </div>
        </form>
    </div>
</div>
<?php
}

renderPublicFormModal('formModalNew', 'Create New Public Registration QR', $fieldOptions);
foreach ($publicForms as $formRow) {
    renderPublicFormModal('formModal' . (int) $formRow['form_id'], 'Edit Public Registration QR', $fieldOptions, $formRow);
}
?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    $(function () {
        const registrationsTable = $('#registrationsTable').DataTable({
            responsive: true,
            paging: false,
            searching: false,
            ordering: false,
            info: false
        });

        function escapeRegex(value) {
            return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function getAjaxErrorMessage(xhr, fallback) {
            if (xhr.responseJSON && xhr.responseJSON.message) {
                return xhr.responseJSON.message;
            }

            const responseText = String(xhr.responseText || '').trim();
            if (!responseText) {
                return fallback;
            }

            try {
                const parsed = JSON.parse(responseText);
                if (parsed && parsed.message) {
                    return parsed.message;
                }
            } catch (error) {
                // The server may return a PHP warning/fatal page instead of JSON.
            }

            return responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').slice(0, 500) || fallback;
        }

        $('#sendFacilitatorAccountEmailsBtn').on('click', function() {
            const button = $(this);

            Swal.fire({
                title: 'Send Facilitator Emails?',
                html: `
                    <div class="text-left">
                        <p>This action is only for facilitator login credentials.</p>
                        <label class="mb-1" for="facilitatorEmailComponent">Component</label>
                        <select id="facilitatorEmailComponent" class="form-control">
                            <option value="">All Components</option>
                            <option value="CWTS">CWTS</option>
                            <option value="LTS">LTS</option>
                            <option value="ROTC">ROTC</option>
                        </select>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Send Facilitator Emails',
                preConfirm: () => $('#facilitatorEmailComponent').val()
            }).then(function(result) {
                if (!result.isConfirmed) return;

                const originalHtml = button.html();
                button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Sending Facilitator Emails');

                $.ajax({
                    url: 'endpoint/send-facilitator-account-emails.php',
                    method: 'POST',
                    dataType: 'json',
                    data: { program: result.value || '' },
                    success: function(response) {
                        Swal.fire(response.success ? 'Facilitator Emails Finished' : 'Unable to Send', response.message || 'Please try again.', response.success ? 'success' : 'error');
                    },
                    error: function() {
                        Swal.fire('Request Failed', 'Unable to send facilitator emails. Please try again.', 'error');
                    },
                    complete: function() {
                        button.prop('disabled', false).html(originalHtml);
                    }
                });
            });
        });

        $('#sendAccountEmailsBtn').on('click', function() {
            const button = $(this);
            const selectedComponent = $('#componentFilter').val();
            const scopeLabel = selectedComponent ? selectedComponent : 'all components';
            const batchLimit = 5;
            const batchDelayMs = 30000;

            Swal.fire({
                title: 'Send Student Emails?',
                text: 'This will create missing student accounts and email credentials for uploaded registrations under ' + scopeLabel + '. Emails will be sent in batches.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Send Student Emails',
                cancelButtonText: 'Cancel'
            }).then(async function(result) {
                if (!result.isConfirmed) {
                    return;
                }

                const originalHtml = button.html();
                button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Sending');

                const totals = {
                    sent: 0,
                    resent: 0,
                    createdNoEmail: 0,
                    invalidEmail: 0,
                    failed: 0,
                    processed: 0
                };
                let remaining = 0;
                let lastMessage = '';
                let stoppedWithoutDelivery = false;

                Swal.fire({
                    title: 'Sending student emails',
                    html: 'Starting batch send...',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: function() {
                        Swal.showLoading();
                    }
                });

                try {
                    let keepSending = true;
                    while (keepSending) {
                        const response = await $.ajax({
                            url: 'endpoint/send-student-account-emails.php',
                            method: 'POST',
                            dataType: 'json',
                            data: {
                                component: selectedComponent || '',
                                limit: batchLimit
                            }
                        });

                        if (!response.success) {
                            throw new Error(response.message || 'Unable to send account emails. Please try again.');
                        }

                        totals.sent += Number(response.sent || 0);
                        totals.resent += Number(response.resent || 0);
                        totals.createdNoEmail += Number(response.created_no_email || 0);
                        totals.invalidEmail += Number(response.invalid_email || 0);
                        totals.failed += Number(response.failed || 0);
                        totals.processed += Number(response.processed || 0);
                        remaining = Number(response.pending_after || 0);
                        lastMessage = response.message || '';

                        Swal.update({
                            html: `
                                <div class="text-left">
                                    <p class="mb-2"><strong>Sent:</strong> ${totals.sent} &nbsp; <strong>Resent:</strong> ${totals.resent}</p>
                                    <p class="mb-2"><strong>Processed:</strong> ${totals.processed} &nbsp; <strong>Remaining:</strong> ${remaining}</p>
                                    <p class="mb-0 text-muted">${escapeHtml(lastMessage)}</p>
                                </div>
                            `
                        });

                        const deliveredThisBatch = Number(response.sent || 0) + Number(response.resent || 0);
                        stoppedWithoutDelivery = Boolean(response.has_more) && deliveredThisBatch === 0;
                        keepSending = Boolean(response.has_more) && deliveredThisBatch > 0;

                        if (keepSending) {
                            await new Promise(function(resolve) {
                                setTimeout(resolve, batchDelayMs);
                            });
                        }
                    }

                    let summary = 'Student email process finished. Sent: ' + totals.sent + '.';
                    if (totals.resent > 0) {
                        summary += ' Existing accounts resent: ' + totals.resent + '.';
                    }
                    if (totals.createdNoEmail > 0) {
                        summary += ' Created but email failed: ' + totals.createdNoEmail + '.';
                    }
                    if (totals.invalidEmail > 0) {
                        summary += ' Invalid emails skipped: ' + totals.invalidEmail + '.';
                    }
                    if (totals.failed > 0) {
                        summary += ' Failed: ' + totals.failed + '.';
                    }
                    if (remaining > 0) {
                        summary += ' Remaining pending: ' + remaining + '.';
                    }
                    if (stoppedWithoutDelivery && lastMessage) {
                        summary += ' Last batch details: ' + lastMessage;
                    }

                    Swal.fire('Done', summary, remaining > 0 ? 'warning' : 'success').then(function() {
                        window.location.reload();
                    });
                } catch (error) {
                    Swal.fire('Request Failed', error.message || 'Unable to send account emails. Please try again.', 'error');
                } finally {
                    button.prop('disabled', false).html(originalHtml);
                }
            });
        });

        $('#registrationsTable').on('click', '.resend-student-credentials', function() {
            const button = $(this);
            const registrationId = button.data('registration-id');
            const studentName = button.data('name') || 'this student';
            const studentEmail = button.data('email') || 'their registered email';
            const canViewCredentials = String(button.data('can-view')) === '1';
            const safeStudentName = escapeHtml(studentName);
            const safeStudentEmail = escapeHtml(studentEmail);
            const hasDisplayEmail = studentEmail && studentEmail !== 'N/A';

            Swal.fire({
                title: canViewCredentials ? 'Generate temporary password?' : 'Resend credentials?',
                html: `
                    <div class="text-left">
                        <p>This will generate a new temporary password for <strong>${safeStudentName}</strong>.</p>
                        <p class="mb-0 text-muted">${
                            canViewCredentials
                                ? 'The temporary password will be shown after generation so the super admin can release it manually if needed.'
                                : hasDisplayEmail
                                ? `The credentials will be sent to <strong>${safeStudentEmail}</strong>.`
                                : 'No valid email is available, so the generated credentials will be shown for manual release.'
                        }</p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#198754',
                confirmButtonText: canViewCredentials ? 'Generate Password' : 'Send Credentials',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) {
                    return;
                }

                const originalHtml = button.html();
                button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

                $.ajax({
                    url: 'endpoint/send-single-student-account-email.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        registration_id: registrationId
                    },
                    success: function(response) {
                        if (response.success) {
                            let resultHtml = '<div class="text-left"><p>' + escapeHtml(response.message || 'Credentials generated.') + '</p>';
                            if (response.credentials && response.credentials.username && response.credentials.temporary_password) {
                                resultHtml += `
                                    <div class="border rounded p-3 mt-3 bg-light">
                                        <div class="small text-muted text-uppercase font-weight-bold">Username</div>
                                        <div class="h5 mb-3"><code>${escapeHtml(response.credentials.username)}</code></div>
                                        <div class="small text-muted text-uppercase font-weight-bold">Temporary Password</div>
                                        <div class="h5 mb-2"><code id="generatedStudentTempPassword">${escapeHtml(response.credentials.temporary_password)}</code></div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary copy-generated-student-password" data-password="${escapeHtml(response.credentials.temporary_password)}">
                                            <i class="fas fa-copy mr-1"></i> Copy Password
                                        </button>
                                    </div>
                                    <p class="small text-danger mt-3 mb-0">This replaces any previous temporary password. Only share it with the correct student.</p>
                                `;
                            }
                            resultHtml += '</div>';

                            Swal.fire({
                                title: response.email_sent === false ? 'Email Not Sent' : 'Credentials Ready',
                                html: resultHtml,
                                icon: response.email_sent === false ? 'warning' : 'success',
                                confirmButtonText: 'OK'
                            }).then(function() {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire('Unable to Send', response.message || 'Please try again.', 'error');
                        }
                    },
                    error: function(xhr) {
                        Swal.fire('Request Failed', getAjaxErrorMessage(xhr, 'Unable to send credentials. Please try again.'), 'error');
                    },
                    complete: function() {
                        button.prop('disabled', false).html(originalHtml);
                    }
                });
            });
        });

        $(document).on('click', '.copy-generated-student-password', function() {
            const password = $(this).data('password') || '';
            if (!password || !navigator.clipboard) {
                return;
            }

            navigator.clipboard.writeText(password).then(() => {
                $(this).html('<i class="fas fa-check mr-1"></i> Copied');
            });
        });

        $('#registrationsTable').on('click', '.delete-student-account', function() {
            const button = $(this);
            const userId = button.data('user-id');
            const studentName = button.data('name') || 'this student';
            const safeStudentName = escapeHtml(studentName);

            Swal.fire({
                title: 'Delete student account?',
                html: `
                    <div class="text-left">
                        <p>This will permanently delete <strong>${safeStudentName}</strong>.</p>
                        <p class="mb-0 text-danger"><strong>All linked data will be removed:</strong> account, registration, student record, attendance history, grades, notifications, and requests.</p>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Delete All Data',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) {
                    return;
                }

                button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

                $.ajax({
                    url: 'endpoint/delete-admin.php',
                    method: 'POST',
                    data: { user_id: userId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire('Deleted', response.message || 'Student account deleted.', 'success').then(function() {
                                window.location.reload();
                            });
                            return;
                        }

                        Swal.fire('Unable to Delete', response.message || 'Please try again.', 'error');
                        button.prop('disabled', false).html('<i class="fas fa-user-times"></i>');
                    },
                    error: function() {
                        Swal.fire('Request Failed', 'Unable to delete student account. Please try again.', 'error');
                        button.prop('disabled', false).html('<i class="fas fa-user-times"></i>');
                    }
                });
            });
        });

        const qrPageSize = 4;
        let qrCurrentPage = 1;
        const qrItems = $('.qr-form-item');
        let filteredQrItems = qrItems.toArray();

        function applyQrFilters() {
            const selectedRole = $('#qrRoleFilter').val();
            const sortMode = $('#qrSortFilter').val();

            filteredQrItems = qrItems.toArray().filter(item => {
                return !selectedRole || $(item).data('role') === selectedRole;
            });

            filteredQrItems.sort((a, b) => {
                const firstCreated = Number($(a).data('created')) || 0;
                const secondCreated = Number($(b).data('created')) || 0;
                return sortMode === 'oldest' ? firstCreated - secondCreated : secondCreated - firstCreated;
            });

            $('#qrFormsGrid').append(filteredQrItems);
            qrCurrentPage = 1;
            renderQrPagination();
        }

        function renderQrPagination() {
            const qrTotalPages = Math.ceil(filteredQrItems.length / qrPageSize);
            const startIndex = (qrCurrentPage - 1) * qrPageSize;
            const endIndex = Math.min(startIndex + qrPageSize, filteredQrItems.length);

            qrItems.hide();
            $('#visibleQrFormsCount').text(filteredQrItems.length);
            $('#qrFormsTotal').text(filteredQrItems.length);
            $('#qrFormsEmpty').toggle(filteredQrItems.length === 0);
            $('#qrPaginationBar').toggle(filteredQrItems.length > 0);
            $('#qrFormsStart').text(filteredQrItems.length === 0 ? 0 : startIndex + 1);
            $('#qrFormsEnd').text(endIndex);

            if (filteredQrItems.length === 0) {
                $('#qrFormsPagination').empty();
                return;
            }

            $(filteredQrItems).slice(startIndex, endIndex).show();

            const pagination = $('#qrFormsPagination');
            pagination.empty();

            if (qrTotalPages <= 1) {
                return;
            }

            const prevDisabled = qrCurrentPage === 1 ? ' disabled' : '';
            pagination.append(`
                <li class="page-item${prevDisabled}">
                    <button type="button" class="page-link qr-page-link" data-page="${qrCurrentPage - 1}" aria-label="Previous">&laquo;</button>
                </li>
            `);

            for (let page = 1; page <= qrTotalPages; page++) {
                const active = page === qrCurrentPage ? ' active' : '';
                pagination.append(`
                    <li class="page-item${active}">
                        <button type="button" class="page-link qr-page-link" data-page="${page}">${page}</button>
                    </li>
                `);
            }

            const nextDisabled = qrCurrentPage === qrTotalPages ? ' disabled' : '';
            pagination.append(`
                <li class="page-item${nextDisabled}">
                    <button type="button" class="page-link qr-page-link" data-page="${qrCurrentPage + 1}" aria-label="Next">&raquo;</button>
                </li>
            `);
        }

        $('#qrFormsPagination').on('click', '.qr-page-link', function() {
            const targetPage = Number($(this).data('page'));
            const qrTotalPages = Math.ceil(filteredQrItems.length / qrPageSize);
            if (!targetPage || targetPage < 1 || targetPage > qrTotalPages || targetPage === qrCurrentPage) {
                return;
            }

            qrCurrentPage = targetPage;
            renderQrPagination();
        });

        $('#qrRoleFilter, #qrSortFilter').on('change', applyQrFilters);
        $('#clearQrFilters').on('click', function() {
            $('#qrRoleFilter').val('');
            $('#qrSortFilter').val('latest');
            applyQrFilters();
        });

        applyQrFilters();

        $('.edit-public-form').on('click', function(event) {
            event.preventDefault();
            event.stopPropagation();

            const modalId = String($(this).data('modal-id') || '');
            const modal = modalId ? document.getElementById(modalId) : null;
            if (!modal) {
                Swal.fire('Unable to Edit', 'The edit form for this QR could not be loaded.', 'error');
                return;
            }

            $(modal).appendTo(document.body).modal('show');
        });

        $('.public-form-settings').on('submit', function(e) {
            e.preventDefault();
            const form = this;
            const button = $(form).find('button[type="submit"]');
            button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-1"></span> Saving...');

            $.ajax({
                url: form.action,
                method: 'POST',
                data: $(form).serialize(),
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        location.reload();
                        return;
                    }
                    alert(response.message || 'Unable to save form.');
                },
                error: function() {
                    alert('Unable to save form. Please try again.');
                },
                complete: function() {
                    button.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save Form');
                }
            });
        });

        function syncSelectAll(form) {
            const fieldChecks = $(form).find('.public-field-check');
            const checkedCount = fieldChecks.filter(':checked').length;
            $(form).find('.public-select-all').prop('checked', fieldChecks.length > 0 && checkedCount === fieldChecks.length);
        }

        function applyFormRoleSettings(form) {
            const role = $(form).find('.public-registration-role').val();
            if (role === 'facilitator') {
                $(form).find('[name="fields[name]"], [name="fields[email]"]').prop('checked', true);
                $(form).find('[name="fields[course_section]"], [name="fields[student_number]"], [name="fields[extension_name]"], [name="fields[middle_name]"], [name="fields[birth_info]"], [name="fields[religion]"], [name="fields[address]"], [name="fields[formal_picture]"]').prop('checked', false);
            }
            syncSelectAll(form);
        }

        $('.public-form-settings').each(function() {
            applyFormRoleSettings(this);
        });

        $('.public-registration-role').on('change', function() {
            applyFormRoleSettings($(this).closest('form'));
        });

        $('.public-select-all').on('change', function() {
            const form = $(this).closest('form');
            form.find('.public-field-check').prop('checked', $(this).is(':checked'));
        });

        $('.public-field-check').on('change', function() {
            applyFormRoleSettings($(this).closest('form'));
        });

        $('.delete-public-form').on('click', function() {
            const formId = $(this).data('id');
            const formTitle = $(this).data('title');

            if (!confirm(`Delete this QR form?\n\n${formTitle}\n\nSubmitted student records will stay, but this QR link will no longer be available.`)) {
                return;
            }

            $.ajax({
                url: 'endpoint/delete-public-registration-form.php',
                method: 'POST',
                data: { form_id: formId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        location.reload();
                        return;
                    }
                    alert(response.message || 'Unable to delete QR form.');
                },
                error: function() {
                    alert('Unable to delete QR form. Please try again.');
                }
            });
        });
    });
</script>
</body>
</html>
