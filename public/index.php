<?php
require_once '../src/Security.php';
Security::startSession(Security::PLAYER_SESSION_NAME);
Security::sendSecurityHeaders(true);

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
            --background-color: <?php echo Security::safeColor($theme['background_color'] ?? null, '#1a1a1a'); ?>;
            --text-color: <?php echo Security::safeColor($theme['text_color'] ?? null, '#00ff00'); ?>;
            --prompt-user-color: <?php echo Security::safeColor($theme['prompt_color_user'] ?? null, '#50fa7b'); ?>;
            --prompt-path-color: <?php echo Security::safeColor($theme['prompt_color_path'] ?? null, '#bd93f9'); ?>;
        }
    </style>
</head>
<body>
    <div id="terminal-container">
        <div id="terminal-output">
        </div>
        <div id="terminal-input-line" style="display: none;">
            <span id="prompt-label">Username:</span>
            <div id="input-wrapper">
                <span id="password-dots"></span>
                <span id="input-mirror"></span><span class="cursor-block"></span><span id="input-mirror-after"></span>
                <input type="text" id="terminal-input" autofocus autocomplete="new-password">
            </div>
        </div>
    </div>
    <script>
        // Pass the MOTD to JavaScript. json_encode with the HEX flags yields a JS string
        // literal that cannot break out of the string or the <script> element.
        const motd = <?php echo json_encode((string)($theme['motd'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const loginGreeting = <?php echo json_encode((string)($theme['login_greeting'] ?? 'Welcome.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="terminal.js"></script>
</body>
</html>