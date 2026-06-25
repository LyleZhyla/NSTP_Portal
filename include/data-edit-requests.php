<?php

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/user-permissions.php';

function ensureDataEditRequestsTable(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_data_edit_requests (
            request_id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            current_data JSON NOT NULL,
            requested_data JSON NOT NULL,
            reason TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            review_note TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status_created (status, created_at),
            INDEX idx_user_status (user_id, status),
            INDEX idx_reviewed_by (reviewed_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function dataEditRequestClean($value, $maxLength = 255) {
    $value = trim((string) $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $maxLength)
        : substr($value, 0, $maxLength);
}

function dataEditRequestSuperAdmins(PDO $conn) {
    $stmt = $conn->prepare("
        SELECT user_id, full_name, email
        FROM tbl_users
        WHERE role = 'super_admin'
        ORDER BY user_id
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dataEditRequestPendingForUser(PDO $conn, $userId) {
    ensureDataEditRequestsTable($conn);

    $stmt = $conn->prepare("
        SELECT *
        FROM tbl_data_edit_requests
        WHERE user_id = ? AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([(int) $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function submitDataEditRequest(PDO $conn, array $user, array $requestedData, $reason = '') {
    ensureDataEditRequestsTable($conn);

    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user account.');
    }

    if (dataEditRequestPendingForUser($conn, $userId)) {
        throw new RuntimeException('You already have a pending data edit request.');
    }

    $currentData = [
        'full_name' => dataEditRequestClean($user['full_name'] ?? ''),
        'username' => dataEditRequestClean($user['username'] ?? ''),
        'email' => dataEditRequestClean($user['email'] ?? ''),
    ];

    $newData = [
        'full_name' => dataEditRequestClean($requestedData['full_name'] ?? ''),
        'username' => dataEditRequestClean($requestedData['username'] ?? ''),
        'email' => dataEditRequestClean($requestedData['email'] ?? ''),
    ];

    if ($newData['full_name'] === '' || $newData['username'] === '' || $newData['email'] === '') {
        throw new InvalidArgumentException('Full name, username, and email are required.');
    }

    if (!filter_var($newData['email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid email address.');
    }

    if ($newData === $currentData) {
        throw new InvalidArgumentException('No profile changes were requested.');
    }

    $checkStmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE (username = ? OR email = ?) AND user_id != ? LIMIT 1");
    $checkStmt->execute([$newData['username'], $newData['email'], $userId]);
    if ($checkStmt->fetchColumn()) {
        throw new RuntimeException('Username or email already exists.');
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_data_edit_requests (user_id, current_data, requested_data, reason)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        json_encode($currentData, JSON_UNESCAPED_SLASHES),
        json_encode($newData, JSON_UNESCAPED_SLASHES),
        dataEditRequestClean($reason, 2000),
    ]);
    $requestId = (int) $conn->lastInsertId();

    $requesterName = $currentData['full_name'] ?: $currentData['username'];
    foreach (dataEditRequestSuperAdmins($conn) as $superAdmin) {
        createUserNotification(
            $conn,
            (int) $superAdmin['user_id'],
            'data_edit_request',
            'Data Edit Request',
            $requesterName . ' requested changes to their account data.',
            'tbl_data_edit_requests',
            $requestId
        );
    }

    logSystemEvent($conn, 'data_edit_request_submitted', 'User #' . $userId . ' submitted data edit request #' . $requestId);
    return $requestId;
}

function dataEditRequestDecode($json) {
    $data = json_decode((string) $json, true);
    return is_array($data) ? $data : [];
}

function dataEditRequestList(PDO $conn, $status = 'pending') {
    ensureDataEditRequestsTable($conn);
    $status = strtolower(trim((string) $status));
    $allowed = ['pending', 'approved', 'rejected', 'all'];
    if (!in_array($status, $allowed, true)) {
        $status = 'pending';
    }

    $where = $status === 'all' ? '1=1' : 'r.status = ?';
    $params = $status === 'all' ? [] : [$status];

    $stmt = $conn->prepare("
        SELECT r.*, u.full_name, u.username, u.email, u.role, reviewer.full_name AS reviewer_name
        FROM tbl_data_edit_requests r
        INNER JOIN tbl_users u ON u.user_id = r.user_id
        LEFT JOIN tbl_users reviewer ON reviewer.user_id = r.reviewed_by
        WHERE {$where}
        ORDER BY FIELD(r.status, 'pending', 'approved', 'rejected'), r.created_at DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dataEditRequestReview(PDO $conn, $requestId, array $reviewer, $action, $note = '') {
    ensureDataEditRequestsTable($conn);

    $requestId = (int) $requestId;
    $action = strtolower(trim((string) $action));
    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new InvalidArgumentException('Invalid review action.');
    }

    if (($reviewer['role'] ?? '') !== 'super_admin') {
        throw new RuntimeException('Only the super admin can review data edit requests.');
    }

    $stmt = $conn->prepare("
        SELECT r.*, u.role AS user_role
        FROM tbl_data_edit_requests r
        INNER JOIN tbl_users u ON u.user_id = r.user_id
        WHERE r.request_id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        throw new RuntimeException('Data edit request not found.');
    }

    if (($request['status'] ?? '') !== 'pending') {
        throw new RuntimeException('This request has already been reviewed.');
    }

    $newData = dataEditRequestDecode($request['requested_data'] ?? '');
    $status = $action === 'approve' ? 'approved' : 'rejected';
    $note = dataEditRequestClean($note, 2000);

    $conn->beginTransaction();
    try {
        if ($action === 'approve') {
            $checkStmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE (username = ? OR email = ?) AND user_id != ? LIMIT 1");
            $checkStmt->execute([$newData['username'] ?? '', $newData['email'] ?? '', (int) $request['user_id']]);
            if ($checkStmt->fetchColumn()) {
                throw new RuntimeException('Username or email is already used by another account.');
            }

            $updateStmt = $conn->prepare("UPDATE tbl_users SET full_name = ?, username = ?, email = ? WHERE user_id = ?");
            $updateStmt->execute([
                $newData['full_name'] ?? '',
                $newData['username'] ?? '',
                $newData['email'] ?? '',
                (int) $request['user_id'],
            ]);

            $syncStmt = $conn->prepare("UPDATE tbl_student SET student_name = ? WHERE user_id = ?");
            $syncStmt->execute([$newData['full_name'] ?? '', (int) $request['user_id']]);
        }

        $reviewStmt = $conn->prepare("
            UPDATE tbl_data_edit_requests
            SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
            WHERE request_id = ?
        ");
        $reviewStmt->execute([$status, (int) $reviewer['user_id'], $note, $requestId]);

        createUserNotification(
            $conn,
            (int) $request['user_id'],
            'data_edit_request_' . $status,
            'Data Edit Request ' . ucfirst($status),
            $action === 'approve'
                ? 'Your account data edit request was approved.'
                : 'Your account data edit request was rejected.' . ($note !== '' ? ' Note: ' . $note : ''),
            'tbl_data_edit_requests',
            $requestId
        );

        $conn->commit();
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }

    logSystemEvent($conn, 'data_edit_request_' . $status, 'Request #' . $requestId . ' was ' . $status);
    return $status;
}
