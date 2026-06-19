<?php
session_start();
// Include the logo functions
require_once 'include/logo-functions.php';

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['super_admin', 'coordinator'], true)) {
        header("Location: landing_page.php");
    } elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'student') {
        header("Location: student-dashboard.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

// Check for error/success messages
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
$success = isset($_GET['success']) ? htmlspecialchars($_GET['success']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TAU NSTP </title>
    
    <!-- Favicon and icon tags - YOUR CUSTOM LOGO IN BROWSER TAB -->
    <?php echo getFaviconTags(); ?>
    
    <!-- Alternative/Backup favicon using PHP generator -->

    <link rel="icon" type="image/png" sizes="32x32" href="include/logo.png">
    
    <!-- Google Fonts - Plus Jakarta Sans -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        /* GLOBAL STYLES */
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
            background: linear-gradient(135deg, #0f5132, #198754);
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
            background: linear-gradient(135deg, #0f5132, #198754);
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

        /* === RIGHT PANEL – FORM === */
        .right-panel {
            flex: 1.3;
            background: linear-gradient(165deg, #b8dbe7, #9fc7d5);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 3.5rem 2.5rem;
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
            margin-bottom: 2rem;
            text-align: center;
            border-bottom: 1.5px dashed #5c97a5;
            padding-bottom: 1rem;
            width: 100%;
            max-width: 280px;
        }

        /* FORM */
        .login-form {
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
            margin-bottom: 1.5rem;
            border-radius: 50px;
            overflow: hidden;
            border: 1.8px solid #7faebb;
            background: white;
            transition: all 0.2s ease;
            box-shadow: 0 6px 14px rgba(52, 104, 118, 0.08);
        }

        .input-group:focus-within {
            border-color: #198754;
            box-shadow: 0 8px 18px rgba(52, 152, 219, 0.2);
            background: white;
        }

        .input-group-text {
            background: white;
            border: none;
            color: #0f5132;
            padding: 0.95rem 1.2rem 0.95rem 1.5rem;
            font-size: 1rem;
            border-radius: 50px 0 0 50px;
        }

        .form-control {
            border: none;
            padding: 0.95rem 1rem 0.95rem 0.2rem;
            font-size: 0.98rem;
            font-weight: 400;
            color: #0f5132;
            background: white;
            border-radius: 0 50px 50px 0;
        }

        .form-control::placeholder {
            color: #699aa8;
            font-weight: 300;
            font-size: 0.9rem;
        }

        .form-control:focus {
            box-shadow: none;
            background: white;
            outline: none;
        }

        .password-toggle {
            background: white;
            border: none;
            color: #198754;
            padding: 0 1.4rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0 50px 50px 0;
            transition: color 0.2s;
            cursor: pointer;
        }

        .password-toggle:hover {
            color: #0f5132;
            background: #ecf6fa;
        }

        .form-helper {
            width: 100%;
            display: flex;
            justify-content: flex-end;
            margin-top: -0.6rem;
            margin-bottom: 1.2rem;
        }

        .form-helper a {
            color: #1a5565;
            font-size: 0.78rem;
            text-decoration: none;
            font-weight: 600;
            padding: 0.3rem 1.2rem;
            border-radius: 40px;
            background: rgba(255,255,255,0.6);
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            border: 1px solid #8ab3bf;
        }

        .form-helper a:hover {
            background: white;
            border-color: #198754;
            color: #0f5132;
        }

        .btn-login {
            background: linear-gradient(135deg, #0f5132, #198754);
            border: none;
            padding: 0.95rem 1.8rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: white;
            border-radius: 60px;
            width: 70%;
            transition: all 0.25s ease;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            border: 1px solid rgba(255,255,255,0.25);
            box-shadow: 0 10px 20px rgba(44,62,80,0.25);
            margin-top: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            cursor: pointer;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, #198754, #0f5132);
            transform: translateY(-3px);
            box-shadow: 0 16px 28px rgba(44,62,80,0.3);
        }

        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .landing-link {
            margin-top: 0.85rem;
            width: 100%;
            display: flex;
            justify-content: center;
        }

        .landing-link a {
            color: #1a5668;
            text-decoration: none;
            font-size: 0.86rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .landing-link a:hover {
            color: #0c404e;
            text-decoration: underline;
        }

        /* ALERTS */
        .alert {
            border-radius: 30px;
            padding: 0.8rem 1.3rem;
            margin-bottom: 1.5rem;
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
            margin-top: 2rem;
            font-size: 0.72rem;
            color: #1e5665;
            display: flex;
            justify-content: center;
            gap: 0.6rem;
            letter-spacing: 0.02em;
            font-weight: 500;
        }

        .footer-credit i {
            color: #198754;
        }

        /* RESPONSIVE */
        @media (max-width: 820px) {
            .split-wrapper {
                flex-direction: column;
                max-width: 500px;
            }
            .left-panel, .right-panel {
                padding: 2.5rem 1.8rem;
            }
            .btn-login {
                width: 80%;
            }
        }

        .form-control:-webkit-autofill,
        .form-control:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 100px white inset;
            -webkit-text-fill-color: #0f5132;
        }
        /* NSTP green uniform override */
        body {
            background: linear-gradient(145deg, #0f5132 0%, #198754 100%) !important;
        }
        .right-panel {
            background: linear-gradient(165deg, #e8f6ee, #cce8d6) !important;
        }
        .nstp-brand,
        .logo-text {
            background: linear-gradient(135deg, #0f5132, #198754) !important;
            -webkit-background-clip: text !important;
            -webkit-text-fill-color: transparent !important;
        }
        .nstp-badge,
        .login-btn,
        .btn-primary {
            background: linear-gradient(135deg, #0f5132, #198754) !important;
            border-color: #198754 !important;
        }
        .input-group:focus-within,
        .form-control:focus,
        .btn-outline-primary {
            border-color: #198754 !important;
            box-shadow: 0 8px 18px rgba(25, 135, 84, 0.18) !important;
        }
        .input-group-text,
        .password-toggle,
        .footer-credit i,
        .forgot-link,
        
        .footer-credit span,
        .left-tagline,
        .right-panel h3 {
            color: #0f5132 !important;
        }
    </style>

</head>

<body>

    <div class="split-wrapper">
        
        <!-- LEFT PANEL – NSTP LOGO -->
        <div class="left-panel">
            <div class="left-content">
                <!-- NSTP LOGO - NO CIRCLE -->
                <div class="custom-logo-container">
                    <img src="include/logo.png" alt="NSTP CWTS ROTC LTS Logo">
                </div>
                
                <!-- NSTP Branding -->
                <div class="nstp-brand"> TAU - NSTP</div>
                
                <div class="nstp-sub">
                    <span class="nstp-badge">CWTS</span>
                    <span class="nstp-badge">LTS</span>
                    <span class="nstp-badge">ROTC</span>
                </div>
                
                <div class="left-tagline">
                    <i class="fas fa-leaf me-2"></i> National Service Training Program
                </div>
                
                
            </div>
        </div>

        <!-- RIGHT PANEL – LOGIN FORM -->
        <div class="right-panel">
            <h3>Welcome</h3>
            <div class="right-sub">
                <i class="fas fa-arrow-right-to-bracket" style="margin-right:6px;"></i> access dashboard
            </div>

            <!-- ERROR ALERT -->
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo $error; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>
            
            <!-- SUCCESS ALERT -->
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>

            <!-- LOGIN FORM -->
            <form class="login-form" action="endpoint/login-user.php" method="POST">
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fas fa-envelope"></i>
                    </span>
                    <input type="text" name="username" class="form-control" placeholder="Username or email" required autofocus>
                </div>

                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fas fa-lock"></i>
                    </span>
                    <input type="password" name="password" id="password" class="form-control" placeholder="Password" required>
                    <button type="button" class="password-toggle" id="togglePassword">
                        <i class="fas fa-eye" id="toggleIcon"></i>
                    </button>
                </div>
                
                <div class="form-helper">
                    <a href="forgot-password.php">
                        <i class="fas fa-key"></i> Forgot Password?
                    </a>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    <i class="fas fa-arrow-right-to-bracket"></i> Sign In
                </button>
            </form>

            <div class="landing-link">
                <a href="landing_page.php">
                    <i class="fas fa-circle-info"></i> Learn about CWTS, LTS, and ROTC
                </a>
            </div>

            <div class="footer-credit">
                <i class="fas fa-qrcode"></i> TAU-NSTP  · v2.1
                <span style="color:#679aa5;">•</span>
                <span style="color: #0f5132;">National Service Training Program</span>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Auto dismiss alerts after 4 seconds
            setTimeout(function() {
                $('.alert').alert('close');
            }, 4000);

            // Password visibility toggle
            const togglePassword = document.getElementById('togglePassword');
            const password = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');

            if (togglePassword && password) {
                togglePassword.addEventListener('click', function() {
                    const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                    password.setAttribute('type', type);
                    
                    toggleIcon.classList.toggle('fa-eye');
                    toggleIcon.classList.toggle('fa-eye-slash');
                    
                    this.style.color = type === 'text' ? '#198754' : '#5f7469';
                });
            }

            // Loading state on form submit
            $('form').on('submit', function() {
                const btn = $(this).find('button[type="submit"]');
                btn.html('<i class="fas fa-spinner fa-spin me-2"></i> Signing in...');
                btn.prop('disabled', true);
            });

            // Input focus effects
            $('.form-control').on('focus', function() {
                $(this).closest('.input-group').css('border-color', '#198754');
            }).on('blur', function() {
                $(this).closest('.input-group').css('border-color', '#7faebb');
            });

            // Prevent multiple form submissions
            $('form').on('submit', function(e) {
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
