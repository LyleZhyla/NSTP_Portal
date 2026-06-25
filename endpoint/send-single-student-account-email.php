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

    if (!preg_match('/^\d{10}$/', $studentNumber)) {
        echo json_encode(['success' => false, 'message' => 'This registration has no valid student number.']);
        exit();
    }

    if ($email === '' || isPlaceholderEmail($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'This registration has no valid recipient email.']);
        exit();
    }

    $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ? AND role = 'student' LIMIT 1");
    $stmt->execute([$studentNumber]);
    $userId = (int) $stmt->fetchColumn();

    if ($userId <= 0) {
        $createResult = autoCreateStudentAccountFromPublicRegistrations($conn, $studentNumber);
        if (empty($createResult['user_id'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Student account could not be created: ' . ($createResult['reason'] ?? 'unknown reason'),
            ]);
            exit();
        }
        $userId = (int) $createResult['user_id'];
    }

    $password = generateStudentAccountPassword();
    $stmt = $conn->prepare("UPDATE tbl_users SET password_hash = ?, email = ? WHERE user_id = ?");
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $email, $userId]);

    $sent = sendStudentAccountEmail($conn, $registration, $studentNumber, $password);
    if (!$sent) {
        echo json_encode(['success' => false, 'message' => getAppMailLastError() ?: 'Email failed to send.']);
        exit();
    }

    echo json_encode(['success' => true, 'message' => 'Credentials were sent to ' . $email . '.']);
} catch (Throwable $error) {
    error_log('Single student account email error: ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to send credentials: ' . $error->getMessage()]);
}
