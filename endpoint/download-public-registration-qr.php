<?php
require_once '../conn/conn.php';
require_once '../include/public-registration-forms.php';

$slug = trim((string) ($_GET['form'] ?? ''));
if ($slug === '') {
    http_response_code(400);
    echo 'Missing form.';
    exit();
}

$form = getPublicRegistrationForm($conn, $slug);
if (!$form || $form['form_slug'] !== $slug) {
    http_response_code(404);
    echo 'Form not found.';
    exit();
}

$basePath = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
$publicUrl = 'http://' . $_SERVER['HTTP_HOST'] . $basePath . '/public-registration.php?form=' . urlencode($slug);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=900x900&format=png&data=' . urlencode($publicUrl);

$imageData = @file_get_contents($qrUrl);
if ($imageData === false) {
    header('Location: ' . $qrUrl);
    exit();
}

$safeSlug = preg_replace('/[^a-zA-Z0-9_-]/', '-', $slug);
header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="public-registration-' . $safeSlug . '.png"');
header('Content-Length: ' . strlen($imageData));
echo $imageData;
exit();
