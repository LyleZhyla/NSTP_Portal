<?php

// Copy this file to config/mail.local.php for local/manual server setup.
// Do not commit config/mail.local.php because it contains your SMTP password.
return [
    'host' => 'smtp.gmail.com',
    'username' => 'your-email@example.com',
    'password' => 'your-app-password',
    'port' => 587,
    'encryption' => 'tls',
    'from_email' => 'your-email@example.com',
    'from_name' => 'TAU NSTP Portal',
];
