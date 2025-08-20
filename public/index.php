<?php
session_start();

// Fetch theme settings from the database
require_once '../config/database.php';
require_once '../src/Database.php';
$db = new Database();
$db->query("SELECT name, value FROM themes");
$themeResults = $db->resultSet();
$theme = [];
foreach ($themeResults as $row) {
    $theme[$row['name']] = $row['value'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($theme['terminal_title'] ?? 'PseudoTerm'); ?></title>
    <link rel="stylesheet" href="terminal.css">
    <style>
        :root {
            --background-color: <?php echo htmlspecialchars($theme['background_color'] ?? '#1a1a1a'); ?>;
            --text-color: <?php echo htmlspecialchars($theme['text_color'] ?? '#00ff00'); ?>;
            --prompt-user-color: <?php echo htmlspecialchars($theme['prompt_color_user'] ?? '#50fa7b'); ?>;
            --prompt-path-color: <?php echo htmlspecialchars($theme['prompt_color_path'] ?? '#bd93f9'); ?>;
        }
    </style>
</head>
<body>
    <div id="terminal-container">
        <div id="terminal-output">
             <div class="motd"><?php echo htmlspecialchars($theme['login_greeting'] ?? 'Welcome.'); ?></div>
        </div>
        <div id="terminal-input-line">
            <span id="prompt-label">Username:</span>
            <span id="password-dots"></span>
            <input type="text" id="terminal-input" autofocus autocomplete="new-password">
        </div>
    </div>
    <script>
        // Pass the MOTD to JavaScript
        const motd = `<?php echo addslashes($theme['motd'] ?? ''); ?>`;
    </script>
    <script src="terminal.js"></script>
</body>
</html>