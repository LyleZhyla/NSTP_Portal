<?php

function profilePictureUploadErrorMessage($errorCode) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'Profile picture is bigger than the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'Profile picture is bigger than the form upload limit.',
        UPLOAD_ERR_PARTIAL => 'Profile picture upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server upload temp folder is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the profile picture.',
        UPLOAD_ERR_EXTENSION => 'A server extension blocked the profile picture upload.',
    ];

    return $messages[$errorCode] ?? 'Profile picture upload failed.';
}

function profilePictureRootDir($baseDir = '') {
    return $baseDir !== '' ? rtrim($baseDir, '/\\') : dirname(__DIR__);
}

function profilePictureFullPath($path, $baseDir = '') {
    $path = trim((string) $path);
    if ($path === '' || preg_match('/^(https?:)?\/\//i', $path) || strpos($path, 'data:') === 0) {
        return '';
    }

    return profilePictureRootDir($baseDir) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
}

function profilePictureExists($path, $baseDir = '') {
    $fullPath = profilePictureFullPath($path, $baseDir);
    return $fullPath !== '' && is_file($fullPath);
}

function profilePictureUrl($path, $baseDir = '') {
    $path = trim(str_replace('\\', '/', (string) $path));
    if ($path === '' || preg_match('/^(https?:)?\/\//i', $path) || strpos($path, 'data:') === 0) {
        return $path;
    }

    $urlParts = explode('?', $path, 2);
    $cleanPath = ltrim($urlParts[0], '/');
    $query = $urlParts[1] ?? '';
    $fullPath = profilePictureFullPath($cleanPath, $baseDir);

    if ($fullPath !== '' && is_file($fullPath)) {
        $query .= ($query !== '' ? '&' : '') . 'v=' . filemtime($fullPath);
    }

    return $cleanPath . ($query !== '' ? '?' . $query : '');
}

function registrationProfilePictureForUser(PDO $conn, $userId, $baseDir = '') {
    $userId = (int) $userId;
    if ($userId <= 0) {
        return '';
    }

    $stmt = $conn->prepare("
        SELECT r.formal_picture
        FROM tbl_public_student_registrations r
        LEFT JOIN tbl_student s ON s.student_number = r.student_number
        WHERE (r.user_id = ? OR s.user_id = ?)
          AND r.formal_picture IS NOT NULL
          AND r.formal_picture <> ''
          AND r.formal_picture <> 'include/logo.png'
        ORDER BY r.created_at DESC, r.registration_id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $userId]);
    $formalPicture = trim((string) $stmt->fetchColumn());

    return profilePictureExists($formalPicture, $baseDir) ? $formalPicture : '';
}

function syncRegistrationProfilePicture(PDO $conn, $userId, $baseDir = '') {
    $userId = (int) $userId;
    if ($userId <= 0) {
        return '';
    }

    $stmt = $conn->prepare("SELECT profile_picture FROM tbl_users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $currentPicture = trim((string) $stmt->fetchColumn());
    if ($currentPicture !== '' && profilePictureExists($currentPicture, $baseDir)) {
        return $currentPicture;
    }

    if ($currentPicture !== '') {
        return '';
    }

    $registrationPicture = registrationProfilePictureForUser($conn, $userId, $baseDir);
    if ($registrationPicture === '') {
        return '';
    }

    $updateStmt = $conn->prepare("UPDATE tbl_users SET profile_picture = ? WHERE user_id = ?");
    $updateStmt->execute([$registrationPicture, $userId]);

    return $registrationPicture;
}

function deleteProfilePictureFile($path, $baseDir = '') {
    $normalizedPath = ltrim(str_replace('\\', '/', trim((string) $path)), '/');
    $allowedPrefixes = ['uploads/profile_pictures/', 'uploads/profiles/'];
    $isAllowedProfileUpload = false;

    foreach ($allowedPrefixes as $prefix) {
        if (strpos($normalizedPath, $prefix) === 0) {
            $isAllowedProfileUpload = true;
            break;
        }
    }

    if (!$isAllowedProfileUpload) {
        return false;
    }

    $fullPath = profilePictureFullPath($path, $baseDir);
    if ($fullPath !== '' && is_file($fullPath)) {
        return unlink($fullPath);
    }

    return false;
}

function uploadProfilePicture(array $file, $baseDir = '', $prefix = 'profile') {
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $errorCode = $file['error'] ?? UPLOAD_ERR_OK;
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException(profilePictureUploadErrorMessage($errorCode));
    }

    $tmpName = $file['tmp_name'] ?? '';
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('The uploaded profile picture was not received by the server.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Profile picture must be 5MB or smaller.');
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $mimeType = mime_content_type($tmpName);
    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Profile picture must be JPG, PNG, GIF, or WEBP.');
    }

    $relativeDir = 'uploads/profile_pictures/';
    $uploadDir = profilePictureRootDir($baseDir) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($relativeDir, '/\\')) . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Could not create profile picture upload folder.');
    }

    if (!is_writable($uploadDir)) {
        throw new RuntimeException('Profile picture upload folder is not writable.');
    }

    $safePrefix = preg_replace('/[^a-z0-9_]/i', '', (string) $prefix) ?: 'profile';
    $fileName = $safePrefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $allowedTypes[$mimeType];
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Could not save the profile picture.');
    }

    @chmod($targetPath, 0644);

    return $relativeDir . $fileName;
}
