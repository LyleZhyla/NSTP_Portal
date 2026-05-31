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
            religion VARCHAR(120) NOT NULL DEFAULT 'N/A',
            email VARCHAR(150) NOT NULL,
            province VARCHAR(120) NOT NULL,
            city_municipality VARCHAR(120) NOT NULL,
            barangay VARCHAR(120) NOT NULL,
            street VARCHAR(180) NOT NULL,
            house_no VARCHAR(80) NOT NULL,
            student_number VARCHAR(10) NULL,
            college VARCHAR(150) NOT NULL,
            course VARCHAR(150) NOT NULL,
            major VARCHAR(120) NOT NULL DEFAULT 'N/A',
            year_section VARCHAR(40) NOT NULL,
            component VARCHAR(20) NULL,
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
            'student_number' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN student_number VARCHAR(10) NULL AFTER house_no",
            'college' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN college VARCHAR(150) NOT NULL DEFAULT '' AFTER student_number",
            'major' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN major VARCHAR(120) NOT NULL DEFAULT 'N/A' AFTER course",
            'component' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN component VARCHAR(20) NULL AFTER year_section",
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
    SELECT r.*, u.username, u.full_name, f.form_title
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
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
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
                                <div class="col-lg-6 mb-3 qr-form-item">
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
                                                <a class="btn btn-sm btn-info" href="endpoint/download-public-registration-qr.php?form=<?php echo urlencode($formRow['form_slug']); ?>">
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
                        <?php if (count($publicForms) > 4): ?>
                            <div class="qr-pagination-bar d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                                <div class="text-muted small mb-2 mb-md-0">
                                    Showing <span id="qrFormsStart">1</span>-<span id="qrFormsEnd">4</span>
                                    of <span id="qrFormsTotal"><?php echo count($publicForms); ?></span> QR forms
                                </div>
                                <nav aria-label="QR form pagination">
                                    <ul class="pagination pagination-sm mb-0" id="qrFormsPagination"></ul>
                                </nav>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-table mr-2"></i>Submitted Public Registrations</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-filter-bar">
                            <div class="row align-items-end">
                                <div class="col-md-5">
                                    <label for="formTitleFilter" class="mb-1">Filter by Form Title</label>
                                    <select class="form-control" id="formTitleFilter">
                                        <option value="">All public registration forms</option>
                                        <?php foreach ($publicForms as $formRow): ?>
                                            <option value="<?php echo htmlspecialchars($formRow['form_title']); ?>">
                                                <?php echo htmlspecialchars($formRow['form_title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="Default Public Registration">Default Public Registration</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mt-3 mt-md-0">
                                    <span class="detail-label">Visible Submissions</span>
                                    <span class="detail-value">
                                        <span id="visibleSubmissionCount"><?php echo (int) $totalRegistrations; ?></span>
                                        of <?php echo (int) $totalRegistrations; ?>
                                    </span>
                                </div>
                                <div class="col-md-3 mt-3 mt-md-0 text-md-right">
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
                                        <th>Details</th>
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
                                        ?>
                                        <tr>
                                            <td><img class="registration-photo" src="<?php echo htmlspecialchars($photoPath); ?>" alt="Formal picture"></td>
                                            <td><?php echo htmlspecialchars($row['form_title'] ?: 'Default Public Registration'); ?></td>
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
                                                                    <div class="col-12 mb-3">
                                                                        <span class="detail-label">Complete Address</span>
                                                                        <span class="detail-value"><?php echo htmlspecialchars($address); ?></span>
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

        function updateVisibleSubmissionCount() {
            $('#visibleSubmissionCount').text(registrationsTable.rows({ filter: 'applied' }).count());
        }

        $('#formTitleFilter').on('change', function() {
            const selectedFormTitle = $(this).val();
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
            registrationsTable.column(1).search('').draw();
        });

        registrationsTable.on('draw', updateVisibleSubmissionCount);
        updateVisibleSubmissionCount();

        const qrPageSize = 4;
        let qrCurrentPage = 1;
        const qrItems = $('.qr-form-item');
        const qrTotalPages = Math.ceil(qrItems.length / qrPageSize);

        function renderQrPagination() {
            if (qrTotalPages <= 1) return;

            const startIndex = (qrCurrentPage - 1) * qrPageSize;
            const endIndex = Math.min(startIndex + qrPageSize, qrItems.length);
            qrItems.hide().slice(startIndex, endIndex).show();
            $('#qrFormsStart').text(startIndex + 1);
            $('#qrFormsEnd').text(endIndex);

            const pagination = $('#qrFormsPagination');
            pagination.empty();

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
            if (!targetPage || targetPage < 1 || targetPage > qrTotalPages || targetPage === qrCurrentPage) {
                return;
            }

            qrCurrentPage = targetPage;
            renderQrPagination();
        });

        renderQrPagination();

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
