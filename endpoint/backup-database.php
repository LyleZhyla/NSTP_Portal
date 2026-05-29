<?php
session_start();
require_once '../conn/conn.php';
require_once '../include/user-permissions.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || $currentUser['role'] !== 'super_admin') {
    http_response_code(403);
    echo 'Unauthorized access';
    exit();
}

function quoteIdentifier($identifier) {
    return '`' . str_replace('`', '``', $identifier) . '`';
}

try {
    $databaseName = $conn->query('SELECT DATABASE()')->fetchColumn();
    $tablesStmt = $conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    $tables = [];

    while ($row = $tablesStmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $fileName = $databaseName . '_backup_' . date('Ymd_His') . '.sql';
    logSystemEvent($conn, 'database_backup_downloaded', 'Downloaded SQL backup ' . $fileName);

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "-- Database backup for " . $databaseName . "\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . " Asia/Manila\n\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "SET time_zone = \"+08:00\";\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        $quotedTable = quoteIdentifier($table);
        $createStmt = $conn->query('SHOW CREATE TABLE ' . $quotedTable);
        $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
        $createSql = $createRow['Create Table'] ?? array_values($createRow)[1];

        echo "-- --------------------------------------------------------\n";
        echo "-- Table structure for table {$quotedTable}\n\n";
        echo "DROP TABLE IF EXISTS {$quotedTable};\n";
        echo $createSql . ";\n\n";

        $rowsStmt = $conn->query('SELECT * FROM ' . $quotedTable);
        $columns = [];

        for ($i = 0; $i < $rowsStmt->columnCount(); $i++) {
            $meta = $rowsStmt->getColumnMeta($i);
            $columns[] = quoteIdentifier($meta['name']);
        }

        $rowCount = 0;
        while ($row = $rowsStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($rowCount === 0) {
                echo "-- Dumping data for table {$quotedTable}\n\n";
            }

            $values = [];
            foreach ($row as $value) {
                $values[] = $value === null ? 'NULL' : $conn->quote((string) $value);
            }

            echo 'INSERT INTO ' . $quotedTable . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
            $rowCount++;
        }

        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    exit();
} catch (Throwable $error) {
    http_response_code(500);
    echo 'Backup failed: ' . $error->getMessage();
    exit();
}
?>
