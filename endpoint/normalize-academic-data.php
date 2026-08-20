<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/college-courses.php';
require_once '../include/automatic-sectioning.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Only the super admin can normalize academic records.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT registration_id, user_id, student_number, college, course, major, year_section
        FROM tbl_public_student_registrations
        WHERE registrant_role = 'student'
        ORDER BY registration_id ASC
    ");
    $stmt->execute();
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updateRegistrationStmt = $conn->prepare("
        UPDATE tbl_public_student_registrations
        SET college = ?, course = ?, major = ?, year_section = ?
        WHERE registration_id = ?
    ");
    $syncByStudentNumberStmt = $conn->prepare("
        UPDATE tbl_student
        SET original_section = ?
        WHERE student_number = ?
    ");
    $syncByUserStmt = $conn->prepare("
        UPDATE tbl_student
        SET original_section = ?
        WHERE user_id = ?
    ");

    $corrected = 0;
    $unchanged = 0;
    $unresolved = 0;

    ensureSectionFoldersTable($conn);
    $conn->beginTransaction();
    foreach ($registrations as $registration) {
        $canonical = canonicalizeAcademicData(
            $registration['college'] ?? '',
            $registration['course'] ?? '',
            $registration['major'] ?? 'N/A',
            $registration['year_section'] ?? ''
        );
        if (!$canonical['resolved']) {
            $unresolved++;
            continue;
        }

        $hasChanges = false;
        foreach (['college', 'course', 'major', 'year_section'] as $field) {
            if (trim((string) ($registration[$field] ?? '')) !== (string) $canonical[$field]) {
                $hasChanges = true;
                break;
            }
        }

        if ($hasChanges) {
            $updateRegistrationStmt->execute([
                $canonical['college'],
                $canonical['course'],
                $canonical['major'],
                $canonical['year_section'],
                (int) $registration['registration_id'],
            ]);
            $corrected++;
        } else {
            $unchanged++;
        }

        $originalSection = trim($canonical['course'] . ' ' . $canonical['year_section']);
        $studentNumber = trim((string) ($registration['student_number'] ?? ''));
        $registrationUserId = (int) ($registration['user_id'] ?? 0);
        if ($studentNumber !== '') {
            $syncByStudentNumberStmt->execute([$originalSection, $studentNumber]);
        }
        if ($registrationUserId > 0) {
            $syncByUserStmt->execute([$originalSection, $registrationUserId]);
        }
    }

    $resectioned = $corrected > 0 ? rebuildAutoSectionFolders($conn) : 0;
    if ($conn->inTransaction()) {
        $conn->commit();
    }

    markSharedDataChanged($conn);
    logSystemEvent(
        $conn,
        'academic_data_normalized',
        "Normalized {$corrected} registration record(s); {$unresolved} unresolved; {$resectioned} student section assignment(s) recalculated."
    );

    echo json_encode([
        'success' => true,
        'message' => "Academic names normalized. {$corrected} corrected, {$unchanged} already valid, {$unresolved} unresolved, and {$resectioned} section assignment(s) recalculated.",
        'corrected' => $corrected,
        'unchanged' => $unchanged,
        'unresolved' => $unresolved,
        'resectioned' => $resectioned,
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
