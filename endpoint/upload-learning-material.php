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
$actor = getCurrentUserRecord($conn);
if (!$actor || !canUploadLearningMaterials($actor)) {
    http_response_code(403);
    exit('Only administrators and coordinators can upload learning materials.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Use the upload form in Learning Materials.');
}

try {
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        throw new InvalidArgumentException('The upload exceeds the server request limit. Choose a smaller file.');
    }
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || empty($_SESSION['learning_material_csrf']) || !hash_equals($_SESSION['learning_material_csrf'], $token)) {
        http_response_code(403);
        exit('Your form has expired. Reload Learning Materials and try again.');
    }
    $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
    $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
    if ($title === '' || mb_strlen($title) > 180 || mb_strlen($description) > 5000) {
        throw new InvalidArgumentException('Enter a title up to 180 characters and a description up to 5,000 characters.');
    }
    $file = $_FILES['material'] ?? null;
    if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
        throw new InvalidArgumentException('Choose a file to upload.');
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new InvalidArgumentException('The file is too large. Maximum size: ' . learningMaterialSize(learningMaterialUploadLimit()) . '.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('The file could not be uploaded. Choose the file and try again.');
    }
    $name = basename(str_replace('\\', '/', $file['name']));
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
    if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 255) {
        throw new InvalidArgumentException('Use a file name up to 255 characters.');
    }
    $size = validateLearningMaterialFile($file['tmp_name'], $name, learningMaterialUploadLimit());
    $content = file_get_contents($file['tmp_name']);
    if ($content === false) throw new RuntimeException('Cannot read uploaded material.');
    ensureLearningMaterialsTable($conn);
    $stmt = $conn->prepare('INSERT INTO tbl_learning_materials (title, description, original_name, file_size, file_content, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bindValue(1, $title);
    $stmt->bindValue(2, $description);
    $stmt->bindValue(3, $name);
    $stmt->bindValue(4, $size, PDO::PARAM_INT);
    $stmt->bindValue(5, $content, PDO::PARAM_LOB);
    $stmt->bindValue(6, (int) $actor['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $_SESSION['learning_material_flash'] = ['type' => 'success', 'message' => 'Learning material uploaded successfully.'];
    unset($_SESSION['learning_material_old']);
} catch (Throwable $error) {
    $message = 'Unable to save the learning material. Please try again or contact your administrator.';
    if ($error instanceof InvalidArgumentException) {
        $message = $error->getMessage();
    } else {
        error_log('Learning material upload failed: ' . $error->getMessage());
    }
    $_SESSION['learning_material_flash'] = ['type' => 'danger', 'message' => $message];
    $_SESSION['learning_material_old'] = ['title' => mb_substr($title ?? '', 0, 180), 'description' => mb_substr($description ?? '', 0, 5000)];
}
header('Location: ../learning-management.php?tab=learning-materials', true, 303);
exit;
