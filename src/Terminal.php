<?php
// src/Terminal.php
//
// Output contract: every response's 'output' is PLAIN TEXT, and the browser escapes it
// before display. The only exception is a response with 'format' => 'html' (used by ls
// for coloured names), whose HTML is built here from escaped parts. Each command
// also chooses its own 'animation', so the client never infers anything from the wording.
//
// Storage contract: txt/app content is stored HTML-encoded exactly once (see Admin::encodeContent).
// It is decoded here before being shown or executed.
require_once __DIR__ . '/ScriptingEngine.php';
require_once __DIR__ . '/RateLimiter.php';

class Terminal {
    // Wrong unlock passwords allowed per player before a cooldown. Generous enough for
    // normal puzzle guessing, but stops scripted brute force (and the Argon2 cost it incurs).
    const UNLOCK_MAX_FAILURES = 20;
    const UNLOCK_WINDOW_SECONDS = 300;

    private $db;
    private $userId;
    private $currentDirId;

    public function __construct($db, $userId, $currentDirId) {
        $this->db = $db;
        $this->userId = $userId;
        $this->currentDirId = $currentDirId;

        if (!isset($_SESSION['unlocked_files']) || !is_array($_SESSION['unlocked_files'])) {
            $_SESSION['unlocked_files'] = [];
        }
    }

    /** Build a plain-text response, optionally with a client animation. */
    private function text($output, $animation = null) {
        $response = ['output' => (string)$output];
        if ($animation !== null) {
            $response['animation'] = $animation;
        }
        return $response;
    }

    /** Decode stored (HTML-encoded) txt/app content back to the text the author wrote. */
    private function decodeContent($content) {
        return htmlspecialchars_decode((string)$content, ENT_QUOTES);
    }

    private function _checkPermission($fileOwnerId) {
        if ($fileOwnerId === null) {
            return true;
        }
        return $fileOwnerId == $this->userId;
    }

    private function lockedMessage($file, $filename) {
        $hint = (string)($file['password_hint'] ?? '');
        $what = ($file['type'] ?? '') === 'dir' ? 'Directory' : 'File';
        return $this->text("Access Denied. {$what} is locked.\nHint: {$hint}\nUse: unlock {$filename} [password]");
    }

    // --- Locks ---
    // An unlock is remembered together with a fingerprint of the password hash it was
    // opened with, so changing an item's password in the admin panel locks it again.

    private static function lockFingerprint($passwordHash) {
        return hash('sha256', (string)$passwordHash);
    }

    private function isLocked($item) {
        if (empty($item['password'])) {
            return false;
        }
        $unlocked = $_SESSION['unlocked_files'][$item['id']] ?? null;
        return !(is_string($unlocked) && hash_equals($unlocked, self::lockFingerprint($item['password'])));
    }

    private function markUnlocked($item) {
        $_SESSION['unlocked_files'][$item['id']] = self::lockFingerprint($item['password']);
    }

    /**
     * Make sure the session's current directory still exists, is reachable by this
     * player and isn't (re)locked; otherwise return to /. Covers directories that were
     * deleted, moved under a restricted parent, reassigned, or given a new password.
     */
    public function ensureValidCurrentDirectory() {
        $dirId = (int)$this->currentDirId;
        $seen = [];
        while ($dirId !== 1) {
            $this->db->query("SELECT id, type, parent_id, owner_id, password FROM filesystem WHERE id = :id");
            $this->db->bind(':id', $dirId);
            $dir = $this->db->single();
            $valid = $dir
                && !isset($seen[$dirId])
                && $dir['type'] === 'dir'
                && $dir['parent_id'] !== null
                && $this->_checkPermission($dir['owner_id'])
                && !$this->isLocked($dir);
            if (!$valid) {
                $this->currentDirId = 1;
                $_SESSION['current_directory_id'] = 1;
                return false;
            }
            $seen[$dirId] = true;
            $dirId = (int)$dir['parent_id'];
        }
        return true;
    }

    /** Move the player, keeping the session and this object in sync so the returned path is current. */
    private function changeDirectory($dirId) {
        $this->currentDirId = (int)$dirId;
        $_SESSION['current_directory_id'] = (int)$dirId;
    }

    public function executeCommand($commandStr) {
        $parts = preg_split('/\s+/', $commandStr, -1, PREG_SPLIT_NO_EMPTY);
        $command = strtolower((string)array_shift($parts));
        $args = $parts;

        switch ($command) {
            case 'ls':
                return $this->_commandLs();
            case 'cat':
                return $this->_commandCat($args);
            case 'cd':
                return $this->_commandCd($args);
            case 'unlock':
                return $this->_commandUnlock($args);
            case 'run':
                return $this->_commandRun($args);
            case 'view':
                return $this->_commandView($args);
            case 'reset':
                return $this->_commandReset();
            case 'help':
                return $this->text("Available commands:\n  ls       - List files\n  cd       - Change directory\n  cat      - Read file\n  view     - View an image file\n  run      - Execute a script file\n  unlock   - Unlock a protected file\n  reset    - Reset your game progress\n  clear    - Clear screen\n  logout   - Log out");
            default:
                return $this->text("invalid command");
        }
    }

    private function _commandReset() {
        unset($_SESSION['env_vars']);
        unset($_SESSION['unlocked_files']);
        unset($_SESSION['run_input']);

        return $this->text('Player progress has been reset.', 'typewriter');
    }

    private function _commandLs() {
        $this->db->query("SELECT name, type FROM filesystem WHERE parent_id = :currentDirId AND is_hidden = 0 ORDER BY type = 'dir' DESC, name");
        $this->db->bind(':currentDirId', $this->currentDirId);
        $items = $this->db->resultSet();

        if (empty($items)) {
            return $this->text('');
        }

        $lines = [];
        foreach ($items as $item) {
            $name = htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8');
            if ($item['type'] === 'dir') {
                $lines[] = "<span style='color: #87CEFA;'>{$name}/</span>";
            } else if ($item['type'] === 'app') {
                $lines[] = "<span style='color: #50fa7b;'>{$name}*</span>";
            } else {
                $lines[] = $name;
            }
        }
        // The only HTML response: every interpolated value above is escaped
        return ['output' => implode("\n", $lines), 'format' => 'html'];
    }

    private function _commandCat($args) {
        if (empty($args)) {
            return $this->text('usage: cat [filename]');
        }
        $filename = $args[0];

        $this->db->query("SELECT id, content, type, password, password_hint, owner_id FROM filesystem WHERE parent_id = :currentDirId AND name = :name");
        $this->db->bind(':currentDirId', $this->currentDirId);
        $this->db->bind(':name', $filename);
        $file = $this->db->single();

        if (!$file) {
            return $this->text("cat: {$filename}: No such file or directory", 'typewriterChunk');
        }

        if (!$this->_checkPermission($file['owner_id'])) {
            return $this->text("Access Denied. No user permission.", 'typewriterChunk');
        }

        if ($file['type'] === 'dir') {
            return $this->text("cat: {$filename}: Is a directory", 'typewriterChunk');
        }

        if ($file['type'] === 'app') {
            return $this->text("Cannot display content of an executable file. Use 'run {$filename}' instead.", 'typewriterChunk');
        }

        if ($this->isLocked($file)) {
            $locked = $this->lockedMessage($file, $filename);
            $locked['animation'] = 'typewriterChunk';
            return $locked;
        }

        if ($file['type'] === 'img') {
            // Image content is stored raw as "width;bits"; cat shows the bits
            $content = explode(';', (string)$file['content'], 2);
            return $this->text(count($content) === 2 ? $content[1] : $file['content'], 'typewriterChunk');
        }

        return $this->text($this->decodeContent($file['content']), 'typewriterChunk');
    }

    private function _commandCd($args) {
        if (empty($args)) {
            return $this->text("usage: cd [directory]\n  cd ..    - Go up one level\n  cd /     - Go to root directory");
        }
        $targetDirName = $args[0];

        if ($targetDirName === '/') {
            $this->changeDirectory(1);
            return $this->text('');
        }

        if ($targetDirName === '..') {
            if ($this->currentDirId == 1) {
                return $this->text(''); // Already at root
            }
            $this->db->query("SELECT parent_id FROM filesystem WHERE id = :currentDirId");
            $this->db->bind(':currentDirId', $this->currentDirId);
            $current = $this->db->single();
            if ($current && $current['parent_id'] !== null) {
                $this->changeDirectory($current['parent_id']);
            }
        } else {
            $this->db->query("SELECT id, type, owner_id, password, password_hint FROM filesystem WHERE parent_id = :currentDirId AND name = :name AND type = 'dir'");
            $this->db->bind(':currentDirId', $this->currentDirId);
            $this->db->bind(':name', $targetDirName);
            $target = $this->db->single();

            if ($target) {
                if (!$this->_checkPermission($target['owner_id'])) {
                    return $this->text("cd: {$targetDirName}: Access Denied. No user permission.");
                }
                if ($this->isLocked($target)) {
                    return $this->lockedMessage($target, $targetDirName);
                }
                $this->changeDirectory($target['id']);
            } else {
                return $this->text("cd: {$targetDirName}: No such directory");
            }
        }
        return $this->text('');
    }

    private function _commandUnlock($args) {
        if (empty($args)) {
            return $this->text('usage: unlock [filename]');
        }
        $filename = $args[0];

        $this->db->query("SELECT id, type, password, owner_id FROM filesystem WHERE parent_id = :currentDirId AND name = :name");
        $this->db->bind(':currentDirId', $this->currentDirId);
        $this->db->bind(':name', $filename);
        $file = $this->db->single();

        if (!$file) {
            return $this->text("unlock: {$filename}: No such file.");
        }

        if (!$this->_checkPermission($file['owner_id'])) {
            return $this->text("Access Denied. No user permission.");
        }

        if (empty($file['password'])) {
            return $this->text("File is not password protected.", 'typewriter');
        }

        if (!$this->isLocked($file)) {
            return $this->text("File is already unlocked.", 'typewriter');
        }

        if (count($args) < 2) {
            return $this->text('usage: unlock [filename] [password]');
        }

        $limiterKey = 'unlock-user:' . $this->userId;
        $retryAfter = RateLimiter::retryAfter($limiterKey, self::UNLOCK_MAX_FAILURES, self::UNLOCK_WINDOW_SECONDS);
        if ($retryAfter > 0) {
            return $this->text("Access Denied. Too many failed attempts. Try again in {$retryAfter} seconds.");
        }

        $passwordAttempt = $args[1];
        if (password_verify($passwordAttempt, $file['password'])) {
            $this->markUnlocked($file);
            $next = [
                'dir' => "You can now 'cd' into it.",
                'app' => "You can now 'run' it.",
                'img' => "You can now 'view' the file.",
            ][$file['type']] ?? "You can now 'cat' the file.";
            return $this->text("Access Granted. {$next}", 'typewriter');
        } else {
            RateLimiter::hit($limiterKey, self::UNLOCK_WINDOW_SECONDS);
            return $this->text("Access Denied. Incorrect password.");
        }
    }

    private function _commandRun($args) {
        if (empty($args)) {
            return $this->text('usage: run [filename.app] [argument]');
        }
        $filename = $args[0];

        $runInput = $args[1] ?? null;
        if ($runInput) {
            $_SESSION['run_input'] = preg_replace("/[^A-Za-z0-9]/", '', $runInput);
        } else {
            $_SESSION['run_input'] = null;
        }

        $this->db->query("SELECT id, type, content, owner_id, password, password_hint FROM filesystem WHERE parent_id = :currentDirId AND name = :name AND type = 'app'");
        $this->db->bind(':currentDirId', $this->currentDirId);
        $this->db->bind(':name', $filename);
        $file = $this->db->single();

        if (!$file) {
            return $this->text("run: {$filename}: No such executable file");
        }

        if (!$this->_checkPermission($file['owner_id'])) {
            return $this->text("Access Denied. No user permission.");
        }

        if ($this->isLocked($file)) {
            return $this->lockedMessage($file, $filename);
        }

        // Run the script as the author wrote it (quotes, <, > etc.), not its stored encoding.
        // The script's output is plain text like every other response.
        $engine = new ScriptingEngine();
        $output = $engine->execute($this->decodeContent($file['content']));

        return $this->text($output, 'typewriter');
    }

    private function _commandView($args) {
        if (empty($args)) {
            return $this->text('usage: view [filename.img]');
        }
        $filename = $args[0];

        $this->db->query("SELECT id, content, type, password, password_hint, owner_id FROM filesystem WHERE parent_id = :currentDirId AND name = :name");
        $this->db->bind(':currentDirId', $this->currentDirId);
        $this->db->bind(':name', $filename);
        $file = $this->db->single();

        if (!$file) {
            return $this->text("view: {$filename}: No such file");
        }

        if (!$this->_checkPermission($file['owner_id'])) {
            return $this->text("Access Denied. No user permission.");
        }

        if ($file['type'] !== 'img') {
            return $this->text("Image file render error. Likely invalid image file.");
        }

        if ($this->isLocked($file)) {
            return $this->lockedMessage($file, $filename);
        }

        return $this->text($file['content'], 'imageRender');
    }

    public function getCurrentPath() {
        $path = '';
        $dirId = $this->currentDirId;

        if ($dirId == 1) return '/';

        $currentPathSegments = [];
        while ($dirId !== null && $dirId != 1) {
            $this->db->query("SELECT name, parent_id FROM filesystem WHERE id = :dirId");
            $this->db->bind(':dirId', $dirId);
            $dir = $this->db->single();
            if ($dir) {
                array_unshift($currentPathSegments, $dir['name']);
                $dirId = $dir['parent_id'];
            } else {
                return '/';
            }
        }
        return '~/' . implode('/', $currentPathSegments);
    }
}
