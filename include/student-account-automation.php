<?php

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/automatic-sectioning.php';

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

function studentAutomationLatestRegistration(PDO $conn, $studentNumber) {
    $stmt = $conn->prepare("
        SELECT *
        FROM tbl_public_student_registrations
        WHERE student_number = ?
          AND COALESCE(status, 'submitted') <> 'account_deleted'
        ORDER BY CASE WHEN status = 'attendance_only' THEN 1 ELSE 0 END, created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$studentNumber]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function studentAutomationFullName(array $registration, $studentNumber) {
    $fullNameParts = [
        $registration['first_name'] ?? '',
        ($registration['middle_name'] ?? 'N/A') === 'N/A' ? '' : ($registration['middle_name'] ?? ''),
        $registration['last_name'] ?? '',
        ($registration['extension_name'] ?? 'N/A') === 'N/A' ? '' : ($registration['extension_name'] ?? ''),
    ];
    $fullName = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($fullNameParts))));

    if ($fullName === '' || $fullName === 'Student ' . $studentNumber || $fullName === 'Student') {
        return 'Student #' . $studentNumber;
    }

    return $fullName;
}

function studentAutomationOriginalSection(array $registration) {
    return autoSectionOriginalSection(
        $registration['course'] ?? '',
        $registration['year_section'] ?? '',
        $registration['component'] ?? 'Public Registration'
    );
}

function studentAutomationCourseSection(PDO $conn, array $registration) {
    if (!empty($registration['_resolved_course_section'])) {
        return (string) $registration['_resolved_course_section'];
    }

    $component = normalizeProgram($registration['component'] ?? null) ?: 'PUBLIC';
    $originalSection = studentAutomationOriginalSection($registration);

    if (autoSectionUsesAutomaticFolders($component)) {
        return autoSectionFolderForStudent(
            $conn,
            $component,
            $registration['course'] ?? '',
            $registration['year_section'] ?? '',
            $originalSection,
            $registration['college'] ?? ''
        );
    }

    return $component;
}

function ensureStudentQrRecordForAccount(PDO $conn, $studentNumber, $userId = null, ?array $registration = null) {
    $studentNumber = preg_replace('/\D/', '', (string) $studentNumber);
    if (!preg_match('/^\d{10}$/', $studentNumber)) {
        return null;
    }

    if ($registration === null) {
        $registration = studentAutomationLatestRegistration($conn, $studentNumber);
    }

    if (!$registration) {
        if (!$userId) {
            return null;
        }

        $stmt = $conn->prepare("SELECT user_id, full_name, program FROM tbl_users WHERE user_id = ? AND role = 'student' LIMIT 1");
        $stmt->execute([(int) $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }

        $fallbackSection = normalizeProgram($user['program'] ?? null) ?: 'PUBLIC';
        $fallbackName = trim((string) ($user['full_name'] ?? '')) ?: 'Student #' . $studentNumber;
        $generatedCode = 'PUB_' . $studentNumber;

        $stmt = $conn->prepare("
            SELECT tbl_student_id, generated_code
            FROM tbl_student
            WHERE student_number = ?
            LIMIT 1
        ");
        $stmt->execute([$studentNumber]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($student) {
            $setFields = ['user_id = COALESCE(NULLIF(user_id, 0), ?)', 'student_name = ?'];
            $params = [(int) $userId, $fallbackName];
            if (trim((string) ($student['generated_code'] ?? '')) === '') {
                $setFields[] = 'generated_code = ?';
                $params[] = $generatedCode;
            }
            $params[] = (int) $student['tbl_student_id'];

            $stmt = $conn->prepare("UPDATE tbl_student SET " . implode(', ', $setFields) . " WHERE tbl_student_id = ?");
            $stmt->execute($params);
            return (int) $student['tbl_student_id'];
        }

        $stmt = $conn->prepare("
            INSERT INTO tbl_student (user_id, student_number, student_name, original_section, course_section, generated_code, qr_code, created_by)
            VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)
        ");
        $stmt->execute([
            (int) $userId,
            $studentNumber,
            $fallbackName,
            $fallbackSection,
            $fallbackSection,
            $generatedCode,
        ]);

        return (int) $conn->lastInsertId();
    }

    $studentName = studentAutomationFullName($registration, $studentNumber);
    $originalSection = studentAutomationOriginalSection($registration);
    $courseSection = studentAutomationCourseSection($conn, $registration);
    if ($originalSection === '' || strtoupper($originalSection) === 'N/A') {
        $originalSection = $courseSection;
    }

    $stmt = $conn->prepare("
        SELECT s.tbl_student_id, s.user_id, s.generated_code, s.created_by, creator.role AS creator_role
        FROM tbl_student s
        LEFT JOIN tbl_users creator ON creator.user_id = s.created_by
        WHERE s.student_number = ?
        LIMIT 1
    ");
    $stmt->execute([$studentNumber]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    $generatedCode = 'PUB_' . $studentNumber;
    $accountUserId = $userId ? (int) $userId : ($registration['user_id'] ?? null);

    if ($student) {
        $isAssignedToFacilitator = !empty($student['created_by']) && ($student['creator_role'] ?? '') === 'facilitator';
        $setFields = ['user_id = COALESCE(NULLIF(user_id, 0), ?)', 'student_name = ?', 'original_section = ?'];
        $params = [$accountUserId, $studentName, $originalSection];

        if (!$isAssignedToFacilitator) {
            $setFields[] = 'course_section = ?';
            $params[] = $courseSection;
        }

        if (trim((string) ($student['generated_code'] ?? '')) === '') {
            $setFields[] = 'generated_code = ?';
            $params[] = $generatedCode;
        }

        $params[] = (int) $student['tbl_student_id'];
        $stmt = $conn->prepare("UPDATE tbl_student SET " . implode(', ', $setFields) . " WHERE tbl_student_id = ?");
        $stmt->execute($params);
        return (int) $student['tbl_student_id'];
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_student (user_id, student_number, student_name, original_section, course_section, generated_code, qr_code, created_by)
        VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)
    ");
    $stmt->execute([
        $accountUserId,
        $studentNumber,
        $studentName,
        $originalSection,
        $courseSection,
        $generatedCode,
    ]);

    return (int) $conn->lastInsertId();
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

function studentRegistrationCredentialsWereSent(array $registration) {
    return (int) ($registration['email_sent'] ?? 0) === 1;
}

function resetStudentAccountPasswordAndEmail(PDO $conn, $studentNumber) {
    $studentNumber = preg_replace('/\D/', '', (string) $studentNumber);
    if (!preg_match('/^\d{10}$/', $studentNumber)) {
        return ['sent' => false, 'reason' => 'missing_student_number'];
    }

    $registration = studentAutomationLatestRegistration($conn, $studentNumber);
    if (!$registration) {
        return ['sent' => false, 'reason' => 'registration_not_found'];
    }

    $email = trim((string) ($registration['email'] ?? ''));
    if ($email === '' || isPlaceholderEmail($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'reason' => 'invalid_email'];
    }

    $stmt = $conn->prepare("SELECT user_id, password_hash, email, last_password_change FROM tbl_users WHERE username = ? AND role = 'student' LIMIT 1");
    $stmt->execute([$studentNumber]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId <= 0) {
        return ['sent' => false, 'reason' => 'account_not_found'];
    }

    ensureStudentQrRecordForAccount($conn, $studentNumber, $userId, $registration);

    if (empty($user['last_password_change']) && studentRegistrationCredentialsWereSent($registration)) {
        return ['sent' => false, 'reason' => 'temporary_password_still_active'];
    }

    $password = generateStudentAccountPassword();
    $stmt = $conn->prepare("UPDATE tbl_users SET password_hash = ?, email = ?, last_password_change = NULL WHERE user_id = ?");
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $email, $userId]);

    $sent = sendStudentAccountEmail($conn, $registration, $studentNumber, $password);
    if (!$sent) {
        $stmt = $conn->prepare("UPDATE tbl_users SET password_hash = ?, email = ?, last_password_change = ? WHERE user_id = ?");
        $stmt->execute([$user['password_hash'], $user['email'], $user['last_password_change'], $userId]);
    }

    return ['sent' => $sent, 'reason' => $sent ? 'resent' : (getAppMailLastError() ?: 'email_failed')];
}

function autoCreateStudentAccountIfEligible(PDO $conn, $studentNumber) {
    $studentNumber = preg_replace('/\D/', '', (string) $studentNumber);
    if (!preg_match('/^\d{10}$/', $studentNumber)) {
        return ['created' => false, 'reason' => 'missing_student_number'];
    }

    $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ? LIMIT 1");
    $stmt->execute([$studentNumber]);
    $existingUserId = $stmt->fetchColumn();
    if ($existingUserId) {
        ensureStudentQrRecordForAccount($conn, $studentNumber, (int) $existingUserId);
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

    $registration = studentAutomationLatestRegistration($conn, $studentNumber);
    if (!$registration) {
        return ['created' => false, 'reason' => 'registration_not_found', 'attendance_count' => $attendanceCount];
    }

    $fullName = studentAutomationFullName($registration, $studentNumber);

    $email = trim((string) ($registration['email'] ?? ''));
    if ($email === '') {
        $email = 'student' . $studentNumber . '@no-email.tau-nstp.local';
    }

    $password = generateStudentAccountPassword();
    $program = normalizeProgram($registration['component'] ?? null);
    // Automatic folder creation may run DDL and implicitly commit in MySQL.
    // Resolve it before opening the account transaction.
    $registration['_resolved_course_section'] = studentAutomationCourseSection($conn, $registration);

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

        ensureStudentQrRecordForAccount($conn, $studentNumber, $userId, $registration);

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
        ensureStudentQrRecordForAccount($conn, $studentNumber, (int) $existingUserId);
        return ['created' => false, 'user_id' => (int) $existingUserId, 'reason' => 'already_exists'];
    }

    $registration = studentAutomationLatestRegistration($conn, $studentNumber);
    if (!$registration) {
        return ['created' => false, 'reason' => 'registration_not_found'];
    }

    $fullName = studentAutomationFullName($registration, $studentNumber);

    $email = trim((string) ($registration['email'] ?? ''));
    if ($email === '') {
        $email = 'student' . $studentNumber . '@no-email.tau-nstp.local';
    }

    $password = generateStudentAccountPassword();
    $program = normalizeProgram($registration['component'] ?? null);
    // Automatic folder creation may run DDL and implicitly commit in MySQL.
    // Resolve it before opening the account transaction.
    $registration['_resolved_course_section'] = studentAutomationCourseSection($conn, $registration);

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

        ensureStudentQrRecordForAccount($conn, $studentNumber, $userId, $registration);

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
