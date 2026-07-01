<?php
if (PHP_SAPI !== 'cli') {
    session_start();
    header('Content-Type: application/json');
}

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../conn/conn.php';
require_once __DIR__ . '/../include/notifications.php';

function absentNotificationRequestAuthorized(PDO $conn) {
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $configuredToken = trim((string) getSystemSetting($conn, 'absent_notification_cron_token', ''));
    $requestToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
    if ($configuredToken !== '' && hash_equals($configuredToken, $requestToken)) {
        return true;
    }

    if (empty($_SESSION['user_id'])) {
        return false;
    }

    $currentUser = getCurrentUserRecord($conn);
    return $currentUser && canAccessStaffTools($currentUser['role'] ?? '');
}

try {
    if (!absentNotificationRequestAuthorized($conn)) {
        $response = ['success' => false, 'message' => 'Unauthorized'];
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, json_encode($response, JSON_PRETTY_PRINT) . PHP_EOL);
            exit(1);
        }

        http_response_code(403);
        echo json_encode($response);
        exit;
    }

    $attendanceDate = $_GET['date'] ?? $_POST['date'] ?? null;
    if (PHP_SAPI === 'cli' && isset($argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])) {
        $attendanceDate = $argv[1];
    }

    $summary = processAbsentAttendanceNotifications($conn, $attendanceDate);
    $response = ['success' => true, 'summary' => $summary];

    if (PHP_SAPI === 'cli') {
        echo json_encode($response, JSON_PRETTY_PRINT) . PHP_EOL;
        exit;
    }

    echo json_encode($response);
} catch (Throwable $error) {
    $response = ['success' => false, 'message' => $error->getMessage()];

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, json_encode($response, JSON_PRETTY_PRINT) . PHP_EOL);
        exit(1);
    }

    http_response_code(500);
    echo json_encode($response);
}
