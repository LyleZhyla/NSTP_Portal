<?php
require_once __DIR__ . '/user-permissions.php';

function ensureLearningMaterialsTable(PDO $conn) {
    $conn->exec("CREATE TABLE IF NOT EXISTS tbl_learning_materials (
        material_id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(180) NOT NULL,
        description TEXT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL,
        file_content LONGBLOB NOT NULL,
        storage_name VARCHAR(68) NULL,
        uploaded_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_material_created (created_at, material_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $columns = $conn->query('SHOW COLUMNS FROM tbl_learning_materials')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    if (stripos($columns['file_size']['Type'], 'bigint') === false) {
        $conn->exec('ALTER TABLE tbl_learning_materials MODIFY file_size BIGINT UNSIGNED NOT NULL');
    }
    if (!isset($columns['storage_name'])) {
        $conn->exec('ALTER TABLE tbl_learning_materials ADD storage_name VARCHAR(68) NULL');
    }
    $conn->exec("CREATE TABLE IF NOT EXISTS tbl_learning_material_uploads (
        upload_id CHAR(64) PRIMARY KEY,
        uploaded_by INT NOT NULL,
        title VARCHAR(180) NOT NULL,
        description TEXT NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        total_size BIGINT UNSIGNED NOT NULL,
        received_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_upload_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['tbl_learning_materials', 'tbl_learning_material_uploads'] as $table) {
        $audienceColumns = $conn->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        foreach (['audience_components' => 'VARCHAR(20)', 'audience_rotc_levels' => 'VARCHAR(30)'] as $column => $type) {
            if (!isset($audienceColumns[$column])) $conn->exec("ALTER TABLE {$table} ADD {$column} {$type} NULL");
        }
    }
}

function normalizeLearningMaterialAudience($components, $levels) {
    if (!is_array($components) || !$components || count($components) > 3) throw new InvalidArgumentException('Select at least one component.');
    foreach ($components as $component) {
        if (!is_string($component) || !in_array($component, ['CWTS', 'LTS', 'ROTC'], true)) throw new InvalidArgumentException('Invalid component selection.');
    }
    if (!is_array($levels) || count($levels) > 3) throw new InvalidArgumentException('Invalid ROTC MS level selection.');
    foreach ($levels as $level) {
        if (!is_string($level) || !in_array($level, getRotcMsLevels(), true)) throw new InvalidArgumentException('Invalid ROTC MS level selection.');
    }
    if (in_array('ROTC', $components, true) && !$levels) throw new InvalidArgumentException('Select at least one ROTC MS level.');
    return [
        'components' => implode(',', array_values(array_intersect(['CWTS', 'LTS', 'ROTC'], $components))),
        'levels' => in_array('ROTC', $components, true) ? implode(',', array_values(array_intersect(getRotcMsLevels(), $levels))) : '',
    ];
}

function learningMaterialViewer(PDO $conn, array $actor) {
    $actor['program'] = normalizeProgram($actor['program'] ?? null);
    $actor['ms_level'] = null;
    if (($actor['role'] ?? '') !== 'student') return $actor;
    $stmt = $conn->prepare('SELECT s.*, creator.role AS creator_role, creator.program AS creator_program FROM tbl_student s LEFT JOIN tbl_users creator ON creator.user_id = s.created_by WHERE s.user_id = ? ORDER BY s.tbl_student_id DESC LIMIT 1');
    $stmt->execute([$actor['user_id']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $number = $student['student_number'] ?? '';
    $stmt = $conn->prepare("SELECT component, rotc_ms_level FROM tbl_public_student_registrations WHERE user_id = ? OR (? <> '' AND student_number = ?) ORDER BY registration_id DESC LIMIT 1");
    $stmt->execute([$actor['user_id'], $number, $number]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $actor['program'] = resolveStudentComponentFromSources($actor['program'], $registration['component'] ?? null, $student['course_section'] ?? '', $student['creator_role'] ?? null, $student['creator_program'] ?? null);
    if ($actor['program'] === 'ROTC') {
        $actor['ms_level'] = ($student ? getRotcStudentMsLevel($conn, $student) : normalizeRotcMsLevel($registration['rotc_ms_level'] ?? null)) ?: 'MS-1';
    }
    return $actor;
}

// Use the exact same filter for the list, its count, and direct downloads.
function learningMaterialVisibilitySql(array $viewer) {
    if (($viewer['role'] ?? '') === 'super_admin') return ['sql' => '1=1', 'params' => []];
    return [
        'sql' => "(m.audience_components IS NULL OR m.uploaded_by = ? OR
            (FIND_IN_SET(?, m.audience_components) > 0 AND
            (? <> 'ROTC' OR ? <> 'student' OR FIND_IN_SET(?, m.audience_rotc_levels) > 0)))",
        'params' => [(int) $viewer['user_id'], $viewer['program'] ?? '', $viewer['program'] ?? '', $viewer['role'] ?? '', $viewer['ms_level'] ?? ''],
    ];
}

function learningMaterialAudienceLabel(array $material) {
    if ($material['audience_components'] === null) return 'All accounts (legacy material)';
    $label = str_replace(',', ', ', $material['audience_components']);
    if (in_array('ROTC', explode(',', $material['audience_components']), true)) $label .= ' | ROTC students: ' . str_replace(',', ', ', $material['audience_rotc_levels'] ?? '');
    return $label;
}

function canUploadLearningMaterials(array $user) {
    return in_array($user['role'] ?? '', ['super_admin', 'coordinator'], true);
}

function learningMaterialUploadLimit() {
    return 10 * 1024 * 1024 * 1024;
}

function learningMaterialChunkLimit() {
    $limit = 1024 * 1024;
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
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024, 1) . ' KB';
}

function learningMaterialSessionActive(PDO $conn) {
    if (empty($_SESSION['user_id'])) return false;
    $minutes = max(1, (int) getSystemSetting($conn, 'inactivity_timeout_minutes', '5'));
    if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > $minutes * 60) return false;
    $_SESSION['last_activity'] = time();
    return true;
}

// The PHP guard prevents direct execution/download even on PHP's development
// server. Apache also denies this directory with its checked-in .htaccess.
function learningMaterialFileGuard() {
    return "<?php http_response_code(404); exit; __halt_compiler();\n";
}

function learningMaterialStoragePath($name) {
    if (!preg_match('/^[a-f0-9]{64}\\.php$/D', $name)) throw new RuntimeException('Invalid storage name.');
    $directory = getenv('NSTP_LEARNING_MATERIALS_DIR') ?: __DIR__ . '/../storage/learning-materials';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Learning materials storage is not writable.');
    }
    return $directory . DIRECTORY_SEPARATOR . $name;
}

function cleanupLearningMaterialUploads(PDO $conn) {
    $expired = $conn->query("SELECT upload_id FROM tbl_learning_material_uploads WHERE updated_at < DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 20")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($expired as $id) {
        $path = learningMaterialStoragePath($id . '.php');
        $handle = is_file($path) ? fopen($path, 'r+b') : false;
        if ($handle && !flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); continue; }
        $stmt = $conn->prepare('DELETE FROM tbl_learning_material_uploads WHERE upload_id = ? AND updated_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $stmt->execute([$id]);
        if ($handle) fclose($handle);
        if ($stmt->rowCount() && is_file($path)) unlink($path);
    }
}

// Read only ZIP directory metadata, relative to the payload after the PHP guard.
// ZIP64 offsets allow large Office documents without copying/decompressing them.
function learningMaterialOfficeEntries($path, $offset, $size, $stream = null) {
    $handle = $stream ?: fopen($path, 'rb');
    if (!$handle) throw new RuntimeException('Cannot read Office file.');
    try {
        $tailSize = min($size, 65557);
        fseek($handle, $offset + $size - $tailSize);
        $tail = fread($handle, $tailSize);
        $end = strrpos($tail, "PK\x05\x06");
        if ($end === false || strlen($tail) - $end < 22) throw new InvalidArgumentException('Invalid Office file.');
        $record = substr($tail, $end);
        $comment = unpack('v', substr($record, 20, 2))[1];
        if (strlen($record) !== 22 + $comment) throw new InvalidArgumentException('Invalid Office ZIP directory.');
        $entries = unpack('v', substr($record, 10, 2))[1];
        $directory = unpack('V', substr($record, 16, 4))[1];
        if ($entries === 65535 || $directory === 4294967295) {
            fseek($handle, $offset + $size - $tailSize + $end - 20);
            $locator = fread($handle, 20);
            if (strlen($locator) !== 20 || substr($locator, 0, 4) !== "PK\x06\x07") throw new InvalidArgumentException('Invalid ZIP64 Office file.');
            $zip64Offset = unpack('P', substr($locator, 8, 8))[1];
            if ($zip64Offset < 0 || $zip64Offset > $size - 56) throw new InvalidArgumentException('Invalid ZIP64 offset.');
            fseek($handle, $offset + $zip64Offset);
            $zip64 = fread($handle, 56);
            if (strlen($zip64) !== 56 || substr($zip64, 0, 4) !== "PK\x06\x06") throw new InvalidArgumentException('Invalid ZIP64 directory.');
            $entries = unpack('P', substr($zip64, 32, 8))[1];
            $directory = unpack('P', substr($zip64, 48, 8))[1];
        }
        if ($entries < 1 || $entries > 100000 || $directory < 0 || $directory >= $size) throw new InvalidArgumentException('Invalid or excessively complex Office file.');
        fseek($handle, $offset + $directory);
        $names = [];
        for ($i = 0; $i < $entries; $i++) {
            $header = fread($handle, 46);
            if (strlen($header) !== 46 || substr($header, 0, 4) !== "PK\x01\x02") throw new InvalidArgumentException('Invalid Office directory entry.');
            $lengths = unpack('vname/vextra/vcomment', substr($header, 28, 6));
            if ($lengths['name'] < 1 || ftell($handle) + array_sum($lengths) > $offset + $size) throw new InvalidArgumentException('Invalid Office entry length.');
            $name = fread($handle, $lengths['name']);
            if (stripos($name, 'vbaProject') !== false) throw new InvalidArgumentException('Macro-enabled Office files are not supported.');
            // Keep only the required document names in memory.
            if (in_array($name, ['[Content_Types].xml', 'word/document.xml', 'ppt/presentation.xml', 'xl/workbook.xml'], true)) $names[$name] = true;
            fseek($handle, $lengths['extra'] + $lengths['comment'], SEEK_CUR);
        }
        return $names;
    } finally { if (!$stream) fclose($handle); }
}

function validateLearningMaterialFile($path, $name, $limit, $offset = 0, $stream = null) {
    clearstatcache(true, $path);
    $size = filesize($path);
    if ($size !== false) $size -= $offset;
    if ($size === false || $size === 0 || $size > $limit) {
        throw new InvalidArgumentException('Choose a non-empty file up to ' . learningMaterialSize($limit) . '.');
    }
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $handle = $stream ?: fopen($path, 'rb');
    if (!$handle) throw new RuntimeException('Cannot read material.');
    fseek($handle, $offset);
    $sample = fread($handle, 65536);
    if (!$stream) fclose($handle);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($sample);
    $types = [
        'pdf' => ['application/pdf'], 'txt' => ['text/plain'],
        'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
    ];
    $office = ['docx' => 'word/document.xml', 'pptx' => 'ppt/presentation.xml', 'xlsx' => 'xl/workbook.xml'];
    if (isset($office[$extension])) {
        $names = learningMaterialOfficeEntries($path, $offset, $size, $stream);
        if (!isset($names['[Content_Types].xml'], $names[$office[$extension]])) throw new InvalidArgumentException('The file content does not match its Office extension.');
    } elseif (!isset($types[$extension]) || !in_array($mime, $types[$extension], true)) {
        throw new InvalidArgumentException('Upload a valid PDF, DOCX, PPTX, XLSX, TXT, PNG, or JPG file.');
    }
    return (int) $size;
}
