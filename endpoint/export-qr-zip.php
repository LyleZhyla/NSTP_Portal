<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit(json_encode(['error' => 'Unauthorized']));
}

include('../conn/conn.php');
require_once '../include/user-permissions.php';

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'facilitator';
$currentUser = getCurrentUserRecord($conn);

// Get export parameters
$section = isset($_GET['section']) ? $_GET['section'] : '';
$admin_id = isset($_GET['admin_id']) ? $_GET['admin_id'] : $user_id;

// For super admin, they can export any admin's section
if ($user_role === 'super_admin' && $admin_id != $user_id) {
    $target_user_id = $admin_id;
} elseif ($user_role === 'coordinator') {
    $target_user_id = $admin_id;
    $target_stmt = $conn->prepare("SELECT user_id, role, program FROM tbl_users WHERE user_id = ?");
    $target_stmt->execute([$target_user_id]);
    $target_user = $target_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentUser || !$target_user || !canManageUserRecord($currentUser, $target_user)) {
        header("HTTP/1.1 403 Forbidden");
        exit(json_encode(['error' => 'You do not have access to this facilitator']));
    }
} else {
    $target_user_id = $user_id;
}

// Validate access (similar to above)
if (in_array($user_role, ['coordinator', 'facilitator'], true) && !empty($section)) {
    $check_stmt = $conn->prepare("
        SELECT COUNT(*) FROM tbl_admin_sections 
        WHERE user_id = ? AND course_section = ?
    ");
    $check_stmt->execute([$target_user_id, $section]);
    $has_access = $check_stmt->fetchColumn() > 0;
    
    if (!$has_access) {
        $legacy_stmt = $conn->prepare("
            SELECT assigned_section FROM tbl_users 
            WHERE user_id = ? AND assigned_section = ?
        ");
        $legacy_stmt->execute([$target_user_id, $section]);
        $has_access = $legacy_stmt->fetchColumn() ? true : false;
    }
    
    if (!$has_access && !empty($section)) {
        header("HTTP/1.1 403 Forbidden");
        exit(json_encode(['error' => 'You do not have access to this section']));
    }
}

// Get students
if (!empty($section)) {
    $stmt = $conn->prepare("
        SELECT student_name, generated_code, original_section, course_section
        FROM tbl_student 
        WHERE created_by = ? AND course_section = ?
        ORDER BY student_name ASC
    ");
    $stmt->execute([$target_user_id, $section]);
} else {
    $stmt = $conn->prepare("
        SELECT student_name, generated_code, original_section, course_section
        FROM tbl_student 
        WHERE created_by = ?
        ORDER BY course_section ASC, student_name ASC
    ");
    $stmt->execute([$target_user_id]);
}

$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($students)) {
    header("HTTP/1.1 404 Not Found");
    exit(json_encode(['error' => 'No students found for export']));
}

// Create ZIP file
$zip = new ZipArchive();
$filename = tempnam(sys_get_temp_dir(), 'qr_export_');
$zip_filename = "QR_Images_" . (!empty($section) ? $section : "All_Sections") . "_" . date('Y-m-d') . ".zip";

if ($zip->open($filename, ZipArchive::CREATE) !== TRUE) {
    exit("Cannot create ZIP file");
}

// Create a text file with student info
$info_text = "QR CODE EXPORT\n";
$info_text .= "Exported on: " . date('Y-m-d H:i:s') . "\n";
$info_text .= "Section: " . (!empty($section) ? $section : "All Sections") . "\n";
$info_text .= "Total Students: " . count($students) . "\n\n";
$info_text .= "Student Name | QR Code | Original Section | Admin Folder\n";
$info_text .= str_repeat("-", 80) . "\n";

$counter = 1;
foreach ($students as $student) {
    $qrCodeData = $student['generated_code'];
    $studentName = preg_replace('/[^a-zA-Z0-9]/', '_', $student['student_name']);
    $qrImageUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($qrCodeData);
    
    // Download QR image
    $imageData = file_get_contents($qrImageUrl);
    if ($imageData) {
        $imageFileName = $counter . "_" . $studentName . "_QR.png";
        $zip->addFromString($imageFileName, $imageData);
    }
    
    $info_text .= $student['student_name'] . " | " . $qrCodeData . " | " . 
                  ($student['original_section'] ?? 'N/A') . " | " . $student['course_section'] . "\n";
    
    $counter++;
}

// Add info text file
$zip->addFromString("student_info.txt", $info_text);

// Add a simple HTML file to view all QR codes
$html_content = '<!DOCTYPE html>
<html>
<head>
    <title>QR Codes Export</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .student-card { border: 1px solid #ccc; padding: 15px; margin: 10px 0; border-radius: 5px; }
        .student-name { font-size: 18px; font-weight: bold; }
        .qr-code { margin: 10px 0; }
        .section-info { color: #666; }
    </style>
</head>
<body>
    <h1>QR Codes Export</h1>
    <p>Exported on: ' . date('Y-m-d H:i:s') . '</p>
    <p>Section: ' . (!empty($section) ? $section : "All Sections") . '</p>
    <hr>';

$counter = 1;
foreach ($students as $student) {
    $qrCodeData = $student['generated_code'];
    $html_content .= '
    <div class="student-card">
        <div class="student-name">' . $counter++ . '. ' . htmlspecialchars($student['student_name']) . '</div>
        <div class="qr-code">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrCodeData) . '" 
                 alt="QR Code for ' . htmlspecialchars($student['student_name']) . '">
        </div>
        <div class="section-info">
            <strong>QR Code:</strong> ' . htmlspecialchars($qrCodeData) . '<br>
            <strong>Original Section:</strong> ' . htmlspecialchars($student['original_section'] ?? 'N/A') . '<br>
            <strong>Admin Folder:</strong> ' . htmlspecialchars($student['course_section']) . '
        </div>
    </div>';
}

$html_content .= '
</body>
</html>';

$zip->addFromString("qr_codes_view.html", $html_content);

$zip->close();

// Send ZIP file to browser
header("Content-Type: application/zip");
header("Content-Disposition: attachment; filename=\"$zip_filename\"");
header("Content-Length: " . filesize($filename));
header("Pragma: no-cache");
header("Expires: 0");

readfile($filename);
unlink($filename);
exit;
?>
