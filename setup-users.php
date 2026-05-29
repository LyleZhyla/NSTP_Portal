<?php
require_once './conn/conn.php';

// Fix the superadmin password (currently stored as plain text)
$superadmin_password = 'admin123'; // Default password
$password_hash = password_hash($superadmin_password, PASSWORD_DEFAULT);

// Update superadmin password
$sql = "UPDATE tbl_users SET password_hash = ? WHERE username = 'superadmin'";
$stmt = $conn->prepare($sql);
$stmt->execute([$password_hash]);

echo "Superadmin password updated to hash: $password_hash<br>";

// Show all users
echo "<br>Current users in database:<br>";
$stmt = $conn->query("SELECT user_id, username, email, full_name, role FROM tbl_users");
$users = $stmt->fetchAll();

foreach ($users as $user) {
    echo "ID: {$user['user_id']} | ";
    echo "Name: {$user['full_name']} | ";
    echo "Username: {$user['username']} | ";
    echo "Email: {$user['email']} | ";
    echo "Role: {$user['role']}<br>";
}
?>