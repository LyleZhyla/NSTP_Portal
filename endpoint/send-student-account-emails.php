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

    $registrationIds = $_POST['registration_ids'] ?? [];
    if (!is_array($registrationIds)) {
        $registrationIds = [];
    }
    $registrationIds = array_values(array_unique(array_filter(
        array_map('intval', $registrationIds),
        static fn($registrationId) => $registrationId > 0
    )));

    if (empty($registrationIds)) {
        throw new InvalidArgumentException('Select at least one student before sending emails.');
    }
    if (count($registrationIds) > 5) {
        throw new InvalidArgumentException('A maximum of 5 selected students can be processed per batch.');
    }

    $where = [
        "r.registration_id IN (" . implode(',', array_fill(0, count($registrationIds), '?')) . ")",
        "r.registrant_role = 'student'",
        "COALESCE(r.status, 'submitted') NOT IN ('attendance_only', 'account_deleted')",
    ];
    $params = $registrationIds;

    if (($currentUser['role'] ?? '') === 'coordinator') {
        $component = normalizeProgram($currentUser['program'] ?? null);
        if (!$component) {
            throw new RuntimeException('Your coordinator account has no assigned NSTP program.');
        }
        $where[] = 'r.component = ?';
        $params[] = $component;
    }

    $stmt = $conn->prepare("
        SELECT r.registration_id, r.student_number, r.email
        FROM tbl_public_student_registrations r
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.registration_id ASC
    ");
    $stmt->execute($params);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    $createdNoEmail = 0;
    $resent = 0;
    $activeTemporary = 0;
    $invalidEmail = 0;
    $failed = 0;
    $failureReasons = [];

    $processedStudentNumbers = [];
    foreach ($registrations as $registration) {
        $studentNumber = preg_replace('/\D/', '', (string) ($registration['student_number'] ?? ''));
        $email = trim((string) ($registration['email'] ?? ''));

        if (!preg_match('/^\d{10}$/', $studentNumber) || !filter_var($email, FILTER_VALIDATE_EMAIL) || isPlaceholderEmail($email)) {
            $invalidEmail++;
            continue;
        }
        if (isset($processedStudentNumbers[$studentNumber])) {
            continue;
        }
        $processedStudentNumbers[$studentNumber] = true;

        try {
            $result = autoCreateStudentAccountFromPublicRegistrations($conn, $studentNumber);

            if (!empty($result['created']) && !empty($result['email_sent'])) {
                $sent++;
            } elseif (!empty($result['created'])) {
                $createdNoEmail++;
                $reason = getAppMailLastError() ?: 'email_failed';
                $failureReasons[$reason] = ($failureReasons[$reason] ?? 0) + 1;
            } elseif (($result['reason'] ?? '') === 'already_exists') {
                $resendResult = resetStudentAccountPasswordAndEmail($conn, $studentNumber, true);
                if (!empty($resendResult['sent'])) {
                    $resent++;
                } elseif (($resendResult['reason'] ?? '') === 'temporary_password_still_active') {
                    $activeTemporary++;
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

    $notAccessible = count($registrationIds) - count($registrations);
    if ($notAccessible > 0) {
        $failed += $notAccessible;
        $failureReasons['Selected registration is unavailable or outside your assigned program'] = $notAccessible;
    }

    $message = "Selected student email batch finished. Sent: {$sent}.";
    if ($resent > 0) {
        $message .= " Existing accounts resent: {$resent}.";
    }
    if ($activeTemporary > 0) {
        $message .= " Active temporary passwords kept: {$activeTemporary}.";
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
        'active_temporary' => $activeTemporary,
        'created_no_email' => $createdNoEmail,
        'invalid_email' => $invalidEmail,
        'failed' => $failed,
        'failure_reasons' => $failureReasons,
        'processed' => count($registrationIds),
        'batch_limit' => 5,
        'pending_before' => count($registrationIds),
        'pending_after' => 0,
        'has_more' => false,
    ]);
} catch (Throwable $error) {
    error_log('Bulk student account email error: ' . $error->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to send account emails: ' . $error->getMessage()]);
}
