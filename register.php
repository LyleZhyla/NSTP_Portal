<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - QR Code Attendance System</title>
    
    <!-- Google Fonts - Plus Jakarta Sans -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        /* GLOBAL STYLES - MATCHING LOGIN PAGE */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(145deg, #5f9db2 0%, #3e7a8c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            margin: 0;
        }

        /* MAIN WRAPPER – TWO PANEL */
        .split-wrapper {
            display: flex;
            width: 100%;
            max-width: 1120px;
            background: #ffffff;
            border-radius: 40px;
            box-shadow: 0 25px 50px -8px rgba(21, 66, 80, 0.25);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(2px);
        }

        /* === LEFT PANEL – NSTP LOGO === */
        .left-panel {
            flex: 1.1;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
            position: relative;
        }

        .left-content {
            text-align: center;
            max-width: 360px;
        }

        /* NSTP LOGO - NO CIRCLE, JUST THE LOGO */
        .custom-logo-container {
            width: 180px;
            height: 180px;
            margin: 0 auto 1.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: floatIcon 3.8s infinite ease-in-out;
        }

        .custom-logo-container img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 10px 20px rgba(44, 62, 80, 0.25));
            transition: transform 0.3s;
        }
        
        .custom-logo-container:hover img {
            transform: scale(1.05);
        }

        /* NSTP Branding Text */
        .nstp-brand {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            background: linear-gradient(135deg, #2c3e50, #3498db);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.3rem;
            line-height: 1.2;
        }

        .nstp-sub {
            display: flex;
            justify-content: center;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 0.8rem;
        }

        .nstp-badge {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            color: white;
            padding: 0.4rem 1.2rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            border: 1px solid rgba(255,255,255,0.3);
            box-shadow: 0 4px 10px rgba(44,62,80,0.2);
        }

        .left-tagline {
            font-size: 0.95rem;
            font-weight: 500;
            color: #2c6a7a;
            background: #def0f5;
            padding: 0.6rem 1.8rem;
            border-radius: 60px;
            display: inline-block;
            border: 1px solid #aacdd6;
            backdrop-filter: blur(2px);
            margin-top: 1.5rem;
        }

        @keyframes floatIcon {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-14px); }
            100% { transform: translateY(0px); }
        }

        /* === RIGHT PANEL – REGISTRATION FORM === */
        .right-panel {
            flex: 1.3;
            background: linear-gradient(165deg, #b8dbe7, #9fc7d5);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 2.5rem 2.5rem;
            position: relative;
            backdrop-filter: blur(4px);
        }

        .right-panel h3 {
            font-size: 2rem;
            font-weight: 700;
            color: #0c404e;
            letter-spacing: -0.01em;
            margin-bottom: 0.2rem;
            text-align: center;
        }

        .right-sub {
            color: #1a5668;
            font-size: 0.95rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
            text-align: center;
            border-bottom: 1.5px dashed #5c97a5;
            padding-bottom: 1rem;
            width: 100%;
            max-width: 280px;
        }

        /* FORM */
        .register-form {
            width: 100%;
            max-width: 380px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* INPUTS */
        .input-group {
            width: 100%;
            margin-bottom: 1.2rem;
            border-radius: 50px;
            overflow: hidden;
            border: 1.8px solid #7faebb;
            background: white;
            transition: all 0.2s ease;
            box-shadow: 0 6px 14px rgba(52, 104, 118, 0.08);
        }

        .input-group:focus-within {
            border-color: #3498db;
            box-shadow: 0 8px 18px rgba(52, 152, 219, 0.2);
            background: white;
        }

        .input-group-text {
            background: white;
            border: none;
            color: #2c3e50;
            padding: 0.85rem 1rem 0.85rem 1.5rem;
            font-size: 1rem;
            border-radius: 50px 0 0 50px;
        }

        .form-control {
            border: none;
            padding: 0.85rem 1rem 0.85rem 0.2rem;
            font-size: 0.95rem;
            font-weight: 400;
            color: #2c3e50;
            background: white;
            border-radius: 0 50px 50px 0;
        }

        .form-control::placeholder {
            color: #699aa8;
            font-weight: 300;
            font-size: 0.85rem;
        }

        .form-control:focus {
            box-shadow: none;
            background: white;
            outline: none;
        }

        .password-toggle {
            background: white;
            border: none;
            color: #3498db;
            padding: 0 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0 50px 50px 0;
            transition: color 0.2s;
            cursor: pointer;
        }

        .password-toggle:hover {
            color: #2c3e50;
            background: #ecf6fa;
        }

        /* Password strength indicator */
        .password-strength {
            width: 100%;
            height: 4px;
            background: #e0e0e0;
            border-radius: 2px;
            margin-top: -0.5rem;
            margin-bottom: 0.8rem;
            overflow: hidden;
        }

        .strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
            border-radius: 2px;
        }

        .strength-bar.weak {
            width: 33.33%;
            background-color: #e74c3c;
        }

        .strength-bar.medium {
            width: 66.66%;
            background-color: #f39c12;
        }

        .strength-bar.strong {
            width: 100%;
            background-color: #27ae60;
        }

        .strength-text {
            font-size: 0.7rem;
            margin-top: 0.2rem;
            margin-bottom: 0.5rem;
            text-align: right;
            color: #2c6a7a;
        }

        /* Validation feedback */
        .validation-feedback {
            font-size: 0.7rem;
            margin-top: -0.8rem;
            margin-bottom: 0.5rem;
            padding-left: 1.5rem;
            color: #e74c3c;
            text-align: left;
            width: 100%;
        }

        .validation-feedback.valid {
            color: #27ae60;
        }

        .btn-register {
            background: linear-gradient(135deg, #2c3e50, #3498db);
            border: none;
            padding: 0.85rem 1.8rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: white;
            border-radius: 60px;
            width: 80%;
            transition: all 0.25s ease;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            border: 1px solid rgba(255,255,255,0.25);
            box-shadow: 0 10px 20px rgba(44,62,80,0.25);
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            cursor: pointer;
        }

        .btn-register:hover {
            background: linear-gradient(135deg, #3498db, #2c3e50);
            transform: translateY(-3px);
            box-shadow: 0 16px 28px rgba(44,62,80,0.3);
        }

        .btn-register:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .login-link {
            margin-top: 1.5rem;
            width: 100%;
            display: flex;
            justify-content: center;
        }

        .login-link a {
            color: #2c3e50;
            text-decoration: none;
            font-size: 0.88rem;
            font-weight: 600;
            background: rgba(255,255,255,0.6);
            padding: 0.7rem 2rem;
            border-radius: 60px;
            border: 1px solid #80aeb9;
            display: inline-flex;
            align-items: center;
            gap: 0.7rem;
            transition: all 0.2s;
            backdrop-filter: blur(2px);
        }

        .login-link a:hover {
            background: white;
            border-color: #3498db;
            color: #2c3e50;
        }

        /* ALERTS */
        .alert {
            border-radius: 30px;
            padding: 0.8rem 1.3rem;
            margin-bottom: 1.2rem;
            border: none;
            font-size: 0.82rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.7rem;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(6px);
            border-left: 6px solid;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 8px 18px rgba(19, 73, 88, 0.08);
        }

        .alert-danger {
            color: #8e444c;
            border-left-color: #b14d56;
            background: #ffefed;
        }

        .alert-success {
            color: #266155;
            border-left-color: #2f8475;
            background: #e0f3ef;
        }

        .close {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.2rem;
            opacity: 0.5;
            color: inherit;
            cursor: pointer;
        }

        .close:hover {
            opacity: 1;
        }

        .footer-credit {
            margin-top: 1.5rem;
            font-size: 0.72rem;
            color: #1e5665;
            display: flex;
            justify-content: center;
            gap: 0.6rem;
            letter-spacing: 0.02em;
            font-weight: 500;
        }

        .footer-credit i {
            color: #3498db;
        }

        /* RESPONSIVE */
        @media (max-width: 820px) {
            .split-wrapper {
                flex-direction: column;
                max-width: 500px;
            }
            .left-panel, .right-panel {
                padding: 2rem 1.5rem;
            }
            .btn-register {
                width: 90%;
            }
        }

        .form-control:-webkit-autofill,
        .form-control:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 100px white inset;
            -webkit-text-fill-color: #2c3e50;
        }
    </style>
</head>
<body>

    <div class="split-wrapper">
        
        <!-- LEFT PANEL – NSTP LOGO (SAME AS LOGIN PAGE) -->
        <div class="left-panel">
            <div class="left-content">
                <!-- NSTP LOGO - NO CIRCLE -->
                <div class="custom-logo-container">
                    <img src="include/logo.png" alt="NSTP CWTS ROTC LTS Logo">
                </div>
                
                <!-- NSTP Branding -->
                <div class="nstp-brand">TAU - NSTP</div>
                
                <div class="nstp-sub">
                    <span class="nstp-badge">CWTS</span>
                    <span class="nstp-badge">ROTC</span>
                    <span class="nstp-badge">LTS</span>
                </div>
                
                <div class="left-tagline">
                    <i class="fas fa-qrcode me-2"></i> QR Attendance System
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL – REGISTRATION FORM -->
        <div class="right-panel">
            <h3>Create Account</h3>
            <div class="right-sub">
                <i class="fas fa-user-plus" style="margin-right:6px;"></i> join the system
            </div>

            <!-- ALERT CONTAINER (FOR JAVASCRIPT MESSAGES) -->
            <div id="messageAlert" class="alert" style="display: none;"></div>

            <!-- REGISTRATION FORM -->
            <form class="register-form" id="registerForm" method="POST" action="endpoint/register-user.php">
                <!-- Full Name -->
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fas fa-user"></i>
                    </span>
                    <input type="text" name="full_name" id="full_name" class="form-control" placeholder="Full Name" required>
                </div>

                <!-- Username -->
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fas fa-at"></i>
                    </span>
                    <input type="text" name="username" id="username" class="form-control" placeholder="Username" required>
                </div>

                <!-- Email -->
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fas fa-envelope"></i>
                    </span>
                    <input type="email" name="email" id="email" class="form-control" placeholder="Email address" required>
                </div>

                <!-- Password with toggle -->
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fas fa-lock"></i>
                    </span>
                    <input type="password" name="password" id="password" class="form-control" placeholder="Password" required>
                    <button type="button" class="password-toggle" id="togglePassword">
                        <i class="fas fa-eye" id="toggleIcon"></i>
                    </button>
                </div>

                <!-- Password strength indicator -->
                <div class="password-strength">
                    <div class="strength-bar" id="strengthBar"></div>
                </div>
                <div class="strength-text" id="strengthText"></div>

                <!-- Confirm Password -->
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fas fa-lock"></i>
                    </span>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Confirm Password" required>
                    <button type="button" class="password-toggle" id="toggleConfirmPassword">
                        <i class="fas fa-eye" id="toggleConfirmIcon"></i>
                    </button>
                </div>
                
                <!-- Validation feedback for confirm password -->
                <div class="validation-feedback" id="confirmFeedback"></div>

                <button type="submit" class="btn-register" id="registerBtn">
                    <i class="fas fa-user-plus"></i> Register
                </button>
            </form>

            <div class="login-link">
                <a href="login.php">
                    <i class="fas fa-arrow-right-to-bracket"></i> Back to Login
                </a>
            </div>

            <div class="footer-credit">
                <i class="fas fa-qrcode"></i> TAU-NSTP · v2.1
                <span style="color:#679aa5;">•</span>
                <span style="color: #2c3e50;">QR Attendance</span>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Password visibility toggles
            const togglePassword = document.getElementById('togglePassword');
            const password = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');

            if (togglePassword && password) {
                togglePassword.addEventListener('click', function() {
                    const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                    password.setAttribute('type', type);
                    
                    toggleIcon.classList.toggle('fa-eye');
                    toggleIcon.classList.toggle('fa-eye-slash');
                    
                    this.style.color = type === 'text' ? '#3498db' : '#568e9c';
                });
            }

            const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
            const confirmPassword = document.getElementById('confirm_password');
            const toggleConfirmIcon = document.getElementById('toggleConfirmIcon');

            if (toggleConfirmPassword && confirmPassword) {
                toggleConfirmPassword.addEventListener('click', function() {
                    const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
                    confirmPassword.setAttribute('type', type);
                    
                    toggleConfirmIcon.classList.toggle('fa-eye');
                    toggleConfirmIcon.classList.toggle('fa-eye-slash');
                    
                    this.style.color = type === 'text' ? '#3498db' : '#568e9c';
                });
            }

            // Password strength checker
            $('#password').on('input', function() {
                const password = $(this).val();
                const strengthBar = $('#strengthBar');
                const strengthText = $('#strengthText');
                
                // Check password strength
                let strength = 0;
                
                if (password.length >= 6) strength += 1;
                if (password.length >= 8) strength += 1;
                if (/[a-z]/.test(password)) strength += 1;
                if (/[A-Z]/.test(password)) strength += 1;
                if (/[0-9]/.test(password)) strength += 1;
                if (/[^a-zA-Z0-9]/.test(password)) strength += 1;
                
                // Remove all classes
                strengthBar.removeClass('weak medium strong');
                
                if (password.length === 0) {
                    strengthBar.css('width', '0');
                    strengthText.text('');
                    return;
                }
                
                // Determine strength level
                if (strength < 3) {
                    strengthBar.addClass('weak');
                    strengthText.text('Weak password');
                } else if (strength < 5) {
                    strengthBar.addClass('medium');
                    strengthText.text('Medium password');
                } else {
                    strengthBar.addClass('strong');
                    strengthText.text('Strong password');
                }
            });

            // Confirm password validation
            $('#confirm_password, #password').on('input', function() {
                const password = $('#password').val();
                const confirm = $('#confirm_password').val();
                const feedback = $('#confirmFeedback');
                
                if (confirm.length === 0) {
                    feedback.text('');
                    feedback.removeClass('valid');
                    return;
                }
                
                if (password === confirm) {
                    feedback.text('✓ Passwords match');
                    feedback.addClass('valid');
                } else {
                    feedback.text('✗ Passwords do not match');
                    feedback.removeClass('valid');
                }
            });

            // Form submission
            $('#registerForm').on('submit', async function(e) {
                e.preventDefault();
                
                const password = $('#password').val();
                const confirm = $('#confirm_password').val();
                const email = $('#email').val();
                const username = $('#username').val();
                const fullName = $('#full_name').val();
                const registerBtn = $('#registerBtn');
                const messageDiv = $('#messageAlert');
                
                // Client-side validation
                if (!fullName || !username || !email || !password || !confirm) {
                    showMessage('All fields are required', 'danger');
                    return;
                }
                
                // Email validation
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    showMessage('Please enter a valid email address', 'danger');
                    return;
                }
                
                // Username validation
                if (username.length < 3) {
                    showMessage('Username must be at least 3 characters', 'danger');
                    return;
                }
                
                // Password validation
                if (password.length < 6) {
                    showMessage('Password must be at least 6 characters', 'danger');
                    return;
                }
                
                if (password !== confirm) {
                    showMessage('Passwords do not match', 'danger');
                    return;
                }
                
                // Show loading state
                registerBtn.html('<i class="fas fa-spinner fa-spin me-2"></i> Registering...');
                registerBtn.prop('disabled', true);
                messageDiv.hide();
                
                try {
                    const formData = new FormData(this);
                    
                    const response = await fetch(this.action, {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        showMessage(data.message, 'success');
                        $('#registerForm')[0].reset();
                        $('#strengthBar').css('width', '0');
                        $('#strengthText').text('');
                        $('#confirmFeedback').text('');
                        
                        // Redirect to login after 2 seconds
                        setTimeout(() => {
                            window.location.href = 'login.php?success=' + encodeURIComponent('Registration successful! Please login.');
                        }, 2000);
                    } else {
                        showMessage(data.message, 'danger');
                        registerBtn.html('<i class="fas fa-user-plus"></i> Register');
                        registerBtn.prop('disabled', false);
                    }
                } catch (error) {
                    showMessage('An error occurred. Please try again.', 'danger');
                    registerBtn.html('<i class="fas fa-user-plus"></i> Register');
                    registerBtn.prop('disabled', false);
                }
            });
            
            function showMessage(message, type) {
                const messageDiv = $('#messageAlert');
                messageDiv.removeClass('alert-success alert-danger');
                messageDiv.addClass('alert-' + type);
                messageDiv.html('<i class="fas fa-' + (type === 'success' ? 'check-circle' : 'exclamation-triangle') + '"></i> ' + message + 
                    '<button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>');
                messageDiv.show();
                
                // Auto hide after 5 seconds
                setTimeout(() => {
                    messageDiv.fadeOut();
                }, 5000);
            }

            // Input focus effects
            $('.form-control').on('focus', function() {
                $(this).closest('.input-group').css('border-color', '#3498db');
            }).on('blur', function() {
                $(this).closest('.input-group').css('border-color', '#7faebb');
            });

            // Prevent multiple form submissions
            $('#registerForm').on('submit', function(e) {
                if ($(this).data('submitted') === true) {
                    e.preventDefault();
                } else {
                    $(this).data('submitted', true);
                }
            });
        });
    </script>
</body>
</html>