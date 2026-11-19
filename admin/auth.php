<?php
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/RateLimiter.php';
// Provides ADMIN_USERNAME and ADMIN_PASSWORD_HASH from config/config.local.php or the environment
require_once __DIR__ . '/../config/database.php';
Security::startSession(Security::ADMIN_SESSION_NAME);

// Failed admin logins allowed per IP before a cooldown
const ADMIN_LOGIN_MAX_FAILURES = 5;
const ADMIN_LOGIN_WINDOW_SECONDS = 900;

// --- Authentication Logic ---
if (!Security::isPost()) {
    header('Location: login.php');
    exit;
}

if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    header('Location: login.php?error=expired');
    exit;
}

$limiterKey = 'admin-login-ip:' . Security::clientIp();
if (RateLimiter::retryAfter($limiterKey, ADMIN_LOGIN_MAX_FAILURES, ADMIN_LOGIN_WINDOW_SECONDS) > 0) {
    header('Location: login.php?error=locked');
    exit;
}

// No committed default credentials: admin login stays disabled until a hash is configured
if (!is_string(ADMIN_PASSWORD_HASH) || ADMIN_PASSWORD_HASH === '' || !is_string(ADMIN_USERNAME) || ADMIN_USERNAME === '') {
    header('Location: login.php?error=unconfigured');
    exit;
}

$username = is_string($_POST['username'] ?? null) ? $_POST['username'] : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

// Always run the hash check so a wrong username takes as long as a wrong password
$passwordOk = password_verify($password, ADMIN_PASSWORD_HASH);
if (hash_equals(ADMIN_USERNAME, $username) && $passwordOk) {
    // Login successful
    RateLimiter::clear($limiterKey);
    Security::regenerateSession();
    Security::rotateCsrfToken();
    $_SESSION['is_admin_logged_in'] = true;
    header('Location: index.php');
    exit;
}

// Login failed
RateLimiter::hit($limiterKey, ADMIN_LOGIN_WINDOW_SECONDS);
header('Location: login.php?error=1');
exit;
