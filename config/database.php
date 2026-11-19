<?php
// config/database.php
// Loads site settings. This file is tracked in git and must not contain secrets.
//
// Put your real settings in config/config.local.php (copy config.local.example.php;
// it is gitignored), or provide them as environment variables:
//   TERMI_DB_HOST, TERMI_DB_PORT, TERMI_DB_USER, TERMI_DB_PASS, TERMI_DB_NAME,
//   TERMI_ADMIN_USERNAME, TERMI_ADMIN_PASSWORD_HASH
// Values in config.local.php take precedence over environment variables.

if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

$termiEnv = function ($name, $default = '') {
    $value = getenv($name);
    return ($value === false) ? $default : $value;
};

defined('DB_HOST') || define('DB_HOST', $termiEnv('TERMI_DB_HOST', 'localhost'));
defined('DB_USER') || define('DB_USER', $termiEnv('TERMI_DB_USER'));
defined('DB_PASS') || define('DB_PASS', $termiEnv('TERMI_DB_PASS'));
defined('DB_NAME') || define('DB_NAME', $termiEnv('TERMI_DB_NAME'));
defined('DB_PORT') || define('DB_PORT', (int)$termiEnv('TERMI_DB_PORT', '0')); // 0 = driver default (3306)

// Admin panel login. Generate the hash with: php config/hash_password.php 'your-password'
// Admin login stays disabled until a hash is configured.
defined('ADMIN_USERNAME') || define('ADMIN_USERNAME', $termiEnv('TERMI_ADMIN_USERNAME', 'storymanager'));
defined('ADMIN_PASSWORD_HASH') || define('ADMIN_PASSWORD_HASH', $termiEnv('TERMI_ADMIN_PASSWORD_HASH'));

unset($termiEnv);

if (DB_NAME === '' || DB_USER === '') {
    error_log('termi-story: database is not configured. Copy config/config.local.example.php to config/config.local.php and fill it in.');
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
    }
    echo "Termi-Story is not configured yet. See config/config.local.example.php.\n";
    exit(1);
}
