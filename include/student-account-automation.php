<?php

require_once __DIR__ . '/mailer.php';

if (!function_exists('normalizeProgram')) {
    function normalizeProgram($program) {
        $program = strtoupper(trim((string) $program));
        return in_array($program, ['CWTS', 'LTS', 'ROTC'], true) ? $program : null;
    }
}

function automationColumnExists(PDO $conn, $table, $column) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensureStudentNumberColumn(PDO $conn) {
    if (!automationColumnExists($conn, 'tbl_student', 'student_number')) {
        $conn->exec("ALTER TABLE tbl_student ADD COLUMN student_number VARCHAR(10) NULL AFTER user_id");
    }

    if (!automationColumnExists($conn, 'tbl_public_student_registrations', 'component')) {
        $conn->exec("ALTER TABLE tbl_public_student_registrations ADD COLUMN component VARCHAR(20) NULL AFTER year_section");
    }

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tbl_public_student_registrations'
              AND INDEX_NAME = 'unique_student_number'
        ");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) {
            $conn->exec("ALTER TABLE tbl_public_student_registrations DROP INDEX unique_student_number");
        }
    } catch (Throwable $error) {
        // Older imports may not have this index; duplicate submissions are intentionally allowed.
    }
}

function birthdayPasswordFromDate($dateValue) {
    try {
        $date = new DateTime((string) $dateValue);
        return $date->format('mdY');
    } catch (Throwable $error) {
        return '01011900';
    }
}

function generateStudentAccountPassword($length = 10) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}

function sendStudentAccountEmail(PDO $conn, array $registration, $studentNumber, $password) {
    $email = trim((string) ($registration['email'] ?? ''));
    if ($email === '' || isPlaceholderEmail($email)) {
        return false;
    }

    $fullNameParts = [
        $registration['first_name'] ?? '',
        ($registration['middle_name'] ?? 'N/A') === 'N/A' ? '' : ($registration['middle_name'] ?? ''),
        $registration['last_name'] ?? '',
        ($registration['extension_name'] ?? 'N/A') === 'N/A' ? '' : ($registration['extension_name'] ?? ''),
    ];
    $fullName = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($fullNameParts))));

    $sent = sendAccountCredentialsEmail($email, $fullName, $studentNumber, $password, 'student');
    if ($sent && automationColumnExists($conn, 'tbl_public_student_registrations', 'email_sent')) {
        $stmt = $conn->prepare("UPDATE tbl_public_student_registrations SET email_sent = 1 WHERE student_number = ?");
        $stmt->execute([$studentNumber]);
    }

    return $sent;
}

function autoCreateStudentAccountIfEligible(PDO $conn, $studentNumber) {
    ensureStudentNumberColumn($conn);

    $studentNumber = preg_replace('/\D/', '', (string) $studentNumber);
    if (!preg_match('/^\d{10}$/', $studentNumber)) {
        return ['created' => false, 'reason' => 'missing_student_number'];
    }

    $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ? LIMIT 1");
    $stmt->execute([$studentNumber]);
    $existingUserId = $stmt->fetchColumn();
    if ($existingUserId) {
        $linkStmt = $conn->prepare("UPDATE tbl_student SET user_id = ? WHERE student_number = ? AND (user_id IS NULL OR user_id = 0)");
        $linkStmt->execute([$existingUserId, $studentNumber]);
        return ['created' => false, 'user_id' => (int) $existingUserId, 'reason' => 'already_exists'];
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_attendance a
        INNER JOIN tbl_student s ON a.tbl_student_id = s.tbl_student_id
        WHERE s.student_number = ?
    ");
    $stmt->execute([$studentNumber]);
    $attendanceCount = (int) $stmt->fetchColumn();
    if ($attendanceCount < 2) {
        return ['created' => false, 'reason' => 'attendance_below_threshold', 'attendance_count' => $attendanceCount];
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM tbl_public_student_registrations
        WHERE student_number = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$studentNumber]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$registration) {
        return ['created' => false, 'reason' => 'registration_not_found', 'attendance_count' => $attendanceCount];
    }

    $fullNameParts = [
        $registration['first_name'] ?? '',
        ($registration['middle_name'] ?? 'N/A') === 'N/A' ? '' : ($registration['middle_name'] ?? ''),
        $registration['last_name'] ?? '',
        ($registration['extension_name'] ?? 'N/A') === 'N/A' ? '' : ($registration['extension_name'] ?? ''),
    ];
    $fullName = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($fullNameParts))));
    if ($fullName === '' || $fullName === 'Student ' . $studentNumber || $fullName === 'Student') {
        $fullName = 'Student #' . $studentNumber;
    }

    $email = trim((string) ($registration['email'] ?? ''));
    if ($email === '') {
        $email = 'student' . $studentNumber . '@no-email.tau-nstp.local';
    }

    $password = generateStudentAccountPassword();
    $program = normalizeProgram($registration['component'] ?? null);

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare("
            INSERT INTO tbl_users (username, email, password_hash, full_name, role, program, profile_picture)
            VALUES (?, ?, ?, ?, 'student', ?, ?)
        ");
        $stmt->execute([
            $studentNumber,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $fullName,
            $program,
            $registration['formal_picture'] ?? 'include/logo.png',
        ]);
        $userId = (int) $conn->lastInsertId();

        $stmt = $conn->prepare("UPDATE tbl_public_student_registrations SET user_id = ? WHERE student_number = ?");
        $stmt->execute([$userId, $studentNumber]);

        $stmt = $conn->prepare("UPDATE tbl_student SET user_id = ? WHERE student_number = ?");
        $stmt->execute([$userId, $studentNumber]);

        $conn->commit();
        $emailSent = sendStudentAccountEmail($conn, $registration, $studentNumber, $password);
        return ['created' => true, 'user_id' => $userId, 'username' => $studentNumber, 'password' => $password, 'attendance_count' => $attendanceCount, 'email_sent' => $emailSent];
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }
}

function autoCreateStudentAccountFromPublicRegistrations(PDO $conn, $studentNumber) {
    ensureStudentNumberColumn($conn);

    $studentNumber = preg_replace('/\D/', '', (string) $studentNumber);
    if (!preg_match('/^\d{10}$/', $studentNumber)) {
        return ['created' => false, 'reason' => 'missing_student_number'];
    }

    $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ? LIMIT 1");
    $stmt->execute([$studentNumber]);
    $existingUserId = $stmt->fetchColumn();
    if ($existingUserId) {
        $stmt = $conn->prepare("UPDATE tbl_public_student_registrations SET user_id = ? WHERE student_number = ? AND (user_id IS NULL OR user_id = 0)");
        $stmt->execute([$existingUserId, $studentNumber]);
        return ['created' => false, 'user_id' => (int) $existingUserId, 'reason' => 'already_exists'];
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM tbl_public_student_registrations
        WHERE student_number = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$studentNumber]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$registration) {
        return ['created' => false, 'reason' => 'registration_not_found'];
    }

    $fullNameParts = [
        $registration['first_name'] ?? '',
        ($registration['middle_name'] ?? 'N/A') === 'N/A' ? '' : ($registration['middle_name'] ?? ''),
        $registration['last_name'] ?? '',
        ($registration['extension_name'] ?? 'N/A') === 'N/A' ? '' : ($registration['extension_name'] ?? ''),
    ];
    $fullName = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($fullNameParts))));
    if ($fullName === '' || $fullName === 'Student ' . $studentNumber || $fullName === 'Student') {
        $fullName = 'Student #' . $studentNumber;
    }

    $email = trim((string) ($registration['email'] ?? ''));
    if ($email === '') {
        $email = 'student' . $studentNumber . '@no-email.tau-nstp.local';
    }

    $password = generateStudentAccountPassword();
    $program = normalizeProgram($registration['component'] ?? null);

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare("
            INSERT INTO tbl_users (username, email, password_hash, full_name, role, program, profile_picture)
            VALUES (?, ?, ?, ?, 'student', ?, ?)
        ");
        $stmt->execute([
            $studentNumber,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $fullName,
            $program,
            $registration['formal_picture'] ?? 'include/logo.png',
        ]);
        $userId = (int) $conn->lastInsertId();

        $stmt = $conn->prepare("UPDATE tbl_public_student_registrations SET user_id = ? WHERE student_number = ?");
        $stmt->execute([$userId, $studentNumber]);

        $stmt = $conn->prepare("UPDATE tbl_student SET user_id = ? WHERE student_number = ?");
        $stmt->execute([$userId, $studentNumber]);

        $conn->commit();
        $emailSent = sendStudentAccountEmail($conn, $registration, $studentNumber, $password);
        return ['created' => true, 'user_id' => $userId, 'username' => $studentNumber, 'password' => $password, 'email_sent' => $emailSent];
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }
}
