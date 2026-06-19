<?php

$readMailEnv = static function (array $keys, $default = '') {
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    return $default;
};

$config = [
    'host' => $readMailEnv(['MAIL_HOST', 'SMTP_HOST'], 'smtp.gmail.com'),
    'username' => $readMailEnv(['MAIL_USERNAME', 'SMTP_USERNAME']),
    'password' => $readMailEnv(['MAIL_PASSWORD', 'SMTP_PASSWORD']),
    'port' => (int) $readMailEnv(['MAIL_PORT', 'SMTP_PORT'], '587'),
    'encryption' => strtolower($readMailEnv(['MAIL_ENCRYPTION', 'SMTP_ENCRYPTION'], 'tls')),
    'from_email' => $readMailEnv(['MAIL_FROM_ADDRESS', 'MAIL_FROM_EMAIL', 'SMTP_FROM_EMAIL', 'MAIL_USERNAME', 'SMTP_USERNAME']),
    'from_name' => $readMailEnv(['MAIL_FROM_NAME', 'SMTP_FROM_NAME'], 'TAU NSTP Portal'),
];

$localConfigPath = __DIR__ . '/mail.local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        $config = array_merge($config, $localConfig);
    }
}

return $config;
