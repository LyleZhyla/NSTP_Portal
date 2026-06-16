<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "NSTP Portal Hostinger Check\n";
echo "===========================\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "PDO loaded: " . (extension_loaded('pdo') ? 'yes' : 'no') . "\n";
echo "PDO MySQL loaded: " . (extension_loaded('pdo_mysql') ? 'yes' : 'no') . "\n\n";

try {
    require __DIR__ . '/conn/conn.php';
    echo "Database connection: OK\n";

    $tables = [
        'tbl_users',
        'tbl_student',
        'tbl_attendance',
        'tbl_attendance_archive',
        'tbl_public_student_registrations',
    ];

    foreach ($tables as $table) {
        try {
            $stmt = $conn->query("SELECT COUNT(*) FROM `$table`");
            echo "$table: OK (" . $stmt->fetchColumn() . " rows)\n";
        } catch (Throwable $error) {
            echo "$table: ERROR - " . $error->getMessage() . "\n";
        }
    }
} catch (Throwable $error) {
    echo "Fatal error: " . $error->getMessage() . "\n";
}

echo "\nDelete this file after checking.\n";
