<?php
require_once 'conn/conn.php';

try {
    $stmt = $conn->prepare('SELECT username, email, role FROM tbl_users WHERE username = "admin"');
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "Admin user found: " . $user['username'] . " (" . $user['email'] . ") - Role: " . $user['role'] . "\n";
    } else {
        echo "No admin user found in database\n";
        echo "Creating default admin user...\n";
        
        // Create default admin user
        $stmt = $conn->prepare("
            INSERT INTO tbl_users (username, email, password_hash, full_name, role) 
            VALUES ('admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'super_admin')
        ");
        $stmt->execute();
        
        echo "Default admin user created successfully!\n";
        echo "Username: admin\n";
        echo "Password: admin123\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
