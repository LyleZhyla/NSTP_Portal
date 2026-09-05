<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function materialUploadReply($status, array $body) {
    http_response_code($status);
    echo json_encode($body);
    exit;
}
if (empty($_SESSION['user_id'])) materialUploadReply(401, ['message' => 'Please sign in again.']);
require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../include/learning-materials.php';
if (!learningMaterialSessionActive($conn)) materialUploadReply(401, ['message' => 'Your session expired. Please sign in again.']);
$actor = getCurrentUserRecord($conn);
if (!$actor || !canUploadLearningMaterials($actor)) materialUploadReply(403, ['message' => 'Only administrators and coordinators can upload materials.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    materialUploadReply(405, ['message' => 'Use the upload form in Learning Materials.']);
}
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) materialUploadReply(413, ['message' => 'This upload part exceeds the server request limit.']);
$csrf = $_POST['csrf_token'] ?? null;
if (!is_string($csrf) || empty($_SESSION['learning_material_csrf']) || !hash_equals($_SESSION['learning_material_csrf'], $csrf)) {
    materialUploadReply(403, ['message' => 'Reload Learning Materials and try again.']);
}
session_write_close();
$handle = null;
$deletePath = null;
try {
    if (PHP_INT_SIZE < 8) throw new RuntimeException('Large uploads require 64-bit PHP.');
    ensureLearningMaterialsTable($conn);
    $action = $_POST['action'] ?? '';
    if ($action === 'delete_material') {
        $materialId = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$materialId) throw new InvalidArgumentException('Invalid material.');
        $conn->beginTransaction();
        $stmt = $conn->prepare('SELECT uploaded_by, storage_name FROM tbl_learning_materials WHERE material_id=? FOR UPDATE');
        $stmt->execute([$materialId]);
        $material = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$material || ($actor['role'] !== 'super_admin' && (int)$material['uploaded_by'] !== (int)$actor['user_id'])) {
            $conn->rollBack();
            materialUploadReply(403, ['message'=>'You cannot delete this material.']);
        }
        $storedPath = !empty($material['storage_name']) ? learningMaterialStoragePath($material['storage_name']) : null;
        $conn->prepare('DELETE FROM tbl_learning_materials WHERE material_id=?')->execute([$materialId]);
        $conn->commit();
        // Set this only after commit so a rolled-back deletion never removes its file.
        $deletePath = $storedPath;
        $result = ['success'=>true];
    } elseif ($action === 'set_availability') {
        $materialId = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $open = $_POST['is_open'] ?? null;
        if (!$materialId || !in_array($open, ['0', '1'], true)) throw new InvalidArgumentException('Invalid material availability.');
        $stmt = $conn->prepare('SELECT uploaded_by FROM tbl_learning_materials WHERE material_id=?');
        $stmt->execute([$materialId]);
        $owner = $stmt->fetchColumn();
        if ($owner === false || ($actor['role'] !== 'super_admin' && (int)$owner !== (int)$actor['user_id'])) materialUploadReply(403, ['message'=>'You cannot open or close this material.']);
        $conn->prepare('UPDATE tbl_learning_materials SET is_open=? WHERE material_id=?')->execute([(int)$open,$materialId]);
        $result = ['success'=>true, 'is_open'=>(int)$open];
    } elseif ($action === 'update_audience') {
        $audience = normalizeLearningMaterialAudience($_POST['components'] ?? null, $_POST['rotc_levels'] ?? []);
        $materialId = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$materialId) throw new InvalidArgumentException('Invalid material.');
        $stmt = $conn->prepare('SELECT uploaded_by FROM tbl_learning_materials WHERE material_id = ?');
        $stmt->execute([$materialId]);
        $owner = $stmt->fetchColumn();
        if ($owner === false || ($actor['role'] !== 'super_admin' && (int) $owner !== (int) $actor['user_id'])) materialUploadReply(403, ['message' => 'You cannot change the audience of this material.']);
        $stmt = $conn->prepare('UPDATE tbl_learning_materials SET audience_components = ?, audience_rotc_levels = ? WHERE material_id = ?');
        $stmt->execute([$audience['components'], $audience['levels'], $materialId]);
        $result = ['success' => true];
    } elseif ($action === 'start') {
        $audience = normalizeLearningMaterialAudience($_POST['components'] ?? null, $_POST['rotc_levels'] ?? []);
        cleanupLearningMaterialUploads($conn);
        $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
        $name = is_string($_POST['name'] ?? null) ? basename(str_replace('\\', '/', $_POST['name'])) : '';
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $total = filter_var($_POST['size'] ?? null, FILTER_VALIDATE_INT);
        if ($title === '' || mb_strlen($title) > 180 || mb_strlen($description) > 5000) throw new InvalidArgumentException('Enter a title up to 180 characters and a description up to 5,000 characters.');
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 255 || !preg_match('/\.(pdf|docx|pptx|xlsx|txt|png|jpe?g|mp4|webm|mov)$/iD', $name)) throw new InvalidArgumentException('Choose a PDF, DOCX, PPTX, XLSX, TXT, PNG, JPG, MP4, WebM, or MOV file.');
        if (!$total || $total < 1 || $total > learningMaterialUploadLimit()) throw new InvalidArgumentException('Choose a non-empty file up to 10 GB.');
        $id = bin2hex(random_bytes(32));
        $path = learningMaterialStoragePath($id . '.php');
        $free = disk_free_space(dirname($path));
        if ($free !== false && $free < $total + 10485760) throw new InvalidArgumentException('The server does not have enough free disk space for this file.');
        $handle = fopen($path, 'xb');
        if (!$handle) throw new RuntimeException('Cannot create material file.');
        $deletePath = $path;
        if (fwrite($handle, learningMaterialFileGuard()) !== strlen(learningMaterialFileGuard())) throw new RuntimeException('Cannot prepare material file.');
        fclose($handle); $handle = null;
        $stmt = $conn->prepare('INSERT INTO tbl_learning_material_uploads (upload_id, uploaded_by, title, description, original_name, total_size, audience_components, audience_rotc_levels) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$id, $actor['user_id'], $title, $description, $name, $total, $audience['components'], $audience['levels']]);
        $deletePath = null;
        $result = ['upload_id' => $id, 'received' => 0, 'chunk_size' => learningMaterialChunkLimit()];
    } else {
        $id = $_POST['upload_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id)) throw new InvalidArgumentException('Invalid upload.');
        $stmt = $conn->prepare('SELECT * FROM tbl_learning_material_uploads WHERE upload_id = ? AND uploaded_by = ?');
        $stmt->execute([$id, $actor['user_id']]);
        $upload = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$upload) {
            // A repeated finish after a lost response must not publish twice.
            $done = $conn->prepare('SELECT material_id FROM tbl_learning_materials WHERE storage_name = ? AND uploaded_by = ?');
            $done->execute([$id . '.php', $actor['user_id']]);
            $materialId = $done->fetchColumn();
            if ($action === 'finish' && $materialId) materialUploadReply(200, ['success' => true, 'material_id' => (int) $materialId]);
            throw new InvalidArgumentException('Upload unavailable or expired. Choose the file and start again.');
        }
        $path = learningMaterialStoragePath($id . '.php');
        $handle = fopen($path, 'r+b');
        if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Cannot lock upload file.');
        // Re-read under the file lock to serialize concurrent/retried chunks.
        $stmt->execute([$id, $actor['user_id']]);
        $upload = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$upload) throw new InvalidArgumentException('Upload is no longer active.');
        $received = (int) $upload['received_size'];
        $total = (int) $upload['total_size'];
        $guardSize = strlen(learningMaterialFileGuard());
        if ($action === 'chunk') {
            $offset = filter_var($_POST['offset'] ?? null, FILTER_VALIDATE_INT);
            $file = $_FILES['chunk'] ?? [];
            if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Upload part failed. Please retry.');
            $length = filesize($file['tmp_name']);
            if ($offset === false || $offset < 0 || !$length || $length > learningMaterialChunkLimit() || $offset + $length > $total) throw new InvalidArgumentException('Invalid upload part or file exceeds its declared size.');
            if ($offset === $received) {
                // Discard uncommitted bytes left by an interrupted write.
                $storedSize = fstat($handle)['size'];
                if ($storedSize < $guardSize + $received) throw new RuntimeException('Upload file is incomplete. Please cancel and start again.');
                if (($storedSize > $guardSize + $received && !ftruncate($handle, $guardSize + $received)) || fseek($handle, $guardSize + $received) !== 0) throw new RuntimeException('Cannot seek in upload.');
                $source = fopen($file['tmp_name'], 'rb');
                if (!$source) throw new RuntimeException('Cannot read upload part.');
                try { $written = stream_copy_to_stream($source, $handle, $length); } finally { fclose($source); }
                if ($written !== $length || !fflush($handle)) throw new RuntimeException('Not enough disk space or write failed.');
                $received += $length;
                $update = $conn->prepare('UPDATE tbl_learning_material_uploads SET received_size = ?, updated_at = CURRENT_TIMESTAMP WHERE upload_id = ?');
                $update->execute([$received, $id]);
            } elseif ($offset > $received || $offset + $length > $received) {
                throw new InvalidArgumentException('Upload parts arrived out of order.');
            }
            $result = ['received' => $received];
        } elseif ($action === 'finish') {
            if ($received !== $total) throw new InvalidArgumentException('The file is incomplete. Finish uploading all parts first.');
            $size = validateLearningMaterialFile($path, $upload['original_name'], learningMaterialUploadLimit(), $guardSize, $handle, true);
            if ($size !== $total) throw new InvalidArgumentException('Uploaded size does not match the original file.');
            $conn->beginTransaction();
            $insert = $conn->prepare("INSERT INTO tbl_learning_materials (title, description, original_name, file_size, file_content, storage_name, uploaded_by, audience_components, audience_rotc_levels) VALUES (?, ?, ?, ?, '', ?, ?, ?, ?)");
            $insert->execute([$upload['title'], $upload['description'], $upload['original_name'], $size, $id . '.php', $actor['user_id'], $upload['audience_components'], $upload['audience_rotc_levels']]);
            $materialId = (int) $conn->lastInsertId();
            $conn->prepare('DELETE FROM tbl_learning_material_uploads WHERE upload_id = ?')->execute([$id]);
            $conn->commit();
            $result = ['success' => true, 'material_id' => $materialId];
        } elseif ($action === 'cancel') {
            $conn->prepare('DELETE FROM tbl_learning_material_uploads WHERE upload_id = ?')->execute([$id]);
            $deletePath = $path;
            $result = ['success' => true];
        } else {
            throw new InvalidArgumentException('Unknown upload action.');
        }
    }
} catch (Throwable $error) {
    if ($conn->inTransaction()) $conn->rollBack();
    $status = $error instanceof InvalidArgumentException ? 400 : 500;
    $result = ['message' => $status === 400 ? $error->getMessage() : 'Unable to save this upload part. Check server storage and try again.'];
    if ($status === 500) error_log('Learning material upload: ' . $error->getMessage());
} finally {
    if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
    if ($deletePath && is_file($deletePath)) unlink($deletePath);
}
materialUploadReply($status ?? 200, $result);
