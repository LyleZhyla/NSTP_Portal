<?php
require_once './conn/conn.php';

// Create admin table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS tbl_admin (
    tbl_admin_id INT PRIMARY KEY AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role ENUM('super_admin', 'coordinator', 'facilitator', 'student') DEFAULT 'facilitator',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

try {
    $conn->exec($sql);
    echo "Table created successfully.<br>";
} catch (PDOException $e) {
    die("Error creating table: " . $e->getMessage());
}

// Generate a password hash for 'admin123'
$password = 'admin123';
$password_hash = password_hash($password, PASSWORD_DEFAULT);

// Insert default super admin (username: superadmin, password: admin123)
$sql = "INSERT INTO tbl_admin (full_name, username, password_hash, email, role) 
        VALUES ('Super Administrator', 'superadmin', ?, 'admin@system.com', 'super_admin')
        ON DUPLICATE KEY UPDATE password_hash = ?";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute([$password_hash, $password_hash]);
    
    if ($stmt->rowCount() > 0) {
        echo "Default admin created successfully!<br>";
        echo "Username: superadmin<br>";
        echo "Password: admin123<br>";
        echo "Password Hash: " . $password_hash . "<br>";
    } else {
        echo "Admin already exists or could not be created.<br>";
    }
} catch (PDOException $e) {
    echo "Error creating admin: " . $e->getMessage() . "<br>";
}

// Show all admins in the table
echo "<br>Current admins in database:<br>";
$stmt = $conn->query("SELECT tbl_admin_id, full_name, username, email, role, status FROM tbl_admin");
$admins = $stmt->fetchAll();

foreach ($admins as $admin) {
    echo "ID: {$admin['tbl_admin_id']} | ";
    echo "Name: {$admin['full_name']} | ";
    echo "Username: {$admin['username']} | ";
    echo "Role: {$admin['role']}<br>";
}
?>
