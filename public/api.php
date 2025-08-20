<?php
session_start();
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../src/Database.php';
require_once '../src/Terminal.php';

$db = new Database();
$response = [];

$data = json_decode(file_get_contents('php://input'), true);
$commandStr = $data['command'] ?? '';
$action = $data['action'] ?? 'command';

if ($action === 'login') {
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    
    $db->query("SELECT * FROM users WHERE username = :username");
    $db->bind(':username', $username);
    $user = $db->single();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['current_directory_id'] = $user['home_directory_id'] ?? 1;
        unset($_SESSION['unlocked_files']);
        unset($_SESSION['run_input']);
        
        $response['success'] = true;
        $response['output'] = "Login successful. Welcome, " . htmlspecialchars($user['username']) . ".";

        // Add a flag to tell the front-end if this is the first login
        if (empty($_SESSION['env_vars']['SESS_INIT'])) {
            $response['run_init'] = true;
        }

    } else {
        $response['success'] = false;
        $response['output'] = "Access Denied.";
    }

} elseif ($action === 'autocomplete' && isset($_SESSION['user_id'])) {
    $partial = $data['partial'] ?? '';
    $commands = ['ls', 'cat', 'cd', 'run', 'unlock', 'reset', 'help', 'clear', 'logout'];
    $matches = [];

    if (strpos($data['full_line'], ' ') === false) {
        foreach ($commands as $cmd) {
            if (str_starts_with($cmd, $partial)) {
                $matches[] = $cmd;
            }
        }
    }

    $currentDirId = $_SESSION['current_directory_id'];
    $db->query("SELECT name FROM filesystem WHERE parent_id = :parent_id AND name LIKE :partial AND is_hidden = 0");
    $db->bind(':parent_id', $currentDirId);
    $db->bind(':partial', $partial . '%');
    $items = $db->resultSet();

    foreach ($items as $item) {
        $matches[] = $item['name'];
    }
    
    $response['matches'] = array_unique($matches);

} elseif (isset($_SESSION['user_id'])) {
    $terminal = new Terminal($db, $_SESSION['user_id'], $_SESSION['current_directory_id']);
    $commandParts = explode(' ', $commandStr);
    $baseCommand = strtolower($commandParts[0]);
    
    if (empty($commandStr)) {
        $response['output'] = ''; 
    } else {
        $response = $terminal->executeCommand($commandStr);

        if (($baseCommand === 'unlock' || $baseCommand === 'run' || $baseCommand === 'reset') && 
            !str_contains(strtolower($response['output']), 'usage:') &&
            !str_contains(strtolower($response['output']), 'access denied') &&
            !str_contains(strtolower($response['output']), 'no such')) 
        {
            $response['animation'] = 'typewriter';
        } 
        elseif ($baseCommand === 'cat' && !empty($response['output']) && !str_contains(strtolower($response['output']), 'usage:')) {
             $response['animation'] = 'typewriterChunk';
        }
    }
    
    $response['path'] = $terminal->getCurrentPath();
    $response['username'] = $_SESSION['username'];
    
} else {
    $response['error'] = 'Authentication required.';
}

echo json_encode($response);