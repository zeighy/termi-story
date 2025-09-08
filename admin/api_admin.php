<?php
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../src/Database.php';
require_once '../src/Admin.php';

session_start();
if (!isset($_SESSION['is_admin_logged_in']) || $_SESSION['is_admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$db = new Database();
$admin = new Admin($db);

$response = ['success' => false];

$input = json_decode(file_get_contents('php://input'), true);
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
        
    // Theme Actions
    case 'update_theme':
        $response = $admin->updateThemeSettings($data);
        break;

    default:
        $response['message'] = 'Invalid action specified.';
        break;
}

echo json_encode($response);