<?php
session_start();

if (!isset($_SESSION['is_admin_logged_in']) || $_SESSION['is_admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Filesystem</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jstree/3.2.1/themes/default/style.min.css" />
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <div class="header">
        <h1>Admin Panel</h1>
        <div class="nav-tabs">
            <button class="tab-link active" data-tab="filesystem">Filesystem</button>
            <button class="tab-link" data-tab="users">Users</button>
            <button class="tab-link" data-tab="theme">Theme</button>
            <a href="logout.php" class="logout-link">Logout</a>
        </div>
    </div>

    <div class="help-section">
        <div class="help-title">Help & Info</div>
        <div class="help-content">
            <div class="help-tab" data-tab="filesystem" style="display: block;">
                <p>This page allows you to manage the filesystem that users will interact with on the public-facing terminal.</p>
                <ul>
                    <li><b>File Tree:</b> The tree on the left displays the current file and folder structure.</li>
                    <li><b>Actions:</b> To <strong>Edit</strong> or <strong>Delete</strong> an item, right-click on it in the tree to open the context menu.</li>
                    <li><b>Adding Items:</b> To add a new file or directory, first click on the parent directory in the tree where you want to add it. Then, fill out the "Add New Item" form on the right.</li>
                </ul>
            </div>
            <div class="help-tab" data-tab="users" style="display: none;">
                <p>Manage user accounts for the public terminal.</p>
                <ul>
                    <li><b>Add User:</b> Use the form to create a new user.</li>
                    <li><b>Edit/Delete:</b> Use the buttons in the table row. Leaving password blank keeps the current one.</li>
                </ul>
            </div>
            <div class="help-tab" data-tab="theme" style="display: none;">
                <p>Customize the appearance of the public-facing terminal.</p>
                <ul>
                    <li><b>Colors & Text:</b> Adjust colors and text instantly.</li>
                    <li><b>Save:</b> Changes are applied immediately upon saving.</li>
                </ul>
            </div>
        </div>
    </div>

    <div id="tab-content-filesystem" class="tab-content active">
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
    </div>

    <div id="tab-content-users" class="tab-content" style="display: none;">
        <div class="container">
            <div class="filesystem-viewer"> <!-- Reusing class for layout -->
                <h2>Player Accounts</h2>
                <table id="users-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Loaded via JS -->
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
                    <button type="button" id="cancel-user-edit" style="display:none; background-color: #6c757d; margin-left: 10px;">Cancel Edit</button>
                </form>
                <div id="user-form-response"></div>
            </div>
        </div>
    </div>

    <div id="tab-content-theme" class="tab-content" style="display: none;">
        <div class="container-full">
            <div class="form-container" style="width: 100%; max-width: 800px; margin: auto;">
                <form id="theme-form">
                    <h2>Branding & Text</h2>
                    <label for="terminal_title">Terminal Title (Browser Tab):</label>
                    <input type="text" name="terminal_title" id="theme_terminal_title">

                    <label for="login_greeting">Login Greeting:</label>
                    <input type="text" name="login_greeting" id="theme_login_greeting">

                    <label for="motd">Message of the Day (after login):</label>
                    <textarea name="motd" id="theme_motd" rows="4"></textarea>

                    <h2 style="margin-top: 2em;">Terminal Colors</h2>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <label for="background_color">Background Color:</label>
                            <input type="color" name="background_color" id="theme_background_color" style="height: 40px;">
                        </div>
                        <div>
                            <label for="text_color">Default Text Color:</label>
                            <input type="color" name="text_color" id="theme_text_color" style="height: 40px;">
                        </div>
                        <div>
                            <label for="prompt_color_user">Prompt Username Color:</label>
                            <input type="color" name="prompt_color_user" id="theme_prompt_color_user" style="height: 40px;">
                        </div>
                        <div>
                            <label for="prompt_color_path">Prompt Path Color:</label>
                            <input type="color" name="prompt_color_path" id="theme_prompt_color_path" style="height: 40px;">
                        </div>
                    </div>

                    <button type="submit">Save Theme</button>
                </form>
                <div id="theme-form-response"></div>
            </div>
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
    <script>
        // Simple accordion for help
        document.querySelectorAll('.help-title').forEach(item => {
            item.addEventListener('click', event => {
                const content = item.nextElementSibling;
                if (content.style.display === "block") {
                    content.style.display = "none";
                    item.classList.remove('open');
                } else {
                    content.style.display = "block";
                    item.classList.add('open');
                }
            });
        });
    </script>
</body>
</html>