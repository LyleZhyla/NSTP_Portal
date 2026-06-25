<?php
session_start();

require_once 'conn/conn.php';
require_once 'include/attendance-settings.php';
require_once 'include/profile-picture-utils.php';
require_once 'include/data-edit-requests.php';


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: landing_page.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';
$componentOptions = ['CWTS', 'LTS', 'ROTC'];

// Handle profile picture upload
if (isset($_POST['upload_picture'])) {
    try {
        $target_path = uploadProfilePicture($_FILES['profile_picture'] ?? [], __DIR__, 'profile_' . $user_id);
        if ($target_path) {
            // Get old profile picture to delete
            $stmt = $conn->prepare("SELECT profile_picture FROM tbl_users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $old_picture = $stmt->fetchColumn();

            deleteProfilePictureFile($old_picture, __DIR__);

            // Update database
            $stmt = $conn->prepare("UPDATE tbl_users SET profile_picture = ? WHERE user_id = ?");

            if ($stmt->execute([$target_path, $user_id])) {
                // Update session with new profile picture
                $_SESSION['profile_picture'] = $target_path;
                $message = "Profile picture updated successfully!";
            } else {
                $error = "Error updating database.";
            }
        } else {
            $error = "Please select an image file.";
        }
    } catch (RuntimeException $uploadError) {
        $error = $uploadError->getMessage();
    }
}

// Handle remove profile picture
if (isset($_POST['remove_picture'])) {
    $stmt = $conn->prepare("SELECT profile_picture FROM tbl_users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $old_picture = $stmt->fetchColumn();
    
    deleteProfilePictureFile($old_picture, __DIR__);
    
    $stmt = $conn->prepare("UPDATE tbl_users SET profile_picture = NULL WHERE user_id = ?");
    
    if ($stmt->execute([$user_id])) {
        // Remove from session
        $_SESSION['profile_picture'] = null;
        unset($_SESSION['profile_picture']);
        $message = "Profile picture removed successfully!";
    } else {
        $error = "Error removing profile picture.";
    }
}

// Handle password change
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    $stmt = $conn->prepare("SELECT password_hash FROM tbl_users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();
    
    if ($user_data && password_verify($current_password, $user_data['password_hash'])) {
        if ($new_password === $confirm_password) {
            if (strlen($new_password) >= 8) {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE tbl_users SET password_hash = ?, last_password_change = NOW() WHERE user_id = ?");
                
                if ($stmt->execute([$new_password_hash, $user_id])) {
                    $message = "Password changed successfully!";
                } else {
                    $error = "Error changing password.";
                }
            } else {
                $error = "Password must be at least 8 characters long.";
            }
        } else {
            $error = "New passwords do not match.";
        }
    } else {
        $error = "Current password is incorrect.";
    }
}

// Handle profile info update
if (isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);

    $stmt = $conn->prepare("SELECT * FROM tbl_users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $currentUserForUpdate = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentUserForUpdate) {
        $error = "User account not found.";
    } elseif (($currentUserForUpdate['role'] ?? '') !== 'super_admin') {
        try {
            submitDataEditRequest($conn, $currentUserForUpdate, [
                'full_name' => $full_name,
                'username' => $username,
                'email' => $email,
            ], $_POST['request_reason'] ?? '');
            $message = "Your data edit request was sent to the super admin for review.";
        } catch (Throwable $requestError) {
            $error = $requestError->getMessage();
        }
    } else {
        $check_sql = "SELECT user_id FROM tbl_users WHERE (username = ? OR email = ?) AND user_id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->execute([$username, $email, $user_id]);

        if ($check_stmt->rowCount() == 0) {
            $update_sql = "UPDATE tbl_users SET full_name = ?, email = ?, username = ? WHERE user_id = ?";
            $update_stmt = $conn->prepare($update_sql);

            if ($update_stmt->execute([$full_name, $email, $username, $user_id])) {
                $sync_stmt = $conn->prepare("UPDATE tbl_student SET student_name = ? WHERE user_id = ?");
                $sync_stmt->execute([$full_name, $user_id]);
                $_SESSION['full_name'] = $full_name;
                $_SESSION['username'] = $username;
                $_SESSION['email'] = $email;
                $message = "Profile updated successfully!";
            } else {
                $error = "Error updating profile.";
            }
        } else {
            $error = "Username or email already exists.";
        }
    }
}

// Get user data
$stmt = $conn->prepare("SELECT * FROM tbl_users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$pendingDataEditRequest = $user ? dataEditRequestPendingForUser($conn, $user_id) : null;

$isStudent = $user && ($user['role'] ?? '') === 'student';
$studentRecord = null;
$studentRegistrationSections = [];
$studentAttendanceCount = 0;
$studentLatestAttendance = null;
$studentTodayAttendance = null;
$studentAttendanceDayActive = false;
$studentAttendanceRecords = [];
$studentAttendanceSummary = [
    'present' => 0,
    'late' => 0,
    'absent_today' => 0,
];

function studentAttendanceDisplay($status, $timeIn = null) {
    $rawStatus = trim((string) $status);
    $statusText = 'Present';
    $badgeClass = 'success';
    $session = '';

    if (stripos($rawStatus, 'Late') === 0) {
        $statusText = 'Late';
        $badgeClass = 'warning';
    } elseif (stripos($rawStatus, 'Absent') === 0) {
        $statusText = 'Absent';
        $badgeClass = 'danger';
    } elseif ($rawStatus === '') {
        $statusText = 'Present';
        $badgeClass = 'success';
    }

    if (strpos($rawStatus, '-') !== false) {
        $parts = array_map('trim', explode('-', $rawStatus, 2));
        $session = $parts[1] ?? '';
    } elseif ($timeIn) {
        $session = ((int) date('G', strtotime($timeIn)) < 12) ? 'Morning' : 'Afternoon';
    }

    return [
        'status' => $statusText,
        'badge' => $badgeClass,
        'session' => $session,
    ];
}

if ($isStudent) {
    $stmt = $conn->prepare("
        SELECT s.*, r.registration_id, r.last_name, r.extension_name, r.first_name, r.middle_name, r.place_of_birth,
               r.date_of_birth, r.gender, r.religion, r.blood_type, r.contact_number, r.email AS registration_email,
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
    $stmt->execute([$user_id]);
    $studentRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($studentRecord) {
        if (isset($_POST['submit_registration_edit_request'])) {
            try {
                submitRegistrationDataEditRequest($conn, $user, $studentRecord, $_POST['registration'] ?? [], $_POST['registration_request_reason'] ?? '');
                $message = "Your registration details edit request was sent to the super admin for review.";
                $pendingDataEditRequest = dataEditRequestPendingForUser($conn, $user_id);
            } catch (Throwable $registrationRequestError) {
                $error = $registrationRequestError->getMessage();
            }
        }

        $summaryCounts = studentAttendanceHistoricalSummary($conn, $studentRecord);
        $studentAttendanceCount = (int) ($summaryCounts['total'] ?? 0);
        $studentLatestAttendance = studentAttendanceTimeline($conn, $studentRecord, 1)[0] ?? null;
        $studentTodayAttendance = studentAttendanceTimeline($conn, $studentRecord, 1, date('Y-m-d'))[0] ?? null;
        $studentAttendanceDayActive = hasAttendanceForStudentScopeOnDate($conn, $studentRecord);

        $studentAttendanceRecords = studentAttendanceTimeline($conn, $studentRecord, 30);
        $studentAttendanceSummary['late'] = (int) ($summaryCounts['late'] ?? 0);
        $studentAttendanceSummary['present'] = (int) ($summaryCounts['present'] ?? 0);
        $studentAttendanceSummary['absent_today'] = (!$studentTodayAttendance && $studentAttendanceDayActive) ? 1 : 0;

        $studentAddress = implode(', ', array_filter([
            $studentRecord['house_no'] ?? '',
            $studentRecord['street'] ?? '',
            $studentRecord['barangay'] ?? '',
            $studentRecord['city_municipality'] ?? '',
            $studentRecord['province'] ?? '',
        ], fn($value) => trim((string) $value) !== '' && strtoupper(trim((string) $value)) !== 'N/A')) ?: 'N/A';
        $studentComponent = normalizeProgram($studentRecord['component'] ?? '')
            ?: normalizeProgram($studentRecord['course_section'] ?? '')
            ?: ($studentRecord['component'] ?? 'N/A');
        $studentAssignedSection = trim((string) ($studentRecord['course_section'] ?? ''));

        $studentRegistrationSections = [
            'Personal Information' => [
                'Full Name' => $studentRecord['student_name'] ?? ($user['full_name'] ?? 'N/A'),
                'Student Number' => $studentRecord['student_number'] ?? ($studentRecord['registration_student_number'] ?? 'N/A'),
                'Gender' => $studentRecord['gender'] ?? 'N/A',
                'Date of Birth' => !empty($studentRecord['date_of_birth']) && $studentRecord['date_of_birth'] !== '0000-00-00' ? date('F d, Y', strtotime($studentRecord['date_of_birth'])) : 'N/A',
                'Place of Birth' => $studentRecord['place_of_birth'] ?? 'N/A',
                'Religion' => $studentRecord['religion'] ?? 'N/A',
                'Blood Type' => $studentRecord['blood_type'] ?? 'N/A',
            ],
            'Contact Information' => [
                'Email' => $studentRecord['registration_email'] ?? ($user['email'] ?? 'N/A'),
                'Contact Number' => $studentRecord['contact_number'] ?? 'N/A',
                'Address' => $studentAddress,
            ],
            'Emergency Contact' => [
                'Name' => $studentRecord['emergency_name'] ?? 'N/A',
                'Relationship' => $studentRecord['emergency_relationship'] ?? 'N/A',
                'Contact Number' => $studentRecord['emergency_contact_number'] ?? 'N/A',
                'Address' => $studentRecord['emergency_address'] ?? 'N/A',
            ],
            'Academic Information' => [
                'College' => $studentRecord['college'] ?? 'N/A',
                'Course' => $studentRecord['course'] ?? 'N/A',
                'Major' => $studentRecord['major'] ?? 'N/A',
                'Year and Section' => $studentRecord['year_section'] ?? ($studentRecord['original_section'] ?? 'N/A'),
                'Component' => $studentComponent,
                'Section' => $studentAssignedSection !== '' ? $studentAssignedSection : 'Unassigned',
            ],
        ];
    }
}

// Get user's assigned sections
$sections = [];
if ($user && $user['role'] == 'facilitator') {
    $section_sql = "SELECT course_section FROM tbl_admin_sections WHERE user_id = ? ORDER BY course_section";
    $section_stmt = $conn->prepare($section_sql);
    $section_stmt->execute([$user_id]);
    $sections = $section_stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get total students managed
$student_count = 0;
if ($isStudent) {
    $student_count = $studentAttendanceCount;
} else {
    $student_sql = "SELECT COUNT(*) as total FROM tbl_student WHERE created_by = ?";
    $student_stmt = $conn->prepare($student_sql);
    $student_stmt->execute([$user_id]);
    $student_count = $student_stmt->fetchColumn();
}

// Get recent activity
if ($isStudent && $studentRecord) {
    $activities = studentAttendanceTimeline($conn, $studentRecord, 10);
    foreach ($activities as &$activity) {
        $activity['student_name'] = $studentRecord['student_name'] ?? ($user['full_name'] ?? '');
        $activity['course_section'] = $studentRecord['course_section'] ?? '';
    }
    unset($activity);
} else {
    $activity_sql = "
        SELECT a.*, s.student_name, s.course_section
        FROM tbl_attendance a
        JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
        WHERE s.created_by = ?
        ORDER BY a.time_in DESC
        LIMIT 10
    ";
    $activity_stmt = $conn->prepare($activity_sql);
    $activity_stmt->execute([$user_id]);
    $activities = $activity_stmt->fetchAll();
}

// Get initials for profile
$initials = '';
if (!empty($user['full_name'])) {
    $nameParts = explode(' ', $user['full_name']);
    $initials = strtoupper(substr($nameParts[0], 0, 1));
    if (isset($nameParts[1])) {
        $initials .= strtoupper(substr($nameParts[1], 0, 1));
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile - TAU NSTP National Service Training Program</title>
      <?php include('./include/theme-loader.php'); ?>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="shortcut icon" href="include/logo.png">
    
    <!-- Google Fonts -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Bootstrap 4 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    
    <!-- AdminLTE -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    
    <!-- Theme CSS -->
    <link rel="stylesheet" href="include/theme.css">
    
    <style>
        /* Profile Card Styles */
        .profile-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .profile-header {
            background: linear-gradient(135deg, #198754 0%, #0f5132 100%);
            padding: 30px 20px;
            color: white;
            text-align: center;
            position: relative;
        }
        
        /* Avatar Container */
        .avatar-container {
            position: relative;
            display: inline-block;
            margin-bottom: 15px;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            object-fit: cover;
        }
        
        .profile-avatar-initials {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            background: linear-gradient(135deg, #5a67d8 0%, #6b46a0 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: bold;
            color: white;
            margin: 0 auto;
        }
        
        /* Upload Buttons */
        .upload-btn {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: #198754;
            color: white;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid white;
            transition: all 0.3s;
            z-index: 10;
        }
        
        .upload-btn:hover {
            background: #0056b3;
            transform: scale(1.1);
        }
        
        .remove-btn {
            position: absolute;
            bottom: 5px;
            left: 5px;
            background: #dc3545;
            color: white;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid white;
            transition: all 0.3s;
            z-index: 10;
            border: none;
        }
        
        .remove-btn:hover {
            background: #c82333;
            transform: scale(1.1);
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-card {
            background: #fff;
            color: #1f2933;
            border-radius: 10px;
            padding: 20px 15px;
            text-align: center;
            border: 1px solid #e1e8e4;
            border-left: 4px solid #2f6f7e;
            box-shadow: 0 8px 20px rgba(31, 41, 55, 0.08);
        }
        
        .stat-card.success {
            border-left-color: #198754;
        }
        
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 8px;
            color: #198754;
        }
        
        .stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            line-height: 1.2;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: #5f7168;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* Info Sections */
        .info-section {
            padding: 0 20px 20px;
        }
        
        .info-title {
            font-size: 1rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .info-title i {
            color: #198754;
            margin-right: 8px;
        }
        
        .info-item {
            margin-bottom: 12px;
        }
        
        .info-label {
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 2px;
        }
        
        .info-label i {
            width: 18px;
            color: #198754;
        }
        
        .info-value {
            font-size: 0.95rem;
            color: #343a40;
            font-weight: 500;
            padding-left: 22px;
            word-break: break-word;
        }
        
        .section-badge {
            display: inline-block;
            background: #f3faf6;
            color: #0f5132;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin: 0 3px 5px 0;
            border-left: 3px solid #198754;
        }
        
        /* Tabs */
        .nav-tabs {
            border-bottom: 2px solid #e9ecef;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            padding: 12px 20px;
            font-weight: 500;
            transition: all 0.3s;
            background: transparent;
        }
        
        .nav-tabs .nav-link i {
            margin-right: 8px;
            font-size: 1rem;
        }
        
        .nav-tabs .nav-link.active {
            color: #198754;
            background: transparent;
            border-bottom: 3px solid #198754;
        }
        
        .nav-tabs .nav-link:hover {
            color: #198754;
            background: rgba(0,123,255,0.05);
        }
        
        /* Tab Content */
        .tab-pane {
            padding: 20px 0;
        }
        
        /* Form Controls */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-control {
            border-radius: 20px;
            border: 1px solid #e9ecef;
            padding: 10px 15px;
            height: auto;
            font-size: 0.95rem;
        }
        
        .form-control:focus {
            border-color: #198754;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.1);
        }
        
        .input-group-text {
            border-radius: 20px 0 0 20px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            color: #495057;
        }
        
        .input-group-append .input-group-text {
            border-radius: 0 20px 20px 0;
        }
        
        /* Password Strength */
        .password-strength .progress {
            border-radius: 10px;
            height: 6px;
            margin-top: 8px;
        }
        
        /* Buttons */
        .btn {
            border-radius: 20px;
            padding: 8px 25px;
            font-weight: 500;
            transition: all 0.3s;
            font-size: 0.95rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #198754 0%, #0f5132 100%);
            border: none;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #0f5132 0%, #198754 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }
        
        .btn-secondary {
            background: #6c757d;
            border: none;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .btn-danger {
            background: #dc3545;
            border: none;
        }
        
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        
        /* Badge */
        .badge-light {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        
        /* Alert */
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }
        
        /* Timeline */
        .timeline {
            position: relative;
            padding: 10px 0;
        }
        
        .timeline-item {
            position: relative;
            padding-left: 50px;
            margin-bottom: 20px;
        }
        
        .timeline-badge {
            position: absolute;
            left: 0;
            top: 0;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #198754;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1;
        }
        
        .timeline:before {
            content: '';
            position: absolute;
            left: 17px;
            top: 10px;
            bottom: 0;
            width: 2px;
            background: #e9ecef;
        }
        
        .time-label {
            margin-bottom: 20px;
        }
        
        .time-label span {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            background: #198754;
            color: white;
            font-size: 0.9rem;
        }
        
        /* Activity Card */
        .activity-card {
            border: 1px solid #e9ecef;
            border-radius: 10px;
            margin-bottom: 10px;
            overflow: hidden;
        }
        
        .activity-header {
            background: #f8f9fa;
            padding: 10px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .activity-body {
            padding: 10px 15px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .profile-avatar, .profile-avatar-initials {
                width: 100px;
                height: 100px;
                font-size: 40px;
            }
            
            .upload-btn, .remove-btn {
                width: 32px;
                height: 32px;
            }
            
            .stat-number {
                font-size: 1.5rem;
            }
            
            .nav-tabs .nav-link {
                padding: 8px 12px;
                font-size: 0.85rem;
            }
            
            .nav-tabs .nav-link i {
                margin-right: 4px;
            }
        }
        
        /* Dark mode adjustments */
        body.dark-mode .info-title {
            color: #e0e0e0;
            border-bottom-color: #404040;
        }
        
        body.dark-mode .info-label {
            color: #aaa;
        }
        
        body.dark-mode .info-value {
            color: #e0e0e0;
        }
        
        body.dark-mode .section-badge {
            background: #0f5132;
            color: #5faee3;
            border-left-color: #5faee3;
        }
        
        body.dark-mode .nav-tabs {
            border-bottom-color: #404040;
        }
        
        body.dark-mode .nav-tabs .nav-link {
            color: #aaa;
        }
        
        body.dark-mode .nav-tabs .nav-link.active {
            color: #5faee3;
            border-bottom-color: #5faee3;
        }
        
        body.dark-mode .nav-tabs .nav-link:hover {
            color: #5faee3;
            background: rgba(255,255,255,0.05);
        }
        
        body.dark-mode .input-group-text {
            background: #404040;
            border-color: #4a4a4a;
            color: #e0e0e0;
        }
        
        body.dark-mode .timeline:before {
            background: #404040;
        }
        
        body.dark-mode .activity-card {
            border-color: #404040;
        }
        
        body.dark-mode .activity-header {
            background: #2d2d2d;
            border-bottom-color: #404040;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">
        <!-- Navbar -->
        <nav class="main-header navbar navbar-expand navbar-white navbar-light">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" data-widget="pushmenu" href="#" role="button">
                        <i class="fas fa-bars"></i>
                    </a>
                </li>
                <li class="nav-item d-none d-sm-inline-block">
                    <a href="index.php" class="nav-link">Home</a>
                </li>
            </ul>
            
            <ul class="navbar-nav ml-auto">
                <?php include './include/header-notifications.php'; ?>
                <!-- Time Display -->
                <li class="nav-item">
                    <a class="nav-link" href="#">
                        <i class="far fa-clock mr-1"></i>
                        <span id="current-time"><?php echo date('h:i A'); ?></span>
                    </a>
                </li>
                
                <!-- Theme toggle will appear here if included -->
                
                <!-- User Menu -->
                <li class="nav-item dropdown">
                    <a class="nav-link" data-toggle="dropdown" href="#">
                        <i class="fas fa-user-circle"></i>
                        <?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user mr-2"></i> My Profile
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="./endpoint/logout.php" class="dropdown-item text-danger">
                            <i class="fas fa-sign-out-alt mr-2"></i> Logout
                        </a>
                    </div>
                </li>
            </ul>
        </nav>
        
        <!-- Sidebar -->
        <?php include 'adminlte-sidebar.php'; ?>
        
        <!-- Content Wrapper -->
        <div class="content-wrapper">
            <!-- Content Header -->
            <section class="content-header">
                <div class="container-fluid">
                    <div class="row mb-2">
                        <div class="col-sm-6">
                            <h1><i class="fas fa-user-circle mr-2"></i>My Profile</h1>
                        </div>
                        <div class="col-sm-6">
                            <ol class="breadcrumb float-sm-right">
                                <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                                <li class="breadcrumb-item active">My Profile</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </section>
            
            <!-- Main content -->
            <section class="content">
                <div class="container-fluid">
                    <!-- Alert Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <div class="row">
                        <!-- Left Column - Profile Info -->
                        <div class="col-md-4">
                            <!-- Profile Card -->
                            <div class="profile-card">
                                <div class="profile-header">
                                    <div class="avatar-container">
                                        <?php 
                                        // Check if user has profile picture
                                        $hasProfilePic = false;
                                        $profilePicPath = '';
                                        
                                        if (!empty($user['profile_picture']) && profilePictureExists($user['profile_picture'], __DIR__)) {
                                            $hasProfilePic = true;
                                            $profilePicPath = profilePictureUrl($user['profile_picture'], __DIR__);
                                        }
                                        
                                        if ($hasProfilePic): ?>
                                            <img src="<?php echo htmlspecialchars($profilePicPath); ?>" 
                                                 alt="Profile Picture" 
                                                 class="profile-avatar"
                                                 id="profileImage">
                                        <?php else: ?>
                                            <div class="profile-avatar-initials" id="profileInitials">
                                                <?php echo $initials ?: 'U'; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <label for="profilePictureInput" class="upload-btn" title="Change Profile Picture">
                                            <i class="fas fa-camera"></i>
                                        </label>
                                        
                                        <?php if ($hasProfilePic): ?>
                                            <form method="POST" style="display: inline;">
                                                <button type="submit" name="remove_picture" class="remove-btn" title="Remove Profile Picture" onclick="return confirm('Are you sure you want to remove your profile picture?');">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <h4 class="mt-3 mb-1"><?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?></h4>
                                    <p>
                                        <span class="badge-light">
                                            <i class="fas fa-shield-alt mr-1"></i>
                                            <?php echo ucfirst(str_replace('_', ' ', $user['role'] ?? 'facilitator')); ?>
                                        </span>
                                    </p>
                                </div>
                                
                                <!-- Hidden Upload Form -->
                                <form id="uploadForm" action="" method="POST" enctype="multipart/form-data" style="display: none;">
                                    <input type="file" id="profilePictureInput" name="profile_picture" accept="image/*">
                                    <input type="hidden" name="upload_picture" value="1">
                                </form>
                                
                                <!-- Stats -->
                                <div class="stats-grid px-3 pt-3">
                                    <div class="stat-card">
                                        <div class="stat-icon">
                                            <i class="fas fa-users"></i>
                                        </div>
                                        <div class="stat-number"><?php echo $student_count; ?></div>
                                        <div class="stat-label"><?php echo $isStudent ? 'Attendance' : 'Students'; ?></div>
                                    </div>
                                    <div class="stat-card success">
                                        <div class="stat-icon">
                                            <i class="fas fa-calendar-check"></i>
                                        </div>
                                        <div class="stat-number"><?php echo date('Y'); ?></div>
                                        <div class="stat-label">Year</div>
                                    </div>
                                </div>
                                
                                <!-- Account Information -->
                                <div class="info-section">
                                    <div class="info-title">
                                        <i class="fas fa-id-card"></i> Account Information
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-label">
                                            <i class="fas fa-user"></i> Username
                                        </div>
                                        <div class="info-value"><?php echo htmlspecialchars($user['username'] ?? ''); ?></div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-label">
                                            <i class="fas fa-envelope"></i> Email
                                        </div>
                                        <div class="info-value"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
                                    </div>

                                    <?php if ($isStudent): ?>
                                    <div class="info-item">
                                        <div class="info-label">
                                            <i class="fas fa-layer-group"></i> Component
                                        </div>
                                        <div class="info-value">
                                            <?php if (!empty($user['program'])): ?>
                                                <span class="section-badge"><?php echo htmlspecialchars($user['program']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Not selected yet</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($user && $user['role'] == 'facilitator' && !empty($sections)): ?>
                                    <div class="info-item">
                                        <div class="info-label">
                                            <i class="fas fa-book-open"></i> Assigned Sections
                                        </div>
                                        <div class="info-value">
                                            <?php foreach ($sections as $section): ?>
                                                <span class="section-badge">
                                                    <?php echo htmlspecialchars($section); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="info-item">
                                        <div class="info-label">
                                            <i class="fas fa-calendar-alt"></i> Member Since
                                        </div>
                                        <div class="info-value">
                                            <?php echo date('F d, Y', strtotime($user['created_at'] ?? date('Y-m-d'))); ?>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($user['last_password_change'])): ?>
                                    <div class="info-item">
                                        <div class="info-label">
                                            <i class="fas fa-clock"></i> Last Password Change
                                        </div>
                                        <div class="info-value">
                                            <?php echo date('F d, Y', strtotime($user['last_password_change'])); ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Right Column - Tabs and Content -->
                        <div class="col-md-8">
                            <?php if ($isStudent): ?>
                            <div class="profile-card">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="fas fa-id-badge mr-2"></i>Student QR</h5>
                                </div>
                                <div class="card-body">
                                    <div class="info-item">
                                        <div class="info-label"><i class="fas fa-calendar-check"></i> Number of Attendance</div>
                                        <div class="info-value"><?php echo $studentAttendanceCount; ?></div>
                                    </div>

                                    <?php if ($studentRecord): ?>
                                        <?php $qrImage = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($studentRecord['generated_code']); ?>
                                        <div class="row align-items-center">
                                            <div class="col-md-5 text-center mb-3 mb-md-0">
                                                <img src="<?php echo htmlspecialchars($qrImage); ?>" alt="Student QR Code" class="img-thumbnail" style="max-width: 220px;">
                                            </div>
                                            <div class="col-md-7">
                                                <div class="info-item">
                                                    <div class="info-label"><i class="fas fa-qrcode"></i> QR Code</div>
                                                    <div class="info-value"><code><?php echo htmlspecialchars($studentRecord['generated_code']); ?></code></div>
                                                </div>
                                                <div class="info-item">
                                                    <div class="info-label"><i class="fas fa-clock"></i> Latest Attendance</div>
                                                    <div class="info-value">
                                                        <?php echo $studentLatestAttendance ? date('F d, Y h:i A', strtotime($studentLatestAttendance['time_in'])) : 'No attendance yet'; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info mb-0">
                                            <i class="fas fa-info-circle mr-2"></i>
                                            Select your NSTP component in the Component tab to generate your QR code.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($isStudent && $studentRecord): ?>
                            <?php
                                $todayDisplay = $studentTodayAttendance
                                    ? studentAttendanceDisplay($studentTodayAttendance['status'] ?? '', $studentTodayAttendance['time_in'] ?? null)
                                    : ($studentAttendanceDayActive ? [
                                        'status' => 'Absent',
                                        'badge' => 'danger',
                                        'session' => ((int) date('G') < 12) ? 'Morning' : 'Afternoon',
                                    ] : [
                                        'status' => 'No Attendance',
                                        'badge' => 'secondary',
                                        'session' => '',
                                    ]);
                            ?>
                            <div class="profile-card">
                                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                                    <h5 class="mb-0"><i class="fas fa-calendar-check mr-2"></i>My Attendance</h5>
                                    <span class="badge badge-<?php echo $todayDisplay['badge']; ?>">
                                        Today: <?php echo htmlspecialchars($todayDisplay['status']); ?>
                                    </span>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center mb-3">
                                        <div class="col-md-4 mb-2 mb-md-0">
                                            <div class="info-item mb-0">
                                                <div class="info-label"><i class="fas fa-check-circle"></i> Present</div>
                                                <div class="info-value"><?php echo $studentAttendanceSummary['present']; ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4 mb-2 mb-md-0">
                                            <div class="info-item mb-0">
                                                <div class="info-label"><i class="fas fa-exclamation-circle"></i> Late</div>
                                                <div class="info-value"><?php echo $studentAttendanceSummary['late']; ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="info-item mb-0">
                                                <div class="info-label"><i class="fas fa-times-circle"></i> Absent Today</div>
                                                <div class="info-value"><?php echo $studentAttendanceSummary['absent_today']; ?></div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Time</th>
                                                    <th>Session</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!$studentTodayAttendance && $studentAttendanceDayActive): ?>
                                                    <tr>
                                                        <td><?php echo date('M d, Y'); ?></td>
                                                        <td>No scan</td>
                                                        <td><?php echo htmlspecialchars($todayDisplay['session']); ?></td>
                                                        <td><span class="badge badge-danger">Absent</span></td>
                                                    </tr>
                                                <?php endif; ?>

                                                <?php foreach ($studentAttendanceRecords as $attendanceRecord): ?>
                                                    <?php $attendanceDisplay = studentAttendanceDisplay($attendanceRecord['status'] ?? '', $attendanceRecord['time_in'] ?? null); ?>
                                                    <tr>
                                                        <td><?php echo date('M d, Y', strtotime($attendanceRecord['time_in'])); ?></td>
                                                        <td><?php echo date('h:i A', strtotime($attendanceRecord['time_in'])); ?></td>
                                                        <td><?php echo htmlspecialchars($attendanceDisplay['session'] ?: 'N/A'); ?></td>
                                                        <td>
                                                            <span class="badge badge-<?php echo $attendanceDisplay['badge']; ?>">
                                                                <?php echo htmlspecialchars($attendanceDisplay['status']); ?>
                                                            </span>
                                                            <?php if (!empty($attendanceRecord['is_archived'])): ?>
                                                                <span class="badge badge-secondary ml-1">Archived</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>

                                                <?php if (count($studentAttendanceRecords) === 0 && !(!$studentTodayAttendance && $studentAttendanceDayActive)): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center text-muted py-4">No attendance records yet</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($isStudent && $studentRecord): ?>
                            <div class="profile-card">
                                <div class="card-header bg-white">
                                    <h5 class="mb-0"><i class="fas fa-address-card mr-2"></i>Registration Details</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($studentRegistrationSections as $sectionTitle => $details): ?>
                                            <div class="col-lg-6 mb-3">
                                                <div class="info-section h-100 mb-0">
                                                    <div class="info-title">
                                                        <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($sectionTitle); ?>
                                                    </div>
                                                    <?php foreach ($details as $label => $value): ?>
                                                        <div class="info-item">
                                                            <div class="info-label"><?php echo htmlspecialchars($label); ?></div>
                                                            <div class="info-value"><?php echo htmlspecialchars($value ?: 'N/A'); ?></div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <hr>
                                    <?php if ($pendingDataEditRequest): ?>
                                        <div class="alert alert-info mb-0">
                                            <i class="fas fa-hourglass-half mr-2"></i>
                                            You already have a pending data edit request. You can submit another request after the super admin reviews it.
                                        </div>
                                    <?php else: ?>
                                        <button class="btn btn-outline-primary" type="button" data-toggle="collapse" data-target="#registrationEditRequestForm" aria-expanded="false" aria-controls="registrationEditRequestForm">
                                            <i class="fas fa-paper-plane mr-2"></i>Request Changes to Registration Details
                                        </button>
                                        <div class="collapse mt-3" id="registrationEditRequestForm">
                                            <form method="POST">
                                                <div class="row">
                                                    <?php foreach (registrationEditRequestFields() as $fieldKey => $fieldLabel): ?>
                                                        <?php
                                                            $fieldValue = $fieldKey === 'email'
                                                                ? ($studentRecord['registration_email'] ?? '')
                                                                : ($studentRecord[$fieldKey] ?? '');
                                                            $inputType = $fieldKey === 'date_of_birth' ? 'date' : ($fieldKey === 'email' ? 'email' : 'text');
                                                        ?>
                                                        <div class="col-md-6">
                                                            <div class="form-group">
                                                                <label for="registration_<?php echo htmlspecialchars($fieldKey); ?>"><?php echo htmlspecialchars($fieldLabel); ?></label>
                                                                <input
                                                                    type="<?php echo $inputType; ?>"
                                                                    class="form-control"
                                                                    id="registration_<?php echo htmlspecialchars($fieldKey); ?>"
                                                                    name="registration[<?php echo htmlspecialchars($fieldKey); ?>]"
                                                                    value="<?php echo htmlspecialchars($fieldValue ?? ''); ?>"
                                                                    <?php echo in_array($fieldKey, ['first_name', 'last_name', 'email'], true) ? 'required' : ''; ?>
                                                                >
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="form-group">
                                                    <label for="registration_request_reason">Reason for Request</label>
                                                    <textarea class="form-control" id="registration_request_reason" name="registration_request_reason" rows="3" placeholder="Briefly explain which registration details need correction."></textarea>
                                                </div>
                                                <button type="submit" name="submit_registration_edit_request" class="btn btn-primary">
                                                    <i class="fas fa-paper-plane mr-2"></i>Submit Registration Edit Request
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="profile-card">
                                <!-- Tabs -->
                                <div class="card-header bg-white">
                                    <ul class="nav nav-tabs card-header-tabs" id="profileTabs" role="tablist">
                                        <li class="nav-item">
                                            <a class="nav-link active" id="profile-tab" data-toggle="tab" href="#profile" role="tab">
                                                <i class="fas fa-user-edit"></i> Edit Profile
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link" id="password-tab" data-toggle="tab" href="#password" role="tab">
                                                <i class="fas fa-lock"></i> Change Password
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a class="nav-link" id="activity-tab" data-toggle="tab" href="#activity" role="tab">
                                                <i class="fas fa-history"></i> Recent Activity
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                                
                                <!-- Tab Content -->
                                <div class="card-body">
                                    <div class="tab-content">
                                        <!-- Edit Profile Tab -->
                                        <div class="tab-pane fade show active" id="profile" role="tabpanel">
                                            <?php if ($pendingDataEditRequest): ?>
                                                <?php $pendingRequestedData = dataEditRequestDecode($pendingDataEditRequest['requested_data'] ?? ''); ?>
                                                <div class="alert alert-info">
                                                    <i class="fas fa-hourglass-half mr-2"></i>
                                                    You have a pending data edit request submitted on
                                                    <?php echo date('M d, Y h:i A', strtotime($pendingDataEditRequest['created_at'])); ?>.
                                                    <div class="small mt-2">
                                                        Requested:
                                                        <?php echo htmlspecialchars(($pendingRequestedData['full_name'] ?? '') . ' / ' . ($pendingRequestedData['username'] ?? '') . ' / ' . ($pendingRequestedData['email'] ?? '')); ?>
                                                    </div>
                                                </div>
                                            <?php elseif (($user['role'] ?? '') !== 'super_admin'): ?>
                                                <div class="alert alert-warning">
                                                    <i class="fas fa-user-check mr-2"></i>
                                                    Profile changes are sent to the super admin for approval before they update your account.
                                                </div>
                                            <?php endif; ?>
                                            <form action="" method="POST" id="profileForm">
                                                <div class="form-group">
                                                    <label for="full_name">Full Name</label>
                                                    <div class="input-group">
                                                        <div class="input-group-prepend">
                                                            <span class="input-group-text">
                                                                <i class="fas fa-user"></i>
                                                            </span>
                                                        </div>
                                                        <input type="text" class="form-control" id="full_name" name="full_name" 
                                                               value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label for="username">Username</label>
                                                    <div class="input-group">
                                                        <div class="input-group-prepend">
                                                            <span class="input-group-text">
                                                                <i class="fas fa-at"></i>
                                                            </span>
                                                        </div>
                                                        <input type="text" class="form-control" id="username" name="username" 
                                                               value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
                                                    </div>
                                                    <small class="text-muted">Username must be unique</small>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label for="email">Email Address</label>
                                                    <div class="input-group">
                                                        <div class="input-group-prepend">
                                                            <span class="input-group-text">
                                                                <i class="fas fa-envelope"></i>
                                                            </span>
                                                        </div>
                                                        <input type="email" class="form-control" id="email" name="email" 
                                                               value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                                                    </div>
                                                </div>

                                                <?php if (($user['role'] ?? '') !== 'super_admin'): ?>
                                                <div class="form-group">
                                                    <label for="request_reason">Reason for Request</label>
                                                    <textarea class="form-control" id="request_reason" name="request_reason" rows="3" placeholder="Briefly explain why this data needs to be changed."></textarea>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <div class="mt-4">
                                                    <button type="submit" name="update_profile" class="btn btn-primary" <?php echo $pendingDataEditRequest ? 'disabled' : ''; ?>>
                                                        <i class="fas <?php echo (($user['role'] ?? '') === 'super_admin') ? 'fa-save' : 'fa-paper-plane'; ?> mr-2"></i>
                                                        <?php echo (($user['role'] ?? '') === 'super_admin') ? 'Save Changes' : 'Submit Request'; ?>
                                                    </button>
                                                    <button type="reset" class="btn btn-secondary ml-2">
                                                        <i class="fas fa-undo mr-2"></i>Reset
                                                    </button>
                                                </div>
                                            </form>
                                        </div>

                                        <!-- Change Password Tab -->
                                        <div class="tab-pane fade" id="password" role="tabpanel">
                                            <form action="" method="POST" id="passwordForm">
                                                <div class="form-group">
                                                    <label for="current_password">Current Password</label>
                                                    <div class="input-group">
                                                        <div class="input-group-prepend">
                                                            <span class="input-group-text">
                                                                <i class="fas fa-lock"></i>
                                                            </span>
                                                        </div>
                                                        <input type="password" class="form-control" id="current_password" 
                                                               name="current_password" required>
                                                        <div class="input-group-append">
                                                            <span class="input-group-text toggle-password" style="cursor: pointer;">
                                                                <i class="fas fa-eye"></i>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label for="new_password">New Password</label>
                                                    <div class="input-group">
                                                        <div class="input-group-prepend">
                                                            <span class="input-group-text">
                                                                <i class="fas fa-key"></i>
                                                            </span>
                                                        </div>
                                                        <input type="password" class="form-control" id="new_password" 
                                                               name="new_password" required>
                                                        <div class="input-group-append">
                                                            <span class="input-group-text toggle-password" style="cursor: pointer;">
                                                                <i class="fas fa-eye"></i>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="password-strength">
                                                        <div class="progress">
                                                            <div class="progress-bar" id="passwordStrengthBar" 
                                                                 role="progressbar" style="width: 0%;"></div>
                                                        </div>
                                                    </div>
                                                    <small class="text-muted">
                                                        Password must be at least 8 characters long.
                                                    </small>
                                                </div>
                                                
                                                <div class="form-group">
                                                    <label for="confirm_password">Confirm New Password</label>
                                                    <div class="input-group">
                                                        <div class="input-group-prepend">
                                                            <span class="input-group-text">
                                                                <i class="fas fa-check-circle"></i>
                                                            </span>
                                                        </div>
                                                        <input type="password" class="form-control" id="confirm_password" 
                                                               name="confirm_password" required>
                                                        <div class="input-group-append">
                                                            <span class="input-group-text toggle-password" style="cursor: pointer;">
                                                                <i class="fas fa-eye"></i>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <small id="passwordMatchMsg" class="form-text"></small>
                                                </div>
                                                
                                                <div class="mt-4">
                                                    <button type="submit" name="change_password" class="btn btn-primary" id="changePasswordBtn">
                                                        <i class="fas fa-key mr-2"></i>Change Password
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                        
                                        <!-- Recent Activity Tab -->
                                        <div class="tab-pane fade" id="activity" role="tabpanel">
                                            <?php if (count($activities) > 0): ?>
                                                <div class="timeline">
                                                    <?php 
                                                    $current_date = '';
                                                    foreach ($activities as $activity): 
                                                        $activity_date = date('Y-m-d', strtotime($activity['time_in']));
                                                        $activityDisplay = studentAttendanceDisplay($activity['status'] ?? '', $activity['time_in'] ?? null);
                                                    ?>
                                                        <?php if ($activity_date != $current_date): ?>
                                                            <?php $current_date = $activity_date; ?>
                                                            <div class="time-label">
                                                                <span>
                                                                    <i class="fas fa-calendar mr-1"></i>
                                                                    <?php echo date('F d, Y', strtotime($activity_date)); ?>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <div class="timeline-item">
                                                            <div class="timeline-badge">
                                                                <i class="fas fa-clock"></i>
                                                            </div>
                                                            <div class="activity-card">
                                                                <div class="activity-header">
                                                                    <strong><?php echo htmlspecialchars($activity['student_name']); ?></strong>
                                                                    <span class="badge badge-<?php echo $activityDisplay['badge']; ?>">
                                                                        <?php echo htmlspecialchars($activityDisplay['status']); ?>
                                                                    </span>
                                                                    <?php if (!empty($activity['is_archived'])): ?>
                                                                        <span class="badge badge-secondary ml-1">Archived</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="activity-body">
                                                                    <div>
                                                                        <i class="fas fa-clock mr-1 text-muted"></i>
                                                                        <?php echo date('h:i A', strtotime($activity['time_in'])); ?>
                                                                    </div>
                                                                    <div class="mt-1">
                                                                        <i class="fas fa-book-open mr-1 text-muted"></i>
                                                                        <?php echo htmlspecialchars($activity['course_section']); ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-center py-5">
                                                    <i class="fas fa-history fa-4x text-muted mb-3"></i>
                                                    <h5 class="text-muted">No recent activity found</h5>
                                                    <p class="text-muted">Your attendance records will appear here</p>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        
        <!-- Footer -->
                <!-- Footer -->
                <!-- Footer -->
        <?php include 'footer.php'; ?>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Update time every second
            function updateTime() {
                const now = new Date();
                const hours = now.getHours();
                const minutes = now.getMinutes().toString().padStart(2, '0');
                const ampm = hours >= 12 ? 'PM' : 'AM';
                const formattedHours = (hours % 12 || 12).toString().padStart(2, '0');
                $('#current-time').text(`${formattedHours}:${minutes} ${ampm}`);
            }
            updateTime();
            setInterval(updateTime, 1000);
            
            // Toggle password visibility
            $('.toggle-password').click(function() {
                const input = $(this).closest('.input-group').find('input');
                const icon = $(this).find('i');
                
                if (input.attr('type') === 'password') {
                    input.attr('type', 'text');
                    icon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    input.attr('type', 'password');
                    icon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
            
            // Auto upload when file is selected
            $('#profilePictureInput').change(function() {
                $('#uploadForm').submit();
            });
            
            // Password strength checker
            $('#new_password').on('keyup', function() {
                const password = $(this).val();
                const strengthBar = $('#passwordStrengthBar');
                let strength = 0;
                
                if (password.length >= 8) strength += 40;
                if (password.match(/[A-Z]/)) strength += 15;
                if (password.match(/[a-z]/)) strength += 15;
                if (password.match(/[0-9]/)) strength += 15;
                if (password.match(/[^A-Za-z0-9]/)) strength += 15;
                
                strength = Math.min(strength, 100);
                strengthBar.css('width', strength + '%').attr('aria-valuenow', strength);
                
                if (strength < 40) {
                    strengthBar.removeClass('bg-success bg-warning').addClass('bg-danger');
                } else if (strength < 70) {
                    strengthBar.removeClass('bg-success bg-danger').addClass('bg-warning');
                } else {
                    strengthBar.removeClass('bg-danger bg-warning').addClass('bg-success');
                }
            });
            
            // Password match checker
            $('#confirm_password').on('keyup', function() {
                const password = $('#new_password').val();
                const confirm = $(this).val();
                const msg = $('#passwordMatchMsg');
                
                if (password === confirm) {
                    msg.html('<span class="text-success"><i class="fas fa-check mr-1"></i>Passwords match</span>');
                    $('#changePasswordBtn').prop('disabled', false);
                } else {
                    msg.html('<span class="text-danger"><i class="fas fa-times mr-1"></i>Passwords do not match</span>');
                    $('#changePasswordBtn').prop('disabled', true);
                }
            });
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                $('.alert').fadeOut('slow');
            }, 5000);
            
            // Form validation
            $('#profileForm').submit(function(e) {
                const email = $('#email').val();
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                
                if (!emailRegex.test(email)) {
                    e.preventDefault();
                    alert('Please enter a valid email address.');
                }
            });
            
            $('#passwordForm').submit(function(e) {
                if ($('#changePasswordBtn').prop('disabled')) {
                    e.preventDefault();
                    alert('Please make sure your passwords match.');
                }
            });
            
            // After successful upload, refresh the page to update all images
            <?php if ($message && strpos($message, 'Profile picture') !== false): ?>
            setTimeout(function() {
                location.reload();
            }, 1500);
            <?php endif; ?>
        });
    </script>
</body>
</html>
