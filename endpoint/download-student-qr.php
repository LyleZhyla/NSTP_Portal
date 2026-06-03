<?php
session_start();

require_once '../conn/conn.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'student') {
    http_response_code(401);
    exit('Unauthorized');
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'png')));
if (!in_array($format, ['png', 'jpg', 'jpeg'], true)) {
    $format = 'png';
}

if (!extension_loaded('gd')) {
    http_response_code(500);
    exit('QR download requires the GD extension.');
}

function loadImageFromPath($path) {
    if (!$path || !is_file('../' . $path)) {
        return null;
    }

    $fullPath = '../' . $path;
    $info = @getimagesize($fullPath);
    if (!$info) {
        return null;
    }

    switch ($info[2]) {
        case IMAGETYPE_JPEG:
            return @imagecreatefromjpeg($fullPath);
        case IMAGETYPE_PNG:
            return @imagecreatefrompng($fullPath);
        case IMAGETYPE_WEBP:
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fullPath) : null;
        default:
            return null;
    }
}

function fitText($image, $font, $size, $x, $y, $text, $color, $maxWidth) {
    $words = preg_split('/\s+/', (string) $text);
    $line = '';
    foreach ($words as $word) {
        $test = trim($line . ' ' . $word);
        $box = imagettfbbox($size, 0, $font, $test);
        if (($box[2] - $box[0]) > $maxWidth && $line !== '') {
            imagettftext($image, $size, 0, $x, $y, $color, $font, $line);
            $line = $word;
            $y += $size + 10;
        } else {
            $line = $test;
        }
    }

    if ($line !== '') {
        imagettftext($image, $size, 0, $x, $y, $color, $font, $line);
    }

    return $y;
}

$stmt = $conn->prepare("
    SELECT s.*, r.course, r.year_section, r.formal_picture,
           r.emergency_name, r.emergency_relationship, r.emergency_contact_number
    FROM tbl_student s
    LEFT JOIN tbl_public_student_registrations r ON r.student_number = s.student_number
    WHERE s.user_id = ?
    ORDER BY r.created_at DESC
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    http_response_code(404);
    exit('Student QR record not found.');
}

$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=420x420&data=' . urlencode($student['generated_code']);
$qrBytes = @file_get_contents($qrUrl);
$qrImage = $qrBytes ? @imagecreatefromstring($qrBytes) : null;
if (!$qrImage) {
    http_response_code(500);
    exit('Unable to generate QR image.');
}

$width = 900;
$height = 560;
$canvas = imagecreatetruecolor($width, $height);
$white = imagecolorallocate($canvas, 255, 255, 255);
$ink = imagecolorallocate($canvas, 31, 41, 55);
$muted = imagecolorallocate($canvas, 99, 116, 139);
$green = imagecolorallocate($canvas, 47, 111, 126);
$line = imagecolorallocate($canvas, 220, 231, 235);
$soft = imagecolorallocate($canvas, 240, 247, 249);

imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
imagefilledrectangle($canvas, 0, 0, $width, 92, $green);
imagefilledrectangle($canvas, 34, 122, 866, 520, $soft);
imagerectangle($canvas, 34, 122, 866, 520, $line);

$font = 'C:\Windows\Fonts\arial.ttf';
$boldFont = 'C:\Windows\Fonts\arialbd.ttf';
if (!is_file($font)) {
    http_response_code(500);
    exit('A TrueType font is required to render the QR card.');
}
if (!is_file($boldFont)) {
    $boldFont = $font;
}

imagettftext($canvas, 22, 0, 38, 56, $white, $boldFont, 'TAU NSTP ID');
imagettftext($canvas, 12, 0, 40, 78, $white, $font, 'Scan this code for attendance.');

imagecopyresampled($canvas, $qrImage, 430, 154, 0, 0, 330, 330, imagesx($qrImage), imagesy($qrImage));
imagerectangle($canvas, 430, 154, 760, 484, $line);

$picture = loadImageFromPath($student['formal_picture'] ?? null);
if ($picture) {
    imagecopyresampled($canvas, $picture, 70, 156, 0, 0, 130, 130, imagesx($picture), imagesy($picture));
    imagerectangle($canvas, 70, 156, 200, 286, $line);
} else {
    imagefilledrectangle($canvas, 70, 156, 200, 286, $white);
    imagerectangle($canvas, 70, 156, 200, 286, $line);
    imagettftext($canvas, 42, 0, 104, 238, $green, $boldFont, strtoupper(substr($student['student_name'], 0, 1)));
}

$y = 176;
$x = 226;
$maxTextWidth = 180;
imagettftext($canvas, 11, 0, $x, $y, $muted, $boldFont, 'Name');
$y = fitText($canvas, $boldFont, 17, $x, $y + 28, $student['student_name'], $ink, $maxTextWidth) + 26;

$details = [
    'Course' => $student['course'] ?: 'N/A',
    'Year' => $student['year_section'] ?: ($student['original_section'] ?: 'N/A'),
    'Section' => $student['course_section'] ?: 'Unassigned',
    'Emergency Contact' => $student['emergency_name'] ?: 'N/A',
    'Relationship' => $student['emergency_relationship'] ?: 'N/A',
    'Contact Number' => $student['emergency_contact_number'] ?: 'N/A',
];

foreach ($details as $label => $value) {
    imagettftext($canvas, 10, 0, $x, $y, $muted, $boldFont, $label);
    $y = fitText($canvas, $font, 13, $x, $y + 22, $value, $ink, $maxTextWidth) + 22;
}

imagettftext($canvas, 11, 0, 70, 504, $muted, $font, 'QR Code: ' . $student['generated_code']);

$safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $student['student_name']);
if ($format === 'jpg' || $format === 'jpeg') {
    header('Content-Type: image/jpeg');
    header('Content-Disposition: attachment; filename="' . $safeName . '_NSTP_ID.jpg"');
    imagejpeg($canvas, null, 92);
} else {
    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="' . $safeName . '_NSTP_ID.png"');
    imagepng($canvas);
}

imagedestroy($qrImage);
if ($picture) {
    imagedestroy($picture);
}
imagedestroy($canvas);
