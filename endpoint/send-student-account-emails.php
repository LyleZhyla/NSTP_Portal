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

try {
    ensureStudentNumberColumn($conn);

    $component = null;
    if (($currentUser['role'] ?? '') === 'coordinator') {
        $component = normalizeProgram($currentUser['program'] ?? null);
    } else {
        $component = normalizeProgram($_POST['component'] ?? null);
    }

    $where = [
        "r.registrant_role = 'student'",
        "r.student_number REGEXP '^[0-9]{10}$'",
        "r.email IS NOT NULL",
        "r.email <> ''",
        "r.email NOT LIKE '%@no-email.tau-nstp.local'",
        "(r.email_sent IS NULL OR r.email_sent = 0)",
        "r.registration_id = (
            SELECT MAX(latest.registration_id)
            FROM tbl_public_student_registrations latest
            WHERE latest.student_number = r.student_number
        )",
    ];
    $params = [];

    if ($component) {
        $where[] = "r.component = ?";
        $params[] = $component;
    }

    $stmt = $conn->prepare("
        SELECT r.student_number, r.email
        FROM tbl_public_student_registrations r
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.created_at ASC
    ");
    $stmt->execute($params);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    $createdNoEmail = 0;
    $resent = 0;
    $invalidEmail = 0;
    $failed = 0;
    $failureReasons = [];

    foreach ($registrations as $registration) {
        $studentNumber = preg_replace('/\D/', '', (string) ($registration['student_number'] ?? ''));
        $email = trim((string) ($registration['email'] ?? ''));

        if (!preg_match('/^\d{10}$/', $studentNumber) || !filter_var($email, FILTER_VALIDATE_EMAIL) || isPlaceholderEmail($email)) {
            $invalidEmail++;
            continue;
        }

        try {
            $result = autoCreateStudentAccountFromPublicRegistrations($conn, $studentNumber);

            if (!empty($result['created']) && !empty($result['email_sent'])) {
                $sent++;
            } elseif (!empty($result['created'])) {
                $createdNoEmail++;
                $reason = getAppMailLastError() ?: 'email_failed';
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
            } elseif (($result['reason'] ?? '') === 'already_exists') {
                $resendResult = resetStudentAccountPasswordAndEmail($conn, $studentNumber);
                if (!empty($resendResult['sent'])) {
                    $resent++;
                } else {
                    $failed++;
                    $reason = $resendResult['reason'] ?? 'resend_failed';
                    $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
                }
            } else {
                $failed++;
                $reason = $result['reason'] ?? 'unknown';
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
            }
        } catch (Throwable $error) {
            error_log('Student account email send failed for ' . $studentNumber . ': ' . $error->getMessage());
            $failed++;
            $failureReasons[$error->getMessage()] = ($failureReasons[$error->getMessage()] ?? 0) + 1;
        }
    }

    $message = "Account email process finished. Sent: {$sent}.";
    if ($resent > 0) {
        $message .= " Existing accounts resent: {$resent}.";
    }
    if ($createdNoEmail > 0) {
        $message .= " Created but email failed: {$createdNoEmail}.";
    }
    if ($invalidEmail > 0) {
        $message .= " Invalid emails skipped: {$invalidEmail}.";
    }
    if ($failed > 0) {
        $message .= " Failed: {$failed}.";
    }
    if (!empty($failureReasons)) {
        $message .= " Reason: " . implode('; ', array_map(
            static fn($reason, $count) => $reason . ' (' . $count . ')',
            array_keys($failureReasons),
            $failureReasons
        )) . ".";
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'sent' => $sent,
        'resent' => $resent,
        'created_no_email' => $createdNoEmail,
        'invalid_email' => $invalidEmail,
        'failed' => $failed,
        'failure_reasons' => $failureReasons,
        'processed' => count($registrations),
    ]);
} catch (Throwable $error) {
    error_log('Bulk student account email error: ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to send account emails: ' . $error->getMessage()]);
}
