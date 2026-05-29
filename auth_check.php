<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: landing_page.php");
    exit();
}

// Optional: Check user role for specific pages
function checkAdminRole() {
    if ($_SESSION['role'] !== 'facilitator') {
        header("Location: index.php");
        exit();
    }
}
?>
