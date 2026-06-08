<?php
session_start();
require_once 'conn/conn.php';
require_once 'include/mailer.php';

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$error = '';
$success = '';
$local_reset_link = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } else {
        try {
            // Check if email exists in tbl_users
            $check_query = "SELECT user_id, full_name, email FROM tbl_users WHERE email = :email";
            $stmt = $conn->prepare($check_query);
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Generate unique token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Delete any existing tokens for this email
                $delete_query = "DELETE FROM password_resets WHERE email = :email";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->execute([':email' => $email]);
                
                // Insert new token
                $insert_query = "INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, :expires_at)";
                $insert_stmt = $conn->prepare($insert_query);
                
                if ($insert_stmt->execute([
                    ':email' => $email,
                    ':token' => $token,
                    ':expires_at' => $expires
                ])) {
                    // Create reset link
                    $reset_link = "http://" . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/reset-password.php?token=" . $token;
                    
                    $subject = "Password Reset Request - QR Code Attendance System";
                    $safeName = htmlspecialchars($user['full_name'] ?: 'User', ENT_QUOTES, 'UTF-8');
                    $safeLink = htmlspecialchars($reset_link, ENT_QUOTES, 'UTF-8');
                    $buttonHtml = renderEmailButton('Reset Your Password', $reset_link);
                    $bodyHtml = <<<HTML
<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:#26343d;">Hello {$safeName},</p>
<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#42515c;">We received a request to reset your password for the National Service Training Program.</p>
{$buttonHtml}
<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#42515c;">This link will expire in <strong>1 hour</strong>.</p>
<p style="margin:0 0 18px;font-size:14px;line-height:1.7;color:#667784;">If the button does not work, copy and paste this link into your browser:</p>
<p style="margin:0 0 18px;padding:14px;background:#f4f8fa;border:1px solid #dbe8ed;border-radius:8px;font-size:13px;line-height:1.6;word-break:break-all;color:#42515c;">{$safeLink}</p>
<p style="margin:0;font-size:15px;line-height:1.7;color:#42515c;">If you did not request this, you can safely ignore this email.</p>
HTML;
                    $htmlMessage = renderAppEmailTemplate(
                        'Password Reset Request',
                        'Use this secure link to reset your password. It expires in 1 hour.',
                        $bodyHtml,
                        'For your security, this password reset link expires after 1 hour.'
                    );
                    $textMessage = "Hello " . ($user['full_name'] ?: 'User') . ",\n\n"
                        . "You requested to reset your password. Open this link:\n\n"
                        . $reset_link . "\n\n"
                        . "This link will expire in 1 hour.\n"
                        . "If you did not request this, please ignore this email.\n\n"
                        . "Best regards,\nNational Service Training Program";

                    if (sendAppMail($email, $user['full_name'], $subject, $htmlMessage, $textMessage)) {
                        $success = "Password reset link has been sent to your email.";
                    } else {
                        $error = "Reset link was generated, but email delivery is not configured yet. Please contact the system administrator.";
                        if (in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)) {
                            $local_reset_link = $reset_link;
                        }
                    }
                } else {
                    $error = "Failed to generate reset link. Please try again.";
                }
            } else {
                // Don't reveal that email doesn't exist for security
                $success = "If your email exists in our system, you will receive a password reset link.";
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
    <title>Forgot Password - National Service Training Program</title>
    
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
            max-width: 1000px;
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

        /* === RIGHT PANEL – FORGOT PASSWORD FORM === */
        .right-panel {
            flex: 1.2;
            background: linear-gradient(165deg, #b8dbe7, #9fc7d5);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 3rem 2.5rem;
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
        .forgot-form {
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
            margin-bottom: 1.8rem;
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
            padding: 0.95rem 1rem 0.95rem 1.5rem;
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

        .info-text {
            width: 100%;
            text-align: left;
            margin-top: -1rem;
            margin-bottom: 1rem;
            padding-left: 1.2rem;
            font-size: 0.8rem;
            color: #1a5668;
            font-weight: 500;
        }

        .info-text i {
            color: #198754;
            margin-right: 0.4rem;
        }

        .btn-reset {
            background: linear-gradient(135deg, #0f5132, #198754);
            border: none;
            padding: 0.95rem 1.8rem;
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

        .btn-reset:hover {
            background: linear-gradient(135deg, #198754, #0f5132);
            transform: translateY(-3px);
            box-shadow: 0 16px 28px rgba(44,62,80,0.3);
        }

        .btn-reset:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .back-link {
            margin-top: 2rem;
            width: 100%;
            display: flex;
            justify-content: center;
        }

        .back-link a {
            color: #0f5132;
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

        .back-link a:hover {
            background: white;
            border-color: #198754;
            color: #0f5132;
        }

        /* ALERTS */
        .alert-custom {
            border-radius: 30px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border: none;
            font-size: 0.85rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.7rem;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(6px);
            border-left: 6px solid;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 8px 18px rgba(19, 73, 88, 0.08);
        }

        .alert-danger-custom {
            color: #8e444c;
            border-left-color: #b14d56;
            background: #ffefed;
        }

        .alert-success-custom {
            color: #266155;
            border-left-color: #2f8475;
            background: #e0f3ef;
        }

        .alert-custom .close-btn {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.2rem;
            opacity: 0.5;
            color: inherit;
            cursor: pointer;
        }

        .alert-custom .close-btn:hover {
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
            .btn-reset {
                width: 90%;
            }
        }

        .form-control:-webkit-autofill,
        .form-control:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 100px white inset;
            -webkit-text-fill-color: #0f5132;
        }

        /* Test link styling */
        .test-link {
            margin-top: 0.8rem;
            font-size: 0.75rem;
            word-break: break-all;
            background: rgba(255,255,255,0.5);
            padding: 0.5rem;
            border-radius: 20px;
        }
        .test-link a {
            color: #266155;
            text-decoration: underline;
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
        .reset-btn,
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
        .footer-credit i,
        .login-link {
            color: #198754 !important;
        }
        .footer-credit span,
        .left-tagline,
        .right-panel h3 {
            color: #0f5132 !important;
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
                    <i class="fas fa-leaf me-2"></i> National Service Training Program
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL – FORGOT PASSWORD FORM -->
        <div class="right-panel">
            <h3>Forgot Password?</h3>
            <div class="right-sub">
                <i class="fas fa-key" style="margin-right:6px;"></i> reset your password
            </div>

            <!-- ERROR ALERT -->
            <?php if ($error): ?>
                <div class="alert-custom alert-danger-custom" role="alert" id="errorAlert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>
                        <?php echo htmlspecialchars($error); ?>
                        <?php if ($local_reset_link): ?>
                            <br><small class="text-muted">Local test link: <a href="<?php echo htmlspecialchars($local_reset_link, ENT_QUOTES, 'UTF-8'); ?>" class="alert-link">reset password</a></small>
                        <?php endif; ?>
                    </span>
                    <button type="button" class="close-btn" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- SUCCESS ALERT -->
            <?php if ($success): ?>
                <div class="alert-custom alert-success-custom" role="alert" id="successAlert">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <?php echo htmlspecialchars($success); ?>
                        <?php if (strpos($success, 'reset link') !== false && isset($reset_link) && in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true)): ?>
                            <div class="test-link mt-2">
                                <small><a href="<?php echo htmlspecialchars($reset_link, ENT_QUOTES, 'UTF-8'); ?>" target="_blank">Click here to reset (test mode)</a></small>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="close-btn" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!$success || (strpos($success, 'exist in our system') !== false)): ?>
                <!-- FORGOT PASSWORD FORM -->
                <form class="forgot-form" method="POST" action="" id="forgotPasswordForm">
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-envelope"></i>
                        </span>
                        <input type="email" name="email" id="email" class="form-control" 
                               placeholder="Your email address" required 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                    
                    <div class="info-text">
                        <i class="fas fa-info-circle"></i> We'll send a password reset link to this email
                    </div>

                    <button type="submit" class="btn-reset" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> Send Reset Link
                    </button>
                </form>
            <?php endif; ?>

            <div class="back-link">
                <a href="login.php">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>

            <div class="footer-credit">
                <i class="fas fa-qrcode"></i> TAU-NSTP · v2.1
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
            // Auto dismiss alerts after 5 seconds (except the success message with test link)
            setTimeout(function() {
                $('#errorAlert').fadeOut();
                if (!$('#successAlert .test-link').length) {
                    $('#successAlert').fadeOut();
                }
            }, 5000);

            // Loading state on form submit
            $('#forgotPasswordForm').on('submit', function(e) {
                const email = $('#email').val().trim();
                
                // Basic email validation
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    e.preventDefault();
                    showTemporaryAlert('Please enter a valid email address', 'danger');
                    return;
                }
                
                const submitBtn = $('#submitBtn');
                submitBtn.html('<i class="fas fa-spinner fa-spin me-2"></i> Sending...');
                submitBtn.prop('disabled', true);
            });

            // Function to show temporary alert
            function showTemporaryAlert(message, type) {
                const alertDiv = $('<div class="alert-custom alert-' + type + '-custom" role="alert">' +
                    '<i class="fas fa-' + (type === 'danger' ? 'exclamation-triangle' : 'check-circle') + '"></i>' +
                    '<span>' + message + '</span>' +
                    '<button type="button" class="close-btn" onclick="this.parentElement.remove()">&times;</button>' +
                    '</div>');
                
                $('.right-panel').prepend(alertDiv);
                
                setTimeout(function() {
                    alertDiv.fadeOut(function() {
                        $(this).remove();
                    });
                }, 4000);
            }

            // Input focus effects
            $('.form-control').on('focus', function() {
                $(this).closest('.input-group').css('border-color', '#198754');
            }).on('blur', function() {
                $(this).closest('.input-group').css('border-color', '#7faebb');
            });

            // Prevent multiple form submissions
            $('#forgotPasswordForm').on('submit', function(e) {
                if ($(this).data('submitted') === true) {
                    e.preventDefault();
                } else {
                    $(this).data('submitted', true);
                }
            });

            // Manual close button handling
            $('.close-btn').on('click', function() {
                $(this).parent().remove();
            });
        });
    </script>
</body>
</html>
