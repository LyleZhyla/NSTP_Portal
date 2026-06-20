<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: landing_page.php");
    exit();
}

include('./conn/conn.php');
require_once './include/user-permissions.php';
require_once './include/automatic-sectioning.php';
require_once './include/section-folders.php';

// Get user info
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'facilitator';
$user_name = $_SESSION['full_name'] ?? 'User';
$user_program = normalizeProgram($_SESSION['program'] ?? null);
if (!$user_program) {
    $programStmt = $conn->prepare("SELECT program FROM tbl_users WHERE user_id = ?");
    $programStmt->execute([$user_id]);
    $user_program = normalizeProgram($programStmt->fetchColumn());
}
$isRotcFacilitator = $user_role === 'facilitator' && $user_program === 'ROTC';
ensureRotcAttendanceSchema($conn);

if (!canAccessStaffTools($user_role)) {
    header("Location: profile.php");
    exit();
}

function masterlistTableExists(PDO $conn, $tableName) {
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

function attachMasterlistRegistrationDetails(PDO $conn, array $students) {
    if (empty($students) || !masterlistTableExists($conn, 'tbl_public_student_registrations')) {
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

function masterlistDetailValue(array $student, $key) {
    $registration = $student['_registration'] ?? [];

    if (array_key_exists($key, $registration)) {
        return $registration[$key];
    }

    return $student[$key] ?? '';
}

function displayMasterlistDetailValue($value) {
    $value = trim((string) ($value ?? ''));
    return $value === '' ? 'N/A' : $value;
}

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

// Get admin's ALL assigned sections (folders)
$sections_stmt = $conn->prepare("
    SELECT course_section 
    FROM tbl_admin_sections 
    WHERE user_id = ? 
    ORDER BY assigned_at ASC
");
$sections_stmt->execute([$user_id]);
$assignedSections = $sections_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get the first section for backward compatibility
$assignedSection = !empty($assignedSections) ? $assignedSections[0] : null;

// If no sections in tbl_admin_sections, check assigned_section field in tbl_users
if (empty($assignedSections)) {
    $stmt = $conn->prepare("SELECT assigned_section FROM tbl_users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $legacySection = $stmt->fetchColumn();
    if ($legacySection) {
        $assignedSections = [$legacySection];
        $assignedSection = $legacySection;
    }
}

// Get count of assigned sections
$sections_count = count($assignedSections);

$coordinatorProgram = null;
$coordinatorPendingStudents = [];
$coordinatorStudentsByFacilitator = [];
$coordinatorFacilitators = [];
$coordinatorFacilitatorFolders = [];
$coordinatorFolderCards = [];
$coordinatorFacilitatorCards = [];
$superAdminFolderCards = [];
$superAdminSystemFolderCards = [];
$superAdminComponentCards = [];
$superAdminExportFacilitators = [];
$studentManagementFolders = [];
$folderAssignableFacilitators = [];
$admins_with_sections = [];

if (in_array($user_role, ['super_admin', 'coordinator'], true)) {
    syncSectionFoldersFromExisting($conn);
}

if ($user_role === 'coordinator') {
    $coordinatorProgram = normalizeProgram($_SESSION['program'] ?? null);
    if (!$coordinatorProgram) {
        $stmt = $conn->prepare("SELECT program FROM tbl_users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $coordinatorProgram = normalizeProgram($stmt->fetchColumn());
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
        ORDER BY student_name ASC
    ");
    $stmt->execute([$coordinatorProgram, autoSectionFolderPrefix($coordinatorProgram) . ' %', $coordinatorProgram]);
    $coordinatorPendingStudents = attachMasterlistRegistrationDetails($conn, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $conn->prepare("
        SELECT user_id, full_name, username, program, assigned_section
        FROM tbl_users
        WHERE role = 'facilitator' AND program = ?
        ORDER BY full_name ASC, username ASC
    ");
    $stmt->execute([$coordinatorProgram]);
    $coordinatorFacilitators = $stmt->fetchAll();
    $folderAssignableFacilitators = $coordinatorFacilitators;

    foreach ($coordinatorFacilitators as $facilitator) {
        $coordinatorFacilitatorFolders[(int) $facilitator['user_id']] = [];
    }

    if (!empty($coordinatorFacilitators)) {
        $facilitatorIds = array_map(fn($facilitator) => (int) $facilitator['user_id'], $coordinatorFacilitators);
        $placeholders = implode(',', array_fill(0, count($facilitatorIds), '?'));
        $stmt = $conn->prepare("
            SELECT admin_section_id, user_id, course_section
            FROM tbl_admin_sections
            WHERE user_id IN ($placeholders)
            ORDER BY user_id ASC, course_section ASC
        ");
        $stmt->execute($facilitatorIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $folder) {
            $coordinatorFacilitatorFolders[(int) $folder['user_id']][] = [
                'assignment_id' => (int) $folder['admin_section_id'],
                'course_section' => $folder['course_section'],
            ];
        }
    }

    foreach ($coordinatorFacilitators as $facilitator) {
        $facilitatorId = (int) $facilitator['user_id'];
        $facilitatorName = trim($facilitator['full_name'] ?? '') ?: $facilitator['username'];
        $facilitatorStudentCount = 0;
        foreach ($coordinatorFacilitatorFolders[$facilitatorId] ?? [] as $folderInfo) {
            $folderName = $folderInfo['course_section'];
            $stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM tbl_student
                WHERE created_by = ? AND course_section = ?
            ");
            $stmt->execute([$facilitatorId, $folderName]);
            $folderStudentCount = (int) $stmt->fetchColumn();
            $facilitatorStudentCount += $folderStudentCount;
            $coordinatorFolderCards[] = [
                'assignment_id' => (int) $folderInfo['assignment_id'],
                'facilitator_id' => $facilitatorId,
                'facilitator_name' => $facilitatorName,
                'folder' => $folderName,
                'count' => $folderStudentCount,
            ];
        }
        $coordinatorFacilitatorCards[] = [
            'facilitator_id' => $facilitatorId,
            'facilitator_name' => $facilitatorName,
            'assigned_section' => $facilitator['assigned_section'] ?? '',
            'folder_count' => count($coordinatorFacilitatorFolders[$facilitatorId] ?? []),
            'student_count' => $facilitatorStudentCount,
        ];
    }
}

// FOR REGULAR ADMIN WITH MULTIPLE SECTIONS - Get students organized by section folder
if ($user_role === 'facilitator' && $isRotcFacilitator) {
    $rotcCondition = rotcMs1StudentSqlCondition('s');
    $stmt = $conn->prepare("
        SELECT s.*, s.original_section
        FROM tbl_student s
        WHERE {$rotcCondition}
        ORDER BY s.student_name ASC
    ");
    $stmt->execute();
    $result = $stmt->fetchAll();
} elseif ($user_role === 'facilitator' && $sections_count > 1) {
    // Get all students for this admin, organized by folder section
    $sections_with_students = [];
    
    foreach ($assignedSections as $section) {
        $stmt = $conn->prepare("
            SELECT s.*, s.original_section 
            FROM tbl_student s 
            WHERE s.created_by = ? AND s.course_section = ?
            ORDER BY s.student_name ASC
        ");
        $stmt->execute([$user_id, $section]);
        $students = $stmt->fetchAll();
        
        $sections_with_students[$section] = $students;
    }
    
} else if ($user_role === 'facilitator') {
    // Regular admin with single section - simple list
    if (!empty($assignedSection)) {
        $stmt = $conn->prepare("
            SELECT s.*, s.original_section 
            FROM tbl_student s 
            WHERE s.created_by = ? AND s.course_section = ?
            ORDER BY s.student_name DESC
        ");
        $stmt->execute([$user_id, $assignedSection]);
    } else {
        $stmt = $conn->prepare("
            SELECT s.*, s.original_section 
            FROM tbl_student s 
            WHERE 1 = 0
        ");
        $stmt->execute();
    }
    $result = $stmt->fetchAll();
}

// SUPER ADMIN - Get all data with folder organization
if ($user_role === 'coordinator') {
    $total_students = count($coordinatorPendingStudents);
    foreach ($coordinatorFolderCards as $folderCard) {
        $total_students += (int) ($folderCard['count'] ?? 0);
    }
    $my_students_count = $total_students;
} elseif ($user_role === 'super_admin') {
    $stmt = $conn->prepare("
        SELECT user_id, full_name, username, program
        FROM tbl_users
        WHERE role = 'facilitator'
        ORDER BY FIELD(program, 'CWTS', 'LTS', 'ROTC'), full_name ASC, username ASC
    ");
    $stmt->execute();
    $folderAssignableFacilitators = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach (['CWTS', 'LTS', 'ROTC'] as $componentName) {
        if ($componentName === 'ROTC') {
            $rotcCondition = rotcStudentSqlCondition('s');
            $stmt = $conn->prepare("
                SELECT COUNT(DISTINCT s.tbl_student_id)
                FROM tbl_student s
                LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
                WHERE {$rotcCondition}
                   OR (creator.role = 'facilitator' AND creator.program = 'ROTC')
            ");
            $stmt->execute();
        } else {
        $stmt = $conn->prepare("
            SELECT COUNT(DISTINCT s.tbl_student_id)
            FROM tbl_student s
            LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
            WHERE (creator.role = 'facilitator' AND creator.program = ?)
               OR (
                    (s.created_by IS NULL OR creator.role <> 'facilitator')
                    AND (s.course_section = ? OR s.course_section LIKE ?)
               )
        ");
        $stmt->execute([$componentName, $componentName, autoSectionFolderPrefix($componentName) . ' %']);
        }

        $facilitatorStmt = $conn->prepare("SELECT COUNT(*) FROM tbl_users WHERE role = 'facilitator' AND program = ?");
        $facilitatorStmt->execute([$componentName]);

        $superAdminComponentCards[] = [
            'component' => $componentName,
            'student_count' => (int) $stmt->fetchColumn(),
            'facilitator_count' => (int) $facilitatorStmt->fetchColumn(),
        ];
    }

    $folderStmt = $conn->prepare("
        SELECT
            ads.user_id AS facilitator_id,
            ads.course_section AS folder,
            facilitator.full_name AS facilitator_name,
            facilitator.username AS facilitator_username,
            facilitator.program,
            COUNT(s.tbl_student_id) AS student_count
        FROM tbl_admin_sections ads
        INNER JOIN tbl_users facilitator ON ads.user_id = facilitator.user_id
        LEFT JOIN tbl_student s ON s.created_by = ads.user_id AND s.course_section = ads.course_section
        WHERE facilitator.role = 'facilitator'
        GROUP BY ads.user_id, ads.course_section, facilitator.full_name, facilitator.username, facilitator.program
        ORDER BY FIELD(facilitator.program, 'CWTS', 'LTS', 'ROTC'), facilitator.full_name ASC, ads.course_section ASC
    ");
    $folderStmt->execute();
    $superAdminFolderCards = $folderStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($superAdminFolderCards as $folderCard) {
        $facilitatorId = (int) $folderCard['facilitator_id'];
        if (!isset($superAdminExportFacilitators[$facilitatorId])) {
            $superAdminExportFacilitators[$facilitatorId] = [
                'user_id' => $facilitatorId,
                'full_name' => $folderCard['facilitator_name'],
                'username' => $folderCard['facilitator_username'],
                'program' => $folderCard['program'],
                'student_count' => 0,
            ];
        }
        $superAdminExportFacilitators[$facilitatorId]['student_count'] += (int) $folderCard['student_count'];
    }

    $systemFolderStmt = $conn->prepare("
        SELECT
            COALESCE(NULLIF(s.course_section, ''), 'Unassigned') AS folder,
            COUNT(s.tbl_student_id) AS student_count
        FROM tbl_student s
        LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
        WHERE s.created_by IS NULL OR creator.role <> 'facilitator'
        GROUP BY COALESCE(NULLIF(s.course_section, ''), 'Unassigned')
        ORDER BY folder ASC
    ");
    $systemFolderStmt->execute();
    $superAdminSystemFolderCards = $systemFolderStmt->fetchAll(PDO::FETCH_ASSOC);

    $my_students_by_section = [];
    $system_students = [];
    $system_by_section = [];
}

if ($user_role === 'coordinator' && $coordinatorProgram) {
    $stmt = $conn->prepare("
        SELECT
            f.folder_id,
            f.program,
            f.course_section,
            f.created_at,
            assigned.user_id AS facilitator_id,
            assigned.full_name AS facilitator_name,
            assigned.username AS facilitator_username,
            COUNT(DISTINCT s.tbl_student_id) AS student_count
        FROM tbl_section_folders f
        LEFT JOIN (
            tbl_admin_sections ads
            INNER JOIN tbl_users assigned
                ON assigned.user_id = ads.user_id
               AND assigned.role = 'facilitator'
        ) ON ads.course_section = f.course_section
           AND assigned.program = f.program
        LEFT JOIN tbl_student s ON s.course_section = f.course_section
        WHERE f.program = ?
        GROUP BY f.folder_id, f.program, f.course_section, f.created_at, assigned.user_id, assigned.full_name, assigned.username
        ORDER BY f.course_section ASC
    ");
    $stmt->execute([$coordinatorProgram]);
    $studentManagementFolders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user_role === 'super_admin') {
    $stmt = $conn->prepare("
        SELECT
            f.folder_id,
            f.program,
            f.course_section,
            f.created_at,
            assigned.user_id AS facilitator_id,
            assigned.full_name AS facilitator_name,
            assigned.username AS facilitator_username,
            COUNT(DISTINCT s.tbl_student_id) AS student_count
        FROM tbl_section_folders f
        LEFT JOIN (
            tbl_admin_sections ads
            INNER JOIN tbl_users assigned
                ON assigned.user_id = ads.user_id
               AND assigned.role = 'facilitator'
        ) ON ads.course_section = f.course_section
           AND assigned.program = f.program
        LEFT JOIN tbl_student s ON s.course_section = f.course_section
        GROUP BY f.folder_id, f.program, f.course_section, f.created_at, assigned.user_id, assigned.full_name, assigned.username
        ORDER BY FIELD(f.program, 'CWTS', 'LTS', 'ROTC'), f.course_section ASC
    ");
    $stmt->execute();
    $studentManagementFolders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get total counts for stats
if ($user_role === 'super_admin') {
    $total_stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_student");
    $total_stmt->execute();
    $total_students = $total_stmt->fetchColumn();
    
    $my_students_count = $conn->prepare("SELECT COUNT(*) FROM tbl_student WHERE created_by = ?");
    $my_students_count->execute([$user_id]);
    $my_students_count = $my_students_count->fetchColumn();
    
    $total_admins_stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_users WHERE role = 'facilitator'");
    $total_admins_stmt->execute();
    $total_admins = $total_admins_stmt->fetchColumn();

    $total_coordinators_stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_users WHERE role = 'coordinator'");
    $total_coordinators_stmt->execute();
    $total_coordinators = $total_coordinators_stmt->fetchColumn();
} elseif ($user_role === 'facilitator') {
    if (!empty($assignedSections)) {
        if ($isRotcFacilitator) {
            $rotcCondition = rotcMs1StudentSqlCondition('s');
            $total_stmt = $conn->prepare("
                SELECT COUNT(*)
                FROM tbl_student s
                WHERE {$rotcCondition}
            ");
            $total_stmt->execute();
        } else {
        $placeholders = implode(',', array_fill(0, count($assignedSections), '?'));
        $total_stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM tbl_student
            WHERE created_by = ? AND course_section IN ($placeholders)
        ");
        $total_stmt->execute(array_merge([$user_id], $assignedSections));
        }
    } elseif ($isRotcFacilitator) {
        $rotcCondition = rotcMs1StudentSqlCondition('s');
        $total_stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM tbl_student s
            WHERE {$rotcCondition}
        ");
        $total_stmt->execute();
    } else {
        $total_stmt = $conn->prepare("SELECT 0");
        $total_stmt->execute();
    }
    $total_students = $total_stmt->fetchColumn();
    $my_students_count = $total_students;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Masterlist TAU-NSTP</title>
     <?php include('./include/theme-loader.php'); ?>
    <!-- TAB LOGO - NSTP LOGO -->
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="shortcut icon" href="include/logo.png">
    <link rel="apple-touch-icon" href="include/logo.png">
    
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .student-table {
            max-height: 600px;
            overflow-y: auto;
        }
        
        .action-buttons .btn {
            margin: 2px;
        }
        
        .qr-modal-img {
            max-width: 300px;
            margin: 0 auto;
            display: block;
        }
        
        .user-badge {
            font-size: 0.85rem;
            padding: 5px 10px;
        }
        
        .permission-badge {
            font-size: 0.75rem;
            padding: 3px 8px;
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

        .qr-thumb {
            width: 74px;
            height: 74px;
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
        
        .section-info {
            font-size: 0.9rem;
            background: #f8fbf9;
            border-left: 4px solid #8bc4a4;
            padding: 10px 15px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        
        .section-badge-large {
            font-size: 1rem;
            padding: 8px 15px;
            margin: 0 5px;
        }
        
        .multiple-sections-container {
            background: #fff;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid #dfe7e2;
            border-left: 4px solid #198754;
            height: 100%;
            color: #1f2933;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-shadow: 0 8px 20px rgba(31, 41, 55, 0.08);
        }

        .multiple-sections-container .folder-summary-title {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-weight: 800;
            color: #0f5132;
        }

        .multiple-sections-container .folder-summary-title h4 {
            font-size: 1rem;
            margin: 0;
            font-weight: 800;
        }

        .multiple-sections-container .folder-summary-text {
            margin: 0 0 8px;
            color: #5f7168;
            font-size: 0.88rem;
            line-height: 1.3;
        }
        
        .section-chip {
            display: inline-block;
            padding: 4px 10px;
            margin: 2px;
            background: #f3faf6;
            border-radius: 25px;
            font-size: 0.82rem;
            border: 1px solid #d7eadf;
            color: #0f5132;
        }
        
        .section-chip i {
            margin-right: 5px;
        }
        
        .btn-spinner {
            position: relative;
            padding-left: 40px !important;
        }
        .btn-spinner .fa-spinner {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
        }
        
        /* Folder/Section Styles */
        .section-folder {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 15px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .section-folder:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .section-folder-header {
            background: #0f5132;
            color: white;
            padding: 15px 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
        }
        
        .section-folder-header.collapsed {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        }
        
        .section-folder-header:hover {
            opacity: 0.95;
        }
        
        .folder-icon {
            font-size: 1.2rem;
            margin-right: 15px;
            color: #ffd700;
        }
        
        .section-info-header {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .section-name {
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .section-stats {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .stat-badge {
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .stat-badge i {
            font-size: 0.9rem;
        }
        
        .expand-collapse-icon {
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }
        
        .section-folder-body {
            background: white;
            transition: all 0.3s ease;
        }
        
        .section-folder-body.collapsed {
            display: none;
        }
        
        .folder-table {
            margin: 0;
        }
        
        .folder-table thead {
            background: #f8f9fa;
        }
        
        .folder-table thead th {
            border-top: none;
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
        }
        
        .original-section-badge {
            background-color: #f3faf6;
            color: #0f5132;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85rem;
            display: inline-block;
            border-left: 3px solid #198754;
        }
        
        .folder-section-badge {
            background-color: #f0f0f0;
            color: #666;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            display: inline-block;
            border-left: 3px solid #666;
        }
        
        .empty-folder {
            padding: 40px;
            text-align: center;
            color: #6c757d;
        }
        
        .empty-folder i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .folder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
            gap: 12px;
        }

        .folder-box {
            display: block;
            min-height: 126px;
            padding: 14px;
            border: 1px solid #e1e8e4;
            border-radius: 8px;
            background: #fff;
            color: #1f2933;
            text-decoration: none;
            box-shadow: 0 8px 20px rgba(31, 41, 55, 0.06);
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }

        .folder-box:hover {
            color: #1f2933;
            text-decoration: none;
            transform: translateY(-2px);
            border-color: #198754;
            box-shadow: 0 12px 28px rgba(31, 41, 55, 0.12);
        }

        .folder-box-wrap {
            position: relative;
        }

        .folder-box-wrap .folder-box {
            height: 100%;
            padding-right: 54px;
        }

        .folder-delete-btn {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 2;
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .folder-box-icon {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f3faf6;
            color: #198754;
            font-size: 1.1rem;
            margin-bottom: 10px;
        }

        .folder-box-title {
            display: block;
            font-weight: 800;
            margin-bottom: 6px;
            line-height: 1.25;
        }

        .folder-box-meta {
            display: block;
            color: #5f7168;
            font-size: 0.87rem;
            line-height: 1.35;
        }

        .folder-box-count {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            padding: 5px 10px;
            border-radius: 999px;
            background: #f3faf6;
            color: #198754;
            font-weight: 700;
            font-size: 0.82rem;
        }

        .folder-box.pending {
            border-color: #ffe3a3;
            background: #fffaf0;
        }

        .folder-box.pending .folder-box-icon {
            background: #fff0c2;
            color: #946200;
        }
        
        /* Admin Folder Styles (for super admin) */
        .admin-folder {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 15px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .admin-folder-header {
            background: #0f5132;
            color: white;
            padding: 15px 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .admin-folder-header.collapsed {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        }
        
        .admin-info {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .admin-name {
            font-weight: bold;
            font-size: 1.1rem;
        }
        
        .admin-username {
            font-size: 0.9rem;
            opacity: 0.9;
            background: rgba(255,255,255,0.2);
            padding: 3px 10px;
            border-radius: 20px;
        }
        
        .my-folder .admin-folder-header {
            background: linear-gradient(135deg, #198754 0%, #0f5132 100%);
        }
        
        .system-folder .admin-folder-header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }
        
        /* Nested section folders inside admin folders */
        .nested-section-folder {
            border-left: 3px solid #198754;
            margin: 10px 20px;
            border-radius: 4px;
            background: #f8f9fa;
        }
        
        .nested-section-header {
            background: #3f5661;
            color: white;
            padding: 12px 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .nested-section-header.collapsed {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
        }
        
        .section-tag {
            display: inline-block;
            padding: 2px 8px;
            background: #e9ecef;
            border-radius: 4px;
            font-size: 0.8rem;
            color: #495057;
            margin-left: 5px;
        }
        
        .search-box {
            margin-bottom: 20px;
        }
        
        .search-box input {
            border-radius: 20px;
            padding: 10px 20px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }
        
        .search-box input:focus {
            border-color: #198754;
            box-shadow: none;
        }
        
        .admin-filter {
            margin-bottom: 20px;
        }
        
        .admin-filter .btn {
            border-radius: 20px;
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .admin-filter .btn.active {
            background: #198754;
            color: white;
            border-color: #198754;
        }
        
        .section-select-container {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .section-option {
            padding: 10px 15px;
            margin: 5px 0;
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .section-option:hover {
            border-color: #198754;
            background: #f0f2ff;
        }
        
        .section-option.selected {
            border-color: #198754;
            background: #e8eaff;
            position: relative;
        }
        
        .section-option.selected:after {
            content: '✓';
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #198754;
            font-weight: bold;
        }
        
        .section-badge {
            background: rgba(255,255,255,0.2);
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
        }
        
        .info-tooltip {
            cursor: help;
            border-bottom: 1px dotted #999;
        }
        
        /* Export Preview Styles */
        .export-preview-card {
            margin-top: 15px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }
        
        .export-preview-header {
            background: #f8f9fa;
            padding: 10px 15px;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
        }
        
        .export-preview-body {
            max-height: 300px;
            overflow-y: auto;
            padding: 10px;
        }
        
        .preview-item {
            padding: 8px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .preview-item:hover {
            background: #f8f9fa;
        }
        
        .preview-qr {
            width: 40px;
            height: 40px;
            border: 1px solid #ddd;
            padding: 2px;
        }
        
        @media (max-width: 768px) {
            .section-folder-header,
            .admin-folder-header,
            .nested-section-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .section-info-header,
            .admin-info {
                margin-bottom: 10px;
            }
            
            .section-stats,
            .admin-stats {
                width: 100%;
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
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

    <!-- Sidebar -->
    <?php include 'adminlte-sidebar.php'; ?>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <!-- Content Header -->
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0">
                            <i class="fas fa-folder-open mr-2"></i>
                            Student Masterlist
                        </h1>
                        <small>
                            Logged in as: 
                            <span class="badge badge-<?php echo ($user_role === 'super_admin') ? 'danger' : 'primary'; ?> user-badge">
                                <?php echo htmlspecialchars($user_name); ?> (<?php echo $user_role; ?>)
                            </span>
                        </small>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                            <li class="breadcrumb-item active">Students</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <section class="content">
            <div class="container-fluid">
                <!-- Quick Stats -->
                <div class="row mb-3">
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-info">
                            <div class="inner">
                                <h3><?php echo $total_students; ?></h3>
                                <p>
                                    <?php if ($user_role === 'super_admin'): ?>
                                    Total Students
                                    <?php else: ?>
                                    My Students
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($user_role === 'super_admin'): ?>
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-warning">
                            <div class="inner">
                                <h3><?php echo $total_coordinators; ?></h3>
                                <p>Coordinator Folders</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-user-shield"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-primary">
                            <div class="inner">
                                <h3><?php echo count($superAdminFolderCards) + count($superAdminSystemFolderCards); ?></h3>
                                <p>Folder Boxes</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-folder-tree"></i>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($user_role === 'facilitator' && $sections_count > 1): ?>
                    <div class="col-lg-9 col-12">
                        <div class="multiple-sections-container">
                            <div class="folder-summary-title">
                                <i class="fas fa-folder-tree"></i>
                                <h4>Your Admin Folders</h4>
                            </div>
                            <p class="folder-summary-text">
                                You have <strong><?php echo $sections_count; ?> admin folders</strong>. Students are organized by folder.
                                <span class="info-tooltip" title="Students will show their original college section separately">
                                    <i class="fas fa-info-circle ml-1"></i>
                                </span>
                            </p>
                            <div>
                                <?php foreach ($assignedSections as $section): ?>
                                <span class="section-chip">
                                    <i class="fas fa-folder"></i>
                                    <?php echo htmlspecialchars($section); ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($user_role === 'coordinator'): ?>
                    <div class="col-lg-3 col-6">
                        <div class="small-box bg-warning">
                            <div class="inner">
                                <h3><?php echo count($coordinatorFacilitators); ?></h3>
                                <p><?php echo htmlspecialchars($coordinatorProgram); ?> Facilitators</p>
                            </div>
                            <div class="icon">
                                <i class="fas fa-user-tie"></i>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Section Information for Regular Admins -->
                <?php if ($user_role === 'facilitator' && $sections_count <= 1): ?>
                <div class="row mb-3">
                    <div class="col-12">
                        <?php if ($isRotcFacilitator): ?>
                        <div class="section-info">
                            <i class="fas fa-layer-group mr-2"></i>
                            <strong>ROTC Access:</strong>
                            <span class="badge badge-primary section-badge-large">
                                <i class="fas fa-users mr-1"></i>
                                All ROTC Students
                            </span>
                        </div>
                        <?php elseif ($assignedSection): ?>
                        <div class="section-info">
                            <i class="fas fa-folder mr-2"></i>
                            <strong>Your Admin Folder:</strong>
                            <span class="badge badge-primary section-badge-large">
                                <i class="fas fa-folder-open mr-1"></i>
                                <?php echo htmlspecialchars($assignedSection); ?>
                            </span>
                            <span class="info-tooltip ml-2" title="Students will show their original college section separately">
                                <i class="fas fa-info-circle"></i>
                            </span>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>No Folders Assigned!</strong> You are not assigned to any admin folder. Please contact super admin to assign folders.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <?php if ($user_role === 'facilitator'): ?>
                <div class="row mb-3">
                    <div class="col-12">
                        <!-- Export QR Button -->
                        <button class="btn btn-info" data-toggle="modal" data-target="#exportQRModal"
                                <?php echo ($user_role === 'facilitator' && empty($assignedSections)) ? 'disabled' : ''; ?>>
                            <i class="fas fa-file-export mr-2"></i> Export QR Codes (ZIP)
                        </button>
                        
                        <!-- Quick Export Button for Single Section Admins -->
                        <?php if ($user_role === 'facilitator' && $sections_count == 1 && !empty($assignedSection)): ?>
                        <a href="./endpoint/export-qr-zip.php?section=<?php echo urlencode($assignedSection); ?>" 
                           class="btn btn-warning ml-2" target="_blank">
                            <i class="fas fa-file-archive mr-2"></i> Quick Export ZIP
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- SEARCH AND FILTER CONTROLS -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="search-box">
                            <input type="text" id="searchStudent" class="form-control" 
                                   placeholder="🔍 Search students by name or section...">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="admin-filter text-right">
                            <button class="btn btn-outline-secondary btn-sm" data-filter="all">All Folders</button>
                            <button class="btn btn-outline-secondary btn-sm" data-filter="expanded">Expand All</button>
                            <button class="btn btn-outline-secondary btn-sm active" data-filter="collapsed">Collapse All</button>
                        </div>
                    </div>
                </div>

                <!-- ==================== -->
                <!-- COORDINATOR VIEW - STUDENT INTAKE AND ASSIGNMENT -->
                <!-- ==================== -->
                <?php if (in_array($user_role, ['super_admin', 'coordinator'], true)): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-folder-tree mr-2"></i>
                            <?php echo $user_role === 'coordinator' ? htmlspecialchars($coordinatorProgram . ' Student Folders') : 'Student Folders'; ?>
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-sm btn-outline-danger mr-2 bulk-delete-student-folders-btn" disabled>
                                <i class="fas fa-trash mr-1"></i> Delete Selected
                            </button>
                            <button type="button" class="btn btn-sm btn-success" data-toggle="modal" data-target="#createStudentFolderModal">
                                <i class="fas fa-folder-plus mr-1"></i> Create Folder
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($studentManagementFolders)): ?>
                            <div class="d-flex flex-wrap align-items-center justify-content-between mb-2" style="gap: 8px;">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="selectAllStudentFoldersTop">
                                    <label class="form-check-label" for="selectAllStudentFoldersTop">Select all folders</label>
                                </div>
                                <button type="button" class="btn btn-sm btn-danger bulk-delete-student-folders-btn" disabled>
                                    <i class="fas fa-trash mr-1"></i> Delete Checked Folders
                                </button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover mb-0" id="studentFoldersTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 42px;" class="text-center">
                                                <input type="checkbox" id="selectAllStudentFolders" aria-label="Select all folders">
                                            </th>
                                            <th>Program</th>
                                            <th>Folder</th>
                                            <th>Students</th>
                                            <th>Facilitator</th>
                                            <th style="width: 310px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($studentManagementFolders as $folder): ?>
                                            <tr class="folder-summary-row"
                                                data-folder-name="<?php echo htmlspecialchars(strtolower((string) $folder['course_section']), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-folder-program="<?php echo htmlspecialchars(strtolower((string) $folder['program']), ENT_QUOTES, 'UTF-8'); ?>"
                                                data-folder-facilitator="<?php echo htmlspecialchars(strtolower((string) (($folder['facilitator_name'] ?: $folder['facilitator_username']) ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                                                <td class="text-center align-middle">
                                                    <input type="checkbox"
                                                           class="student-folder-check"
                                                           value="<?php echo (int) $folder['folder_id']; ?>"
                                                           data-folder-name="<?php echo htmlspecialchars($folder['course_section'], ENT_QUOTES, 'UTF-8'); ?>"
                                                           data-student-count="<?php echo (int) $folder['student_count']; ?>"
                                                           aria-label="Select <?php echo htmlspecialchars($folder['course_section'], ENT_QUOTES, 'UTF-8'); ?>">
                                                </td>
                                                <td><span class="badge badge-primary"><?php echo htmlspecialchars($folder['program']); ?></span></td>
                                                <td>
                                                    <a href="folder-students.php?scope=student_folder&folder=<?php echo urlencode($folder['course_section']); ?>" class="font-weight-bold">
                                                        <i class="fas fa-folder-open text-warning mr-1"></i>
                                                        <?php echo htmlspecialchars($folder['course_section']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info">
                                                        <i class="fas fa-users mr-1"></i><?php echo (int) $folder['student_count']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($folder['facilitator_id'])): ?>
                                                        <span class="badge badge-success">
                                                            <?php echo htmlspecialchars($folder['facilitator_name'] ?: $folder['facilitator_username']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">No facilitator assigned</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a class="btn btn-sm btn-outline-info mr-1" href="folder-students.php?scope=student_folder&folder=<?php echo urlencode($folder['course_section']); ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-danger mr-1 delete-student-folder"
                                                            data-folder-id="<?php echo (int) $folder['folder_id']; ?>"
                                                            data-folder-name="<?php echo htmlspecialchars($folder['course_section']); ?>"
                                                            data-student-count="<?php echo (int) $folder['student_count']; ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                    <?php if (empty($folder['facilitator_id'])): ?>
                                                        <form class="assign-folder-to-facilitator-form d-inline-flex" style="gap: 8px;">
                                                            <input type="hidden" name="course_section" value="<?php echo htmlspecialchars($folder['course_section']); ?>">
                                                            <select class="form-control form-control-sm" name="user_id" required>
                                                                <option value="">Select facilitator</option>
                                                                <?php foreach ($folderAssignableFacilitators as $facilitator): ?>
                                                                    <?php if (normalizeProgram($facilitator['program'] ?? null) !== normalizeProgram($folder['program'] ?? null)) continue; ?>
                                                                    <option value="<?php echo (int) $facilitator['user_id']; ?>">
                                                                        <?php echo htmlspecialchars(($facilitator['full_name'] ?: $facilitator['username']) . ' (' . $facilitator['program'] . ')'); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <button type="submit" class="btn btn-sm btn-primary">
                                                                <i class="fas fa-user-plus"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Already assigned</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-2"></i>
                                No folders yet. Create a folder first, then students can be organized inside it.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($user_role === 'coordinator'): ?>

                <div class="row mb-3">
                    <div class="col-12">
                        <button class="btn btn-success" data-toggle="modal" data-target="#importExcelModal"
                                <?php echo empty($studentManagementFolders) ? 'disabled' : ''; ?>>
                            <i class="fas fa-file-excel mr-2"></i> Import Students
                        </button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-user-check mr-2"></i>
                            <?php echo htmlspecialchars($coordinatorProgram); ?> Students Pending Facilitator Assignment
                        </h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($coordinatorPendingStudents)): ?>
                            <div class="column-picker">
                                <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap: 8px;">
                                    <button type="button"
                                            class="btn btn-link btn-sm p-0 column-picker-toggle"
                                            data-toggle="collapse"
                                            data-target="#coordinatorVisibleDetailsPanel"
                                            aria-expanded="false"
                                            aria-controls="coordinatorVisibleDetailsPanel">
                                        <i class="fas fa-chevron-right mr-1" id="coordinatorVisibleDetailsIcon"></i>
                                        <i class="fas fa-columns mr-1"></i> Visible Details
                                    </button>
                                    <div>
                                        <button type="button" class="btn btn-xs btn-outline-primary" id="coordinatorShowAllColumns">Show All</button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary" id="coordinatorHideOptionalColumns">Basic Only</button>
                                    </div>
                                </div>
                                <div class="collapse mt-3" id="coordinatorVisibleDetailsPanel">
                                    <div class="column-picker-grid">
                                        <?php foreach ($detailColumns as $columnKey => $columnLabel): ?>
                                            <div class="form-check mb-0">
                                                <input class="form-check-input coordinator-detail-column-toggle"
                                                       type="checkbox"
                                                       value="<?php echo htmlspecialchars($columnKey); ?>"
                                                       id="coordinator_toggle_<?php echo htmlspecialchars($columnKey); ?>"
                                                       checked>
                                                <label class="form-check-label" for="coordinator_toggle_<?php echo htmlspecialchars($columnKey); ?>">
                                                    <?php echo htmlspecialchars($columnLabel); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0 student-detail-table" id="coordinatorPendingStudentsTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 70px;">No.</th>
                                            <?php foreach ($detailColumns as $columnKey => $columnLabel): ?>
                                                <th class="coordinator-detail-col coordinator-detail-col-<?php echo htmlspecialchars($columnKey); ?>" data-column="<?php echo htmlspecialchars($columnKey); ?>">
                                                    <?php echo htmlspecialchars($columnLabel); ?>
                                                </th>
                                            <?php endforeach; ?>
                                            <th>Assign Facilitator</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($coordinatorPendingStudents as $index => $student): ?>
                                            <?php
                                                $searchStudentName = strtolower((string) masterlistDetailValue($student, 'student_name'));
                                                $searchOriginalSection = strtolower((string) masterlistDetailValue($student, 'original_section'));
                                                $searchFolderSection = strtolower((string) masterlistDetailValue($student, 'course_section'));
                                            ?>
                                            <tr class="student-row"
                                                data-student-name="<?php echo htmlspecialchars($searchStudentName, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-original-section="<?php echo htmlspecialchars($searchOriginalSection, ENT_QUOTES, 'UTF-8'); ?>"
                                                data-folder-section="<?php echo htmlspecialchars($searchFolderSection, ENT_QUOTES, 'UTF-8'); ?>">
                                                <td><?php echo $index + 1; ?></td>
                                                <?php foreach ($detailColumns as $columnKey => $columnLabel): ?>
                                                    <?php
                                                        $value = masterlistDetailValue($student, $columnKey);
                                                        $displayValue = displayMasterlistDetailValue($value);
                                                        $longColumns = ['street', 'emergency_address', 'course', 'college'];
                                                        $cellClass = in_array($columnKey, $longColumns, true) ? ' detail-long' : '';
                                                    ?>
                                                    <td class="coordinator-detail-col coordinator-detail-col-<?php echo htmlspecialchars($columnKey); ?><?php echo $cellClass; ?>" data-column="<?php echo htmlspecialchars($columnKey); ?>">
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
                                                                        onclick="showStudentQrModal(<?= htmlspecialchars(json_encode($displayValue), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($student['student_name'] ?? 'Student'), ENT_QUOTES, 'UTF-8') ?>)">
                                                                    <img class="qr-thumb" src="https://api.qrserver.com/v1/create-qr-code/?size=90x90&data=<?php echo urlencode($displayValue); ?>" alt="QR">
                                                                </button>
                                                                <code class="ml-2"><?php echo htmlspecialchars($displayValue); ?></code>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        <?php elseif ($columnKey === 'course_section'): ?>
                                                            <span class="badge badge-success"><?php echo htmlspecialchars($displayValue); ?></span>
                                                        <?php elseif ($columnKey === 'rotc_ms_level'): ?>
                                                            <span class="badge badge-warning"><?php echo htmlspecialchars($displayValue); ?></span>
                                                        <?php elseif ($columnKey === 'student_number'): ?>
                                                            <code><?php echo htmlspecialchars($displayValue); ?></code>
                                                        <?php else: ?>
                                                            <?php echo htmlspecialchars($displayValue); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endforeach; ?>
                                                <td>
                                                    <form class="assign-student-form" method="POST">
                                                        <input type="hidden" name="student_id" value="<?php echo (int) $student['tbl_student_id']; ?>">
                                                        <select class="form-control form-control-sm mb-2 facilitator-select" name="facilitator_id" required>
                                                            <option value="">Select facilitator</option>
                                                            <?php foreach ($coordinatorFacilitators as $facilitator): ?>
                                                                <?php
                                                                    $facilitatorId = (int) $facilitator['user_id'];
                                                                    $folders = array_column($coordinatorFacilitatorFolders[$facilitatorId] ?? [], 'course_section');
                                                                ?>
                                                                <option value="<?php echo $facilitatorId; ?>" data-folders="<?php echo htmlspecialchars(json_encode($folders), ENT_QUOTES, 'UTF-8'); ?>">
                                                                    <?php echo htmlspecialchars($facilitator['full_name'] ?: $facilitator['username']); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <select class="form-control form-control-sm mb-2 folder-select" name="course_section" required disabled>
                                                            <option value="">Select facilitator first</option>
                                                        </select>
                                                        <button type="submit" class="btn btn-sm btn-primary">
                                                            <i class="fas fa-user-plus"></i> Assign
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-2"></i>
                                No <?php echo htmlspecialchars($coordinatorProgram); ?> students are waiting for facilitator assignment.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-folder-open mr-2"></i>
                            <?php echo htmlspecialchars($coordinatorProgram); ?> Facilitators
                        </h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($coordinatorFacilitatorCards) || !empty($coordinatorPendingStudents)): ?>
                            <div class="folder-grid">
                                <?php if (!empty($coordinatorPendingStudents)): ?>
                                    <a class="folder-box pending" href="folder-students.php?scope=pending&component=<?php echo urlencode($coordinatorProgram); ?>">
                                        <span class="folder-box-icon"><i class="fas fa-user-clock"></i></span>
                                        <span class="folder-box-title">Pending Facilitator Assignment</span>
                                        <span class="folder-box-meta">Students still waiting for an existing facilitator folder.</span>
                                        <span class="folder-box-count"><i class="fas fa-users"></i><?php echo count($coordinatorPendingStudents); ?> students</span>
                                    </a>
                                <?php endif; ?>
                                <?php foreach ($coordinatorFacilitatorCards as $facilitatorCard): ?>
                                    <a class="folder-box" href="folder-students.php?scope=coordinator_facilitator&facilitator_id=<?php echo (int) $facilitatorCard['facilitator_id']; ?>">
                                        <span class="folder-box-icon"><i class="fas fa-user-tie"></i></span>
                                        <span class="folder-box-title"><?php echo htmlspecialchars($facilitatorCard['facilitator_name']); ?></span>
                                        <span class="folder-box-meta">
                                            <?php echo (int) $facilitatorCard['folder_count']; ?> section folder<?php echo (int) $facilitatorCard['folder_count'] === 1 ? '' : 's'; ?>
                                            <?php if (!empty($facilitatorCard['assigned_section'])): ?>
                                                / <?php echo htmlspecialchars($facilitatorCard['assigned_section']); ?>
                                            <?php endif; ?>
                                        </span>
                                        <span class="folder-box-count"><i class="fas fa-users"></i><?php echo (int) $facilitatorCard['student_count']; ?> students</span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-2"></i>
                                No facilitators are assigned to <?php echo htmlspecialchars($coordinatorProgram); ?> yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-users-cog mr-2"></i>
                            <?php echo htmlspecialchars($coordinatorProgram); ?> Facilitators
                        </h3>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($coordinatorFacilitators)): ?>
                            <div class="row">
                                <?php foreach ($coordinatorFacilitators as $facilitator): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="info-box mb-0">
                                            <span class="info-box-icon bg-success">
                                                <i class="fas fa-user-tie"></i>
                                            </span>
                                            <div class="info-box-content">
                                                <span class="info-box-text"><?php echo htmlspecialchars(trim($facilitator['full_name'] ?? '') ?: $facilitator['username']); ?></span>
                                                <span class="info-box-number">
                                                    <?php
                                                        $facilitatorFolders = $coordinatorFacilitatorFolders[(int) $facilitator['user_id']] ?? [];
                                                    ?>
                                                    <?php if (!empty($facilitatorFolders)): ?>
                                                        <?php foreach ($facilitatorFolders as $folderInfo): ?>
                                                            <span class="badge badge-info mr-1 mb-1">
                                                                <i class="fas fa-folder mr-1"></i><?php echo htmlspecialchars($folderInfo['course_section']); ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">No assigned folders</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-2"></i>
                                No facilitators are assigned to <?php echo htmlspecialchars($coordinatorProgram); ?> yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php endif; ?>

                <!-- ==================== -->
                <!-- REGULAR ADMIN VIEW - FOLDER ORGANIZATION FOR MULTIPLE SECTIONS -->
                <!-- ==================== -->
                <?php if ($user_role === 'facilitator' && $sections_count > 1): ?>
                
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="alert alert-info d-flex align-items-center">
                            <i class="fas fa-file-export fa-2x mr-3"></i>
                            <div>
                                <strong>View and export access:</strong> Your folders are shown below. Open a folder to review students before exporting.
                                <span class="badge badge-light ml-2">
                                    <i class="fas fa-users mr-1"></i> Total: <?php echo $total_students; ?> students
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="folder-grid">
                    <?php foreach ($sections_with_students as $section_name => $students): ?>
                        <a class="folder-box" href="folder-students.php?scope=facilitator&folder=<?php echo urlencode($section_name); ?>">
                            <span class="folder-box-icon"><i class="fas fa-folder"></i></span>
                            <span class="folder-box-title"><?php echo htmlspecialchars($section_name); ?></span>
                            <span class="folder-box-meta">Open this folder to view the student list.</span>
                            <span class="folder-box-count"><i class="fas fa-users"></i><?php echo count($students); ?> students</span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- ==================== -->
                <!-- REGULAR ADMIN VIEW - SINGLE SECTION (TABLE VIEW) -->
                <!-- ==================== -->
                <?php elseif ($user_role === 'facilitator'): ?>
                
                <?php if ($isRotcFacilitator): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-file-export mr-2"></i>
                        <strong>ROTC access:</strong> All ROTC facilitators can view the full ROTC student list.
                    </div>
                    <div class="folder-grid">
                        <a class="folder-box" href="folder-students.php?scope=rotc_all">
                            <span class="folder-box-icon"><i class="fas fa-users"></i></span>
                            <span class="folder-box-title">All ROTC Students</span>
                            <span class="folder-box-meta">Open the complete ROTC student list.</span>
                            <span class="folder-box-count"><i class="fas fa-users"></i><?php echo $total_students; ?> students</span>
                        </a>
                    </div>
                <?php elseif (!empty($assignedSection)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-file-export mr-2"></i>
                        <strong>View and export access:</strong> Your folder is shown below. Open it to review students before exporting.
                    </div>
                    <div class="folder-grid">
                        <a class="folder-box" href="folder-students.php?scope=facilitator&folder=<?php echo urlencode($assignedSection); ?>">
                            <span class="folder-box-icon"><i class="fas fa-folder"></i></span>
                            <span class="folder-box-title"><?php echo htmlspecialchars($assignedSection); ?></span>
                            <span class="folder-box-meta">Open this folder to view the student list.</span>
                            <span class="folder-box-count"><i class="fas fa-users"></i><?php echo $total_students; ?> students</span>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        You are not assigned to any folder yet.
                    </div>
                <?php endif; ?>

                <!-- ==================== -->
                <!-- SUPER ADMIN VIEW - ADMIN FOLDERS WITH NESTED SECTION FOLDERS -->
                <!-- ==================== -->
                <?php elseif ($user_role === 'super_admin'): ?>

                <div class="alert alert-secondary d-flex align-items-center">
                    <i class="fas fa-eye fa-2x mr-3"></i>
                    <div>
                        <strong>Read-only security view:</strong>
                        Super Admin can review students by component. Open a component to view its student list.
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-12">
                        <button class="btn btn-success" data-toggle="modal" data-target="#importExcelModal">
                            <i class="fas fa-file-excel mr-2"></i> Import Student Registrations
                        </button>
                    </div>
                </div>

                <div class="folder-grid">
                    <?php foreach ($superAdminComponentCards as $componentCard): ?>
                        <a class="folder-box" href="folder-students.php?scope=component&component=<?php echo urlencode($componentCard['component']); ?>">
                            <span class="folder-box-icon"><i class="fas fa-layer-group"></i></span>
                            <span class="folder-box-title"><?php echo htmlspecialchars($componentCard['component']); ?></span>
                            <span class="folder-box-meta">
                                <?php echo (int) $componentCard['facilitator_count']; ?> facilitator<?php echo (int) $componentCard['facilitator_count'] === 1 ? '' : 's'; ?>
                            </span>
                            <span class="folder-box-count"><i class="fas fa-users"></i><?php echo (int) $componentCard['student_count']; ?> students</span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if (false): ?>

                <!-- My Students Folder (Current Super Admin) -->
                <?php if (!empty($my_students_by_section)): ?>
                <div class="admin-folder my-folder" data-admin-id="<?php echo $user_id; ?>">
                    <div class="admin-folder-header collapsed">
                        <div class="admin-info">
                            <i class="fas fa-folder folder-icon"></i>
                            <span class="admin-name">
                                <i class="fas fa-star mr-1" style="color: #ffd700;"></i>
                                My Added Students
                            </span>
                            <span class="admin-username">
                                <i class="fas fa-user mr-1"></i>
                                <?php echo htmlspecialchars($user_name); ?> (You)
                            </span>
                        </div>
                        <div class="admin-stats">
                            <span class="stat-badge">
                                <i class="fas fa-folder-tree"></i>
                                <?php echo count($my_students_by_section); ?> folders
                            </span>
                            <span class="stat-badge">
                                <i class="fas fa-users"></i>
                                <?php echo $my_students_count; ?> students
                            </span>
                            <i class="fas fa-chevron-circle-right expand-collapse-icon"></i>
                        </div>
                    </div>
                    <div class="admin-folder-body" style="display: none;">
                        <?php foreach ($my_students_by_section as $section_name => $students): 
                            $student_count = count($students);
                        ?>
                        <div class="nested-section-folder">
                            <div class="nested-section-header collapsed">
                                <div class="section-info-header">
                                    <i class="fas fa-folder folder-icon"></i>
                                    <span class="section-name">
                                        <i class="fas fa-users mr-1"></i>
                                        Admin Folder: <?php echo htmlspecialchars($section_name); ?>
                                    </span>
                                </div>
                                <div class="section-stats">
                                    <span class="stat-badge">
                                        <i class="fas fa-users"></i>
                                        <?php echo $student_count; ?> students
                                    </span>
                                    <i class="fas fa-chevron-circle-right expand-collapse-icon"></i>
                                </div>
                            </div>
                            <div class="section-folder-body" style="display: none;">
                                <?php if ($student_count > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover folder-table">
                                        <thead>
                                            <tr>
                                                <th>No.</th>
                                                <th>Student Name</th>
                                                <th>Original College Section</th>
                                                <th>Admin Folder</th>
                                                <th>QR Code</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $my_counter = 1;
                                            foreach ($students as $row): 
                                            ?>
                                                <?php
                                                $studentID = $row["tbl_student_id"];
                                                $studentName = $row["student_name"];
                                                $originalSection = $row["original_section"] ?? $row["course_section"];
                                                $folderSection = $row["course_section"];
                                                $qrCode = $row["generated_code"];
                                                ?>
                                                <tr class="student-row"
                                                    data-student-name="<?= htmlspecialchars(strtolower($studentName), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-original-section="<?= htmlspecialchars(strtolower($originalSection), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-folder-section="<?= htmlspecialchars(strtolower($folderSection), ENT_QUOTES, 'UTF-8') ?>">
                                                    <td><?= $my_counter++ ?></td>
                                                    <td><?= htmlspecialchars($studentName) ?></td>
                                                    <td>
                                                        <span class="original-section-badge">
                                                            <i class="fas fa-university mr-1"></i>
                                                            <?= htmlspecialchars($originalSection) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="folder-section-badge">
                                                            <i class="fas fa-folder mr-1"></i>
                                                            <?= htmlspecialchars($folderSection) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-info btn-sm" onclick="showStudentQrModal(<?= htmlspecialchars(json_encode($qrCode), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($studentName), ENT_QUOTES, 'UTF-8') ?>)">
                                                            <i class="fas fa-qrcode"></i> View QR
                                                        </button>
                                                    </td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <button class="btn btn-warning btn-sm" onclick="updateStudent(<?= $studentID ?>)">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </button>
                                                            <button class="btn btn-danger btn-sm" onclick="deleteStudent(<?= $studentID ?>)">
                                                                <i class="fas fa-trash"></i> Delete
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Coordinator Folders with Nested Facilitator Folders -->
                <?php foreach ($admins_with_sections as $admin): 
                    if ($admin['user_id'] == $user_id) continue;
                ?>
                <div class="admin-folder" data-admin-id="<?php echo $admin['user_id']; ?>">
                    <div class="admin-folder-header collapsed">
                        <div class="admin-info">
                            <i class="fas fa-folder folder-icon"></i>
                            <span class="admin-name">
                                <i class="fas fa-user-shield mr-1"></i>
                                <?php echo htmlspecialchars($admin['program']); ?> Coordinator
                            </span>
                            <span class="admin-username">
                                <i class="fas fa-at mr-1"></i>
                                <?php echo htmlspecialchars($admin['full_name']); ?> / <?php echo htmlspecialchars($admin['username']); ?>
                            </span>
                        </div>
                        <div class="admin-stats">
                            <span class="stat-badge">
                                <i class="fas fa-folder-tree"></i>
                                <?php echo count($admin['sections']); ?> facilitator folders
                            </span>
                            <span class="stat-badge">
                                <i class="fas fa-users"></i>
                                <?php echo $admin['student_count']; ?> students
                            </span>
                            <i class="fas fa-chevron-circle-right expand-collapse-icon"></i>
                        </div>
                    </div>
                    <div class="admin-folder-body" style="display: none;">
                        <?php if (empty($admin['sections'])): ?>
                        <div class="empty-folder">
                            <i class="fas fa-folder-open"></i>
                            <h5>No Students Yet</h5>
                            <p class="text-muted mb-0">No students are currently listed under this coordinator folder.</p>
                        </div>
                        <?php endif; ?>

                        <?php foreach ($admin['sections'] as $section_name => $students): 
                            $student_count = count($students);
                        ?>
                        <div class="nested-section-folder">
                            <div class="nested-section-header collapsed">
                                <div class="section-info-header">
                                    <i class="fas fa-folder folder-icon"></i>
                                    <span class="section-name">
                                        <i class="fas fa-users mr-1"></i>
                                        Facilitator Folder: <?php echo htmlspecialchars($section_name); ?>
                                    </span>
                                </div>
                                <div class="section-stats">
                                    <span class="stat-badge">
                                        <i class="fas fa-users"></i>
                                        <?php echo $student_count; ?> students
                                    </span>
                                    <i class="fas fa-chevron-circle-right expand-collapse-icon"></i>
                                </div>
                            </div>
                            <div class="section-folder-body" style="display: none;">
                                <?php if ($student_count > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover folder-table">
                                        <thead>
                                            <tr>
                                                <th>No.</th>
                                                <th>Student Name</th>
                                                <th>Original College Section</th>
                                                <th>Component / Folder</th>
                                                <th>QR Code</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $admin_counter = 1;
                                            foreach ($students as $row): 
                                            ?>
                                                <?php
                                                $studentID = $row["tbl_student_id"];
                                                $studentName = $row["student_name"];
                                                $originalSection = $row["original_section"] ?? $row["course_section"];
                                                $folderSection = $row["course_section"];
                                                $qrCode = $row["generated_code"];
                                                ?>
                                                <tr class="student-row"
                                                    data-student-name="<?= htmlspecialchars(strtolower($studentName), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-original-section="<?= htmlspecialchars(strtolower($originalSection), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-folder-section="<?= htmlspecialchars(strtolower($folderSection), ENT_QUOTES, 'UTF-8') ?>">
                                                    <td><?= $admin_counter++ ?></td>
                                                    <td><?= htmlspecialchars($studentName) ?></td>
                                                    <td>
                                                        <span class="original-section-badge">
                                                            <i class="fas fa-university mr-1"></i>
                                                            <?= htmlspecialchars($originalSection) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="folder-section-badge">
                                                            <i class="fas fa-folder mr-1"></i>
                                                            <?= htmlspecialchars($folderSection) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-info btn-sm" onclick="showStudentQrModal(<?= htmlspecialchars(json_encode($qrCode), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($studentName), ENT_QUOTES, 'UTF-8') ?>)">
                                                            <i class="fas fa-qrcode"></i> View QR
                                                        </button>
                                                    </td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <span class="text-muted permission-badge" data-toggle="tooltip" 
                                                                  title="You can only modify students you added">
                                                                <i class="fas fa-lock"></i> Read Only
                                                            </span>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- System Added Students Folder -->
                <?php if (!empty($system_by_section)): ?>
                <div class="admin-folder system-folder">
                    <div class="admin-folder-header collapsed">
                        <div class="admin-info">
                            <i class="fas fa-folder folder-icon"></i>
                            <span class="admin-name">
                                <i class="fas fa-cog mr-1"></i>
                                System Added Students
                            </span>
                            <span class="admin-username">
                                <i class="fas fa-robot mr-1"></i>
                                No assigned admin
                            </span>
                        </div>
                        <div class="admin-stats">
                            <span class="stat-badge">
                                <i class="fas fa-folder-tree"></i>
                                <?php echo count($system_by_section); ?> folders
                            </span>
                            <span class="stat-badge">
                                <i class="fas fa-users"></i>
                                <?php echo count($system_students); ?> students
                            </span>
                            <i class="fas fa-chevron-circle-right expand-collapse-icon"></i>
                        </div>
                    </div>
                    <div class="admin-folder-body" style="display: none;">
                        <?php foreach ($system_by_section as $section_name => $students): 
                            $student_count = count($students);
                        ?>
                        <div class="nested-section-folder">
                            <div class="nested-section-header collapsed">
                                <div class="section-info-header">
                                    <i class="fas fa-folder folder-icon"></i>
                                    <span class="section-name">
                                        <i class="fas fa-users mr-1"></i>
                                        Admin Folder: <?php echo htmlspecialchars($section_name); ?>
                                    </span>
                                </div>
                                <div class="section-stats">
                                    <span class="stat-badge">
                                        <i class="fas fa-users"></i>
                                        <?php echo $student_count; ?> students
                                    </span>
                                    <i class="fas fa-chevron-circle-right expand-collapse-icon"></i>
                                </div>
                            </div>
                            <div class="section-folder-body" style="display: none;">
                                <div class="table-responsive">
                                    <table class="table table-hover folder-table">
                                        <thead>
                                            <tr>
                                                <th>No.</th>
                                                <th>Student Name</th>
                                                <th>Original College Section</th>
                                                <th>Admin Folder</th>
                                                <th>QR Code</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $system_counter = 1;
                                            foreach ($students as $row): 
                                            ?>
                                                <?php
                                                $studentID = $row["tbl_student_id"];
                                                $studentName = $row["student_name"];
                                                $originalSection = $row["original_section"] ?? $row["course_section"];
                                                $folderSection = $row["course_section"];
                                                $qrCode = $row["generated_code"];
                                                ?>
                                                <tr class="student-row"
                                                    data-student-name="<?= htmlspecialchars(strtolower($studentName), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-original-section="<?= htmlspecialchars(strtolower($originalSection), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-folder-section="<?= htmlspecialchars(strtolower($folderSection), ENT_QUOTES, 'UTF-8') ?>">
                                                    <td><?= $system_counter++ ?></td>
                                                    <td><?= htmlspecialchars($studentName) ?></td>
                                                    <td>
                                                        <span class="original-section-badge">
                                                            <i class="fas fa-university mr-1"></i>
                                                            <?= htmlspecialchars($originalSection) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="folder-section-badge">
                                                            <i class="fas fa-folder mr-1"></i>
                                                            <?= htmlspecialchars($folderSection) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-info btn-sm" onclick="showStudentQrModal(<?= htmlspecialchars(json_encode($qrCode), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($studentName), ENT_QUOTES, 'UTF-8') ?>)">
                                                            <i class="fas fa-qrcode"></i> View QR
                                                        </button>
                                                    </td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <button class="btn btn-warning btn-sm" onclick="updateStudent(<?= $studentID ?>)">
                                                                <i class="fas fa-edit"></i> Edit
                                                            </button>
                                                            <button class="btn btn-danger btn-sm" onclick="deleteStudent(<?= $studentID ?>)">
                                                                <i class="fas fa-trash"></i> Delete
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php endif; ?>

                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Footer -->
        <!-- Footer -->
    <?php include 'footer.php'; ?>
</div>

<?php if (false && $user_role === 'facilitator'): ?>
<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus mr-2"></i>
                    Add New Student
                </h5>
                <?php if ($user_role === 'facilitator' && $sections_count > 1): ?>
                <span class="badge badge-info ml-2">
                    <i class="fas fa-folder-tree mr-1"></i>
                    <?php echo $sections_count; ?> Admin Folders
                </span>
                <?php endif; ?>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form action="./endpoint/add-student.php" method="POST" id="addStudentForm">
                    <?php if ($user_role === 'super_admin'): ?>
                    <!-- Super Admin: Can input any section -->
                    <div class="alert alert-info">
                        <i class="fas fa-shield-alt mr-2"></i>
                        As a super admin, you can add students to any admin folder.
                    </div>
                    
                    <div class="form-group">
                        <label for="studentName">Student Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="studentName" name="student_name" required>
                    </div>

                    <div class="form-group">
                        <label for="studentNumber">Student Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="studentNumber" name="student_number" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" required>
                        <small class="text-muted">This links QR attendance to the public registration and future student account.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="originalSection">Student's Original College Section <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="originalSection" name="original_section" 
                               placeholder="e.g., BSIT 1A, BSA 2B" required>
                        <small class="text-muted">The student's actual course and section in their college</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="studentCourse">Admin Folder Section <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="studentCourse" name="course_section" 
                               placeholder="e.g., NSTP 1A, NSTP 1B" required>
                        <small class="text-muted">The folder where this student will be organized (NSTP section)</small>
                    </div>
                    
                    <?php elseif ($user_role === 'facilitator'): ?>
                    
                    <?php if ($sections_count > 1): ?>
                    <!-- Multiple sections - show folder selection -->
                    <div class="alert alert-warning d-flex align-items-center">
                        <i class="fas fa-folder-tree fa-2x mr-3"></i>
                        <div>
                            <strong>Select Admin Folder:</strong>
                            <p class="mb-0">Choose which admin folder to organize this student under.</p>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="course_section">
                            <i class="fas fa-folder mr-1"></i>
                            Admin Folder <span class="text-danger">*</span>
                        </label>
                        <select class="form-control" id="course_section" name="course_section" required>
                            <option value="">-- Select Admin Folder --</option>
                            <?php foreach ($assignedSections as $section): ?>
                            <option value="<?php echo htmlspecialchars($section); ?>">
                                <i class="fas fa-folder"></i> <?php echo htmlspecialchars($section); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">This is for organization only - not the student's actual college section</small>
                    </div>
                    
                    <?php elseif ($sections_count == 1): ?>
                    <!-- Single section - auto-assign with folder icon -->
                    <input type="hidden" name="course_section" value="<?php echo htmlspecialchars($assignedSection); ?>">
                    <div class="alert alert-primary d-flex align-items-center">
                        <i class="fas fa-folder-open fa-2x mr-3"></i>
                        <div>
                            <strong>Admin Folder:</strong>
                            <p class="mb-0">Student will be organized under folder: 
                                <span class="badge badge-light"><?php echo htmlspecialchars($assignedSection); ?></span>
                            </p>
                            <small class="text-muted">This is for organization only</small>
                        </div>
                    </div>
                    
                    <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>No Folders Assigned!</strong> You cannot add students until you are assigned an admin folder.
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="studentName">Student Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="studentName" name="student_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="originalSection">Student's Original College Section <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="originalSection" name="original_section" 
                               placeholder="e.g., BSIT 1A, BSA 2B" required>
                        <small class="text-muted">Enter the student's actual college course and section</small>
                    </div>
                    
                    <?php endif; ?>
                    
                    <!-- QR Code Generation -->
                    <div class="form-group mt-3">
                        <label>
                            <i class="fas fa-qrcode mr-1"></i>
                            QR Code
                        </label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="generatedCode" name="generated_code" readonly placeholder="Click Generate to create QR code">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-primary" onclick="generateQrCode()">
                                    <i class="fas fa-qrcode"></i> Generate
                                </button>
                            </div>
                        </div>
                        <small class="text-muted">Generate a unique QR code for this student</small>
                    </div>

                    <div class="qr-con text-center mt-3" style="display: none;">
                        <div class="alert alert-success py-2">
                            <i class="fas fa-check-circle"></i> QR Code Generated Successfully!
                        </div>
                        <img class="mb-3 img-thumbnail" src="" id="qrImg" alt="QR Code" style="max-width: 200px;">
                        <p class="text-muted">Scan this QR code to record attendance</p>
                    </div>
                    
                    <div class="modal-footer px-0 pb-0" style="display: none;" id="addModalFooter">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">
                            <i class="fas fa-times mr-1"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" id="addStudentBtn">
                            <i class="fas fa-save mr-1"></i> Add Student
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Update Student Modal -->
<div class="modal fade" id="updateStudentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit mr-2"></i>
                    Update Student
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form action="./endpoint/update-student.php" method="POST" id="updateStudentForm">
                    <input type="hidden" class="form-control" id="updateStudentId" name="tbl_student_id">
                    
                    <div class="form-group">
                        <label for="updateStudentName">Student Full Name:</label>
                        <input type="text" class="form-control" id="updateStudentName" name="student_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="updateOriginalSection">Original College Section:</label>
                        <input type="text" class="form-control" id="updateOriginalSection" name="original_section" required>
                        <small class="text-muted">The student's actual college section</small>
                    </div>
                    
                    <?php if ($user_role === 'super_admin'): ?>
                    <div class="form-group">
                        <label for="updateStudentCourse">Admin Folder Section:</label>
                        <input type="text" class="form-control" id="updateStudentCourse" name="course_section" required>
                        <small class="text-muted">The folder where this student is organized</small>
                    </div>
                    
                    <?php elseif ($user_role === 'facilitator' && $sections_count > 1): ?>
                    <!-- Admin with multiple sections can move student between folders -->
                    <div class="form-group">
                        <label for="updateStudentCourse">
                            <i class="fas fa-folder mr-1"></i>
                            Move to Admin Folder:
                        </label>
                        <select class="form-control" id="updateStudentCourse" name="course_section" required>
                            <option value="">-- Select Admin Folder --</option>
                            <?php foreach ($assignedSections as $section): ?>
                            <option value="<?php echo htmlspecialchars($section); ?>">
                                <i class="fas fa-folder"></i> <?php echo htmlspecialchars($section); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">You can move this student to any of your admin folders</small>
                    </div>
                    
                    <?php else: ?>
                    <input type="hidden" id="updateStudentCourse" name="course_section" value="<?php echo htmlspecialchars($assignedSection); ?>">
                    <div class="alert alert-primary">
                        <i class="fas fa-folder-open mr-2"></i>
                        <strong>Admin Folder:</strong> <?php echo htmlspecialchars($assignedSection); ?>
                        <small class="d-block mt-1 text-muted">Folder cannot be changed</small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">
                            <i class="fas fa-times mr-1"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-primary" id="updateStudentBtn">
                            <i class="fas fa-save mr-1"></i> Update Student
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Import Excel Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-excel mr-2"></i>
                    Import Students from Excel
                </h5>
                <?php if ($user_role === 'facilitator' && $sections_count > 1): ?>
                <span class="badge badge-info ml-2">
                    <i class="fas fa-folder-tree mr-1"></i>
                    <?php echo $sections_count; ?> Admin Folders
                </span>
                <?php endif; ?>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="importAlert" style="display: none;"></div>
                
                <form id="importExcelForm" enctype="multipart/form-data">
                    <?php if ($user_role === 'super_admin'): ?>
                    <div class="alert alert-warning d-flex align-items-center">
                        <i class="fas fa-shield-alt fa-2x mr-3"></i>
                        <div>
                            <strong>Super Admin Import:</strong>
                            <p class="mb-0">Choose the NSTP component first. The uploaded spreadsheet must contain every enabled student registration field.</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="import_component">
                            <i class="fas fa-layer-group mr-1"></i>
                            Target Component <span class="text-danger">*</span>
                        </label>
                        <select class="form-control" id="import_component" name="component" required>
                            <option value="">-- Select Component --</option>
                            <option value="CWTS">CWTS</option>
                            <option value="LTS">LTS</option>
                            <option value="ROTC">ROTC</option>
                        </select>
                        <small class="form-text text-muted">
                            All accepted rows will be saved under the selected component.
                        </small>
                    </div>
                    <?php endif; ?>

                    <?php if ($user_role === 'facilitator' && empty($assignedSections)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        You are not assigned to any admin folder. Please contact super admin before importing students.
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($user_role === 'facilitator' && $sections_count > 1): ?>
                    <!-- Multiple sections - show folder selection for import -->
                    <div class="alert alert-warning d-flex align-items-center">
                        <i class="fas fa-folder-tree fa-2x mr-3"></i>
                        <div>
                            <strong>Select Target Admin Folder:</strong>
                            <p class="mb-0">Choose which admin folder to import the students into.</p>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="import_section">
                            <i class="fas fa-folder mr-1"></i>
                            Target Admin Folder <span class="text-danger">*</span>
                        </label>
                        <select class="form-control" id="import_section" name="import_section" required>
                            <option value="">-- Select Admin Folder --</option>
                            <?php foreach ($assignedSections as $section): ?>
                            <option value="<?php echo htmlspecialchars($section); ?>">
                                <i class="fas fa-folder"></i> <?php echo htmlspecialchars($section); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">
                            Students will be imported to this admin folder. The Excel file should have columns for:
                            <br><strong>Column A:</strong> Student Full Name
                            <br><strong>Column B:</strong> Original College Section
                        </small>
                    </div>
                    
                    <?php elseif ($user_role === 'facilitator' && $sections_count == 1): ?>
                    <!-- Single section - auto-assign -->
                    <input type="hidden" name="import_section" value="<?php echo htmlspecialchars($assignedSection); ?>">
                    <div class="alert alert-primary d-flex align-items-center">
                        <i class="fas fa-folder-open fa-2x mr-3"></i>
                        <div>
                            <strong>Target Admin Folder:</strong>
                            <p class="mb-0">Students will be imported to folder: 
                                <span class="badge badge-light"><?php echo htmlspecialchars($assignedSection); ?></span>
                            </p>
                            <small>The Excel file should have columns for Student Name and Original College Section</small>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="excel_file">Select Excel File:</label>
                        <input type="file" class="form-control-file" id="excel_file" name="excel_file" 
                               accept=".xlsx,.xls" required>
                        <small class="form-text text-muted">
                            Supported formats: .xlsx, .xls (Excel files)<br>
                            <?php if ($user_role === 'super_admin'): ?>
                            Use headers matching the student registration fields, for example: Last Name, First Name, Middle Name, Student Number, College, Course, Major, Year/Section, and the other enabled registration details.<br>
                            The import is strict: incomplete rows or invalid N/A values will reject the whole file.
                            <?php else: ?>
                            <strong>Column A:</strong> Student Full Name (Required)<br>
                            <strong>Column B:</strong> Original College Section (Required)<br>
                            <?php endif; ?>
                            <br><br>
                            <strong>Note:</strong> The first row should contain headers (it will be skipped)
                        </small>
                    </div>
                    
                    <div class="progress mb-3" style="display: none; height: 30px;" id="importProgress">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
                             role="progressbar" style="width: 0%; font-weight: bold;">0%</div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Note:</strong> 
                        <ul class="mb-0 mt-2">
                            <li>QR codes will be automatically generated for each student</li>
                            <?php if ($user_role === 'super_admin'): ?>
                            <li>Super Admin import saves complete public registration records and assigns the selected component</li>
                            <li>The whole upload is rejected if any required registration detail is missing</li>
                            <?php else: ?>
                            <li>Duplicate students (same name and section) will be skipped</li>
                            <?php endif; ?>
                            <li>The first row should contain headers (it will be skipped)</li>
                            <li>Students will show both their original college section and the admin folder they belong to</li>
                        </ul>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times mr-1"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="importExcel()" 
                        <?php echo ($user_role === 'facilitator' && empty($assignedSections)) ? 'disabled' : ''; ?> 
                        id="importExcelBtn">
                    <i class="fas fa-file-import mr-2"></i> Import Students
                </button>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if ($user_role === 'super_admin'): ?>
<!-- Super Admin Import Excel Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-excel mr-2"></i>
                    Import Student Registrations
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="importAlert" style="display: none;"></div>

                <form id="importExcelForm" enctype="multipart/form-data">
                    <div class="alert alert-warning d-flex align-items-center">
                        <i class="fas fa-shield-alt fa-2x mr-3"></i>
                        <div>
                            <strong>Super Admin Import:</strong>
                            <p class="mb-0">The spreadsheet must contain every enabled student registration field. One incomplete row rejects the whole file.</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="import_component">
                            <i class="fas fa-layer-group mr-1"></i>
                            Target Component <span class="text-danger">*</span>
                        </label>
                        <select class="form-control" id="import_component" name="component" required>
                            <option value="">-- Select Component --</option>
                            <option value="CWTS">CWTS</option>
                            <option value="LTS">LTS</option>
                            <option value="ROTC">ROTC</option>
                        </select>
                        <small class="form-text text-muted">
                            All imported students will be assigned to the selected NSTP component.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="excel_file">Select Excel File:</label>
                        <input type="file" class="form-control-file" id="excel_file" name="excel_file" accept=".xlsx,.xls,.csv" required>
                        <small class="form-text text-muted">
                            Supported formats: .xlsx, .xls, .csv. Row 1 must contain headers matching the registration fields.
                        </small>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        Required headers follow the active student registration form, such as Last Name, First Name, Student Number, Email, College, Course, Major, Year/Section, and other enabled details. N/A is accepted only for fields that allow N/A in registration.
                    </div>

                    <div class="progress mb-3" style="display: none; height: 30px;" id="importProgress">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                             role="progressbar" style="width: 0%; font-weight: bold;">0%</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times mr-1"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="importExcel()" id="importExcelBtn">
                    <i class="fas fa-file-import mr-2"></i> Import Registrations
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($user_role === 'facilitator'): ?>
<!-- Export QR Code Modal (ZIP Only) -->
<div class="modal fade" id="exportQRModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-export mr-2"></i>
                    Export QR Codes (ZIP Format)
                </h5>
                <?php if ($user_role === 'facilitator' && $sections_count > 1): ?>
                <span class="badge badge-info ml-2">
                    <i class="fas fa-folder-tree mr-1"></i>
                    <?php echo $sections_count; ?> Admin Folders
                </span>
                <?php endif; ?>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="exportAlert" style="display: none;"></div>
                
                <form id="exportQRForm">
                    <?php if ($user_role === 'super_admin'): ?>
                    <!-- Super Admin: Can select which admin to export -->
                    <div class="alert alert-info">
                        <i class="fas fa-shield-alt mr-2"></i>
                        As a super admin, you can export QR codes for any admin.
                    </div>
                    
                    <div class="form-group">
                        <label for="export_admin_id">
                            <i class="fas fa-user-shield mr-1"></i>
                            Select Admin <span class="text-danger">*</span>
                        </label>
                        <select class="form-control" id="export_admin_id" name="export_admin_id" required>
                            <option value="">-- Select Admin --</option>
                            <option value="<?php echo $user_id; ?>">My Students (<?php echo htmlspecialchars($user_name); ?>)</option>
                            <?php foreach ($superAdminExportFacilitators as $admin): ?>
                            <option value="<?php echo $admin['user_id']; ?>">
                                <?php echo htmlspecialchars(($admin['program'] ? $admin['program'] . ' - ' : '') . ($admin['full_name'] ?: $admin['username'])); ?> (<?php echo $admin['student_count']; ?> students)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" id="super_admin_section_container" style="display: none;">
                        <label for="export_section_super">
                            <i class="fas fa-folder mr-1"></i>
                            Select Section (Optional)
                        </label>
                        <select class="form-control" id="export_section_super" name="export_section">
                            <option value="">-- All Sections --</option>
                        </select>
                        <small class="text-muted">Leave empty to export all sections for the selected admin</small>
                    </div>
                    
                    <?php elseif ($user_role === 'facilitator'): ?>
                    
                    <?php if ($sections_count > 1): ?>
                    <!-- Multiple sections - show folder selection -->
                    <div class="alert alert-warning d-flex align-items-center">
                        <i class="fas fa-folder-tree fa-2x mr-3"></i>
                        <div>
                            <strong>Select Section to Export:</strong>
                            <p class="mb-0">Choose which admin folder's QR codes you want to export.</p>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="export_section">
                            <i class="fas fa-folder mr-1"></i>
                            Select Admin Folder <span class="text-danger">*</span>
                        </label>
                        <select class="form-control" id="export_section" name="export_section" required>
                            <option value="">-- Select Admin Folder --</option>
                            <?php foreach ($assignedSections as $section): ?>
                            <option value="<?php echo htmlspecialchars($section); ?>">
                                <i class="fas fa-folder"></i> <?php echo htmlspecialchars($section); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Choose the folder you want to export QR codes from</small>
                    </div>
                    
                    <?php elseif ($sections_count == 1): ?>
                    <!-- Single section - show info -->
                    <input type="hidden" name="export_section" value="<?php echo htmlspecialchars($assignedSection); ?>">
                    <div class="alert alert-primary d-flex align-items-center">
                        <i class="fas fa-folder-open fa-2x mr-3"></i>
                        <div>
                            <strong>Exporting from:</strong>
                            <p class="mb-0">Admin Folder: 
                                <span class="badge badge-light"><?php echo htmlspecialchars($assignedSection); ?></span>
                            </p>
                        </div>
                    </div>
                    
                    <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>No Folders Assigned!</strong> You cannot export QR codes until you are assigned an admin folder.
                    </div>
                    <?php endif; ?>
                    
                    <?php endif; ?>
                    
                    <!-- Preview Section -->
                    <div class="mt-3" id="exportPreview" style="display: none;">
                        <div class="card card-outline card-info">
                            <div class="card-header">
                                <h3 class="card-title">
                                    <i class="fas fa-eye mr-1"></i>
                                    Preview
                                </h3>
                                <div class="card-tools">
                                    <span class="badge badge-info" id="previewCount">0 students</span>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive" style="max-height: 200px;">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Student Name</th>
                                                <th>Original Section</th>
                                                <th>QR Preview</th>
                                            </tr>
                                        </thead>
                                        <tbody id="previewList">
                                            <!-- Preview rows will be inserted here -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>ZIP Export Details:</strong> 
                        <ul class="mb-0 mt-2">
                            <li>Each student gets an individual QR code image (PNG format)</li>
                            <li>Images are named with student names for easy identification</li>
                            <li>Includes an HTML viewer file to see all QR codes at once</li>
                            <li>Includes a text file with student information and QR code strings</li>
                            <li>Perfect for printing or sharing individual QR codes</li>
                        </ul>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times mr-1"></i> Cancel
                </button>
                <button type="button" class="btn btn-info" onclick="previewExport()" id="previewBtn">
                    <i class="fas fa-eye mr-2"></i> Preview
                </button>
                <button type="button" class="btn btn-success" onclick="exportQR()" 
                        <?php echo ($user_role === 'facilitator' && empty($assignedSections)) ? 'disabled' : ''; ?> 
                        id="exportQRBtn">
                    <i class="fas fa-file-archive mr-2"></i> Export as ZIP
                </button>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if ($user_role === 'coordinator'): ?>
<!-- Coordinator Import Excel Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-excel mr-2"></i>
                    Import Students to Folder
                </h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="importAlert" style="display: none;"></div>

                <form id="importExcelForm" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="import_section">
                            <i class="fas fa-folder mr-1"></i>
                            Target Folder <span class="text-danger">*</span>
                        </label>
                        <select class="form-control" id="import_section" name="import_section" required>
                            <option value="">-- Select Folder --</option>
                            <?php foreach ($studentManagementFolders as $folder): ?>
                                <?php if (normalizeProgram($folder['program'] ?? null) !== $coordinatorProgram) continue; ?>
                                <option value="<?php echo htmlspecialchars($folder['course_section']); ?>">
                                    <?php echo htmlspecialchars($folder['course_section']); ?>
                                    <?php echo !empty($folder['facilitator_name']) || !empty($folder['facilitator_username']) ? ' - ' . htmlspecialchars($folder['facilitator_name'] ?: $folder['facilitator_username']) : ' - Pending facilitator'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">
                            Column A: Student Full Name. Column B: Original College Section. First row is skipped. Students stay inside the folder even if no facilitator is assigned yet.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="excel_file">Select Excel File:</label>
                        <input type="file" class="form-control-file" id="excel_file" name="excel_file" accept=".xlsx,.xls,.csv" required>
                    </div>

                    <div class="progress mb-3" style="display: none; height: 30px;" id="importProgress">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                             role="progressbar" style="width: 0%; font-weight: bold;">0%</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times mr-1"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="importExcel()" id="importExcelBtn">
                    <i class="fas fa-file-import mr-2"></i> Import Students
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (in_array($user_role, ['super_admin', 'coordinator'], true)): ?>
<!-- Create Student Folder Modal -->
<div class="modal fade" id="createStudentFolderModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success">
                <h5 class="modal-title text-white">
                    <i class="fas fa-folder-plus mr-2"></i>Create Student Folder
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form id="createStudentFolderForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="student_folder_program">Program</label>
                        <?php if ($user_role === 'super_admin'): ?>
                            <select class="form-control" id="student_folder_program" name="program" required>
                                <option value="">-- Select Program --</option>
                                <option value="CWTS">CWTS</option>
                                <option value="LTS">LTS</option>
                                <option value="ROTC">ROTC</option>
                            </select>
                        <?php else: ?>
                            <input type="text" class="form-control" id="student_folder_program" name="program" value="<?php echo htmlspecialchars($coordinatorProgram); ?>" readonly>
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="student_folder_name">Folder Name</label>
                        <input type="text" class="form-control" id="student_folder_name" name="course_section" required>
                        <small class="form-text text-muted">Students will be placed inside this folder before assigning a facilitator.</small>
                        <div id="studentFolderPresetButtons" class="mt-2"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save mr-1"></i> Create Folder
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Reusable Student QR Modal -->
<div class="modal fade" id="studentQrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-qrcode mr-2"></i>
                    <span id="studentQrModalTitle">Student QR</span>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body text-center">
                <img id="studentQrModalImg" class="qr-modal-img img-thumbnail mb-3" src="" alt="Student QR Code">
                <div>
                    <code id="studentQrModalCode"></code>
                </div>
                <small class="text-muted d-block mt-2">QR image is generated from the student's generated code.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-info" id="studentQrPrintBtn">
                    <i class="fas fa-print mr-1"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    const coordinatorBasicColumns = new Set(['student_name', 'student_number', 'formal_picture', 'component', 'rotc_ms_level', 'course_section', 'generated_code']);
    let coordinatorPendingStudentsTable = null;
    const coordinatorColumnIndexes = {};

    function setCoordinatorColumnVisible(columnKey, visible) {
        if (coordinatorPendingStudentsTable && Object.prototype.hasOwnProperty.call(coordinatorColumnIndexes, columnKey)) {
            coordinatorPendingStudentsTable
                .column(coordinatorColumnIndexes[columnKey])
                .visible(visible, false);
            coordinatorPendingStudentsTable.columns.adjust().draw(false);
            return;
        }

        $('.coordinator-detail-col-' + columnKey).toggle(visible);
    }

    $('.coordinator-detail-column-toggle').on('change', function() {
        setCoordinatorColumnVisible(this.value, this.checked);
    });

    $('#coordinatorShowAllColumns').on('click', function() {
        $('.coordinator-detail-column-toggle').prop('checked', true).each(function() {
            setCoordinatorColumnVisible(this.value, true);
        });
    });

    $('#coordinatorHideOptionalColumns').on('click', function() {
        $('.coordinator-detail-column-toggle').each(function() {
            const visible = coordinatorBasicColumns.has(this.value);
            this.checked = visible;
            setCoordinatorColumnVisible(this.value, visible);
        });
    });

    $('#coordinatorVisibleDetailsPanel')
        .on('show.bs.collapse', function() {
            $('#coordinatorVisibleDetailsIcon').removeClass('fa-chevron-right').addClass('fa-chevron-down');
        })
        .on('hide.bs.collapse', function() {
            $('#coordinatorVisibleDetailsIcon').removeClass('fa-chevron-down').addClass('fa-chevron-right');
        });

    function folderPresetsForProgram(program) {
        const normalized = String(program || '').toUpperCase();
        if (normalized === 'ROTC') {
            const companies = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot'];
            const platoons = ['1st', '2nd', '3rd', '4th'];
            const sections = [];
            companies.forEach(company => platoons.forEach(platoon => sections.push(`${company} Company ${platoon} Platoon`)));
            return sections;
        }

        if (normalized === 'CWTS' || normalized === 'LTS') {
            const sections = [];
            ['1', '2'].forEach(year => ['A', 'B', 'C', 'D', 'E', 'F'].forEach(letter => sections.push(`${normalized} ${year}${letter}`)));
            return sections;
        }

        return [];
    }

    function renderStudentFolderPresets(program) {
        const presets = folderPresetsForProgram(program);
        const container = $('#studentFolderPresetButtons');
        container.empty();

        if (presets.length === 0) {
            return;
        }

        container.append('<div class="small text-muted mb-1">Quick presets</div>');
        presets.slice(0, 12).forEach(function(section) {
            container.append(`<button type="button" class="btn btn-xs btn-outline-success mr-1 mb-1 student-folder-preset" data-section="${escapeHtml(section)}">${escapeHtml(section)}</button>`);
        });
    }

    $('#createStudentFolderModal').on('show.bs.modal', function() {
        renderStudentFolderPresets($('#student_folder_program').val());
    });

    $('#student_folder_program').on('change', function() {
        renderStudentFolderPresets(this.value);
    });

    $(document).on('click', '.student-folder-preset', function() {
        $('#student_folder_name').val($(this).data('section')).focus();
    });

    $('#createStudentFolderForm').on('submit', function(e) {
        e.preventDefault();

        const form = $(this);
        const button = form.find('button[type="submit"]');
        const originalText = button.html();
        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Creating');

        $.ajax({
            url: './endpoint/create-section-folder.php',
            method: 'POST',
            data: form.serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Folder Created',
                        text: response.message,
                        timer: 1400,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', response.message || 'Unable to create folder.', 'error');
                    button.prop('disabled', false).html(originalText);
                }
            },
            error: function(xhr) {
                const rawResponse = String(xhr.responseText || '');
                if (rawResponse.includes('"success":true')) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Folder Created',
                        text: 'Folder created successfully.',
                        timer: 1400,
                        showConfirmButton: false
                    }).then(() => location.reload());
                    return;
                }

                Swal.fire('Request Failed', 'Unable to create folder. Please try again.', 'error');
                button.prop('disabled', false).html(originalText);
            }
        });
    });

    $('.assign-folder-to-facilitator-form').on('submit', function(e) {
        e.preventDefault();

        const form = $(this);
        const button = form.find('button[type="submit"]');
        const originalText = button.html();
        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: './endpoint/assign-section.php',
            method: 'POST',
            data: form.serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Facilitator Assigned',
                        text: `${response.message} ${response.moved_students || 0} student(s) moved.`,
                        timer: 1600,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', response.message || 'Unable to assign facilitator.', 'error');
                    button.prop('disabled', false).html(originalText);
                }
            },
            error: function() {
                Swal.fire('Request Failed', 'Unable to assign facilitator. Please try again.', 'error');
                button.prop('disabled', false).html(originalText);
            }
        });
    });

    $('.delete-student-folder').on('click', function() {
        const button = $(this);
        const folderId = button.data('folder-id');
        const folderName = String(button.data('folder-name') || '');
        const studentCount = Number(button.data('student-count') || 0);

        Swal.fire({
            icon: 'warning',
            title: 'Delete folder?',
            html: `
                <p>This will delete <strong>${escapeHtml(folderName)}</strong>.</p>
                <div class="alert alert-info small mb-0">
                    ${studentCount} student${studentCount === 1 ? '' : 's'} inside this folder will not be deleted.
                    They will be released back to the component pending list.
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Delete Folder',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#d33'
        }).then(function(result) {
            if (!result.isConfirmed) {
                return;
            }

            const originalButtonHtml = button.html();
            button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
            Swal.fire({
                title: 'Deleting folder...',
                html: `
                    <div class="text-center">
                        <div class="spinner-border text-danger mb-3" role="status" aria-hidden="true"></div>
                        <p class="mb-0">Please wait while the folder is being deleted.</p>
                    </div>
                `,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false
            });

            $.ajax({
                url: './endpoint/delete-section-folder.php',
                method: 'POST',
                data: { folder_id: folderId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Folder Deleted',
                            text: response.message,
                            timer: 1800,
                            showConfirmButton: false
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Unable to Delete', response.message || 'Please try again.', 'error');
                        button.prop('disabled', false).html(originalButtonHtml);
                    }
                },
                error: function() {
                    Swal.fire('Request Failed', 'Unable to delete folder. Please try again.', 'error');
                    button.prop('disabled', false).html(originalButtonHtml);
                }
            });
        });
    });

    function getSelectedStudentFolders() {
        return $('.student-folder-check:checked').map(function() {
            const checkbox = $(this);
            return {
                id: checkbox.val(),
                name: String(checkbox.data('folder-name') || ''),
                studentCount: Number(checkbox.data('student-count') || 0)
            };
        }).get();
    }

    function getStudentFolderChecks() {
        return $('.student-folder-check');
    }

    function updateBulkDeleteFolderState() {
        const folderChecks = getStudentFolderChecks();
        const totalFolders = folderChecks.length;
        const selectedFolders = getSelectedStudentFolders();
        const selectedCount = selectedFolders.length;

        $('.bulk-delete-student-folders-btn')
            .prop('disabled', selectedCount === 0)
            .html(`<i class="fas fa-trash mr-1"></i> ${selectedCount ? `Delete Selected (${selectedCount})` : 'Delete Selected'}`);

        $('#selectAllStudentFolders, #selectAllStudentFoldersTop')
            .prop('checked', totalFolders > 0 && selectedCount === totalFolders)
            .prop('indeterminate', selectedCount > 0 && selectedCount < totalFolders);
    }

    $(document).on('change', '#selectAllStudentFolders, #selectAllStudentFoldersTop', function() {
        getStudentFolderChecks().prop('checked', $(this).is(':checked'));
        updateBulkDeleteFolderState();
    });

    $(document).on('change', '.student-folder-check', updateBulkDeleteFolderState);

    $(document).on('click', '.bulk-delete-student-folders-btn', function() {
        const selectedFolders = getSelectedStudentFolders();

        if (!selectedFolders.length) {
            return;
        }

        const totalStudents = selectedFolders.reduce((sum, folder) => sum + folder.studentCount, 0);
        const previewNames = selectedFolders.slice(0, 6).map(folder => `<li>${escapeHtml(folder.name)}</li>`).join('');
        const extraCount = selectedFolders.length > 6 ? selectedFolders.length - 6 : 0;

        Swal.fire({
            icon: 'warning',
            title: 'Delete selected folders?',
            html: `
                <p>You selected <strong>${selectedFolders.length}</strong> folder${selectedFolders.length === 1 ? '' : 's'}.</p>
                <ul class="text-left mb-2">${previewNames}${extraCount ? `<li>and ${extraCount} more...</li>` : ''}</ul>
                <div class="alert alert-info small mb-0">
                    ${totalStudents} student${totalStudents === 1 ? '' : 's'} inside these folders will not be deleted.
                    They will be released back to the component pending list.
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Delete Selected',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#d33'
        }).then(function(result) {
            if (!result.isConfirmed) {
                return;
            }

            const bulkButtons = $('.bulk-delete-student-folders-btn');
            bulkButtons
                .prop('disabled', true)
                .html('<i class="fas fa-spinner fa-spin mr-1"></i> Deleting...');
            getStudentFolderChecks().prop('disabled', true);
            $('#selectAllStudentFolders, #selectAllStudentFoldersTop').prop('disabled', true);

            Swal.fire({
                title: 'Deleting folders...',
                html: `
                    <div class="text-center">
                        <div class="spinner-border text-danger mb-3" role="status" aria-hidden="true"></div>
                        <p class="mb-0">Deleting ${selectedFolders.length} selected folder${selectedFolders.length === 1 ? '' : 's'}.</p>
                    </div>
                `,
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false
            });

            $.ajax({
                url: './endpoint/delete-section-folders.php',
                method: 'POST',
                data: { folder_ids: selectedFolders.map(folder => folder.id) },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Folders Deleted',
                            text: response.message,
                            timer: 1800,
                            showConfirmButton: false
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Unable to Delete', response.message || 'Please try again.', 'error');
                        getStudentFolderChecks().prop('disabled', false);
                        $('#selectAllStudentFolders, #selectAllStudentFoldersTop').prop('disabled', false);
                        updateBulkDeleteFolderState();
                    }
                },
                error: function() {
                    Swal.fire('Request Failed', 'Unable to delete selected folders. Please try again.', 'error');
                    getStudentFolderChecks().prop('disabled', false);
                    $('#selectAllStudentFolders, #selectAllStudentFoldersTop').prop('disabled', false);
                    updateBulkDeleteFolderState();
                }
            });
        });
    });

    updateBulkDeleteFolderState();
    
    // ====================================
    // FOLDER TOGGLE FUNCTIONALITY
    // ====================================
    
    // Main folder headers (Admin Folders, Section Folders)
    $('.admin-folder-header, .section-folder-header, .nested-section-header').on('click', function(e) {
        if ($(e.target).closest('button').length) return;
        
        const $header = $(this);
        const $folder = $header.closest('.admin-folder, .section-folder, .nested-section-folder');
        const $body = $folder.find('.admin-folder-body, .section-folder-body').first();
        const $icon = $header.find('.expand-collapse-icon');
        
        $body.slideToggle(300);
        $icon.toggleClass('fa-chevron-circle-right fa-chevron-circle-down');
        $header.toggleClass('collapsed');
    });
    
    // Expand All button
    $('[data-filter="expanded"]').on('click', function() {
        $('#searchStudent').val('');
        $('.student-row, .folder-summary-row, .folder-box, .admin-folder, .section-folder, .nested-section-folder').show();
        $('.admin-folder-body, .section-folder-body, .nested-section-folder .section-folder-body').slideDown(300);
        $('.admin-folder-header, .section-folder-header, .nested-section-header').removeClass('collapsed');
        $('.expand-collapse-icon').removeClass('fa-chevron-circle-right').addClass('fa-chevron-circle-down');
        $(this).addClass('active').siblings().removeClass('active');
        updateBulkDeleteFolderState();
    });
    
    // Collapse All button
    $('[data-filter="collapsed"]').on('click', function() {
        $('#searchStudent').val('');
        $('.student-row, .folder-summary-row, .folder-box, .admin-folder, .section-folder, .nested-section-folder').show();
        $('.admin-folder-body, .section-folder-body, .nested-section-folder .section-folder-body').slideUp(300);
        $('.admin-folder-header, .section-folder-header, .nested-section-header').addClass('collapsed');
        $('.expand-collapse-icon').removeClass('fa-chevron-circle-down').addClass('fa-chevron-circle-right');
        $(this).addClass('active').siblings().removeClass('active');
        updateBulkDeleteFolderState();
    });
    
    // All Folders button
    $('[data-filter="all"]').on('click', function() {
        $('#searchStudent').val('');
        $('.student-row, .folder-summary-row, .folder-box, .admin-folder, .section-folder, .nested-section-folder').show();
        $('.admin-folder-body, .section-folder-body, .nested-section-folder .section-folder-body').slideUp(300);
        $('.admin-folder-header, .section-folder-header, .nested-section-header').addClass('collapsed');
        $('.expand-collapse-icon').removeClass('fa-chevron-circle-down').addClass('fa-chevron-circle-right');
        $(this).addClass('active').siblings().removeClass('active');
        updateBulkDeleteFolderState();
    });
    
    // ====================================
    // SEARCH FUNCTIONALITY
    // ====================================
    
    $('#searchStudent').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase().trim();
        
        if (searchTerm === '') {
            // Reset view - collapse everything
            $('.student-row').show();
            $('.folder-summary-row').show();
            $('.folder-box').show();
            $('.admin-folder, .section-folder').show();
            $('.admin-folder-body, .section-folder-body, .nested-section-folder .section-folder-body').slideUp(300);
            $('.admin-folder-header, .section-folder-header, .nested-section-header').addClass('collapsed');
            $('.expand-collapse-icon').removeClass('fa-chevron-circle-down').addClass('fa-chevron-circle-right');
            
            // Reset stat badges
            $('.section-folder').each(function() {
                const $folder = $(this);
                const totalCount = $folder.find('.student-row').length;
                $folder.find('.stat-badge:first').html(`<i class="fas fa-users"></i> ${totalCount} student${totalCount != 1 ? 's' : ''}`);
            });
            
            $('.admin-folder').each(function() {
                const $folder = $(this);
                const totalCount = $folder.find('.student-row').length;
                $folder.find('.admin-stats .stat-badge:last').html(`<i class="fas fa-users"></i> ${totalCount} students`);
            });
            updateBulkDeleteFolderState();
        } else {
            // Hide all rows first
            $('.student-row').hide();
            $('.folder-summary-row').hide();
            $('.folder-box').hide();
            
            // Show matching rows without relying on CSS attribute selectors.
            $('.student-row').each(function() {
                const row = $(this);
                const searchableText = [
                    row.data('student-name') || '',
                    row.data('original-section') || '',
                    row.data('folder-section') || ''
                ].join(' ').toLowerCase();

                row.toggle(searchableText.includes(searchTerm));
            });

            $('.folder-summary-row').each(function() {
                const row = $(this);
                const searchableText = [
                    row.data('folder-name') || '',
                    row.data('folder-program') || '',
                    row.data('folder-facilitator') || '',
                    row.text() || ''
                ].join(' ').toLowerCase();

                row.toggle(searchableText.includes(searchTerm));
            });

            $('.folder-box').each(function() {
                const box = $(this);
                const searchableText = box.text().toLowerCase();
                box.toggle(searchableText.includes(searchTerm));
            });
            
            // Process section folders
            $('.section-folder').each(function() {
                const $folder = $(this);
                const $visibleRows = $folder.find('.student-row:visible');
                const totalCount = $folder.find('.student-row').length;
                
                if ($visibleRows.length > 0) {
                    $folder.show();
                    $folder.find('.section-folder-body').slideDown(300);
                    $folder.find('.section-folder-header').removeClass('collapsed');
                    $folder.find('.expand-collapse-icon').removeClass('fa-chevron-circle-right').addClass('fa-chevron-circle-down');
                    $folder.find('.stat-badge:first').html(`<i class="fas fa-users"></i> ${$visibleRows.length}/${totalCount} students`);
                } else {
                    $folder.hide();
                }
            });
            
            // Process admin folders (super admin view)
            $('.admin-folder').each(function() {
                const $folder = $(this);
                const $visibleRows = $folder.find('.student-row:visible');
                const totalCount = $folder.find('.student-row').length;
                
                if ($visibleRows.length > 0) {
                    $folder.show();
                    $folder.find('.admin-folder-body').slideDown(300);
                    $folder.find('.admin-folder-header').removeClass('collapsed');
                    $folder.find('.expand-collapse-icon').removeClass('fa-chevron-circle-right').addClass('fa-chevron-circle-down');
                    $folder.find('.admin-stats .stat-badge:last').html(`<i class="fas fa-users"></i> ${$visibleRows.length}/${totalCount} students`);
                    
                    // Show nested section folders with visible students
                    $folder.find('.nested-section-folder').each(function() {
                        const $nested = $(this);
                        const $nestedVisibleRows = $nested.find('.student-row:visible');
                        
                        if ($nestedVisibleRows.length > 0) {
                            $nested.show();
                            $nested.find('.section-folder-body').slideDown(300);
                            $nested.find('.nested-section-header').removeClass('collapsed');
                            $nested.find('.expand-collapse-icon').removeClass('fa-chevron-circle-right').addClass('fa-chevron-circle-down');
                        } else {
                            $nested.hide();
                        }
                    });
                } else {
                    $folder.hide();
                }
            });
            updateBulkDeleteFolderState();
        }
    });
    
    // Initialize DataTable for regular admin with single section
    if ($('#studentFoldersTable').length) {
        $('#studentFoldersTable').DataTable({
            "pageLength": 10,
            "lengthMenu": [[10, 25, 50, -1], [10, 25, 50, "All"]],
            "responsive": true,
            "ordering": true,
            "order": [[2, 'asc']]
        });
    }

    if ($('#coordinatorPendingStudentsTable').length) {
        $('#coordinatorPendingStudentsTable thead th[data-column]').each(function(index) {
            coordinatorColumnIndexes[$(this).data('column')] = index;
        });

        coordinatorPendingStudentsTable = $('#coordinatorPendingStudentsTable').DataTable({
            "pageLength": 25,
            "lengthMenu": [[10, 25, 50, -1], [10, 25, 50, "All"]],
            "responsive": false,
            "ordering": true,
            "order": [[1, 'asc']],
            "columnDefs": [
                { "targets": 0, "orderable": false, "searchable": false },
                { "targets": -1, "orderable": false, "searchable": false }
            ]
        });
    }

    <?php if ($user_role === 'facilitator' && $sections_count <= 1): ?>
    $('#studentTable').DataTable({
        "pageLength": 10,
        "responsive": true,
        "language": {
            "emptyTable": "No students found. Add your first student!"
        }
    });
    <?php endif; ?>
    
    // Initialize DataTables for each folder table (if multiple sections)
    <?php if ($user_role === 'facilitator' && $sections_count > 1): ?>
    $('.folder-table').each(function() {
        if ($(this).find('tbody tr').length > 0) {
            $(this).DataTable({
                "pageLength": 5,
                "lengthMenu": [[5, 10, 25, -1], [5, 10, 25, "All"]],
                "responsive": true
            });
        }
    });
    <?php endif; ?>
    
    // Initialize DataTables for super admin nested tables
    <?php if ($user_role === 'super_admin'): ?>
    $('.folder-table').each(function() {
        if ($(this).find('tbody tr').length > 0) {
            $(this).DataTable({
                "pageLength": 5,
                "lengthMenu": [[5, 10, 25, -1], [5, 10, 25, "All"]],
                "responsive": true,
                "ordering": true
            });
        }
    });
    <?php endif; ?>

    $('.facilitator-select').on('change', function() {
        const form = $(this).closest('form');
        const folderSelect = form.find('.folder-select');
        const selected = $(this).find('option:selected');
        let folders = [];

        try {
            folders = JSON.parse(selected.attr('data-folders') || '[]');
        } catch (error) {
            folders = [];
        }

        folderSelect.empty();
        if (folders.length === 0) {
            folderSelect.append('<option value="">No existing folders</option>');
            folderSelect.prop('disabled', true);
            return;
        }

        folderSelect.append('<option value="">Select existing folder</option>');
        folders.forEach(function(folder) {
            folderSelect.append(`<option value="${escapeHtml(folder)}">${escapeHtml(folder)}</option>`);
        });
        folderSelect.prop('disabled', false);
    });

    $('#import_facilitator_id').on('change', function() {
        const folderSelect = $('#import_section');
        const selected = $(this).find('option:selected');
        let folders = [];

        try {
            folders = JSON.parse(selected.attr('data-folders') || '[]');
        } catch (error) {
            folders = [];
        }

        folderSelect.empty();
        if (folders.length === 0) {
            folderSelect.append('<option value="">No existing folders</option>');
            folderSelect.prop('disabled', true);
            return;
        }

        folderSelect.append('<option value="">-- Select Folder --</option>');
        folders.forEach(function(folder) {
            folderSelect.append(`<option value="${escapeHtml(folder)}">${escapeHtml(folder)}</option>`);
        });
        folderSelect.prop('disabled', false);
    });

    $('.assign-student-form').on('submit', function(e) {
        e.preventDefault();

        const form = $(this);
        const button = form.find('button[type="submit"]');
        const originalText = button.html();

        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Assigning...');

        $.ajax({
            url: './endpoint/assign-student-facilitator.php',
            method: 'POST',
            data: form.serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Assigned',
                        text: response.message,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', response.message, 'error');
                    button.prop('disabled', false).html(originalText);
                }
            },
            error: function() {
                Swal.fire('Error', 'Failed to assign student. Please try again.', 'error');
                button.prop('disabled', false).html(originalText);
            }
        });
    });
    
    // Reset import modal when closed
    $('#importExcelModal').on('hidden.bs.modal', function () {
        document.getElementById('importExcelForm').reset();
        document.getElementById('importProgress').style.display = 'none';
        const progressBar = document.querySelector('#importProgress .progress-bar');
        if (progressBar) {
            progressBar.style.width = '0%';
            progressBar.textContent = '0%';
        }
        const importAlert = document.getElementById('importAlert');
        if (importAlert) {
            importAlert.style.display = 'none';
            importAlert.innerHTML = '';
        }
        const importFolderSelect = document.getElementById('import_section');
        if (importFolderSelect && document.getElementById('import_facilitator_id')) {
            importFolderSelect.innerHTML = '<option value="">-- Select facilitator first --</option>';
            importFolderSelect.disabled = true;
        }
    });
    
    // Reset export modal when closed
    $('#exportQRModal').on('hidden.bs.modal', function () {
        document.getElementById('exportPreview').style.display = 'none';
        document.getElementById('previewList').innerHTML = '';
        
        <?php if ($user_role === 'super_admin'): ?>
        document.getElementById('super_admin_section_container').style.display = 'none';
        <?php endif; ?>
    });
});

// ====================================
// STUDENT MANAGEMENT FUNCTIONS
// ====================================

function updateStudent(id) {
    const button = document.querySelector(`button[onclick="updateStudent(${id})"]`);
    if (!button) return;
    
    const row = button.closest('tr');
    if (!row) return;
    
    // Get the cells
    const cells = row.cells;
    
    // Extract student name (cell 1)
    const studentName = cells[1].textContent.trim();
    
    // Extract original section (cell 2) - remove any HTML tags and trim
    const originalSectionCell = cells[2];
    let originalSection = originalSectionCell.textContent || originalSectionCell.innerText;
    originalSection = originalSection.replace(/<[^>]*>/g, '').trim();
    
    // Extract folder section (cell 3) - remove any HTML tags and trim
    const folderSectionCell = cells[3];
    let folderSection = folderSectionCell.textContent || folderSectionCell.innerText;
    folderSection = folderSection.replace(/<[^>]*>/g, '').trim();
    
    console.log('Updating student:', {
        id: id,
        name: studentName,
        original: originalSection,
        folder: folderSection
    });
    
    // Set values in the form
    $("#updateStudentId").val(id);
    $("#updateStudentName").val(studentName);
    $("#updateOriginalSection").val(originalSection);
    $("#updateStudentCourse").val(folderSection);
    
    // Show the modal
    $("#updateStudentModal").modal("show");
}

function deleteStudent(id) {
    Swal.fire({
        title: 'Delete Student?',
        text: "This student will be permanently deleted!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = "./endpoint/delete-student.php?student=" + id;
        }
    });
}

function deleteCoordinatorFolder(assignmentId, folderName, facilitatorName, studentCount) {
    Swal.fire({
        title: 'Delete Folder?',
        html: `
            <p>This will permanently delete <strong>${escapeHtml(folderName)}</strong> from <strong>${escapeHtml(facilitatorName)}</strong>.</p>
            <div class="alert alert-danger small mb-0">
                This also deletes ${studentCount} student record${studentCount === 1 ? '' : 's'} and their attendance records in this folder.
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete folder',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (!result.isConfirmed) {
            return;
        }

        const formData = new FormData();
        formData.append('assignment_id', assignmentId);

        Swal.fire({
            title: 'Deleting Folder',
            text: 'Please wait...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        fetch('./endpoint/delete-folder.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Deleted', data.message, 'success').then(() => location.reload());
            } else {
                Swal.fire('Unable to Delete', data.message || 'Please try again.', 'error');
            }
        })
        .catch(() => {
            Swal.fire('Error', 'Failed to delete the folder. Please try again.', 'error');
        });
    });
}

function generateRandomCode(length) {
    const characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    let randomString = '';
    for (let i = 0; i < length; i++) {
        const randomIndex = Math.floor(Math.random() * characters.length);
        randomString += characters.charAt(randomIndex);
    }
    return randomString;
}

function generateQrCode() {
    const qrImg = document.getElementById('qrImg');
    const studentName = document.getElementById('studentName').value.trim();
    
    if (!studentName) {
        Swal.fire('Error', 'Please enter student name first!', 'error');
        return;
    }
    
    // Get original section element - declare once
    const originalSectionInput = document.getElementById('originalSection');
    
    <?php if ($user_role === 'facilitator'): ?>
        // Check if original section is filled
        if (!originalSectionInput || !originalSectionInput.value.trim()) {
            Swal.fire('Error', 'Please enter the student\'s original college section!', 'error');
            return;
        }
        
        <?php if ($sections_count > 1): ?>
        // Admin with multiple sections - check if folder section is selected
        const selectedSection = document.getElementById('course_section');
        if (!selectedSection || !selectedSection.value) {
            Swal.fire('Error', 'Please select an admin folder for this student!', 'error');
            return;
        }
        <?php elseif ($sections_count == 0): ?>
        Swal.fire('Error', 'You are not assigned to any admin folder. Please contact super admin.', 'error');
        return;
        <?php endif; ?>
    <?php endif; ?>
    
    <?php if ($user_role === 'super_admin'): ?>
    const studentCourse = document.getElementById('studentCourse').value.trim();
    
    if (!originalSectionInput || !originalSectionInput.value.trim()) {
        Swal.fire('Error', 'Please enter the student\'s original college section!', 'error');
        return;
    }
    
    if (!studentCourse) {
        Swal.fire('Error', 'Please enter admin folder section for the student!', 'error');
        return;
    }
    <?php endif; ?>
    
    let text = generateRandomCode(10);
    $("#generatedCode").val(text);

    if (text === "") {
        Swal.fire('Error', 'Failed to generate QR code!', 'error');
        return;
    } else {
        const apiUrl = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(text)}`;
        qrImg.src = apiUrl;
        document.getElementById('studentName').style.pointerEvents = 'none';
        
<?php if ($user_role === 'facilitator' || $user_role === 'super_admin'): ?>
        if (originalSectionInput) originalSectionInput.style.pointerEvents = 'none';
        <?php endif; ?>
        
        <?php if ($user_role === 'super_admin'): ?>
        document.getElementById('studentCourse').style.pointerEvents = 'none';
        <?php endif; ?>
        
        <?php if ($user_role === 'facilitator' && $sections_count > 1): ?>
        document.getElementById('course_section').style.pointerEvents = 'none';
        <?php endif; ?>
        
        document.getElementById('addModalFooter').style.display = 'flex';
        document.querySelector('.qr-con').style.display = 'block';
    }
}

function importExcel() {
    const form = document.getElementById('importExcelForm');
    const formData = new FormData(form);
    const fileInput = document.getElementById('excel_file');
    const importAlert = document.getElementById('importAlert');
    
    // Clear previous alerts
    if (importAlert) {
        importAlert.style.display = 'none';
        importAlert.innerHTML = '';
    }
    
    if (!fileInput.files.length) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Please select an Excel file to import.'
        });
        return;
    }

    const file = fileInput.files[0];
    const fileName = file.name;
    const fileExt = fileName.split('.').pop().toLowerCase();
    const validExts = ['xlsx', 'xls', 'csv'];
    
    if (!validExts.includes(fileExt)) {
        Swal.fire({
            icon: 'error',
            title: 'Invalid File',
            text: 'Please select a valid Excel file (.xlsx, .xls, or .csv).'
        });
        return;
    }

    <?php if ($user_role === 'facilitator' && $sections_count > 1): ?>
    const importSection = document.getElementById('import_section');
    if (!importSection || !importSection.value) {
        Swal.fire({
            icon: 'error',
            title: 'Folder Required',
            text: 'Please select a target admin folder for import.'
        });
        return;
    }
    formData.append('import_section', importSection.value);
    <?php endif; ?>

    <?php if ($user_role === 'super_admin'): ?>
    const importComponent = document.getElementById('import_component');
    if (!importComponent || !importComponent.value) {
        Swal.fire('Component Required', 'Please select CWTS, LTS, or ROTC for this import.', 'error');
        return;
    }
    formData.set('component', importComponent.value);
    <?php endif; ?>

    <?php if ($user_role === 'coordinator'): ?>
    const importSection = document.getElementById('import_section');
    if (!importSection || !importSection.value) {
        Swal.fire('Folder Required', 'Please select a target folder.', 'error');
        return;
    }
    formData.set('import_section', importSection.value);
    <?php endif; ?>

    const submitBtn = document.getElementById('importExcelBtn');
    const originalHtml = submitBtn.innerHTML;
    const progressBar = document.getElementById('importProgress');
    const progressBarInner = progressBar.querySelector('.progress-bar');
    
    // Disable button and show progress
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
    progressBar.style.display = 'block';
    progressBarInner.style.width = '30%';
    progressBarInner.textContent = 'Uploading...';

    fetch('./endpoint/import-students-excel.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        progressBarInner.style.width = '50%';
        progressBarInner.textContent = 'Processing...';
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text().then(text => {
            console.log('Raw response:', text.substring(0, 500));
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Failed to parse JSON:', e);
                if (text.includes('<!DOCTYPE') || text.includes('<html')) {
                    throw new Error('Server returned HTML instead of JSON. Possible PHP error.');
                } else {
                    throw new Error('Invalid JSON response from server');
                }
            }
        });
    })
    .then(data => {
        console.log('Parsed data:', data);
        
        progressBarInner.style.width = '100%';
        progressBarInner.textContent = 'Complete!';
        
        if (data.success) {
            let message = data.message;
            if (data.errors && data.errors.length > 0) {
                message += '<br><br><strong>Warnings/Errors:</strong><br>';
                message += data.errors.slice(0, 10).join('<br>');
                if (data.errors.length > 10) {
                    message += `<br><br>... and ${data.errors.length - 10} more errors. Check console for details.`;
                }
            }
            
            Swal.fire({
                icon: 'success',
                title: 'Import Successful!',
                html: message,
                confirmButtonText: 'OK'
            }).then(() => {
                $('#importExcelModal').modal('hide');
                setTimeout(() => {
                    location.reload();
                }, 500);
            });
        } else {
            let errorMessage = data.message;
            if (data.errors && data.errors.length > 0) {
                errorMessage += '<br><br><strong>Errors:</strong><br>';
                errorMessage += data.errors.slice(0, 10).join('<br>');
                if (data.errors.length > 10) {
                    errorMessage += `<br><br>... and ${data.errors.length - 10} more errors.`;
                }
            }
            
            Swal.fire({
                icon: 'error',
                title: 'Import Failed',
                html: errorMessage,
                confirmButtonText: 'OK'
            });
            
            progressBar.style.display = 'none';
            progressBarInner.style.width = '0%';
        }
    })
    .catch(error => {
        console.error('Import Error:', error);
        
        let errorMsg = 'An error occurred while importing the file.';
        if (error.message) {
            errorMsg += '<br><br><strong>Error details:</strong> ' + error.message;
        }
        
        Swal.fire({
            icon: 'error',
            title: 'Import Error',
            html: errorMsg,
            confirmButtonText: 'OK'
        });
        
        progressBar.style.display = 'none';
        progressBarInner.style.width = '0%';
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHtml;
        
        setTimeout(() => {
            progressBar.style.display = 'none';
            progressBarInner.style.width = '0%';
            progressBarInner.textContent = '0%';
        }, 3000);
    });
}

function getQrImageUrl(qrCode, size) {
    return `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&data=${encodeURIComponent(qrCode)}`;
}

function showStudentQrModal(qrCode, studentName = 'Student') {
    const safeCode = String(qrCode || '').trim();
    const safeName = String(studentName || 'Student').trim();

    if (!safeCode) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing QR Code',
            text: 'This student does not have a generated QR code yet.'
        });
        return;
    }

    document.getElementById('studentQrModalTitle').textContent = `${safeName} QR`;
    document.getElementById('studentQrModalCode').textContent = safeCode;
    document.getElementById('studentQrModalImg').src = getQrImageUrl(safeCode, 260);
    document.getElementById('studentQrPrintBtn').onclick = () => printQR(safeCode);
    $('#studentQrModal').modal('show');
}

function printQR(qrCode) {
    const printWindow = window.open('', '_blank');
    const qrUrl = getQrImageUrl(qrCode, 300);
    printWindow.document.write(`
        <html>
            <head>
                <title>Print QR Code</title>
                <style>
                    body { display: flex; justify-content: center; align-items: center; height: 100vh; }
                    img { max-width: 300px; }
                </style>
            </head>
            <body>
                <img src="${qrUrl}">
            </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// ====================================
// EXPORT FUNCTIONS (ZIP ONLY)
// ====================================

function previewExport() {
    const previewBtn = document.getElementById('previewBtn');
    const previewDiv = document.getElementById('exportPreview');
    const previewList = document.getElementById('previewList');
    const previewCount = document.getElementById('previewCount');
    
    let params = new URLSearchParams();
    let sectionValue = '';
    
    <?php if ($user_role === 'super_admin'): ?>
    const adminId = document.getElementById('export_admin_id').value;
    if (!adminId) {
        Swal.fire('Error', 'Please select an admin first!', 'error');
        return;
    }
    params.append('admin_id', adminId);
    
    sectionValue = document.getElementById('export_section_super').value;
    if (sectionValue) {
        params.append('section', sectionValue);
    }
    <?php else: ?>
    <?php if ($sections_count > 1): ?>
    sectionValue = document.getElementById('export_section').value;
    if (!sectionValue) {
        Swal.fire('Error', 'Please select a section to export!', 'error');
        return;
    }
    params.append('section', sectionValue);
    <?php else: ?>
    params.append('section', '<?php echo addslashes($assignedSection); ?>');
    <?php endif; ?>
    <?php endif; ?>
    
    // Show loading
    previewBtn.disabled = true;
    previewBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
    
    fetch('./endpoint/preview-export.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            if (data.success && data.students.length > 0) {
                previewList.innerHTML = '';
                data.students.slice(0, 5).forEach(student => {
                    const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=50x50&data=${encodeURIComponent(student.generated_code)}`;
                    previewList.innerHTML += `
                        <tr>
                            <td>${escapeHtml(student.student_name)}</td>
                            <td>${escapeHtml(student.original_section || 'N/A')}</td>
                            <td><img src="${qrUrl}" width="30" height="30" alt="QR"></td>
                        </tr>
                    `;
                });
                previewCount.textContent = `${data.students.length} students`;
                previewDiv.style.display = 'block';
            } else {
                Swal.fire('No Students', 'No students found for export in this section.', 'info');
                previewDiv.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Preview error:', error);
            Swal.fire('Error', 'Failed to load preview', 'error');
        })
        .finally(() => {
            previewBtn.disabled = false;
            previewBtn.innerHTML = '<i class="fas fa-eye mr-2"></i> Preview';
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function exportQR() {
    let params = new URLSearchParams();
    let sectionValue = '';
    
    <?php if ($user_role === 'super_admin'): ?>
    const adminId = document.getElementById('export_admin_id').value;
    if (!adminId) {
        Swal.fire('Error', 'Please select an admin first!', 'error');
        return;
    }
    params.append('admin_id', adminId);
    
    sectionValue = document.getElementById('export_section_super').value;
    if (sectionValue) {
        params.append('section', sectionValue);
    }
    <?php else: ?>
    <?php if ($sections_count > 1): ?>
    sectionValue = document.getElementById('export_section').value;
    if (!sectionValue) {
        Swal.fire('Error', 'Please select a section to export!', 'error');
        return;
    }
    params.append('section', sectionValue);
    <?php endif; ?>
    <?php endif; ?>
    
    // Show loading
    Swal.fire({
        title: 'Preparing ZIP Export',
        text: 'Generating QR codes and creating ZIP file...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Redirect to download
    window.location.href = './endpoint/export-qr-zip.php?' + params.toString();
    
    // Close loading after a short delay
    setTimeout(() => {
        Swal.close();
        $('#exportQRModal').modal('hide');
    }, 2000);
}

<?php if ($user_role === 'super_admin'): ?>
// Super Admin: Load sections when admin is selected
document.getElementById('export_admin_id')?.addEventListener('change', function() {
    const adminId = this.value;
    const sectionContainer = document.getElementById('super_admin_section_container');
    const sectionSelect = document.getElementById('export_section_super');
    
    if (!adminId) {
        sectionContainer.style.display = 'none';
        return;
    }
    
    // Show loading
    sectionSelect.innerHTML = '<option value="">Loading sections...</option>';
    sectionContainer.style.display = 'block';
    
    fetch('./endpoint/get-admin-sections.php?admin_id=' + adminId)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.sections.length > 0) {
                let options = '<option value="">-- All Sections --</option>';
                data.sections.forEach(section => {
                    options += `<option value="${escapeHtml(section)}">${escapeHtml(section)}</option>`;
                });
                sectionSelect.innerHTML = options;
            } else {
                sectionSelect.innerHTML = '<option value="">No sections found</option>';
            }
        })
        .catch(error => {
            console.error('Error loading sections:', error);
            sectionSelect.innerHTML = '<option value="">Error loading sections</option>';
        });
});
<?php endif; ?>

// Modal cleanup
$('#addStudentModal').on('hidden.bs.modal', function () {
    const form = document.getElementById('addStudentForm');
    if (form) {
        form.reset();
        const qrCon = document.querySelector('.qr-con');
        if (qrCon) qrCon.style.display = 'none';
        const footer = document.getElementById('addModalFooter');
        if (footer) footer.style.display = 'none';
        const nameField = document.getElementById('studentName');
        if (nameField) nameField.style.pointerEvents = 'auto';
        
        <?php if ($user_role === 'facilitator' || $user_role === 'super_admin'): ?>
        const originalField = document.getElementById('originalSection');
        if (originalField) originalField.style.pointerEvents = 'auto';
        <?php endif; ?>
        
        <?php if ($user_role === 'super_admin'): ?>
        const courseField = document.getElementById('studentCourse');
        if (courseField) courseField.style.pointerEvents = 'auto';
        <?php endif; ?>
        
        <?php if ($user_role === 'facilitator' && $sections_count > 1): ?>
        const sectionField = document.getElementById('course_section');
        if (sectionField) sectionField.style.pointerEvents = 'auto';
        <?php endif; ?>
    }
});

// AJAX form submission for Add Student
$('#addStudentForm').on('submit', function(e) {
    e.preventDefault();
    
    const form = $(this);
    const submitBtn = document.getElementById('addStudentBtn');
    const originalText = submitBtn.innerHTML;
    
    // Validate QR code
    if (!$('#generatedCode').val()) {
        Swal.fire('Error', 'Please generate QR code first!', 'error');
        return;
    }
    
    <?php if ($user_role === 'facilitator'): ?>
    // Validate original section
    if (!$('#originalSection').val()) {
        Swal.fire('Error', 'Please enter the student\'s original college section!', 'error');
        return;
    }
    <?php endif; ?>
    
    <?php if ($user_role === 'facilitator' && $sections_count > 1): ?>
    // Validate folder section selection
    if (!$('#course_section').val()) {
        Swal.fire('Error', 'Please select an admin folder for this student!', 'error');
        return;
    }
    <?php endif; ?>
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
    submitBtn.classList.add('btn-spinner');
    
    $.ajax({
        url: './endpoint/add-student.php',
        method: 'POST',
        data: form.serialize(),
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: response.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    $('#addStudentModal').modal('hide');
                    setTimeout(() => location.reload(), 500);
                });
            } else {
                Swal.fire('Error', response.message, 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
                submitBtn.classList.remove('btn-spinner');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', xhr.responseText);
            Swal.fire('Error', 'Failed to connect to server. Please try again.', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            submitBtn.classList.remove('btn-spinner');
        }
    });
});

// AJAX form submission for Update Student
$('#updateStudentForm').on('submit', function(e) {
    e.preventDefault();
    
    const form = $(this);
    const submitBtn = document.getElementById('updateStudentBtn');
    const originalText = submitBtn.innerHTML;
    
    // Validate form
    const studentName = $('#updateStudentName').val().trim();
    const originalSection = $('#updateOriginalSection').val().trim();
    const studentCourse = $('#updateStudentCourse').val().trim();
    
    if (!studentName) {
        Swal.fire('Error', 'Student name is required!', 'error');
        return;
    }
    
    <?php if ($user_role === 'facilitator' || $user_role === 'super_admin'): ?>
    if (!originalSection) {
        Swal.fire('Error', 'Original college section is required!', 'error');
        return;
    }
    <?php endif; ?>
    
    if (!studentCourse) {
        Swal.fire('Error', 'Admin folder section is required!', 'error');
        return;
    }
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    submitBtn.classList.add('btn-spinner');
    
    // Log the data being sent (for debugging)
    console.log('Sending update data:', {
        tbl_student_id: $('#updateStudentId').val(),
        student_name: studentName,
        original_section: originalSection,
        course_section: studentCourse
    });
    
    $.ajax({
        url: './endpoint/update-student.php',
        method: 'POST',
        data: form.serialize(),
        dataType: 'json',
        timeout: 30000, // 30 second timeout
        success: function(response) {
            console.log('Update response:', response);
            
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: response.message || 'Student updated successfully!',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    $('#updateStudentModal').modal('hide');
                    // Reload the page to show updated data
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Update Failed',
                    text: response.message || 'Failed to update student. Please try again.',
                    confirmButtonText: 'OK'
                });
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
                submitBtn.classList.remove('btn-spinner');
            }
        },
        error: function(xhr, status, error) {
            console.error('Update Error - Status:', status);
            console.error('Update Error - Details:', error);
            console.error('Update Error - Response:', xhr.responseText);
            
            let errorMsg = 'Failed to connect to server. Please try again.';
            
            if (status === 'timeout') {
                errorMsg = 'Request timed out. Please check your connection and try again.';
            } else if (xhr.responseText) {
                try {
                    // Try to parse error response
                    const response = JSON.parse(xhr.responseText);
                    errorMsg = response.message || errorMsg;
                } catch(e) {
                    // If not JSON, show a generic error
                    console.error('Could not parse error response');
                }
            }
            
            Swal.fire({
                icon: 'error',
                title: 'Update Error',
                text: errorMsg,
                confirmButtonText: 'OK'
            });
            
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            submitBtn.classList.remove('btn-spinner');
        }
    });
});

// Global error handler
window.onerror = function(message, source, lineno, colno, error) {
    console.error('JavaScript Error:', message, 'at', source, 'line', lineno);
    return true;
};
</script>
</body>
</html>
