<?php
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../config/database.php';
Security::startSession(Security::ADMIN_SESSION_NAME);
Security::sendSecurityHeaders(false);
// If already logged in, redirect to the main admin page
if (isset($_SESSION['is_admin_logged_in']) && $_SESSION['is_admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}
$csrfToken = Security::csrfToken();
$error = $_GET['error'] ?? null;
if ($error !== null && !is_string($error)) {
    $error = '1';
}
$errorMessages = [
    'locked' => 'Too many failed attempts. Please wait a few minutes and try again.',
    'expired' => 'Your login form expired. Please try again.',
    'unconfigured' => 'Admin login is not configured. Set ADMIN_PASSWORD_HASH in config/config.local.php (see config.local.example.php).',
];
if (ADMIN_PASSWORD_HASH === '') {
    $error = 'unconfigured';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <link rel="stylesheet" href="assets/admin.css">
    <style>
        body { display: flex; justify-content: center; align-items: center; height: 100vh; }
        .login-container { width: 300px; }
    </style>
</head>
<body>
    <div class="form-container login-container">
        <h2>Admin Login</h2>
        <form action="auth.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" required>

            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>

            <?php if ($error !== null): ?>
                <p style="color: red;"><?php echo htmlspecialchars($errorMessages[$error] ?? 'Invalid username or password.'); ?></p>
            <?php endif; ?>

            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>