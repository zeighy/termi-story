<?php
require_once __DIR__ . '/../src/Security.php';
Security::startSession(Security::ADMIN_SESSION_NAME);

// Logout must be a POST carrying the CSRF token, so other sites can't log the admin out
if (Security::isPost() && Security::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    Security::destroySession();
    header('Location: login.php');
    exit;
}

header('Location: index.php');
exit;
