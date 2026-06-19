<?php

$readMailEnv = static function (array $keys, $default = null) {
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && trim((string) $value) !== '') {
            return trim((string) $value);
        }
    }

    return $default;
};

$mergeMailConfig = static function (array $baseConfig, array $overrideConfig) {
    foreach ($overrideConfig as $key => $value) {
        if (!array_key_exists($key, $baseConfig)) {
            continue;
        }

        if ($value === null || trim((string) $value) === '') {
            continue;
        }

        $baseConfig[$key] = is_string($value) ? trim($value) : $value;
    }

    return $baseConfig;
};

$config = [
    'host' => $readMailEnv(['MAIL_HOST', 'SMTP_HOST'], 'smtp.gmail.com'),
    'username' => $readMailEnv(['MAIL_USERNAME', 'SMTP_USERNAME'], ''),
    'password' => $readMailEnv(['MAIL_PASSWORD', 'SMTP_PASSWORD'], ''),
    'port' => (int) $readMailEnv(['MAIL_PORT', 'SMTP_PORT'], '587'),
    'encryption' => strtolower($readMailEnv(['MAIL_ENCRYPTION', 'SMTP_ENCRYPTION'], 'tls')),
    'from_email' => $readMailEnv(['MAIL_FROM_ADDRESS', 'MAIL_FROM_EMAIL', 'SMTP_FROM_EMAIL'], ''),
    'from_name' => $readMailEnv(['MAIL_FROM_NAME', 'SMTP_FROM_NAME'], 'TAU NSTP Portal'),
];

$localConfigPaths = array_filter([
    __DIR__ . '/mail.local.php',
    dirname(__DIR__) . '/mail.local.php',
    isset($_SERVER['HOME']) ? rtrim((string) $_SERVER['HOME'], '/\\') . '/mail.local.php' : null,
]);

foreach (array_unique($localConfigPaths) as $localConfigPath) {
    if (is_file($localConfigPath)) {
        $localConfig = require $localConfigPath;
        if (is_array($localConfig)) {
            $config = $mergeMailConfig($config, $localConfig);
        }

        break;
    }
}

$config['port'] = (int) $config['port'];
$config['encryption'] = strtolower((string) $config['encryption']);
$config['password'] = str_replace(' ', '', (string) $config['password']);

if ($config['from_email'] === '' && $config['username'] !== '') {
    $config['from_email'] = $config['username'];
}

return $config;
