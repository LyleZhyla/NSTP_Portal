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
$materialActor = getCurrentUserRecord($conn);
if (!$materialActor) {
    http_response_code(403);
    exit('Account unavailable.');
}
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(404);
    exit('Learning material not found.');
}
try {
    ensureLearningMaterialsTable($conn);
    $visibility = learningMaterialVisibilitySql(learningMaterialViewer($conn, $materialActor));
    $stmt = $conn->prepare('SELECT m.original_name, m.file_size, m.storage_name FROM tbl_learning_materials m WHERE m.material_id = ? AND ' . $visibility['sql']);
    $stmt->execute(array_merge([$id], $visibility['params']));
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
$stream = null;
if ($material['storage_name']) {
    try {
        $path = learningMaterialStoragePath($material['storage_name']);
        $stream = is_file($path) ? fopen($path, 'rb') : false;
        if (!$stream || fread($stream, strlen(learningMaterialFileGuard())) !== learningMaterialFileGuard()
            || fstat($stream)['size'] - strlen(learningMaterialFileGuard()) !== (int) $material['file_size']) {
            throw new RuntimeException('Material file missing or incomplete.');
        }
    } catch (Throwable $error) {
        if (is_resource($stream)) fclose($stream);
        http_response_code(404);
        exit('The learning material file is unavailable.');
    }
} else {
    // Preserve downloads of the old, small database-backed uploads.
    $stmt = $conn->prepare('SELECT file_content FROM tbl_learning_materials WHERE material_id = ?');
    $stmt->execute([$id]);
    $material['file_content'] = $stmt->fetchColumn();
}
$size = (int) $material['file_size'];
$start = 0;
$end = $size - 1;
if (isset($_SERVER['HTTP_RANGE'])) {
    $valid = preg_match('/^bytes=(\d*)-(\d*)$/D', $_SERVER['HTTP_RANGE'], $range);
    if ($valid && ($range[1] !== '' || $range[2] !== '')) {
        if ($range[1] === '') $start = max(0, $size - (int) $range[2]);
        else {
            $start = (int) $range[1];
            if ($range[2] !== '') $end = min($end, (int) $range[2]);
        }
    } else $valid = false;
    if (!$valid || $start > $end || $start >= $size) {
        if (is_resource($stream)) fclose($stream);
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}
$videoMime = ($_GET['play'] ?? '') === '1' ? learningMaterialVideoMime($material['original_name']) : null;
header('Content-Type: ' . ($videoMime ?: 'application/octet-stream'));
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: ' . ($videoMime ? 'inline' : 'attachment') . '; filename="learning-material"; filename*=UTF-8\'\'' . rawurlencode($material['original_name']));
header('Content-Length: ' . ($end - $start + 1));
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    if (is_resource($stream)) fclose($stream);
    exit;
}
if ($stream) {
    set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    fseek($stream, strlen(learningMaterialFileGuard()) + $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($stream) && !connection_aborted()) {
        $buffer = fread($stream, min(1048576, $remaining));
        if ($buffer === false || $buffer === '') break;
        echo $buffer;
        $remaining -= strlen($buffer);
        flush();
    }
    fclose($stream);
} else {
    echo substr($material['file_content'], $start, $end - $start + 1);
}
exit;
