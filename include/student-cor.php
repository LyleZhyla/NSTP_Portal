<?php

require_once __DIR__ . '/user-permissions.php';
require_once __DIR__ . '/profile-picture-utils.php';

const STUDENT_COR_MAX_BYTES = 10485760;
const STUDENT_COR_INITIAL_SEMESTER_KEY = 'semester_2026_2027_current';
const STUDENT_COR_INITIAL_SEMESTER_LABEL = 'Current Semester AY 2026-2027';

function ensureStudentCorSchema(PDO $conn): void {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_student_cor_submissions (
            cor_submission_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            semester_key VARCHAR(64) NOT NULL,
            semester_label VARCHAR(150) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            file_hash CHAR(64) NOT NULL,
            file_content MEDIUMBLOB NOT NULL,
            submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (cor_submission_id),
            UNIQUE KEY uq_cor_user_semester (user_id, semester_key),
            KEY idx_cor_semester (semester_key, submitted_at),
            KEY idx_cor_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function currentStudentCorRequirement(PDO $conn): array {
    $enabledSetting = getSystemSetting($conn, 'student_cor_requirement_enabled', null);
    $semesterKey = trim((string) getSystemSetting($conn, 'current_semester_key', ''));
    $semesterLabel = trim((string) getSystemSetting($conn, 'current_semester_label', ''));

    // This deployment is the first COR-gated semester. Activate it once for
    // existing installations that do not yet have semester settings. A saved
    // explicit value of "0" remains respected if the gate is disabled later.
    if ($enabledSetting === null || $semesterKey === '') {
        $semesterKey = $semesterKey !== '' ? $semesterKey : STUDENT_COR_INITIAL_SEMESTER_KEY;
        $semesterLabel = $semesterLabel !== '' ? $semesterLabel : STUDENT_COR_INITIAL_SEMESTER_LABEL;
        setSystemSetting($conn, 'current_semester_key', $semesterKey);
        setSystemSetting($conn, 'current_semester_label', $semesterLabel);
        setSystemSetting($conn, 'student_cor_requirement_enabled', '1');
        $enabledSetting = '1';
    }

    $enabled = $enabledSetting === '1';

    return [
        'enabled' => $enabled && $semesterKey !== '',
        'semester_key' => $semesterKey,
        'semester_label' => $semesterLabel !== '' ? $semesterLabel : STUDENT_COR_INITIAL_SEMESTER_LABEL,
    ];
}

function studentHasCurrentCor(PDO $conn, int $userId, ?array $requirement = null): bool {
    $requirement = $requirement ?? currentStudentCorRequirement($conn);
    if (empty($requirement['enabled'])) {
        return true;
    }

    ensureStudentCorSchema($conn);
    $stmt = $conn->prepare("
        SELECT 1
        FROM tbl_student_cor_submissions
        WHERE user_id = ? AND semester_key = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $requirement['semester_key']]);
    return (bool) $stmt->fetchColumn();
}

function studentNeedsCurrentCor(PDO $conn, int $userId): bool {
    $requirement = currentStudentCorRequirement($conn);
    return !empty($requirement['enabled']) && !studentHasCurrentCor($conn, $userId, $requirement);
}

function stagePendingCorLogin(array $user): void {
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['pending_cor_user_id'] = (int) $user['user_id'];
    $_SESSION['pending_cor_started_at'] = time();
    $_SESSION['cor_upload_csrf'] = bin2hex(random_bytes(32));
}

function activateUserSession(PDO $conn, array $user): void {
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['program'] = $user['program'] ?? null;
    $_SESSION['last_activity'] = time();

    try {
        $stmt = $conn->prepare("
            UPDATE tbl_users
            SET first_login_at = COALESCE(first_login_at, NOW()),
                last_login_at = NOW(),
                login_count = login_count + 1
            WHERE user_id = ?
        ");
        $stmt->execute([(int) $user['user_id']]);
    } catch (Throwable $ignored) {
        // Backward compatibility for databases without login tracking columns.
    }

    $profilePicture = trim((string) ($user['profile_picture'] ?? ''));
    if ($profilePicture === '' || !profilePictureExists($profilePicture, dirname(__DIR__))) {
        $profilePicture = syncRegistrationProfilePicture($conn, (int) $user['user_id'], dirname(__DIR__));
    }
    if ($profilePicture !== '') {
        $_SESSION['profile_picture'] = $profilePicture;
    }

    if (($user['role'] ?? '') === 'student') {
        $requirement = currentStudentCorRequirement($conn);
        if (!empty($requirement['enabled']) && studentHasCurrentCor($conn, (int) $user['user_id'], $requirement)) {
            $_SESSION['cor_verified_semester_key'] = $requirement['semester_key'];
        } else {
            unset($_SESSION['cor_verified_semester_key']);
        }
    }

    logSystemEvent($conn, 'user_login', $user['username'] . ' logged in as ' . $user['role']);
}

function saveStudentCorUpload(PDO $conn, int $userId, array $file): array {
    $requirement = currentStudentCorRequirement($conn);
    if (empty($requirement['enabled'])) {
        throw new RuntimeException('There is no active COR requirement.');
    }

    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE
            ? 'The COR file exceeds 10 MB.'
            : 'Please select a COR file to upload.');
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    $fileSize = (int) ($file['size'] ?? 0);
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('The uploaded file is invalid. Please try again.');
    }
    if ($fileSize < 1 || $fileSize > STUDENT_COR_MAX_BYTES) {
        throw new RuntimeException('The COR must not exceed 10 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($temporaryPath);
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!in_array($mimeType, $allowedTypes, true)) {
        throw new RuntimeException('Only PDF, JPG, and PNG files are allowed.');
    }
    if (strpos($mimeType, 'image/') === 0 && @getimagesize($temporaryPath) === false) {
        throw new RuntimeException('The selected file is not a valid image.');
    }
    if ($mimeType === 'application/pdf') {
        $handle = fopen($temporaryPath, 'rb');
        $signature = $handle ? fread($handle, 5) : '';
        if ($handle) fclose($handle);
        if ($signature !== '%PDF-') {
            throw new RuntimeException('The selected file is not a valid PDF.');
        }
    }

    $contents = file_get_contents($temporaryPath);
    if ($contents === false || strlen($contents) !== $fileSize) {
        throw new RuntimeException('The uploaded COR could not be read completely.');
    }

    ensureStudentCorSchema($conn);
    $originalName = basename(trim((string) ($file['name'] ?? 'COR')));
    $originalName = mb_substr($originalName !== '' ? $originalName : 'COR', 0, 255);
    $stmt = $conn->prepare("
        INSERT INTO tbl_student_cor_submissions
            (user_id, semester_key, semester_label, original_name, mime_type, file_size, file_hash, file_content)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            semester_label = VALUES(semester_label),
            original_name = VALUES(original_name),
            mime_type = VALUES(mime_type),
            file_size = VALUES(file_size),
            file_hash = VALUES(file_hash),
            file_content = VALUES(file_content),
            submitted_at = CURRENT_TIMESTAMP
    ");
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $requirement['semester_key']);
    $stmt->bindValue(3, $requirement['semester_label']);
    $stmt->bindValue(4, $originalName);
    $stmt->bindValue(5, $mimeType);
    $stmt->bindValue(6, $fileSize, PDO::PARAM_INT);
    $stmt->bindValue(7, hash('sha256', $contents));
    $stmt->bindValue(8, $contents, PDO::PARAM_LOB);
    $stmt->execute();

    logSystemEvent($conn, 'student_cor_uploaded', 'Student account #' . $userId . ' uploaded a COR for ' . $requirement['semester_label']);
    return $requirement;
}

function enforceStudentCorAccess(PDO $conn): void {
    if (($_SESSION['role'] ?? '') !== 'student' || empty($_SESSION['user_id'])) {
        return;
    }

    $requirement = currentStudentCorRequirement($conn);
    if (empty($requirement['enabled'])) {
        return;
    }
    if (($_SESSION['cor_verified_semester_key'] ?? '') === $requirement['semester_key']) {
        return;
    }
    if (studentHasCurrentCor($conn, (int) $_SESSION['user_id'], $requirement)) {
        $_SESSION['cor_verified_semester_key'] = $requirement['semester_key'];
        return;
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowedWhilePending = [
        '/cor-upload.php',
        '/endpoint/upload-student-cor.php',
        '/endpoint/logout.php',
        '/endpoint/login-user.php',
    ];
    foreach ($allowedWhilePending as $allowedPath) {
        if (substr($scriptName, -strlen($allowedPath)) === $allowedPath) {
            return;
        }
    }

    $uploadLocation = strpos($scriptName, '/endpoint/') !== false ? '../cor-upload.php' : 'cor-upload.php';
    header('Location: ' . $uploadLocation);
    exit();
}

