<?php
session_start();
require_once 'conn/conn.php';

$error = '';
$success = '';
$token = isset($_GET['token']) ? $_GET['token'] : '';

// Verify token
if (!empty($token)) {
    try {
        $check_token_query = "SELECT pr.*, tu.full_name, tu.email, tu.user_id 
                             FROM password_resets pr
                             JOIN tbl_users tu ON pr.email = tu.email
                             WHERE pr.token = :token AND pr.expires_at > NOW()";
        $stmt = $conn->prepare($check_token_query);
        $stmt->execute([':token' => $token]);
        $token_data = $stmt->fetch();
        
        if (!$token_data) {
            $error = "Invalid or expired reset token. Please request a new password reset.";
        }
    } catch (PDOException $e) {
        error_log("Token verification error: " . $e->getMessage());
        $error = "An error occurred. Please try again later.";
    }
} else {
    $error = "No reset token provided.";
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error) && isset($token_data)) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($password) || empty($confirm_password)) {
        $error = "Please fill in all fields";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } else {
        try {
            // Hash new password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Update password in tbl_users
            $update_query = "UPDATE tbl_users SET password_hash = :password, last_password_change = NOW() WHERE email = :email";
            $update_stmt = $conn->prepare($update_query);
            
            if ($update_stmt->execute([
                ':password' => $hashed_password,
                ':email' => $token_data['email']
            ])) {
                // Delete used token
                $delete_query = "DELETE FROM password_resets WHERE token = :token";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->execute([':token' => $token]);
                
                $success = "Password has been successfully reset. You can now login with your new password.";
                
                // Redirect to login page after 3 seconds
                header("refresh:3;url=index.php");
            } else {
                $error = "Failed to reset password. Please try again.";
            }
        } catch (PDOException $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error = "An error occurred. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - QR Code Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            animation: slideIn 0.5s ease;
        }
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            border-radius: 15px 15px 0 0 !important;
            padding: 25px;
        }
        .card-header h3 {
            margin: 0;
            font-weight: 600;
            font-size: 24px;
        }
        .card-header p {
            margin: 10px 0 0;
            opacity: 0.9;
        }
        .card-body {
            padding: 40px;
        }
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
        }
        .form-control {
            height: 50px;
            border-radius: 10px;
            border: 2px solid #e0e0e0;
            padding: 10px 20px;
            font-size: 16px;
            transition: all 0.3s;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.1);
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            height: 50px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
        .alert {
            border-radius: 10px;
            border: none;
            padding: 15px 20px;
        }
        .password-requirements {
            font-size: 12px;
            color: #6c757d;
            margin-top: 8px;
            padding-left: 20px;
        }
        .password-requirements li {
            margin-bottom: 4px;
        }
        .password-requirements i {
            margin-right: 8px;
            font-size: 10px;
        }
        .valid-feedback, .invalid-feedback {
            display: none;
            font-size: 12px;
            margin-top: 5px;
        }
        .is-valid {
            border-color: #28a745 !important;
        }
        .is-invalid {
            border-color: #dc3545 !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-key fa-3x mb-3"></i>
                        <h3>Reset Password</h3>
                        <p class="mb-0">Enter your new password</p>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                            <?php if (strpos($error, 'expired') !== false || strpos($error, 'token') !== false): ?>
                                <div class="text-center mt-3">
                                    <a href="forgot-password.php" class="btn btn-primary">
                                        <i class="fas fa-redo-alt me-2"></i>Request New Reset Link
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                <?php echo $success; ?>
                            </div>
                            <div class="text-center mt-3">
                                <a href="index.php" class="btn btn-primary">
                                    <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                                </a>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (empty($error) && empty($success) && isset($token_data)): ?>
                            <form method="POST" action="" id="resetPasswordForm">
                                <div class="mb-3">
                                    <label for="password" class="form-label">
                                        <i class="fas fa-lock me-2"></i>New Password
                                    </label>
                                    <input type="password" class="form-control" id="password" name="password" 
                                           required minlength="8" placeholder="Enter new password">
                                    <ul class="password-requirements">
                                        <li id="length-check">
                                            <i class="fas fa-circle text-muted"></i>
                                            At least 8 characters long
                                        </li>
                                        <li id="uppercase-check">
                                            <i class="fas fa-circle text-muted"></i>
                                            At least one uppercase letter
                                        </li>
                                        <li id="lowercase-check">
                                            <i class="fas fa-circle text-muted"></i>
                                            At least one lowercase letter
                                        </li>
                                        <li id="number-check">
                                            <i class="fas fa-circle text-muted"></i>
                                            At least one number
                                        </li>
                                    </ul>
                                </div>
                                <div class="mb-4">
                                    <label for="confirm_password" class="form-label">
                                        <i class="fas fa-lock me-2"></i>Confirm Password
                                    </label>
                                    <input type="password" class="form-control" id="confirm_password" 
                                           name="confirm_password" required minlength="8" 
                                           placeholder="Confirm new password">
                                    <div class="invalid-feedback" id="passwordMatchFeedback">
                                        Passwords do not match
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary w-100" id="submitBtn">
                                    <i class="fas fa-save me-2"></i>Reset Password
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if (empty($error) && empty($success) && isset($token_data)): ?>
    <script>
        // Password strength checker
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const submitBtn = document.getElementById('submitBtn');
        
        // Requirement elements
        const lengthCheck = document.getElementById('length-check');
        const uppercaseCheck = document.getElementById('uppercase-check');
        const lowercaseCheck = document.getElementById('lowercase-check');
        const numberCheck = document.getElementById('number-check');
        const passwordMatchFeedback = document.getElementById('passwordMatchFeedback');
        
        function checkPasswordStrength() {
            const pass = password.value;
            
            // Check length
            if (pass.length >= 8) {
                lengthCheck.innerHTML = '<i class="fas fa-check-circle text-success"></i> At least 8 characters long';
            } else {
                lengthCheck.innerHTML = '<i class="fas fa-circle text-muted"></i> At least 8 characters long';
            }
            
            // Check uppercase
            if (/[A-Z]/.test(pass)) {
                uppercaseCheck.innerHTML = '<i class="fas fa-check-circle text-success"></i> At least one uppercase letter';
            } else {
                uppercaseCheck.innerHTML = '<i class="fas fa-circle text-muted"></i> At least one uppercase letter';
            }
            
            // Check lowercase
            if (/[a-z]/.test(pass)) {
                lowercaseCheck.innerHTML = '<i class="fas fa-check-circle text-success"></i> At least one lowercase letter';
            } else {
                lowercaseCheck.innerHTML = '<i class="fas fa-circle text-muted"></i> At least one lowercase letter';
            }
            
            // Check number
            if (/[0-9]/.test(pass)) {
                numberCheck.innerHTML = '<i class="fas fa-check-circle text-success"></i> At least one number';
            } else {
                numberCheck.innerHTML = '<i class="fas fa-circle text-muted"></i> At least one number';
            }
            
            // Check if all requirements are met
            const isValid = pass.length >= 8 && /[A-Z]/.test(pass) && /[a-z]/.test(pass) && /[0-9]/.test(pass);
            
            if (isValid) {
                password.classList.add('is-valid');
                password.classList.remove('is-invalid');
            } else {
                password.classList.add('is-invalid');
                password.classList.remove('is-valid');
            }
            
            checkPasswordMatch();
        }
        
        function checkPasswordMatch() {
            if (confirmPassword.value) {
                if (password.value === confirmPassword.value) {
                    confirmPassword.classList.add('is-valid');
                    confirmPassword.classList.remove('is-invalid');
                    passwordMatchFeedback.style.display = 'none';
                } else {
                    confirmPassword.classList.add('is-invalid');
                    confirmPassword.classList.remove('is-valid');
                    passwordMatchFeedback.style.display = 'block';
                }
            } else {
                confirmPassword.classList.remove('is-valid', 'is-invalid');
                passwordMatchFeedback.style.display = 'none';
            }
            
            // Enable/disable submit button
            const isValid = password.value.length >= 8 && 
                           /[A-Z]/.test(password.value) && 
                           /[a-z]/.test(password.value) && 
                           /[0-9]/.test(password.value) && 
                           password.value === confirmPassword.value &&
                           confirmPassword.value.length > 0;
            
            submitBtn.disabled = !isValid;
        }
        
        password.addEventListener('input', checkPasswordStrength);
        confirmPassword.addEventListener('input', checkPasswordMatch);
        
        // Loading state on form submit
        document.getElementById('resetPasswordForm').addEventListener('submit', function(e) {
            if (!submitBtn.disabled) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Resetting...';
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>