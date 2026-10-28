<?php
session_start();
date_default_timezone_set('Asia/Manila');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/nstp-enrollment-form.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

ignore_user_abort(true);
@set_time_limit(0);

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized access');
}

$currentUser = getCurrentUserRecord($conn);
$role = $currentUser['role'] ?? '';
if (!$currentUser || !in_array($role, ['super_admin', 'coordinator', 'facilitator'], true)) {
    http_response_code(403);
    die('Unauthorized access');
}

$userId = (int) $currentUser['user_id'];
$program = normalizeProgram($currentUser['program'] ?? ($_SESSION['program'] ?? null));
$selectedComponent = $role === 'super_admin'
    ? normalizeProgram($_GET['component'] ?? null)
    : $program;
$folderKey = trim((string) ($_GET['student_folder'] ?? ''));
$selectedFacilitatorId = null;
$selectedSection = '';

if ($folderKey !== '') {
    if ($role === 'facilitator') {
        $selectedFacilitatorId = $userId;
        $selectedSection = $folderKey;
    } elseif (strpos($folderKey, '::') !== false) {
        [$facilitatorPart, $sectionPart] = explode('::', $folderKey, 2);
        $selectedFacilitatorId = (int) $facilitatorPart;
        $selectedSection = trim($sectionPart);
    }

    if (!$selectedFacilitatorId || $selectedSection === '') {
        http_response_code(400);
        die('Invalid student folder.');
    }

    $accessSql = "
        SELECT COUNT(*)
        FROM tbl_admin_sections ads
        INNER JOIN tbl_users u ON u.user_id = ads.user_id
        WHERE ads.user_id = :facilitator_id
          AND ads.course_section = :course_section
          AND u.role = 'facilitator'
    ";
    $accessParams = [
        ':facilitator_id' => $selectedFacilitatorId,
        ':course_section' => $selectedSection,
    ];
    if ($role === 'coordinator') {
        $accessSql .= ' AND u.program = :program';
        $accessParams[':program'] = $program;
    } elseif ($role === 'facilitator') {
        $accessSql .= ' AND u.user_id = :current_user_id';
        $accessParams[':current_user_id'] = $userId;
    }
    $accessStmt = $conn->prepare($accessSql);
    $accessStmt->execute($accessParams);
    if ((int) $accessStmt->fetchColumn() === 0) {
        http_response_code(403);
        die('You do not have access to the selected student folder.');
    }
}

$componentExpression = "
    COALESCE(
        NULLIF(student_user.program, ''),
        NULLIF(r.component, ''),
        CASE
            WHEN UPPER(COALESCE(s.course_section, '')) LIKE '%LTS%' THEN 'LTS'
            WHEN UPPER(COALESCE(s.course_section, '')) LIKE '%CWTS%' THEN 'CWTS'
            WHEN UPPER(COALESCE(s.course_section, '')) LIKE '%ROTC%'
              OR UPPER(COALESCE(s.course_section, '')) LIKE '%ALPHA%'
              OR UPPER(COALESCE(s.course_section, '')) LIKE '%PLATOON%' THEN 'ROTC'
            ELSE NULL
        END,
        CASE WHEN creator.role = 'facilitator' THEN NULLIF(creator.program, '') ELSE NULL END,
        (
            SELECT NULLIF(folder_owner.program, '')
            FROM tbl_admin_sections folder_assignment
            INNER JOIN tbl_users folder_owner ON folder_owner.user_id = folder_assignment.user_id
            WHERE folder_assignment.course_section = s.course_section
              AND folder_owner.role = 'facilitator'
            ORDER BY folder_assignment.assigned_at DESC, folder_assignment.admin_section_id DESC
            LIMIT 1
        )
    )
";

$studentSql = "
    SELECT
        s.tbl_student_id,
        s.student_name,
        COALESCE(r.last_name, '') AS last_name,
        COALESCE(r.extension_name, '') AS extension_name,
        COALESCE(r.first_name, '') AS first_name,
        COALESCE(r.middle_name, '') AS middle_name,
        COALESCE(r.place_of_birth, '') AS place_of_birth,
        COALESCE(DATE_FORMAT(r.date_of_birth, '%Y-%m-%d'), '') AS date_of_birth,
        COALESCE(r.gender, '') AS gender,
        COALESCE(NULLIF(r.email, ''), student_user.email, '') AS email,
        COALESCE(r.contact_number, '') AS contact_number,
        COALESCE(r.house_no, '') AS house_no,
        COALESCE(r.street, '') AS street,
        COALESCE(r.barangay, '') AS barangay,
        COALESCE(r.city_municipality, '') AS city_municipality,
        COALESCE(r.province, '') AS province,
        COALESCE(r.course, '') AS course,
        COALESCE(r.major, '') AS major,
        COALESCE(r.year_section, '') AS year_section,
        COALESCE(NULLIF(r.formal_picture, ''), NULLIF(student_user.profile_picture, ''), '') AS formal_picture,
        COALESCE(DATE_FORMAT(r.created_at, '%Y-%m-%d'), '') AS registration_date,
        {$componentExpression} AS component
    FROM tbl_student s
    LEFT JOIN tbl_users creator ON creator.user_id = s.created_by
    LEFT JOIN tbl_users student_user ON student_user.user_id = s.user_id AND student_user.role = 'student'
    LEFT JOIN tbl_public_student_registrations r ON r.registration_id = (
        SELECT r2.registration_id
        FROM tbl_public_student_registrations r2
        WHERE r2.registrant_role = 'student'
          AND COALESCE(r2.status, 'submitted') <> 'account_deleted'
          AND (
              (s.user_id IS NOT NULL AND r2.user_id = s.user_id)
              OR (NULLIF(s.student_number, '') IS NOT NULL AND r2.student_number = s.student_number)
          )
        ORDER BY r2.registration_id DESC
        LIMIT 1
    )
";
$where = [];
$params = [];

if ($selectedFacilitatorId && $selectedSection !== '') {
    $where[] = 's.created_by = :selected_facilitator_id';
    $where[] = 's.course_section = :selected_section';
    $params[':selected_facilitator_id'] = $selectedFacilitatorId;
    $params[':selected_section'] = $selectedSection;
} elseif ($role === 'facilitator') {
    $where[] = 's.created_by = :current_facilitator_id';
    $params[':current_facilitator_id'] = $userId;
}

if ($selectedComponent) {
    $where[] = "({$componentExpression}) = :selected_component";
    $params[':selected_component'] = $selectedComponent;
}

if ($where) {
    $studentSql .= ' WHERE ' . implode(' AND ', $where);
}
$studentSql .= ' ORDER BY component, r.last_name, r.first_name, s.student_name, s.tbl_student_id';

$studentStmt = $conn->prepare($studentSql);
$studentStmt->execute($params);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
if (!$students) {
    http_response_code(404);
    die('No students were found for the selected filters.');
}

$coordinatorNames = [];
$coordinatorStmt = $conn->query("
    SELECT program, COALESCE(NULLIF(full_name, ''), username) AS coordinator_name
    FROM tbl_users
    WHERE role = 'coordinator' AND program IN ('CWTS', 'LTS', 'ROTC')
    ORDER BY user_id DESC
");
foreach ($coordinatorStmt->fetchAll(PDO::FETCH_ASSOC) as $coordinator) {
    $coordinatorProgram = normalizeProgram($coordinator['program'] ?? null);
    if ($coordinatorProgram && !isset($coordinatorNames[$coordinatorProgram])) {
        $coordinatorNames[$coordinatorProgram] = (string) $coordinator['coordinator_name'];
    }
}

$projectRoot = dirname(__DIR__);
$sealImage = nstpEnrollmentImageDataUri('include/logos/nstp.png', $projectRoot);
$fontCacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tau-nstp-dompdf-fonts';
if (!is_dir($fontCacheDir)) {
    @mkdir($fontCacheDir, 0775, true);
}

$renderPdf = static function (array $batch) use ($coordinatorNames, $sealImage, $projectRoot, $fontCacheDir) {
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Serif');
    $options->set('fontCache', $fontCacheDir);
    $options->set('tempDir', sys_get_temp_dir());
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml(
        nstpEnrollmentRenderHtml($batch, $coordinatorNames, $sealImage, $projectRoot),
        'UTF-8'
    );
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdf = $dompdf->output();
    unset($dompdf);
    gc_collect_cycles();
    return $pdf;
};

$filenameParts = ['nstp-enrollment-forms'];
if ($selectedComponent) {
    $filenameParts[] = strtolower($selectedComponent);
}
if ($selectedSection !== '') {
    $filenameParts[] = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $selectedSection));
}
$filename = trim(implode('-', $filenameParts), '-') . '.pdf';
$batches = nstpEnrollmentStudentBatches($students, 20);

if (count($batches) === 1) {
    $pdf = $renderPdf($batches[0]);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$zipPath = tempnam(sys_get_temp_dir(), 'nstp-enrollment-');
if ($zipPath === false) {
    http_response_code(500);
    die('Unable to prepare the enrolment form download.');
}
@unlink($zipPath);
$zipPath .= '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    @unlink($zipPath);
    http_response_code(500);
    die('Unable to prepare the enrolment form ZIP file.');
}

try {
    $partTotal = count($batches);
    foreach ($batches as $batchIndex => $batch) {
        $partNumber = $batchIndex + 1;
        $partName = pathinfo($filename, PATHINFO_FILENAME)
            . '-part-' . str_pad((string) $partNumber, 2, '0', STR_PAD_LEFT)
            . '-of-' . str_pad((string) $partTotal, 2, '0', STR_PAD_LEFT)
            . '.pdf';
        if (!$zip->addFromString($partName, $renderPdf($batch))) {
            throw new RuntimeException('Unable to add a PDF batch to the ZIP file.');
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException('Unable to finish the enrolment form ZIP file.');
    }

    $zipFilename = pathinfo($filename, PATHINFO_FILENAME) . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    readfile($zipPath);
} catch (Throwable $error) {
    try {
        $zip->close();
    } catch (Throwable $closeError) {
        // The archive may already be closed after a finalization failure.
    }
    @unlink($zipPath);
    error_log('NSTP enrolment form batch error: ' . $error->getMessage());
    http_response_code(500);
    die('Unable to generate all enrolment form batches. Please try a smaller folder selection.');
}

@unlink($zipPath);
exit;
