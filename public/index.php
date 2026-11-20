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

// Screen effects and sound levels chosen by the author (Admin > Theme). Players can
// override both from the settings menu; their choice is kept in their browser, not the
// database. Must match Admin::CRT_LEVELS / SOUND_LEVELS and terminal.js.
$crtLevels = ['off', 'subtle', 'full'];
$crtLevel = in_array($theme['crt_level'] ?? '', $crtLevels, true) ? $theme['crt_level'] : 'full';
$soundLevels = ['off', 'low', 'high'];
$soundLevel = in_array($theme['sound_level'] ?? '', $soundLevels, true) ? $theme['sound_level'] : 'off';
?>
<!DOCTYPE html>
<html lang="en" data-fx="<?php echo $crtLevel; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($theme['terminal_title'] ?? 'PseudoTerm'); ?></title>
    <script>
        // Settings saved by terminal.js. Apply the player's effects level before the
        // first paint so a player who turned effects off never sees them flash on.
        const SETTINGS_STORAGE_KEY = 'termistory.settings';
        const authorFxLevel = <?php echo json_encode($crtLevel); ?>;
        const authorSoundLevel = <?php echo json_encode($soundLevel); ?>;
        try {
            const saved = JSON.parse(localStorage.getItem(SETTINGS_STORAGE_KEY) || '{}');
            if (saved && <?php echo json_encode($crtLevels); ?>.includes(saved.fx)) {
                document.documentElement.dataset.fx = saved.fx;
            }
        } catch (err) {
            // No storage available: keep the author's level
        }
    </script>
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
    <div id="settings" class="settings">
        <button type="button" id="settings-toggle" class="settings-toggle" aria-expanded="false" aria-controls="settings-panel" title="Settings">
            <span aria-hidden="true">&#8801;</span><span class="visually-hidden">Settings</span>
        </button>
        <div id="settings-panel" class="settings-panel" hidden>
            <fieldset>
                <legend>Text speed</legend>
                <label><input type="radio" name="speed" value="slow"> Slow</label>
                <label><input type="radio" name="speed" value="normal"> Normal</label>
                <label><input type="radio" name="speed" value="fast"> Fast</label>
                <label><input type="radio" name="speed" value="instant"> Instant</label>
            </fieldset>
            <fieldset>
                <legend>Screen effects</legend>
                <label><input type="radio" name="fx" value="off"> Off</label>
                <label><input type="radio" name="fx" value="subtle"> Subtle</label>
                <label><input type="radio" name="fx" value="full"> Full</label>
            </fieldset>
            <fieldset>
                <legend>Sound</legend>
                <label><input type="radio" name="sound" value="off"> Off</label>
                <label><input type="radio" name="sound" value="low"> Low</label>
                <label><input type="radio" name="sound" value="high"> High</label>
            </fieldset>
            <p class="settings-hint">While text is printing, press any key or tap the screen to skip ahead.</p>
        </div>
    </div>
    <div id="crt-overlay" aria-hidden="true"></div>
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
    <script src="sound.js"></script>
    <script src="terminal.js"></script>
</body>
</html>