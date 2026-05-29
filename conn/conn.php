<?php
$host = 'localhost';
$dbname = 'qr_attendance_db';
$username = 'root'; // Change if needed
$password = ''; // Change if needed

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Set timezone
    date_default_timezone_set('Asia/Manila');
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>