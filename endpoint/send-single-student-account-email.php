<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/student-account-automation.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !in_array($currentUser['role'] ?? '', ['super_admin', 'coordinator'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$registrationId = (int) ($_POST['registration_id'] ?? 0);
if ($registrationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Registration record is required.']);
    exit();
}

try {
    ensureStudentNumberColumn($conn);

    $stmt = $conn->prepare("
        SELECT *
        FROM tbl_public_student_registrations
        WHERE registration_id = ?
        LIMIT 1
    ");
    $stmt->execute([$registrationId]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registration || ($registration['registrant_role'] ?? 'student') !== 'student') {
        echo json_encode(['success' => false, 'message' => 'Student registration was not found.']);
        exit();
    }

    if (($currentUser['role'] ?? '') === 'coordinator') {
        $coordinatorProgram = normalizeProgram($currentUser['program'] ?? null);
        if (!$coordinatorProgram || normalizeProgram($registration['component'] ?? null) !== $coordinatorProgram) {
            echo json_encode(['success' => false, 'message' => 'This student is outside your NSTP program.']);
            exit();
        }
    }

    $studentNumber = preg_replace('/\D/', '', (string) ($registration['student_number'] ?? ''));
    $email = trim((string) ($registration['email'] ?? ''));
    $canViewCredentials = ($currentUser['role'] ?? '') === 'super_admin';
    $hasValidRecipientEmail = $email !== ''
        && !isPlaceholderEmail($email)
        && filter_var($email, FILTER_VALIDATE_EMAIL);

    if (!preg_match('/^\d{10}$/', $studentNumber)) {
        echo json_encode(['success' => false, 'message' => 'This registration has no valid student number.']);
        exit();
    }

    if (!$hasValidRecipientEmail && !$canViewCredentials) {
        echo json_encode(['success' => false, 'message' => 'This registration has no valid recipient email.']);
        exit();
    }

    $stmt = $conn->prepare("SELECT user_id, password_hash, email, last_password_change FROM tbl_users WHERE username = ? AND role = 'student' LIMIT 1");
    $stmt->execute([$studentNumber]);
    $studentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    $userId = (int) ($studentUser['user_id'] ?? 0);

    if ($userId <= 0) {
        $createResult = autoCreateStudentAccountFromPublicRegistrations($conn, $studentNumber);
        if (empty($createResult['user_id'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Student account could not be created: ' . ($createResult['reason'] ?? 'unknown reason'),
            ]);
            exit();
        }

        echo json_encode([
            'success' => true,
            'email_sent' => !empty($createResult['email_sent']),
            'message' => !empty($createResult['email_sent'])
                ? 'Credentials were sent to ' . $email . '.'
                : (getAppMailLastError() ?: 'Student account was created, but the credentials email was not sent.'),
            'credentials' => $canViewCredentials
                ? [
                    'username' => $studentNumber,
                    'temporary_password' => $createResult['password'] ?? '',
                ]
                : null,
        ]);
        exit();
    } elseif (empty($studentUser['last_password_change']) && studentRegistrationCredentialsWereSent($registration)) {
        echo json_encode([
            'success' => false,
            'email_sent' => false,
            'message' => 'This student account still has an active temporary password. It will remain valid until the student changes it.',
        ]);
        exit();
    }

    $password = generateStudentAccountPassword();
    if ($hasValidRecipientEmail) {
        $stmt = $conn->prepare("UPDATE tbl_users SET password_hash = ?, email = ?, last_password_change = NULL WHERE user_id = ?");
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $email, $userId]);
    } else {
        $stmt = $conn->prepare("UPDATE tbl_users SET password_hash = ?, last_password_change = NULL WHERE user_id = ?");
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
    }

    $sent = $hasValidRecipientEmail
        ? sendStudentAccountEmail($conn, $registration, $studentNumber, $password)
        : false;
    $credentials = $canViewCredentials
        ? [
            'username' => $studentNumber,
            'temporary_password' => $password,
        ]
        : null;

    if (!$sent) {
        if ($canViewCredentials) {
            $message = $hasValidRecipientEmail
                ? (getAppMailLastError() ?: 'Email failed to send. The generated credentials are shown below.')
                : 'No valid recipient email. The generated credentials are shown below.';

            echo json_encode([
                'success' => true,
                'email_sent' => false,
                'message' => $message,
                'credentials' => $credentials,
            ]);
            exit();
        }

        $stmt = $conn->prepare("UPDATE tbl_users SET password_hash = ?, email = ?, last_password_change = ? WHERE user_id = ?");
        $stmt->execute([
            $studentUser['password_hash'],
            $studentUser['email'],
            $studentUser['last_password_change'],
            $userId,
        ]);

        echo json_encode([
            'success' => false,
            'email_sent' => false,
            'message' => getAppMailLastError() ?: 'Email failed to send.',
        ]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'email_sent' => true,
        'message' => 'Credentials were sent to ' . $email . '.',
        'credentials' => $credentials,
    ]);
} catch (Throwable $error) {
    error_log('Single student account email error: ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to send credentials: ' . $error->getMessage()]);
}
