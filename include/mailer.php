<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

require_once __DIR__ . '/../vendor/autoload.php';

function appMailConfig() {
    $defaults = [
        'host' => '',
        'username' => '',
        'password' => '',
        'port' => 587,
        'encryption' => 'tls',
        'from_email' => 'noreply@tau-nstp.local',
        'from_name' => 'TAU NSTP National Service Training Program',
    ];

    $configPaths = [
        __DIR__ . '/../config/mail.local.php',
        __DIR__ . '/../config/mail.php',
    ];

    foreach ($configPaths as $configPath) {
        if (is_file($configPath)) {
            $config = require $configPath;
            if (is_array($config)) {
                $defaults = array_merge($defaults, $config);
                break;
            }
        }
    }

    return $defaults;
}

function appMailerIsConfigured() {
    $config = appMailConfig();
    return trim((string) $config['host']) !== ''
        && trim((string) $config['username']) !== ''
        && trim((string) $config['password']) !== '';
}

function setAppMailLastError($message) {
    $GLOBALS['app_mail_last_error'] = (string) $message;
}

function getAppMailLastError() {
    return (string) ($GLOBALS['app_mail_last_error'] ?? '');
}

function renderAppEmailTemplate($title, $preheader, $bodyHtml, $footerNote = '') {
    $safeTitle = htmlspecialchars((string) $title, ENT_QUOTES, 'UTF-8');
    $safePreheader = htmlspecialchars((string) $preheader, ENT_QUOTES, 'UTF-8');
    $safeFooterNote = htmlspecialchars((string) ($footerNote ?: 'This is an automated message from TAU NSTP National Service Training Program.'), ENT_QUOTES, 'UTF-8');
    $year = date('Y');

    return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$safeTitle}</title>
</head>
<body style="margin:0;padding:0;background:#eef4f7;font-family:Arial,'Helvetica Neue',Helvetica,sans-serif;color:#1f2933;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{$safePreheader}</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eef4f7;margin:0;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #d7e4ea;box-shadow:0 12px 32px rgba(32,72,84,0.12);">
                    <tr>
                        <td style="background:#2f6f7e;padding:28px 32px;color:#ffffff;">
                            <div style="font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#d8eef3;">TAU NSTP Portal</div>
                            <h1 style="margin:10px 0 0;font-size:24px;line-height:1.25;font-weight:800;color:#ffffff;">{$safeTitle}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            {$bodyHtml}
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f7fafb;border-top:1px solid #e3edf1;padding:20px 32px;text-align:center;">
                            <p style="margin:0 0 6px;font-size:13px;line-height:1.6;color:#667784;">{$safeFooterNote}</p>
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#8a98a3;">&copy; {$year} TAU NSTP National Service Training Program</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

function renderEmailButton($label, $url) {
    $safeLabel = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<table role="presentation" cellspacing="0" cellpadding="0" style="margin:26px 0;">
    <tr>
        <td style="border-radius:8px;background:#2f6f7e;">
            <a href="{$safeUrl}" style="display:inline-block;padding:13px 22px;font-size:15px;line-height:1.2;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">{$safeLabel}</a>
        </td>
    </tr>
</table>
HTML;
}

function sendAppMail($toEmail, $toName, $subject, $htmlBody, $textBody = '') {
    $config = appMailConfig();
    setAppMailLastError('');

    if (!appMailerIsConfigured()) {
        setAppMailLastError('SMTP settings are incomplete. Create config/mail.php or config/mail.local.php on this server.');
        error_log('Email not sent because SMTP settings are not configured.');
        return false;
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->Port = (int) $config['port'];

        $encryption = strtolower((string) $config['encryption']);
        if ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($toEmail, $toName ?: $toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        return $mail->send();
    } catch (MailerException $error) {
        setAppMailLastError($error->getMessage());
        error_log('Email send failed: ' . $error->getMessage());
        return false;
    }
}

function isPlaceholderEmail($email) {
    return strpos((string) $email, '@no-email.tau-nstp.local') !== false;
}

function sendAccountCredentialsEmail($email, $fullName, $username, $password, $role = 'user') {
    if (trim((string) $email) === '' || isPlaceholderEmail($email)) {
        setAppMailLastError('Recipient email is empty or placeholder.');
        return false;
    }

    $roleLabel = ucwords(str_replace('_', ' ', (string) $role));
    $safeName = htmlspecialchars($fullName ?: $roleLabel, ENT_QUOTES, 'UTF-8');
    $safeRole = htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8');
    $safeUsername = htmlspecialchars((string) $username, ENT_QUOTES, 'UTF-8');
    $safePassword = htmlspecialchars((string) $password, ENT_QUOTES, 'UTF-8');

    $subject = 'Your TAU NSTP Portal Account';
    $bodyHtml = <<<HTML
<p style="margin:0 0 16px;font-size:16px;line-height:1.7;color:#26343d;">Hello {$safeName},</p>
<p style="margin:0 0 20px;font-size:15px;line-height:1.7;color:#42515c;">Your {$safeRole} account for TAU NSTP Portal has been created. Use the temporary credentials below to sign in.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:22px 0;background:#f4f8fa;border:1px solid #dbe8ed;border-radius:10px;">
    <tr>
        <td style="padding:18px 20px;border-bottom:1px solid #dbe8ed;">
            <div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#667784;">Username</div>
            <div style="margin-top:6px;font-size:18px;font-weight:800;color:#1f2933;">{$safeUsername}</div>
        </td>
    </tr>
    <tr>
        <td style="padding:18px 20px;">
            <div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#667784;">Temporary Password</div>
            <div style="margin-top:6px;font-size:18px;font-weight:800;color:#1f2933;">{$safePassword}</div>
        </td>
    </tr>
</table>
<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#42515c;">For your security, please change your password after logging in.</p>
<p style="margin:0;font-size:15px;line-height:1.7;color:#42515c;">Best regards,<br><strong>TAU NSTP Portal</strong></p>
HTML;
    $htmlBody = renderAppEmailTemplate(
        'Your TAU NSTP Portal Account',
        'Your TAU NSTP Portal account credentials are ready.',
        $bodyHtml,
        'Keep these credentials private and update your password after your first login.'
    );
    $textBody = "Hello " . ($fullName ?: $roleLabel) . ",\n\n"
        . "Your {$roleLabel} account for TAU NSTP Portal has been created.\n\n"
        . "Username: {$username}\n"
        . "Password: {$password}\n\n"
        . "You can now use these credentials to access the system.\n"
        . "For your security, please change your password after logging in.\n\n"
        . "Best regards,\nTAU NSTP Portal";

    return sendAppMail($email, $fullName, $subject, $htmlBody, $textBody);
}
