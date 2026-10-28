<?php

require_once __DIR__ . '/../include/nstp-enrollment-form.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function nstpFormAssert($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$sampleStudents = [
    [
        'student_name' => 'Ana M. Santos',
        'first_name' => 'Ana',
        'middle_name' => 'M.',
        'last_name' => 'Santos',
        'extension_name' => '',
        'component' => 'CWTS',
        'gender' => 'Female',
        'course' => 'BS Information Technology',
        'major' => 'Web Development',
        'year_section' => '1-A',
        'date_of_birth' => '2007-04-03',
        'place_of_birth' => 'Tarlac City',
        'email' => 'ana@example.test',
        'contact_number' => '09123456789',
        'house_no' => '12',
        'street' => 'Rizal Street',
        'barangay' => 'San Isidro',
        'city_municipality' => 'Camiling',
        'province' => 'Tarlac',
        'registration_date' => '2026-10-28',
        'formal_picture' => '',
    ],
    [
        'student_name' => 'Ben D. Cruz',
        'first_name' => 'Ben',
        'middle_name' => 'D.',
        'last_name' => 'Cruz',
        'extension_name' => 'Jr.',
        'component' => 'ROTC',
        'gender' => 'Male',
        'course' => 'BS Agriculture',
        'major' => '',
        'year_section' => '1-B',
        'date_of_birth' => '2006-11-20',
        'place_of_birth' => 'Camiling, Tarlac',
        'email' => 'ben@example.test',
        'contact_number' => '09987654321',
        'house_no' => '8',
        'street' => 'Mabini Street',
        'barangay' => 'Poblacion',
        'city_municipality' => 'Camiling',
        'province' => 'Tarlac',
        'registration_date' => '2026-10-28',
        'formal_picture' => '',
    ],
];

$coordinators = [
    'CWTS' => 'Maria CWTS Coordinator',
    'LTS' => 'Lina LTS Coordinator',
    'ROTC' => 'Ramon ROTC Coordinator',
];
$projectRoot = dirname(__DIR__);
$seal = nstpEnrollmentImageDataUri('include/logos/nstp.png', $projectRoot);
$html = nstpEnrollmentRenderHtml($sampleStudents, $coordinators, $seal, $projectRoot);

nstpFormAssert(substr_count($html, 'class="student-form"') === 2, 'Each student must have exactly one form page.');
nstpFormAssert(strpos($html, 'Maria CWTS Coordinator') !== false, 'CWTS coordinator was not matched.');
nstpFormAssert(strpos($html, 'Ramon ROTC Coordinator') !== false, 'ROTC coordinator was not matched.');
nstpFormAssert(substr_count($html, '<span class="check-box">X</span>') === 2, 'Each student must have exactly one checked component.');
nstpFormAssert(strpos($html, 'Ana M. Santos') !== false && strpos($html, 'Ben D. Cruz Jr.') !== false, 'Student printed names were not rendered.');

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$fontCacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tau-nstp-dompdf-test-fonts';
if (!is_dir($fontCacheDir)) {
    @mkdir($fontCacheDir, 0775, true);
}
$options->set('fontCache', $fontCacheDir);
$options->set('tempDir', sys_get_temp_dir());
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$pageCount = $dompdf->getCanvas()->get_page_count();
nstpFormAssert($pageCount === count($sampleStudents), 'The PDF must contain exactly one page per student.');
$pdf = $dompdf->output();

$outputPath = trim((string) getenv('NSTP_FORM_TEST_OUTPUT'));
if ($outputPath !== '') {
    if (file_put_contents($outputPath, $pdf) === false) {
        throw new RuntimeException('Unable to write the requested PDF test output.');
    }
}

echo "NSTP enrollment form tests passed.\n";
