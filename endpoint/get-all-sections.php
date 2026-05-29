<?php
session_start();
header('Content-Type: application/json');
require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessAdminManagement($currentUser['role'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

try {
    // Get all unique sections from students
    $stmt = $conn->prepare("
        SELECT DISTINCT course_section 
        FROM tbl_student 
        WHERE course_section IS NOT NULL AND course_section != ''
        ORDER BY course_section
    ");
    $stmt->execute();
    $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Also get sections from admin_sections
    $stmt2 = $conn->prepare("
        SELECT DISTINCT course_section 
        FROM tbl_admin_sections 
        WHERE course_section IS NOT NULL AND course_section != ''
    ");
    $stmt2->execute();
    $adminSections = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    
    // Merge and get unique values
    $allSections = array_unique(array_merge($sections, $adminSections));
    if ($currentUser['role'] === 'coordinator') {
        $program = normalizeProgram($currentUser['program'] ?? null);
        $allSections = array_values(array_filter($allSections, function ($section) use ($program) {
            return inferProgramFromText($section) === $program;
        }));
    }
    sort($allSections);

    echo json_encode([
        'success' => true,
        'sections' => $allSections
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
