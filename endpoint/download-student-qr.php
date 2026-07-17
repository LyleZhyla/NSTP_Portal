<?php
session_start();

require_once '../conn/conn.php';
require_once '../include/student-account-automation.php';

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$sessionRole = strtolower(trim((string) ($_SESSION['role'] ?? '')));

if ($sessionUserId > 0 && $sessionRole !== 'student') {
    $roleStmt = $conn->prepare("SELECT role FROM tbl_users WHERE user_id = ? LIMIT 1");
    $roleStmt->execute([$sessionUserId]);
    $databaseRole = strtolower(trim((string) $roleStmt->fetchColumn()));

    if ($databaseRole !== '') {
        $_SESSION['role'] = $databaseRole;
        $sessionRole = $databaseRole;
    }
}

if ($sessionUserId <= 0 || $sessionRole !== 'student') {
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

function resolveLocalImagePath($path) {
    $path = trim((string) $path);
    if ($path === '' || strpos($path, 'data:') === 0) {
        return '';
    }

    $path = preg_replace('/[?#].*$/', '', $path);
    if (preg_match('/^(https?:)?\/\//i', $path)) {
        $urlPath = parse_url($path, PHP_URL_PATH);
        $path = is_string($urlPath) ? urldecode($urlPath) : '';
    }

    $path = str_replace('\\', '/', trim($path));
    $projectRoot = dirname(__DIR__);
    $candidates = [];

    if ($path !== '' && preg_match('/^[A-Za-z]:\//', $path)) {
        $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $path);
    } elseif ($path !== '' && strpos($path, '/') === 0) {
        $candidates[] = str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    $relativePaths = [];
    $relativePaths[] = ltrim($path, '/');

    foreach (['uploads/', 'include/'] as $knownPrefix) {
        $position = strpos($path, $knownPrefix);
        if ($position !== false) {
            $relativePaths[] = substr($path, $position);
        }
    }

    foreach (array_unique(array_filter($relativePaths)) as $relativePath) {
        $normalizedPath = str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/'));
        $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . $normalizedPath;
        $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $normalizedPath;
    }

    foreach (array_unique($candidates) as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function loadImageFromPath($path) {
    $fullPath = resolveLocalImagePath($path);
    if ($fullPath === '') {
        return null;
    }

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
        case IMAGETYPE_GIF:
            return @imagecreatefromgif($fullPath);
        default:
            return null;
    }
}

function loadFirstAvailableImage(array $paths) {
    foreach ($paths as $path) {
        $image = loadImageFromPath($path);
        if ($image) {
            return $image;
        }
    }

    return null;
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

/**
 * GD's built-in bitmap fonts expect a single-byte Windows character set, not
 * UTF-8. Convert only for that fallback path so characters such as Ñ/ñ are
 * rendered correctly when no TrueType font is installed on the server.
 */
function gdBitmapText($text) {
    $text = (string) $text;

    if (function_exists('iconv') && preg_match('//u', $text)) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($converted !== false) {
            return $converted;
        }
    }

    return $text;
}

function qrCardFirstCharacter($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        $character = mb_substr($text, 0, 1, 'UTF-8');
        return function_exists('mb_strtoupper')
            ? mb_strtoupper($character, 'UTF-8')
            : strtoupper($character);
    }

    if (preg_match('/^./us', $text, $match)) {
        return strtr(strtoupper($match[0]), ['ñ' => 'Ñ']);
    }

    return strtoupper(substr($text, 0, 1));
}

function textWidth($font, $size, $text) {
    if ($font) {
        $box = imagettfbbox($size, 0, $font, $text);
        return $box[2] - $box[0];
    }

    $gdFont = gdFontForSize($size);
    return imagefontwidth($gdFont) * strlen(gdBitmapText($text));
}

function drawText($image, $size, $x, $y, $color, $font, $text) {
    if ($font) {
        imagettftext($image, $size, 0, $x, $y, $color, $font, $text);
        return;
    }

    $gdFont = gdFontForSize($size);
    imagestring($image, $gdFont, $x, max(0, $y - imagefontheight($gdFont)), gdBitmapText($text), $color);
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

function qrCardCleanValue($value) {
    $value = trim((string) $value);
    return $value === '' || strtoupper($value) === 'N/A' ? '' : $value;
}

function qrCardCourseMajorSection(array $student) {
    $parts = array_filter([
        qrCardCleanValue($student['course'] ?? ''),
        qrCardCleanValue($student['major'] ?? ''),
        qrCardCleanValue($student['year_section'] ?? ''),
    ]);

    if (!empty($parts)) {
        return implode(' - ', $parts);
    }

    return qrCardCleanValue($student['original_section'] ?? '') ?: 'N/A';
}

function qrCardStudentDisplayName(array $student) {
    // Keep the downloaded ID consistent with the on-screen ID. This field is
    // synchronized after approved profile and registration name edits.
    $studentName = qrCardCleanValue($student['student_name'] ?? '');
    if ($studentName !== '') {
        return $studentName;
    }

    $nameParts = [
        qrCardCleanValue($student['first_name'] ?? ''),
        qrCardCleanValue($student['middle_name'] ?? ''),
        qrCardCleanValue($student['last_name'] ?? ''),
        qrCardCleanValue($student['extension_name'] ?? ''),
    ];

    if (strtoupper((string) ($student['middle_name'] ?? '')) === 'N/A') {
        $nameParts[1] = '';
    }
    if (strtoupper((string) ($student['extension_name'] ?? '')) === 'N/A') {
        $nameParts[3] = '';
    }

    $registrationName = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($nameParts))));
    return $registrationName !== '' ? $registrationName : 'Student';
}

function studentPicturePaths(array $student) {
    $formalPicture = trim((string) ($student['formal_picture'] ?? ''));
    $profilePicture = trim((string) ($student['profile_picture'] ?? ''));
    $paths = [];

    if ($profilePicture !== '' && str_replace('\\', '/', $profilePicture) !== 'include/logo.png') {
        $paths[] = $profilePicture;
    }

    if ($formalPicture !== '' && str_replace('\\', '/', $formalPicture) !== 'include/logo.png') {
        $paths[] = $formalPicture;
    }

    if ($profilePicture !== '') {
        $paths[] = $profilePicture;
    }

    if ($formalPicture !== '') {
        $paths[] = $formalPicture;
    }

    return array_values(array_unique($paths));
}

$stmt = $conn->prepare("
    SELECT s.*, u.profile_picture, r.last_name, r.extension_name, r.first_name, r.middle_name,
           r.course, r.major, r.year_section, r.formal_picture, r.contact_number,
           r.emergency_name, r.emergency_relationship, r.emergency_contact_number
    FROM tbl_student s
    LEFT JOIN tbl_users u ON u.user_id = s.user_id
    LEFT JOIN tbl_public_student_registrations r ON r.registration_id = (
        SELECT r2.registration_id
        FROM tbl_public_student_registrations r2
        WHERE r2.user_id = s.user_id
           OR (s.student_number IS NOT NULL AND s.student_number <> '' AND r2.student_number = s.student_number)
        ORDER BY r2.created_at DESC
        LIMIT 1
    )
    WHERE s.user_id = ?
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    $userStmt = $conn->prepare("SELECT username FROM tbl_users WHERE user_id = ? AND role = 'student' LIMIT 1");
    $userStmt->execute([$_SESSION['user_id']]);
    $studentNumber = preg_replace('/\D/', '', (string) $userStmt->fetchColumn());

    if (preg_match('/^\d{10}$/', $studentNumber)) {
        ensureStudentQrRecordForAccount($conn, $studentNumber, (int) $_SESSION['user_id']);
        $stmt->execute([$_SESSION['user_id']]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$student) {
        http_response_code(404);
        exit('Student QR record not found.');
    }
}

$displayStudentName = qrCardStudentDisplayName($student);

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

$picture = loadFirstAvailableImage(studentPicturePaths($student));
if ($picture) {
    imagecopyresampled($canvas, $picture, 70, 156, 0, 0, 142, 142, imagesx($picture), imagesy($picture));
    imagerectangle($canvas, 70, 156, 212, 298, $line);
} else {
    imagefilledrectangle($canvas, 70, 156, 212, 298, $white);
    imagerectangle($canvas, 70, 156, 212, 298, $line);
    drawText($canvas, 46, 112, 244, $green, $boldFont, qrCardFirstCharacter($displayStudentName));
}

$x = 236;
$labelSize = 13;
$valueSize = 15;
$maxTextWidth = 300;
$fields = [
    ['label' => 'Full Name:', 'value' => $displayStudentName, 'inline' => false],
    ['label' => 'Course/Major/Section:', 'value' => qrCardCourseMajorSection($student), 'inline' => false],
    ['label' => 'Contact No.:', 'value' => $student['contact_number'] ?: 'N/A', 'inline' => true],
];
$y = 172;
foreach ($fields as $field) {
    $label = $field['label'];
    $value = $field['value'];

    if (!empty($field['inline'])) {
        drawText($canvas, $labelSize, $x, $y, $green, $boldFont, $label);
        $valueX = $x + textWidth($boldFont, $labelSize, $label) + 8;
        drawText($canvas, $valueSize, $valueX, $y, $ink, $boldFont, $value);
        $y += 34;
        continue;
    }

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

$safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $displayStudentName);
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
