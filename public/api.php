<?php
require_once '../src/Security.php';
require_once '../src/RateLimiter.php';
Security::startSession(Security::PLAYER_SESSION_NAME);
header('Content-Type: application/json');

// The terminal only ever sends JSON POSTs. Rejecting anything else blocks
// cross-site form / text/plain submissions (CSRF) without needing a token.
if (!Security::isPost() || !Security::isJsonRequest()) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad request.']);
    exit;
}

require_once '../config/database.php';
require_once '../src/Database.php';
require_once '../src/Terminal.php';

// Failed player logins: per username+IP, plus a looser cap per IP.
const LOGIN_WINDOW_SECONDS = 900;
const LOGIN_MAX_FAILURES_PER_USER = 5;
const LOGIN_MAX_FAILURES_PER_IP = 50;
// Verified against when the username does not exist, so response time doesn't reveal valid usernames.
const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$RHpxNm1BbUJFUVRicVR6ag$tXBjF+n+jFo4Lukx+0rH/hA2Mwcx/4GILEVMsbKhT3o';

$db = new Database();
$response = [];

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}
$commandStr = is_string($data['command'] ?? null) ? $data['command'] : '';
$action = $data['action'] ?? 'command';

if ($action === 'login') {
    $username = is_string($data['username'] ?? null) ? $data['username'] : '';
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';

    $ip = Security::clientIp();
    $userKey = 'player-login-user:' . $ip . ':' . strtolower($username);
    $ipKey = 'player-login-ip:' . $ip;
    $retryAfter = max(
        RateLimiter::retryAfter($userKey, LOGIN_MAX_FAILURES_PER_USER, LOGIN_WINDOW_SECONDS),
        RateLimiter::retryAfter($ipKey, LOGIN_MAX_FAILURES_PER_IP, LOGIN_WINDOW_SECONDS)
    );

    if ($retryAfter > 0) {
        $response['success'] = false;
        $response['output'] = "Access Denied. Too many failed attempts. Try again in {$retryAfter} seconds.";
        echo json_encode($response);
        exit;
    }

    $db->query("SELECT * FROM users WHERE username = :username");
    $db->bind(':username', $username);
    $user = $db->single();

    $passwordOk = password_verify($password, $user ? $user['password'] : DUMMY_PASSWORD_HASH);

    if ($user && $passwordOk) {
        RateLimiter::clear($userKey);

        // Progress lives in the session, not the account. Don't let a different
        // player inherit the previous player's variables on a shared browser.
        $previousUserId = $_SESSION['user_id'] ?? null;
        if ($previousUserId !== null && (int)$previousUserId !== (int)$user['id']) {
            unset($_SESSION['env_vars']);
        }

        Security::regenerateSession();
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
        RateLimiter::hit($userKey, LOGIN_WINDOW_SECONDS);
        RateLimiter::hit($ipKey, LOGIN_WINDOW_SECONDS);
        $response['success'] = false;
        $response['output'] = "Access Denied.";
    }

} elseif ($action === 'logout') {
    // End the server-side session; the client reloads to the login prompt.
    Security::destroySession();
    $response['success'] = true;

} elseif ($action === 'autocomplete' && isset($_SESSION['user_id'])) {
    $partial = is_string($data['partial'] ?? null) ? $data['partial'] : '';
    $fullLine = is_string($data['full_line'] ?? null) ? $data['full_line'] : '';
    $commands = ['ls', 'cat', 'cd', 'run', 'unlock', 'reset', 'help', 'view', 'clear', 'logout'];
    $matches = [];

    if (strpos($fullLine, ' ') === false) {
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

    $response['matches'] = array_values(array_unique($matches));

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
