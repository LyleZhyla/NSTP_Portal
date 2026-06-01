<?php
session_start();

require_once 'conn/conn.php';
require_once 'include/user-permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: landing_page.php');
    exit();
}

if (($_SESSION['role'] ?? '') !== 'student') {
    header('Location: index.php');
    exit();
}

$userId = $_SESSION['user_id'];
$componentSelectionEnabled = isComponentSelectionEnabled($conn);

$stmt = $conn->prepare("SELECT * FROM tbl_users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("
    SELECT s.*, r.last_name, r.extension_name, r.first_name, r.middle_name, r.place_of_birth,
           r.date_of_birth, r.gender, r.religion, r.blood_type, r.contact_number, r.email,
           r.province, r.city_municipality, r.barangay, r.street, r.house_no,
           r.emergency_name, r.emergency_relationship, r.emergency_contact_number, r.emergency_address,
           r.student_number AS registration_student_number, r.college, r.course, r.major, r.year_section,
           r.component, r.formal_picture, r.created_at AS registration_date
    FROM tbl_student s
    LEFT JOIN tbl_public_student_registrations r ON r.student_number = s.student_number
    WHERE s.user_id = ?
    ORDER BY r.created_at DESC
    LIMIT 1
");
$stmt->execute([$userId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$attendanceCount = 0;
$latestAttendance = null;
if ($student) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_attendance WHERE tbl_student_id = ?");
    $stmt->execute([$student['tbl_student_id']]);
    $attendanceCount = (int) $stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT * FROM tbl_attendance WHERE tbl_student_id = ? ORDER BY time_in DESC LIMIT 1");
    $stmt->execute([$student['tbl_student_id']]);
    $latestAttendance = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$registrationSections = [];
if ($student) {
    $registrationSections = [
        'Personal Information' => [
            'Full Name' => $student['student_name'] ?? 'N/A',
            'Student Number' => $student['student_number'] ?? ($student['registration_student_number'] ?? 'N/A'),
            'Gender' => $student['gender'] ?? 'N/A',
            'Date of Birth' => !empty($student['date_of_birth']) && $student['date_of_birth'] !== '0000-00-00' ? date('F d, Y', strtotime($student['date_of_birth'])) : 'N/A',
            'Place of Birth' => $student['place_of_birth'] ?? 'N/A',
            'Religion' => $student['religion'] ?? 'N/A',
            'Blood Type' => $student['blood_type'] ?? 'N/A',
        ],
        'Contact Information' => [
            'Email' => $student['email'] ?? ($user['email'] ?? 'N/A'),
            'Contact Number' => $student['contact_number'] ?? 'N/A',
            'Address' => implode(', ', array_filter([
                $student['house_no'] ?? '',
                $student['street'] ?? '',
                $student['barangay'] ?? '',
                $student['city_municipality'] ?? '',
                $student['province'] ?? '',
            ], fn($value) => trim((string) $value) !== '' && strtoupper(trim((string) $value)) !== 'N/A')) ?: 'N/A',
        ],
        'Emergency Contact' => [
            'Name' => $student['emergency_name'] ?? 'N/A',
            'Relationship' => $student['emergency_relationship'] ?? 'N/A',
            'Contact Number' => $student['emergency_contact_number'] ?? 'N/A',
            'Address' => $student['emergency_address'] ?? 'N/A',
        ],
        'Academic Information' => [
            'College' => $student['college'] ?? 'N/A',
            'Course' => $student['course'] ?? 'N/A',
            'Major' => $student['major'] ?? 'N/A',
            'Year and Section' => $student['year_section'] ?? ($student['original_section'] ?? 'N/A'),
            'Component' => $student['course_section'] ?? ($student['component'] ?? 'N/A'),
        ],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Dashboard - TAU NSTP</title>
    <?php include('./include/theme-loader.php'); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .qr-card {
            border: 1px solid rgba(47, 111, 126, 0.18);
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
        }
        .qr-card-header {
            background: #2f6f7e;
            color: #fff;
            padding: 18px 22px;
        }
        .qr-profile {
            width: 132px;
            height: 132px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #dbe8ed;
            background: #f8fafc;
        }
        .qr-detail-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
        }
        .qr-detail-value {
            color: #1f2937;
            font-weight: 600;
        }
        .download-actions .btn {
            min-width: 126px;
        }
        .detail-card {
            border: 1px solid rgba(47, 111, 126, 0.14);
            border-radius: 8px;
            padding: 18px;
            height: 100%;
            background: #fff;
        }
        .detail-card h6 {
            font-weight: 700;
            color: #2f6f7e;
        }
        .detail-row {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,0,0,0.06);
        }
        .detail-row:last-child {
            border-bottom: 0;
        }
        .detail-label {
            color: #6b7280;
            font-weight: 700;
            font-size: 0.8rem;
        }
        .detail-value {
            color: #1f2937;
        }
        @media (max-width: 575.98px) {
            .detail-row {
                grid-template-columns: 1fr;
                gap: 2px;
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
            <?php include './include/theme-toggle.php'; ?>
            <?php include './include/theme-toggle-slider.php'; ?>
        </ul>
    </nav>

    <?php include 'adminlte-sidebar.php'; ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <h1><i class="fas fa-tachometer-alt mr-2"></i>Student Dashboard</h1>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-lg-4 col-12">
                        <div class="small-box bg-info">
                            <div class="inner">
                                <h3><?php echo htmlspecialchars($user['program'] ?? 'None'); ?></h3>
                                <p>Selected Component</p>
                            </div>
                            <div class="icon"><i class="fas fa-layer-group"></i></div>
                            <a href="component.php" class="small-box-footer">Manage Component <i class="fas fa-arrow-circle-right"></i></a>
                        </div>
                    </div>
                    <div class="col-lg-4 col-12">
                        <div class="small-box bg-success">
                            <div class="inner">
                                <h3><?php echo $attendanceCount; ?></h3>
                                <p>Number of Attendance</p>
                            </div>
                            <div class="icon"><i class="fas fa-calendar-check"></i></div>
                        </div>
                    </div>
                    <div class="col-lg-4 col-12">
                        <div class="small-box <?php echo $componentSelectionEnabled ? 'bg-warning' : 'bg-secondary'; ?>">
                            <div class="inner">
                                <h3><?php echo $componentSelectionEnabled ? 'Open' : 'Closed'; ?></h3>
                                <p>Component Selection</p>
                            </div>
                            <div class="icon"><i class="fas <?php echo $componentSelectionEnabled ? 'fa-unlock' : 'fa-lock'; ?>"></i></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-qrcode mr-2"></i>My QR Code</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($student): ?>
                            <?php $qrImage = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($student['generated_code']); ?>
                            <div class="qr-card">
                                <div class="qr-card-header d-flex justify-content-between align-items-center flex-wrap">
                                    <div>
                                        <h4 class="mb-1">TAU NSTP Student QR</h4>
                                        <div class="small">Use this card when scanning attendance.</div>
                                    </div>
                                    <div class="download-actions mt-3 mt-sm-0">
                                        <a href="endpoint/download-student-qr.php?format=png" class="btn btn-light btn-sm">
                                            <i class="fas fa-file-image mr-1"></i> PNG
                                        </a>
                                        <a href="endpoint/download-student-qr.php?format=jpg" class="btn btn-outline-light btn-sm">
                                            <i class="fas fa-download mr-1"></i> JPG
                                        </a>
                                    </div>
                                </div>
                                <div class="p-4">
                                    <div class="row align-items-center">
                                        <div class="col-lg-3 col-md-4 text-center mb-4 mb-md-0">
                                            <img src="<?php echo htmlspecialchars($student['formal_picture'] ?: 'include/logo.png'); ?>" alt="Student Picture" class="qr-profile mb-3">
                                            <img src="<?php echo htmlspecialchars($qrImage); ?>" alt="Student QR Code" class="img-thumbnail" style="max-width: 180px;">
                                        </div>
                                        <div class="col-lg-9 col-md-8">
                                            <div class="row">
                                                <?php
                                                $qrDetails = [
                                                    'Name' => $student['student_name'],
                                                    'College' => $student['college'] ?? 'N/A',
                                                    'Course' => $student['course'] ?? 'N/A',
                                                ];
                                                if (!empty($student['major']) && strtoupper($student['major']) !== 'N/A') {
                                                    $qrDetails['Major'] = $student['major'];
                                                }
                                                $qrDetails['Section'] = $student['year_section'] ?: ($student['original_section'] ?: 'N/A');
                                                $qrDetails['Component/Folder'] = $student['course_section'] ?: 'Public Registration';
                                                $qrDetails['Latest Attendance'] = $latestAttendance ? date('F d, Y h:i A', strtotime($latestAttendance['time_in'])) : 'No attendance yet';
                                                ?>
                                                <?php foreach ($qrDetails as $label => $value): ?>
                                                    <div class="col-sm-6 mb-3">
                                                        <span class="qr-detail-label"><?php echo htmlspecialchars($label); ?></span>
                                                        <span class="qr-detail-value"><?php echo htmlspecialchars($value); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="alert alert-light border mb-0">
                                                <span class="qr-detail-label">QR Code</span>
                                                <code><?php echo htmlspecialchars($student['generated_code']); ?></code>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-2"></i>
                                Choose your NSTP component first to generate your QR code.
                                <a href="component.php" class="alert-link">Go to Component</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($student): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-id-card mr-2"></i>My Registration Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($registrationSections as $sectionTitle => $details): ?>
                                <div class="col-lg-6 mb-3">
                                    <div class="detail-card">
                                        <h6 class="mb-3"><?php echo htmlspecialchars($sectionTitle); ?></h6>
                                        <?php foreach ($details as $label => $value): ?>
                                            <div class="detail-row">
                                                <div class="detail-label"><?php echo htmlspecialchars($label); ?></div>
                                                <div class="detail-value"><?php echo htmlspecialchars($value ?: 'N/A'); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
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
