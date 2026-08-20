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

        $normalizedValues = [
            'college' => trim((string) ($registration['college'] ?? '')),
            'course' => trim((string) ($registration['course'] ?? '')),
            'major' => trim((string) ($registration['major'] ?? '')),
            'year_section' => trim((string) ($registration['year_section'] ?? '')),
        ];
        if ($canonical['resolved']) {
            foreach (['college', 'course', 'major', 'year_section'] as $field) {
                $normalizedValues[$field] = $canonical[$field];
            }
        } else {
            // Correct every field that can be resolved safely even when a
            // different field in the same record still needs manual review.
            $courseMatch = canonicalAcademicCourse($registration['course'] ?? '');
            $collegeMatch = $courseMatch['college'] ?? canonicalAcademicCollege($registration['college'] ?? '');
            if ($collegeMatch) {
                $normalizedValues['college'] = $collegeMatch;
            }
            if ($courseMatch) {
                $normalizedValues['course'] = $courseMatch['course'];
                $courseItem = findCollegeCourse($courseMatch['college'], $courseMatch['course']);
                if ($courseItem && empty($courseItem['majors'])) {
                    $normalizedValues['major'] = 'N/A';
                } elseif ($courseItem) {
                    $majorMatch = academicBestCanonicalMatch(
                        $registration['major'] ?? '',
                        array_merge($courseItem['majors'], ['N/A']),
                        academicMajorAliases()
                    );
                    if ($majorMatch) {
                        $normalizedValues['major'] = $majorMatch;
                    }
                }
            }
            $yearSectionMatch = normalizeAcademicYearSection($registration['year_section'] ?? '');
            if ($yearSectionMatch) {
                $normalizedValues['year_section'] = $yearSectionMatch;
            }
            $unresolved++;
        }

        $hasChanges = false;
        foreach (['college', 'course', 'major', 'year_section'] as $field) {
            if (trim((string) ($registration[$field] ?? '')) !== (string) $normalizedValues[$field]) {
                $hasChanges = true;
                break;
            }
        }

        if ($hasChanges) {
            $updateRegistrationStmt->execute([
                $normalizedValues['college'],
                $normalizedValues['course'],
                $normalizedValues['major'],
                $normalizedValues['year_section'],
                (int) $registration['registration_id'],
            ]);
            $corrected++;
        } else {
            $unchanged++;
        }

        $canonicalYearSection = normalizeAcademicYearSection($normalizedValues['year_section']);
        $canonicalCourseMatch = canonicalAcademicCourse($normalizedValues['course']);
        if (!$canonicalYearSection || !$canonicalCourseMatch) {
            continue;
        }
        $originalSection = trim($canonicalCourseMatch['course'] . ' ' . $canonicalYearSection);
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
