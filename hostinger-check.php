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

function maskCheckValue($value) {
    $value = (string) $value;
    $length = strlen($value);

    if ($length <= 4) {
        return str_repeat('*', $length);
    }

    return substr($value, 0, 2) . str_repeat('*', max(4, $length - 4)) . substr($value, -2);
}

function runMailDiagnostics() {
    echo "Mail configuration\n";
    echo "==================\n";

    try {
        require_once __DIR__ . '/vendor/autoload.php';
        require_once __DIR__ . '/include/mailer.php';

        $mailConfig = appMailConfig();
        echo "SMTP configured: " . (appMailerIsConfigured() ? 'yes' : 'no') . "\n";
        echo "SMTP host: " . ($mailConfig['host'] ?: '(empty)') . "\n";
        echo "SMTP port: " . ($mailConfig['port'] ?: '(empty)') . "\n";
        echo "SMTP encryption: " . ($mailConfig['encryption'] ?: '(none)') . "\n";
        echo "SMTP username: " . ($mailConfig['username'] ? maskCheckValue($mailConfig['username']) : '(empty)') . "\n";
        echo "From email: " . ($mailConfig['from_email'] ?: '(empty)') . "\n";

        if (!appMailerIsConfigured()) {
            echo "SMTP check: SKIPPED - incomplete mail settings\n";
            return;
        }

        $smtp = new PHPMailer\PHPMailer\SMTP();
        $smtp->do_debug = 0;
        $timeout = 15;
        $host = (string) $mailConfig['host'];
        $port = (int) $mailConfig['port'];
        $encryption = strtolower((string) $mailConfig['encryption']);

        if ($encryption === 'ssl') {
            $host = 'ssl://' . preg_replace('/^ssl:\/\//', '', $host);
        }

        if (!$smtp->connect($host, $port, $timeout)) {
            $smtpError = $smtp->getError();
            echo "SMTP check: FAILED - cannot connect to host/port";
            if (!empty($smtpError['error'])) {
                echo " (" . $smtpError['error'] . ")";
            }
            echo "\n";
        } elseif (!$smtp->hello('localhost')) {
            echo "SMTP check: FAILED - SMTP hello failed\n";
            $smtp->quit(true);
        } elseif ($encryption === 'tls' && !$smtp->startTLS()) {
            echo "SMTP check: FAILED - STARTTLS failed\n";
            $smtp->quit(true);
        } else {
            if ($encryption === 'tls' && !$smtp->hello('localhost')) {
                echo "SMTP check: FAILED - SMTP hello after STARTTLS failed\n";
            } elseif ($smtp->authenticate((string) $mailConfig['username'], (string) $mailConfig['password'])) {
                echo "SMTP check: OK - authentication successful\n";
            } else {
                $smtpError = $smtp->getError();
                echo "SMTP check: FAILED - authentication failed";
                if (!empty($smtpError['error'])) {
                    echo " (" . $smtpError['error'] . ")";
                }
                echo "\n";
            }

            $smtp->quit(true);
        }
    } catch (Throwable $error) {
        echo "SMTP check: ERROR - " . $error->getMessage() . "\n";
    }
}

runMailDiagnostics();
echo "\nDatabase\n";
echo "========\n";

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
    echo "Database connection: ERROR - " . $error->getMessage() . "\n";
}

echo "\nDelete this file after checking.\n";
