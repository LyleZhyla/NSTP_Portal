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

function firstExistingFile(array $paths) {
    foreach ($paths as $path) {
        if ($path && is_file($path) && is_readable($path)) {
            return $path;
        }
    }

    return null;
}

function resolveQrCardFonts() {
    $regular = firstExistingFile([
        __DIR__ . '/../include/fonts/DejaVuSans.ttf',
        __DIR__ . '/../include/fonts/arial.ttf',
        'C:/Windows/Fonts/arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/liberation/LiberationSans-Regular.ttf',
    ]);

    $bold = firstExistingFile([
        __DIR__ . '/../include/fonts/DejaVuSans-Bold.ttf',
        __DIR__ . '/../include/fonts/arialbd.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/liberation/LiberationSans-Bold.ttf',
    ]);

    return [
        'regular' => $regular,
        'bold' => $bold ?: $regular,
    ];
}

function gdFontForSize($size) {
    if ($size >= 18) {
        return 5;
    }
    if ($size >= 14) {
        return 4;
    }
    if ($size >= 11) {
        return 3;
    }

    return 2;
}

function textWidth($font, $size, $text) {
    if ($font) {
        $box = imagettfbbox($size, 0, $font, $text);
        return $box[2] - $box[0];
    }

    $gdFont = gdFontForSize($size);
    return imagefontwidth($gdFont) * strlen((string) $text);
}

function drawText($image, $size, $x, $y, $color, $font, $text) {
    if ($font) {
        imagettftext($image, $size, 0, $x, $y, $color, $font, $text);
        return;
    }

    $gdFont = gdFontForSize($size);
    imagestring($image, $gdFont, $x, max(0, $y - imagefontheight($gdFont)), $text, $color);
}

function fitText($image, $font, $size, $x, $y, $text, $color, $maxWidth) {
    $words = preg_split('/\s+/', (string) $text);
    $line = '';
    foreach ($words as $word) {
        $test = trim($line . ' ' . $word);
        if (textWidth($font, $size, $test) > $maxWidth && $line !== '') {
            drawText($image, $size, $x, $y, $color, $font, $line);
            $line = $word;
            $y += $size + 10;
        } else {
            $line = $test;
        }
    }

    if ($line !== '') {
        drawText($image, $size, $x, $y, $color, $font, $line);
    }

    return $y;
}

$stmt = $conn->prepare("
    SELECT s.*, r.course, r.year_section, r.formal_picture, r.contact_number,
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
$ink = imagecolorallocate($canvas, 20, 38, 49);
$muted = imagecolorallocate($canvas, 77, 96, 108);
$green = imagecolorallocate($canvas, 16, 106, 79);
$deepGreen = imagecolorallocate($canvas, 8, 72, 58);
$gold = imagecolorallocate($canvas, 246, 185, 59);
$line = imagecolorallocate($canvas, 204, 220, 213);
$soft = imagecolorallocate($canvas, 238, 248, 244);
$paleGold = imagecolorallocate($canvas, 255, 248, 226);

imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
imagefilledrectangle($canvas, 0, 0, $width, 112, $deepGreen);
imagefilledrectangle($canvas, 0, 92, $width, 112, $gold);
imagefilledrectangle($canvas, 34, 132, 866, 520, $soft);
imagerectangle($canvas, 34, 132, 866, 520, $line);
imagefilledrectangle($canvas, 54, 330, 554, 496, $white);
imagerectangle($canvas, 54, 330, 554, 496, $line);
imagefilledrectangle($canvas, 584, 132, 866, 520, $paleGold);
imagerectangle($canvas, 584, 132, 866, 520, $line);

$fonts = resolveQrCardFonts();
$font = $fonts['regular'];
$boldFont = $fonts['bold'];

$nstpLogo = loadImageFromPath('include/logos/nstp.png');
if ($nstpLogo) {
    imagecopyresampled($canvas, $nstpLogo, 38, 18, 0, 0, 72, 72, imagesx($nstpLogo), imagesy($nstpLogo));
}

drawText($canvas, 24, 126, 52, $white, $boldFont, 'TAU NSTP ID');
drawText($canvas, 13, 128, 78, $white, $font, 'National Service Training Program');

imagecopyresampled($canvas, $qrImage, 606, 176, 0, 0, 238, 238, imagesx($qrImage), imagesy($qrImage));
imagerectangle($canvas, 606, 176, 844, 414, $line);
drawText($canvas, 11, 626, 448, $muted, $font, 'QR Code: ' . $student['generated_code']);

$picture = loadImageFromPath($student['formal_picture'] ?? null);
if ($picture) {
    imagecopyresampled($canvas, $picture, 70, 156, 0, 0, 142, 142, imagesx($picture), imagesy($picture));
    imagerectangle($canvas, 70, 156, 212, 298, $line);
} else {
    imagefilledrectangle($canvas, 70, 156, 212, 298, $white);
    imagerectangle($canvas, 70, 156, 212, 298, $line);
    drawText($canvas, 46, 112, 244, $green, $boldFont, strtoupper(substr($student['student_name'], 0, 1)));
}

$x = 236;
$labelSize = 13;
$valueSize = 15;
$maxTextWidth = 300;
$fields = [
    'Full Name:' => $student['student_name'] ?: 'N/A',
    'Course/Yr/Section:' => 'BSGE - 1A',
    'Contact #:' => $student['contact_number'] ?: 'N/A',
];
$y = 172;
foreach ($fields as $label => $value) {
    drawText($canvas, $labelSize, $x, $y, $green, $boldFont, $label);
    $y = fitText($canvas, $boldFont, $valueSize, $x, $y + 26, $value, $ink, $maxTextWidth) + 34;
}

drawText($canvas, 16, 74, 366, $green, $boldFont, 'Emergency Contact Details');
imageline($canvas, 74, 380, 534, 380, $gold);

$emergencyFields = [
    'Name:' => $student['emergency_name'] ?: 'N/A',
    'Relationship:' => $student['emergency_relationship'] ?: 'N/A',
    'Contact #:' => $student['emergency_contact_number'] ?: 'N/A',
];
$y = 410;
foreach ($emergencyFields as $label => $value) {
    drawText($canvas, 11, 74, $y, $muted, $boldFont, $label);
    $valueY = fitText($canvas, $font, 12, 188, $y, $value, $ink, 330);
    $y = max($y + 30, $valueY + 18);
}

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
if ($nstpLogo) {
    imagedestroy($nstpLogo);
}
imagedestroy($canvas);
