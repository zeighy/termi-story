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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel - Filesystem</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.2.1/themes/default/style.min.css" />
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <div class="header">
        <h1>Filesystem Manager</h1>
        <div>
            <a href="index.php" class="nav-link">Filesystem</a>
            <a href="users.php" class="nav-link">User Management</a>
            <a href="theme.php" class="nav-link">Theme Editor</a>
            <a href="logout.php">Logout</a>
        </div>
    </div>
    <div class="container">
        <div class="filesystem-viewer">
            <h2>Current Filesystem</h2>
            <div id="fs-tree"></div>
        </div>
        <div class="form-container">
            <h2>Add New Item</h2>
            <form id="add-item-form">
                <p>Selected Directory: <strong id="selected-dir-name">/</strong></p>
                <input type="hidden" id="parent-id" name="parent_id" value="1">
                
                <label for="item-type">Type:</label>
                <select id="item-type" name="type">
                    <option value="dir">Directory</option>
                    <option value="txt">Text File (.txt)</option>
                    <option value="app">App File (.app)</option>
                </select>

                <label for="item-name">Name:</label>
                <input type="text" id="item-name" name="name" required>

                <div id="content-wrapper" style="display: none;">
                    <label for="item-content">Content:</label>
                    <textarea id="item-content" name="content" rows="8"></textarea>
                </div>
                
                <div id="password-wrapper" style="display: none;">
                     <label for="item-password">Password (optional):</label>
                     <input type="text" id="item-password" name="password">
                     <label for="item-hint">Hint (optional):</label>
                     <input type="text" id="item-hint" name="password_hint">
                </div>

                <label class="checkbox-label">
                    <input type="checkbox" id="is-hidden" name="is_hidden" value="1"> Hidden File
                </label>

                <button type="submit">Add Item</button>
            </form>
            <div id="form-response"></div>
        </div>
    </div>

    <div id="edit-modal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <h2>Edit Item</h2>
            <form id="edit-item-form">
                <input type="hidden" id="edit-item-id" name="id">
                
                <label for="edit-item-name">Name:</label>
                <input type="text" id="edit-item-name" name="name" required>

                <div id="edit-content-wrapper">
                    <label for="edit-item-content">Content:</label>
                    <textarea id="edit-item-content" name="content" rows="8"></textarea>
                </div>
                
                <div id="edit-password-wrapper">
                     <label for="edit-item-password">Password (optional):</label>
                     <input type="text" id="edit-item-password" name="password">
                     <label for="edit-item-hint">Hint (optional):</label>
                     <input type="text" id="edit-item-hint" name="password_hint">
                </div>

                <label class="checkbox-label">
                    <input type="checkbox" id="edit-is-hidden" name="is_hidden" value="1"> Hidden File
                </label>

                <div class="modal-actions">
                    <button type="submit">Save Changes</button>
                    <button type="button" id="cancel-edit-btn">Cancel</button>
                </div>
            </form>
            <div id="edit-form-response"></div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.3.12/jstree.min.js"></script>
    <script src="assets/admin.js"></script>
</body>
</html>