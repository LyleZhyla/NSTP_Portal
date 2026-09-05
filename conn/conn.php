<?php
$serverName = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocalhost = in_array($serverName, ['localhost', '127.0.0.1', '::1'], true)
    || strpos($serverName, 'localhost:') === 0
    || strpos($serverName, '127.0.0.1:') === 0;

if ($isLocalhost) {
    $host = 'localhost';
    $dbname = 'qr_attendance_db';
    $username = 'root';
    $password = '';
} else {
    $host = 'localhost';
    $dbname = 'u560685116_nstp_portal';
    $username = 'u560685116_tau_nstp';
    $password = 'S3pt3mb3r162002!';
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    date_default_timezone_set('Asia/Manila');
} catch (PDOException $e) {
    if (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['message' => 'The database is temporarily unavailable. Start MySQL or contact the server administrator.']);
        exit;
    }
    die("Connection failed: " . $e->getMessage());
}
?>
