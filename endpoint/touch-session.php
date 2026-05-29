<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$_SESSION['last_activity'] = time();
echo json_encode(['success' => true]);
?>
