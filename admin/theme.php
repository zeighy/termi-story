<?php
session_start();
if (!isset($_SESSION['is_admin_logged_in']) || $_SESSION['is_admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
require_once '../config/database.php';
require_once '../src/Database.php';
require_once '../src/Admin.php';

$db = new Database();
$admin = new Admin($db);
$themeSettings = $admin->getThemeSettings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel - Theme</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <div class="header">
        <h1>Theme Manager</h1>
        <div>
            <a href="index.php" class="nav-link">Filesystem</a>
            <a href="users.php" class="nav-link">Users</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="container-full">
        <div class="form-container" style="width: 100%; max-width: 800px; margin: auto;">
            <form id="theme-form">
                <h2>Branding & Text</h2>
                <label for="terminal_title">Terminal Title (Browser Tab):</label>
                <input type="text" name="terminal_title" value="<?php echo htmlspecialchars($themeSettings['terminal_title'] ?? ''); ?>">

                <label for="login_greeting">Login Greeting:</label>
                <input type="text" name="login_greeting" value="<?php echo htmlspecialchars($themeSettings['login_greeting'] ?? ''); ?>">
                
                <label for="motd">Message of the Day (after login):</label>
                <textarea name="motd" rows="4"><?php echo htmlspecialchars($themeSettings['motd'] ?? ''); ?></textarea>

                <h2 style="margin-top: 2em;">Terminal Colors</h2>
                <label for="background_color">Background Color:</label>
                <input type="color" name="background_color" value="<?php echo htmlspecialchars($themeSettings['background_color'] ?? '#1a1a1a'); ?>">

                <label for="text_color">Default Text Color:</label>
                <input type="color" name="text_color" value="<?php echo htmlspecialchars($themeSettings['text_color'] ?? '#00ff00'); ?>">
                
                <label for="prompt_color_user">Prompt Username Color:</label>
                <input type="color" name="prompt_color_user" value="<?php echo htmlspecialchars($themeSettings['prompt_color_user'] ?? '#50fa7b'); ?>">

                <label for="prompt_color_path">Prompt Path Color:</label>
                <input type="color" name="prompt_color_path" value="<?php echo htmlspecialchars($themeSettings['prompt_color_path'] ?? '#bd93f9'); ?>">

                <button type="submit">Save Theme</button>
            </form>
            <div id="form-response"></div>
        </div>
    </div>
    
    <script>
        document.getElementById('theme-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            const data = Object.fromEntries(formData.entries());
            
            const response = await fetch('api_admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update_theme', data: data })
            });
            const result = await response.json();
            
            const formResponse = document.getElementById('form-response');
            if (result.success) {
                formResponse.style.color = 'green';
            } else {
                formResponse.style.color = 'red';
            }
            formResponse.textContent = result.message;
        });
    </script>
</body>
</html>