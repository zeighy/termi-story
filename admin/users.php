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
$users = $admin->getAllUsers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel - User Management</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <div class="header">
        <h1>User Management</h1>
        <div>
            <a href="index.php" class="nav-link">Filesystem</a>
            <a href="users.php" class="nav-link">User Management</a>
            <a href="theme.php" class="nav-link">Theme Editor</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <div class="filesystem-viewer">
            <h2>Player Accounts</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo $user['id']; ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td>
                            <button class="edit-user-btn" data-userid="<?php echo $user['id']; ?>" data-username="<?php echo htmlspecialchars($user['username']); ?>">Edit</button>
                            <button class="delete-user-btn" data-userid="<?php echo $user['id']; ?>">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="form-container">
            <h2 id="user-form-title">Add New User</h2>
            <form id="user-form">
                <input type="hidden" name="user_id" id="user-id" value="">

                <label for="username">Username:</label>
                <input type="text" name="username" id="username" required>
                
                <label for="password">Password:</label>
                <input type="password" name="password" id="password">
                <small>Leave blank to keep the current password when editing.</small>
                
                <button type="submit">Save User</button>
                <button type="button" id="cancel-user-edit" style="display:none;">Cancel Edit</button>
            </form>
            <div id="user-form-response"></div>
        </div>
    </div>
    
    <script src="assets/users.js"></script>
</body>
</html>