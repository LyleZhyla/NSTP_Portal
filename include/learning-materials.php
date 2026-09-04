<?php
require_once __DIR__ . '/user-permissions.php';

function ensureLearningMaterialsTable(PDO $conn) {
    $conn->exec("CREATE TABLE IF NOT EXISTS tbl_learning_materials (
        material_id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(180) NOT NULL,
        description TEXT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_size INT UNSIGNED NOT NULL,
        file_content LONGBLOB NOT NULL,
        uploaded_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_material_created (created_at, material_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function canUploadLearningMaterials(array $user) {
    return in_array($user['role'] ?? '', ['super_admin', 'coordinator'], true);
}

function learningMaterialUploadLimit() {
    $limit = 10 * 1024 * 1024;
    foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
        $value = trim(ini_get($setting));
        $bytes = (float) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g': $bytes *= 1024;
            case 'm': $bytes *= 1024;
            case 'k': $bytes *= 1024;
        }
        if ($bytes > 0) {
            // Reserve room for multipart headers and the title/description.
            $limit = min($limit, $setting === 'post_max_size' ? max(0, (int) $bytes - 16384) : (int) $bytes);
        }
    }
    return $limit;
}

function learningMaterialSize($bytes) {
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024, 1) . ' KB';
}

function learningMaterialSessionActive(PDO $conn) {
    if (empty($_SESSION['user_id'])) return false;
    $minutes = max(1, (int) getSystemSetting($conn, 'inactivity_timeout_minutes', '5'));
    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > $minutes * 60) return false;
    $_SESSION['last_activity'] = time();
    return true;
}

function validateLearningMaterialFile($path, $name, $limit) {
    $size = filesize($path);
    if ($size === false || $size === 0 || $size > $limit) {
        throw new InvalidArgumentException('Choose a non-empty file up to ' . learningMaterialSize($limit) . '.');
    }
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $types = [
        'pdf' => ['application/pdf'], 'txt' => ['text/plain'],
        'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
    ];
    $office = ['docx' => 'word/document.xml', 'pptx' => 'ppt/presentation.xml', 'xlsx' => 'xl/workbook.xml'];
    if (isset($office[$extension])) {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new InvalidArgumentException('The Office file is invalid.');
        try {
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($office[$extension]) === false) {
                throw new InvalidArgumentException('The file content does not match its Office file extension.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (stripos($zip->getNameIndex($i), 'vbaProject') !== false) {
                    throw new InvalidArgumentException('Macro-enabled Office files are not supported.');
                }
            }
        } finally {
            $zip->close();
        }
    } elseif (!isset($types[$extension]) || !in_array($mime, $types[$extension], true)) {
        throw new InvalidArgumentException('Upload a valid PDF, DOCX, PPTX, XLSX, TXT, PNG, or JPG file.');
    }
    return (int) $size;
}
