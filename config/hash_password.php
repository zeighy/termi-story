<?php
// hash_password.php - generate an Argon2id hash for the admin password.
// Usage: php config/hash_password.php 'YourPasswordHere'
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

if (isset($argv[1]) && $argv[1] !== '') {
    $hash = password_hash($argv[1], PASSWORD_ARGON2ID);
    echo "Hash: " . $hash . "\n\n";
    echo "Add it to config/config.local.php (keep the single quotes):\n";
    echo "define('ADMIN_PASSWORD_HASH', '" . $hash . "');\n";
} else {
    echo "Usage: php config/hash_password.php 'YourPasswordHere'\n";
}
