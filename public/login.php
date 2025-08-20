<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// Fetch the login greeting from the database
require_once '../config/database.php';
require_once '../src/Database.php';
$db = new Database();
$db->query("SELECT value FROM themes WHERE name = 'login_greeting'");
$loginGreeting = $db->single()['value'] ?? 'PLAYER LOGIN';

$db->query("SELECT value FROM themes WHERE name = 'terminal_title'");
$title = $db->single()['value'] ?? 'PseudoTerm';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($title); ?> - Login</title>
    <link rel="stylesheet" href="terminal.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; height: 100vh; }
        .login-box { background-color: #000; border: 1px solid var(--text-color, #00ff00); padding: 2em; width: 350px; }
        .login-box h2 { margin-top: 0; text-align: center; }
        .login-box label { display: block; margin-top: 1em; }
        .login-box input { width: 100%; background: #222; border: 1px solid var(--text-color, #00ff00); color: var(--text-color, #00ff00); padding: 8px; margin-top: 5px; box-sizing: border-box; }
        .login-box button { width: 100%; background: var(--text-color, #00ff00); border: none; color: var(--background-color, #000); padding: 10px; margin-top: 1.5em; cursor: pointer; font-weight: bold; }
        .error { color: #ff0000; text-align: center; margin-top: 1em; }
    </style>
</head>
<body>
    <div class="login-box">
        <h2><?php echo htmlspecialchars($loginGreeting); ?></h2>
        <form action="auth_user.php" method="POST">
            <label for="username">Username:</label>
            <input type="text" id="username" name="username" required autofocus>

            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>

            <button type="submit">Connect</button>
            
            <?php if (isset($_GET['error'])): ?>
                <p class="error">Access Denied.</p>
            <?php endif; ?>
        </form>
    </div>
</body>
</html>