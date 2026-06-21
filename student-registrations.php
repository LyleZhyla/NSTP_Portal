<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: landing_page.php");
    exit();
}

require_once './conn/conn.php';
require_once './include/user-permissions.php';
require_once './include/public-registration-forms.php';

$currentUser = getCurrentUserRecord($conn);
$role = $currentUser['role'] ?? '';

if (!in_array($role, ['coordinator', 'super_admin'], true)) {
    header("Location: index.php");
    exit();
}

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

ensurePublicRegistrationTableForView($conn);
$publicForms = getPublicRegistrationForms($conn);
$fieldOptions = getPublicRegistrationFieldOptions();

$query = "
    SELECT r.*, u.username, u.full_name, u.role AS account_role, f.form_title
    FROM tbl_public_student_registrations r
    LEFT JOIN tbl_users u ON r.user_id = u.user_id
    LEFT JOIN tbl_public_registration_forms f ON r.form_id = f.form_id
";
$params = [];

if ($role === 'coordinator') {
    $program = normalizeProgram($currentUser['program'] ?? null);
    $query .= " WHERE r.component = ?";
    $params[] = $program;
}

$query .= " ORDER BY r.created_at DESC";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalRegistrations = count($registrations);
$emailSentCount = count(array_filter($registrations, fn($row) => (int) $row['email_sent'] === 1));
$todayCount = count(array_filter($registrations, fn($row) => date('Y-m-d', strtotime($row['created_at'])) === date('Y-m-d')));
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
                    <div class="col-md-4">
                        <div class="small-box bg-info stat-card">
                            <div class="inner">
                                <h3><?php echo (int) $totalRegistrations; ?></h3>
                                <p>Total Submissions</p>
                            </div>
                            <div class="icon"><i class="fas fa-users"></i></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="small-box bg-success stat-card">
                            <div class="inner">
                                <h3><?php echo (int) $emailSentCount; ?></h3>
                                <p>Account Emails Sent</p>
                            </div>
                            <div class="icon"><i class="fas fa-envelope-circle-check"></i></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="small-box bg-warning stat-card">
                            <div class="inner">
                                <h3><?php echo (int) $todayCount; ?></h3>
                                <p>Submitted Today</p>
                            </div>
                            <div class="icon"><i class="fas fa-calendar-day"></i></div>
                        </div>
                    </div>
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
                                                <button type="button" class="btn btn-sm btn-warning" data-toggle="modal" data-target="#formModal<?php echo (int) $formRow['form_id']; ?>">
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
                        <div class="table-filter-bar">
                            <div class="row align-items-end">
                                <div class="col-md-4">
                                    <label for="formTitleFilter" class="mb-1">Filter by Form Title</label>
                                    <select class="form-control" id="formTitleFilter">
                                        <option value="">All public registration forms</option>
                                        <?php foreach ($publicForms as $formRow): ?>
                                            <option value="<?php echo htmlspecialchars($formRow['form_title']); ?>">
                                                <?php echo htmlspecialchars($formRow['form_title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mt-3 mt-md-0">
                                    <label for="componentFilter" class="mb-1">Component</label>
                                    <select class="form-control" id="componentFilter" <?php echo $role === 'coordinator' ? 'disabled' : ''; ?>>
                                        <?php if ($role === 'coordinator'): ?>
                                            <option value="<?php echo htmlspecialchars(normalizeProgram($currentUser['program'] ?? null) ?? ''); ?>" selected>
                                                <?php echo htmlspecialchars(normalizeProgram($currentUser['program'] ?? null) ?? 'Component'); ?>
                                            </option>
                                        <?php else: ?>
                                            <option value="">All Components</option>
                                            <option value="CWTS">CWTS</option>
                                            <option value="LTS">LTS</option>
                                            <option value="ROTC">ROTC</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-2 mt-3 mt-md-0">
                                    <span class="detail-label">Visible Submissions</span>
                                    <span class="detail-value">
                                        <span id="visibleSubmissionCount"><?php echo (int) $totalRegistrations; ?></span>
                                        of <?php echo (int) $totalRegistrations; ?>
                                    </span>
                                </div>
                                <div class="col-md-2 mt-3 mt-md-0">
                                    <label for="publicAttendanceDate" class="mb-1">Attendance Date</label>
                                    <input type="date" class="form-control" id="publicAttendanceDate" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-2 mt-3 mt-md-0 text-md-right">
                                    <a class="btn btn-success mb-2 public-qr-btn" id="downloadPublicAttendance" href="endpoint/download-public-registration-attendance.php?date=<?php echo date('Y-m-d'); ?>">
                                        <i class="fas fa-file-excel mr-1"></i> Download Attendance
                                    </a>
                                    <button type="button" class="btn btn-primary mb-2" id="sendAccountEmailsBtn">
                                        <i class="fas fa-envelope mr-1"></i> Send Account Emails
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="clearFormTitleFilter">
                                        <i class="fas fa-filter-circle-xmark mr-1"></i> Clear Filter
                                    </button>
                                </div>
                            </div>
                        </div>
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
                                        <th>Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
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
                                            $address = $row['house_no'] . ' ' . $row['street'] . ', ' . $row['barangay'] . ', ' . $row['city_municipality'] . ', ' . $row['province'];
                                            if (trim(str_replace(['N/A', ',', ' '], '', $address)) === '') {
                                                $address = 'N/A';
                                            }
                                            $dobDisplay = (!empty($row['date_of_birth']) && $row['date_of_birth'] !== '1900-01-01') ? date('m/d/Y', strtotime($row['date_of_birth'])) : 'N/A';
                                            $photoPath = $row['formal_picture'] ?: 'include/logo.png';
                                            $rotcMsLevel = trim((string) ($row['rotc_ms_level'] ?? ''));
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
                                            <td><?php echo htmlspecialchars(date('m/d/Y h:i A', strtotime($row['created_at']))); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#detailsModal<?php echo $registrationId; ?>">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($role === 'super_admin' && $registrantRole === 'student' && !empty($row['user_id']) && ($row['account_role'] ?? '') === 'student'): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-danger delete-student-account"
                                                        data-user-id="<?php echo (int) $row['user_id']; ?>"
                                                        data-name="<?php echo htmlspecialchars($fullName); ?>">
                                                        <i class="fas fa-user-times"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>

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
            order: [[10, 'desc']],
            pageLength: 25
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

        function updateVisibleSubmissionCount() {
            $('#visibleSubmissionCount').text(registrationsTable.rows({ filter: 'applied' }).count());
        }

        function updatePublicAttendanceDownloadLink() {
            const params = new URLSearchParams();
            params.set('date', $('#publicAttendanceDate').val() || new Date().toISOString().slice(0, 10));
            const selectedFormTitle = $('#formTitleFilter').val();
            if (selectedFormTitle) {
                params.set('form_title', selectedFormTitle);
            }
            const selectedComponent = $('#componentFilter').val();
            if (selectedComponent) {
                params.set('component', selectedComponent);
            }
            $('#downloadPublicAttendance').attr('href', 'endpoint/download-public-registration-attendance.php?' + params.toString());
        }

        function applyComponentFilter() {
            const selectedComponent = $('#componentFilter').val();
            registrationsTable.column(7).search(selectedComponent || '').draw();
            updatePublicAttendanceDownloadLink();
        }

        $('#formTitleFilter').on('change', function() {
            const selectedFormTitle = $(this).val();
            updatePublicAttendanceDownloadLink();
            if (selectedFormTitle === '') {
                registrationsTable.column(1).search('').draw();
                return;
            }

            registrationsTable
                .column(1)
                .search('^' + escapeRegex(selectedFormTitle) + '$', true, false)
                .draw();
        });

        $('#clearFormTitleFilter').on('click', function() {
            $('#formTitleFilter').val('');
            <?php if ($role !== 'coordinator'): ?>
            $('#componentFilter').val('');
            <?php endif; ?>
            updatePublicAttendanceDownloadLink();
            registrationsTable.column(7).search('');
            registrationsTable.column(1).search('').draw();
        });

        $('#publicAttendanceDate').on('change', updatePublicAttendanceDownloadLink);
        $('#componentFilter').on('change', applyComponentFilter);

        $('#sendAccountEmailsBtn').on('click', function() {
            const button = $(this);
            const selectedComponent = $('#componentFilter').val();
            const scopeLabel = selectedComponent ? selectedComponent : 'all components';

            Swal.fire({
                title: 'Send account emails?',
                text: 'This will create missing student accounts and email credentials for uploaded registrations under ' + scopeLabel + '.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Send Emails',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (!result.isConfirmed) {
                    return;
                }

                const originalHtml = button.html();
                button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Sending');

                $.ajax({
                    url: 'endpoint/send-student-account-emails.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        component: selectedComponent || ''
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire('Done', response.message, 'success').then(function() {
                                window.location.reload();
                            });
                        } else {
                            Swal.fire('Unable to Send', response.message || 'Please try again.', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Request Failed', 'Unable to send account emails. Please try again.', 'error');
                    },
                    complete: function() {
                        button.prop('disabled', false).html(originalHtml);
                    }
                });
            });
        });

        $('.delete-student-account').on('click', function() {
            const button = $(this);
            const userId = button.data('user-id');
            const studentName = button.data('name') || 'this student';
            const safeStudentName = escapeHtml(studentName);

            Swal.fire({
                title: 'Delete student account?',
                html: `
                    <div class="text-left">
                        <p>This will delete the login account for <strong>${safeStudentName}</strong>.</p>
                        <p class="mb-0 text-muted">The registration and student record will stay, but the student will no longer be able to log in until a new account is created.</p>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Delete Account',
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

        registrationsTable.on('draw', updateVisibleSubmissionCount);
        updateVisibleSubmissionCount();
        applyComponentFilter();
        updatePublicAttendanceDownloadLink();

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
