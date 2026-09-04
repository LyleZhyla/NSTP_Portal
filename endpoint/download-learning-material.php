<?php
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: ../landing_page.php');
    exit;
}
require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../include/learning-materials.php';
if (!learningMaterialSessionActive($conn)) {
    header('Location: logout.php?reason=timeout');
    exit;
}
if (!getCurrentUserRecord($conn)) {
    http_response_code(403);
    exit('Account unavailable.');
}
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(404);
    exit('Learning material not found.');
}
try {
    $stmt = $conn->prepare('SELECT original_name, file_size, file_content FROM tbl_learning_materials WHERE material_id = ?');
    $stmt->execute([$id]);
    $material = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    error_log('Learning material download failed: ' . $error->getMessage());
    http_response_code(503);
    exit('Learning materials are temporarily unavailable.');
}
if (!$material) {
    http_response_code(404);
    exit('Learning material not found.');
}
session_write_close();
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: attachment; filename="learning-material"; filename*=UTF-8\'\'' . rawurlencode($material['original_name']));
header('Content-Length: ' . (int) $material['file_size']);
echo $material['file_content'];
exit;
