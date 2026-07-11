<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/mailer.php';

function generateFacilitatorEmailTemporaryPassword($length = 12) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || ($currentUser['role'] ?? '') !== 'super_admin') {
    echo json_encode(['success' => false, 'message' => 'Only the super admin can send facilitator account emails in bulk']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$program = normalizeProgram($_POST['program'] ?? null);

try {
    $where = ["role = 'facilitator'"];
    $params = [];

    if ($program) {
        $where[] = 'program = ?';
        $params[] = $program;
    }

    $stmt = $conn->prepare("
        SELECT u.user_id, u.full_name, u.username, u.email, u.role, u.program, u.last_password_change,
               COALESCE(registration.credentials_email_sent, 0) AS credentials_email_sent
        FROM tbl_users u
        LEFT JOIN (
            SELECT user_id, MAX(email_sent) AS credentials_email_sent
            FROM tbl_public_student_registrations
            WHERE registrant_role = 'facilitator'
            GROUP BY user_id
        ) registration ON registration.user_id = u.user_id
        WHERE " . implode(' AND ', array_map(static fn($condition) => 'u.' . $condition, $where)) . "
        ORDER BY COALESCE(registration.credentials_email_sent, 0) ASC,
                 FIELD(u.program, 'CWTS', 'LTS', 'ROTC'), u.full_name, u.username
    ");
    $stmt->execute($params);
    $facilitators = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    $invalidEmail = 0;
    $activeTemporary = 0;
    $failed = 0;
    $failureReasons = [];

    foreach ($facilitators as $facilitator) {
        $email = trim((string) ($facilitator['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isPlaceholderEmail($email)) {
            $invalidEmail++;
            continue;
        }

        // A newly registered facilitator can still have an active temporary
        // password even when the original SMTP attempt failed. Retry those
        // unsent credentials with a fresh password instead of skipping them.
        if (empty($facilitator['last_password_change']) && !empty($facilitator['credentials_email_sent'])) {
            $activeTemporary++;
            continue;
        }

        $temporaryPassword = generateFacilitatorEmailTemporaryPassword();
        $passwordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);

        try {
            $conn->beginTransaction();

            $updateStmt = $conn->prepare("
                UPDATE tbl_users
                SET password_hash = ?, last_password_change = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ? AND role = 'facilitator'
            ");
            $updateStmt->execute([$passwordHash, (int) $facilitator['user_id']]);

            $emailSent = sendAccountCredentialsEmail(
                $email,
                $facilitator['full_name'] ?? '',
                $facilitator['username'] ?? '',
                $temporaryPassword,
                'facilitator'
            );

            if (!$emailSent) {
                $conn->rollBack();
                $failed++;
                $reason = getAppMailLastError() ?: 'email_failed';
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
                continue;
            }

            $markSentStmt = $conn->prepare("
                UPDATE tbl_public_student_registrations
                SET email_sent = 1
                WHERE user_id = ? AND registrant_role = 'facilitator'
            ");
            $markSentStmt->execute([(int) $facilitator['user_id']]);

            $conn->commit();
            $sent++;
        } catch (Throwable $error) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $failed++;
            $failureReasons[$error->getMessage()] = ($failureReasons[$error->getMessage()] ?? 0) + 1;
        }
    }

    if ($sent > 0) {
        logSystemEvent(
            $conn,
            'facilitator_credentials_bulk_emailed',
            'Sent new facilitator credentials to ' . $sent . ' account(s)' . ($program ? " ({$program})" : '')
        );
    }

    $scope = $program ?: 'all programs';
    $message = "Facilitator email process finished for {$scope}. Sent: {$sent}.";
    if ($invalidEmail > 0) {
        $message .= " Invalid emails skipped: {$invalidEmail}.";
    }
    if ($activeTemporary > 0) {
        $message .= " Active temporary passwords kept: {$activeTemporary}.";
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
        'processed' => count($facilitators),
        'sent' => $sent,
        'invalid_email' => $invalidEmail,
        'active_temporary' => $activeTemporary,
        'failed' => $failed,
        'failure_reasons' => $failureReasons,
    ]);
} catch (Throwable $error) {
    error_log('Bulk facilitator account email error: ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to send facilitator account emails: ' . $error->getMessage()]);
}
?>
