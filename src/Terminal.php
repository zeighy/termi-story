<?php
// src/Terminal.php
require_once 'ScriptingEngine.php';

class Terminal {
    private $db;
    private $userId;
    private $currentDirId;

    public function __construct($db, $userId, $currentDirId) {
        $this->db = $db;
        $this->userId = $userId;
        $this->currentDirId = $currentDirId;

        if (!isset($_SESSION['unlocked_files'])) {
            $_SESSION['unlocked_files'] = [];
        }
    }

    public function executeCommand($commandStr) {
        $parts = preg_split('/\s+/', $commandStr, -1, PREG_SPLIT_NO_EMPTY);
        $command = strtolower(array_shift($parts));
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
            case 'reset':
                return $this->_commandReset();
            case 'help':
                return ['output' => "Available commands:\n  ls       - List files\n  cd       - Change directory\n  cat      - Read file\n  run      - Execute a script file\n  unlock   - Unlock a protected file\n  reset    - Reset your game progress\n  clear    - Clear screen"];
            default:
                return ['output' => "invalid command"];
        }
    }
    
    private function _commandReset() {
        unset($_SESSION['env_vars']);
        unset($_SESSION['unlocked_files']);
        unset($_SESSION['run_input']);
        
        return ['output' => 'Player progress has been reset.'];
    }

    private function _commandLs() {
        $this->db->query("SELECT name, type FROM filesystem WHERE parent_id = :currentDirId AND is_hidden = 0");
        $this->db->bind(':currentDirId', $this->currentDirId);
        $items = $this->db->resultSet();

        if (empty($items)) {
            return ['output' => ''];
        }

        $output = '';
        foreach ($items as $item) {
            $name = htmlspecialchars($item['name']);
            if ($item['type'] === 'dir') {
                $output .= "<span style='color: #87CEFA;'>{$name}/</span>\n";
            } else if ($item['type'] === 'app') {
                $output .= "<span style='color: #50fa7b;'>{$name}*</span>\n";
            }
            else {
                $output .= "{$name}\n";
            }
        }
        return ['output' => rtrim($output)];
    }

    private function _commandCat($args) {
        if (empty($args)) {
            return ['output' => 'usage: cat [filename]'];
        }
        $filename = $args[0];

        $this->db->query("SELECT id, content, type, password, password_hint FROM filesystem WHERE parent_id = :currentDirId AND name = :name");
        $this->db->bind(':currentDirId', $this->currentDirId);
        $this->db->bind(':name', $filename);
        $file = $this->db->single();

        if (!$file) {
            return ['output' => "cat: {$filename}: No such file or directory"];
        }
        
        if ($file['type'] === 'dir') {
            return ['output' => "cat: {$filename}: Is a directory"];
        }

        if ($file['type'] === 'app') {
            return ['output' => "Cannot display content of an executable file. Use 'run {$filename}' instead."];
        }

        if (!empty($file['password']) && !in_array($file['id'], $_SESSION['unlocked_files'])) {
            $hint = htmlspecialchars($file['password_hint']);
            return ['output' => "Access Denied. File is locked.\nHint: {$hint}\nUse: unlock {$filename} [password]"];
        }

        return ['output' => htmlspecialchars($file['content'])];
    }
    
    private function _commandCd($args) {
        if (empty($args)) {
            $_SESSION['current_directory_id'] = 1;
            return ['output' => ''];
        }
        $targetDirName = $args[0];

        if ($targetDirName === '..') {
            $this->db->query("SELECT parent_id FROM filesystem WHERE id = :currentDirId");
            $this->db->bind(':currentDirId', $this->currentDirId);
            $current = $this->db->single();
            if ($current && $current['parent_id'] !== null) {
                $_SESSION['current_directory_id'] = $current['parent_id'];
            }
        } else {
            $this->db->query("SELECT id FROM filesystem WHERE parent_id = :currentDirId AND name = :name AND type = 'dir'");
            $this->db->bind(':currentDirId', $this->currentDirId);
            $this->db->bind(':name', $targetDirName);
            $target = $this->db->single();

            if ($target) {
                $_SESSION['current_directory_id'] = $target['id'];
            } else {
                return ['output' => "cd: {$targetDirName}: No such directory"];
            }
        }
        return ['output' => ''];
    }

    private function _commandUnlock($args) {
        if (empty($args)) {
            return ['output' => 'usage: unlock [filename]'];
        }
        $filename = $args[0];

        $this->db->query("SELECT id, password FROM filesystem WHERE parent_id = :currentDirId AND name = :name");
        $this->db->bind(':currentDirId', $this->currentDirId);
        $this->db->bind(':name', $filename);
        $file = $this->db->single();

        if (!$file) {
            return ['output' => "unlock: {$filename}: No such file."];
        }
        
        if (empty($file['password'])) {
            return ['output' => "File is not password protected."];
        }
        
        if (in_array($file['id'], $_SESSION['unlocked_files'])) {
            return ['output' => "File is already unlocked."];
        }
        
        if (count($args) < 2) {
            return ['output' => 'usage: unlock [filename] [password]'];
        }

        $passwordAttempt = $args[1];
        if (password_verify($passwordAttempt, $file['password'])) {
            $_SESSION['unlocked_files'][] = $file['id'];
            return ['output' => "Access Granted. You can now 'cat' the file."];
        } else {
            return ['output' => "Access Denied. Incorrect password."];
        }
    }

    private function _commandRun($args) {
        if (empty($args)) {
            return ['output' => 'usage: run [filename.app] [argument]'];
        }
        $filename = $args[0];
        
        $runInput = $args[1] ?? null;
        if ($runInput) {
            $_SESSION['run_input'] = preg_replace("/[^A-Za-z0-9]/", '', $runInput);
        } else {
            $_SESSION['run_input'] = null;
        }

        $this->db->query("SELECT content FROM filesystem WHERE parent_id = :currentDirId AND name = :name AND type = 'app'");
        $this->db->bind(':currentDirId', $this->currentDirId);
        $this->db->bind(':name', $filename);
        $file = $this->db->single();

        if (!$file) {
            return ['output' => "run: {$filename}: No such executable file"];
        }

        $engine = new ScriptingEngine();
        $output = $engine->execute($file['content']);
        
        return ['output' => $output];
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