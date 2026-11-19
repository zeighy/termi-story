<?php
// config/config.local.example.php
// Copy this file to config/config.local.php and fill in your values.
// config.local.php is gitignored, so your credentials never end up in the repository.

// --- Database ---
define('DB_HOST', 'localhost');
// define('DB_PORT', 3306); // only needed for a non-standard port
define('DB_USER', 'termidb');
define('DB_PASS', 'change-me');
define('DB_NAME', 'termidb');

// --- Admin panel login ---
// Generate the hash on the command line:  php config/hash_password.php 'your-long-unique-password'
// Keep the single quotes: the hash contains "$" characters.
define('ADMIN_USERNAME', 'storymanager');
define('ADMIN_PASSWORD_HASH', '');

// --- Optional settings ---
// Where failed-login / unlock attempt counters are stored (default: system temp dir).
// define('RATE_LIMIT_DIR', '/var/lib/termi-story/ratelimit');

// Let FETCH reach localhost / LAN addresses. Only enable if you trust every script,
// including imported stories.
// define('FETCH_ALLOW_PRIVATE_HOSTS', true);
