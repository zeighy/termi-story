<?php
require_once __DIR__ . '/../src/Security.php';
Security::startSession(Security::ADMIN_SESSION_NAME);
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Admin.php';

function admin_api_reject($status, $message) {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (!isset($_SESSION['is_admin_logged_in']) || $_SESSION['is_admin_logged_in'] !== true) {
    admin_api_reject(401, 'Authentication required.');
}

// CSRF protection: only JSON POSTs carrying the session's token (in the X-CSRF-Token
// header) are accepted. Cross-site forms can neither send JSON nor set custom headers.
if (!Security::isPost()) {
    admin_api_reject(405, 'POST required.');
}
if (!Security::verifyCsrfToken(Security::csrfTokenFromHeader())) {
    admin_api_reject(403, 'Invalid or missing CSRF token. Reload the admin panel and try again.');
}

$rawBody = file_get_contents('php://input');
if (($rawBody === '' || $rawBody === false) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    admin_api_reject(413, 'Request too large for the server (post_max_size is ' . ini_get('post_max_size') . '). Raise post_max_size in php.ini and retry.');
}
if (!Security::isJsonRequest()) {
    admin_api_reject(415, 'Requests must be sent as application/json.');
}
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    admin_api_reject(400, 'Request body must be a JSON object.');
}

$db = new Database();
$admin = new Admin($db);

$response = ['success' => false];

$action = $input['action'] ?? null;
$data = $input['data'] ?? [];
$id = $input['id'] ?? null;

switch ($action) {
    case 'get_fs_tree':
        echo $admin->getFilesystemTreeJson();
        exit();
    // Filesystem Actions
    case 'add_item':
        $response = $admin->addItem($data);
        break;
    case 'get_item':
        $item = $admin->getItem($id);
        if ($item) {
            echo json_encode($item);
            exit();
        }
        $response = ['success' => false, 'message' => 'Item not found'];
        break;
    case 'update_item':
        $response = $admin->updateItem($data);
        break;
    case 'delete_item':
        $response = $admin->deleteItem($id);
        break;
    case 'move_item':
        $response = $admin->moveItem($data);
        break;

    // User Actions
    case 'add_user':
        $response = $admin->addUser($data);
        break;
    case 'update_user':
        $response = $admin->updateUser($data);
        break;
    case 'delete_user':
        $response = $admin->deleteUser($id);
        break;
    case 'get_users':
        $users = $admin->getAllUsers();
        echo json_encode(['success' => true, 'data' => $users]);
        exit();

    // Theme Actions
    case 'update_theme':
        $response = $admin->updateThemeSettings($data);
        break;
    case 'get_theme_settings':
        $settings = $admin->getThemeSettings();
        echo json_encode(['success' => true, 'data' => $settings]);
        exit();

    case 'export_story':
        $response = $admin->exportStory();
        break;
    case 'import_story':
        $payload = $data['payload'] ?? null;
        $mode = $data['mode'] ?? 'merge';
        $includeTheme = !empty($data['include_theme']);
        $response = $admin->importStory($payload, $mode, $includeTheme);
        break;

    default:
        $response['message'] = 'Invalid action specified.';
        break;
}

echo json_encode($response);