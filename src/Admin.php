<?php
// src/Admin.php

class Admin {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // --- Filesystem Methods ---

    public function getFilesystemTreeJson() {
        $this->db->query("SELECT id, parent_id, name, type FROM filesystem ORDER BY parent_id, type DESC, name");
        $items = $this->db->resultSet();
        
        $tree = [];
        foreach ($items as $item) {
            $tree[] = [
                'id' => (string)$item['id'],
                'parent' => $item['parent_id'] === null ? '#' : (string)$item['parent_id'],
                'text' => htmlspecialchars($item['name']),
                'type' => $item['type']
            ];
        }
        
        return json_encode($tree);
    }

    public function getItem($id) {
        $this->db->query("SELECT * FROM filesystem WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }

    public function addItem($data) {
        if (empty($data['name']) || empty($data['type']) || empty($data['parent_id'])) {
            return ['success' => false, 'message' => 'Name, type, and parent are required.'];
        }

        $name = $data['name'];
        $type = $data['type'];
        if ($type === 'txt' && substr($name, -4) !== '.txt') {
            $name .= '.txt';
        } elseif ($type === 'app' && substr($name, -4) !== '.app') {
            $name .= '.app';
        } elseif ($type === 'img' && substr($name, -4) !== '.img') {
            $name .= '.img';
        }
        
        $password = !empty($data['password']) ? password_hash($data['password'], PASSWORD_ARGON2ID) : null;
        
        $sql = "INSERT INTO filesystem (parent_id, name, type, content, password, password_hint, is_hidden, owner_id) 
                VALUES (:parent_id, :name, :type, :content, :password, :password_hint, :is_hidden, :owner_id)";
                
        $this->db->query($sql);
        $this->db->bind(':parent_id', $data['parent_id']);
        $this->db->bind(':name', $name);
        $this->db->bind(':type', $data['type']);
        if ($data['type'] === 'img') {
            $content = ($data['image_width'] ?? '') . ';' . ($data['image_content'] ?? '');
        } else {
            $content = ($data['type'] !== 'dir') ? htmlspecialchars($data['content'] ?? '', ENT_QUOTES, 'UTF-8') : null;
        }
        $this->db->bind(':content', $content);
        $this->db->bind(':password', $password);
        $this->db->bind(':password_hint', !empty($data['password_hint']) ? $data['password_hint'] : null);
        $this->db->bind(':is_hidden', isset($data['is_hidden']) ? 1 : 0);
        $owner_id = (!empty($data['owner_id']) && $data['owner_id'] !== 'null') ? $data['owner_id'] : null;
        $this->db->bind(':owner_id', $owner_id);

        try {
            if ($this->db->execute()) {
                return ['success' => true, 'message' => 'Item added successfully!'];
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                 return ['success' => false, 'message' => 'Error: An item with this name already exists in this directory.'];
            }
            return ['success' => false, 'message' => 'Database error.'];
        }
        return ['success' => false, 'message' => 'Failed to add item.'];
    }

    public function updateItem($data) {
        if (empty($data['name']) || empty($data['id'])) {
            return ['success' => false, 'message' => 'ID and Name are required.'];
        }

        $name = $data['name'];
        $item = $this->getItem($data['id']);
        if (!$item) {
            return ['success' => false, 'message' => 'Item not found.'];
        }
        
        if ($item['type'] === 'txt' && substr($name, -4) !== '.txt') {
            $name .= '.txt';
        } elseif ($item['type'] === 'app' && substr($name, -4) !== '.app') {
            $name .= '.app';
        } elseif ($item['type'] === 'img' && substr($name, -4) !== '.img') {
            $name .= '.img';
        }
        
        $password = $item['password'];
        if (!empty($data['password'])) {
            $password = password_hash($data['password'], PASSWORD_ARGON2ID);
        }

        $sql = "UPDATE filesystem SET 
                    name = :name, 
                    content = :content, 
                    password = :password, 
                    password_hint = :password_hint, 
                    is_hidden = :is_hidden,
                    owner_id = :owner_id
                WHERE id = :id";
        
        $this->db->query($sql);
        $this->db->bind(':id', $data['id']);
        $this->db->bind(':name', $name);
        if ($item['type'] === 'img') {
            if (!empty($data['edit_image_content'])) {
                $content = ($data['edit_image_width'] ?? '') . ';' . ($data['edit_image_content'] ?? '');
            } else {
                $content = $item['content'];
            }
        } else {
            $content = ($item['type'] !== 'dir') ? htmlspecialchars($data['content'] ?? '', ENT_QUOTES, 'UTF-8') : null;
        }
        $this->db->bind(':content', $content);
        $this->db->bind(':password', $password);
        $this->db->bind(':password_hint', !empty($data['password_hint']) ? $data['password_hint'] : null);
        $this->db->bind(':is_hidden', isset($data['is_hidden']) && $data['is_hidden'] ? 1 : 0);
        $owner_id = (!empty($data['owner_id']) && $data['owner_id'] !== 'null') ? $data['owner_id'] : null;
        $this->db->bind(':owner_id', $owner_id);

        if ($this->db->execute()) {
            return ['success' => true, 'message' => 'Item updated successfully!'];
        }
        return ['success' => false, 'message' => 'Failed to update item.'];
    }

    public function deleteItem($id) {
        if ($id == 1) {
            return ['success' => false, 'message' => 'Cannot delete the root directory.'];
        }

        $this->db->query("SELECT id FROM filesystem WHERE parent_id = :id");
        $this->db->bind(':id', $id);
        if (count($this->db->resultSet()) > 0) {
            return ['success' => false, 'message' => 'Cannot delete a directory that is not empty.'];
        }

        $this->db->query("DELETE FROM filesystem WHERE id = :id");
        $this->db->bind(':id', $id);
        
        if ($this->db->execute()) {
            return ['success' => true, 'message' => 'Item deleted successfully.'];
        }
        return ['success' => false, 'message' => 'Failed to delete item.'];
    }

    public function moveItem($data) {
        if (empty($data['id']) || empty($data['new_parent_id'])) {
            return ['success' => false, 'message' => 'Item ID and New Parent ID are required.'];
        }

        $id = $data['id'];
        $new_parent_id = $data['new_parent_id'];

        if ($id == 1) {
            return ['success' => false, 'message' => 'Cannot move the root directory.'];
        }

        if ($id == $new_parent_id) {
            return ['success' => false, 'message' => 'Cannot move an item into itself.'];
        }

        $item = $this->getItem($id);
        if (!$item) {
            return ['success' => false, 'message' => 'Item not found.'];
        }

        $new_parent = $this->getItem($new_parent_id);
        if (!$new_parent || $new_parent['type'] !== 'dir') {
            return ['success' => false, 'message' => 'Invalid destination directory.'];
        }

        // Prevent moving a directory into its own descendants
        if ($item['type'] === 'dir') {
            $current_check_id = $new_parent_id;
            while ($current_check_id != 1 && $current_check_id != null) {
                if ($current_check_id == $id) {
                    return ['success' => false, 'message' => 'Cannot move a directory into its own descendant.'];
                }
                $parent_check = $this->getItem($current_check_id);
                if ($parent_check) {
                    $current_check_id = $parent_check['parent_id'];
                } else {
                    break;
                }
            }
        }

        $this->db->query("UPDATE filesystem SET parent_id = :new_parent_id WHERE id = :id");
        $this->db->bind(':new_parent_id', $new_parent_id);
        $this->db->bind(':id', $id);

        try {
            if ($this->db->execute()) {
                return ['success' => true, 'message' => 'Item moved successfully!'];
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                 return ['success' => false, 'message' => 'Error: An item with this name already exists in the destination directory.'];
            }
            return ['success' => false, 'message' => 'Database error.'];
        }
        return ['success' => false, 'message' => 'Failed to move item.'];
    }
    
    // --- User Methods ---

    public function getAllUsers() {
        $this->db->query("SELECT id, username FROM users ORDER BY username");
        return $this->db->resultSet();
    }

    public function addUser($data) {
        if (empty($data['username']) || empty($data['password'])) {
            return ['success' => false, 'message' => 'Username and password are required.'];
        }
        
        $hash = password_hash($data['password'], PASSWORD_ARGON2ID);

        $this->db->query("INSERT INTO users (username, password, home_directory_id) VALUES (:username, :password, 1)");
        $this->db->bind(':username', $data['username']);
        $this->db->bind(':password', $hash);
        
        try {
            if ($this->db->execute()) {
                return ['success' => true, 'message' => 'User added successfully.'];
            }
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Username already exists.'];
        }
        return ['success' => false, 'message' => 'Failed to add user.'];
    }

    public function updateUser($data) {
        if (empty($data['user_id']) || empty($data['username'])) {
            return ['success' => false, 'message' => 'User ID and username are required.'];
        }
        
        if (empty($data['password'])) {
            $this->db->query("UPDATE users SET username = :username WHERE id = :id");
        } else {
            $hash = password_hash($data['password'], PASSWORD_ARGON2ID);
            $this->db->query("UPDATE users SET username = :username, password = :password WHERE id = :id");
            $this->db->bind(':password', $hash);
        }
        
        $this->db->bind(':id', $data['user_id']);
        $this->db->bind(':username', $data['username']);

        if ($this->db->execute()) {
            return ['success' => true, 'message' => 'User updated successfully.'];
        }
        return ['success' => false, 'message' => 'Failed to update user.'];
    }

    public function deleteUser($id) {
        if (empty($id)) {
            return ['success' => false, 'message' => 'User ID is required.'];
        }
        $this->db->query("DELETE FROM users WHERE id = :id");
        $this->db->bind(':id', $id);

        if ($this->db->execute()) {
            return ['success' => true, 'message' => 'User deleted successfully.'];
        }
        return ['success' => false, 'message' => 'Failed to delete user.'];
    }

    // --- Theme Methods ---

    public function getThemeSettings() {
        $this->db->query("SELECT * FROM themes");
        $results = $this->db->resultSet();
        $settings = [];
        foreach ($results as $row) {
            $settings[$row['name']] = $row['value'];
        }
        return $settings;
    }

    public function updateThemeSettings($data) {
        $this->db->query("UPDATE themes SET value = :value WHERE name = :name");
        foreach ($data as $name => $value) {
            $this->db->bind(':name', $name);
            $this->db->bind(':value', $value);
            $this->db->execute();
        }
        return ['success' => true, 'message' => 'Theme updated successfully!'];
    }

    // --- Story Export / Import ---

    public function exportStory() {
        $this->db->query("SELECT id, username FROM users");
        $users = $this->db->resultSet();
        $usersById = [];
        foreach ($users as $user) {
            $usersById[(int)$user['id']] = $user['username'];
        }

        $this->db->query("SELECT * FROM filesystem ORDER BY parent_id, type DESC, name");
        $items = $this->db->resultSet();
        $itemsById = [];
        foreach ($items as $item) {
            $itemsById[(int)$item['id']] = $item;
        }

        $paths = [];
        $buildPath = function ($itemId) use (&$buildPath, &$paths, $itemsById) {
            if (isset($paths[$itemId])) {
                return $paths[$itemId];
            }
            if (!isset($itemsById[$itemId])) {
                return null;
            }
            $item = $itemsById[$itemId];
            if ($item['parent_id'] === null) {
                $paths[$itemId] = '/';
                return '/';
            }
            $parentPath = $buildPath((int)$item['parent_id']);
            if ($parentPath === null) {
                return null;
            }
            $path = rtrim($parentPath, '/') . '/' . $item['name'];
            $paths[$itemId] = $path;
            return $path;
        };

        $filesystem = [];
        foreach ($items as $item) {
            if ((int)$item['id'] === 1) {
                continue; // root is implied
            }
            $path = $buildPath((int)$item['id']);
            if ($path === null) {
                continue;
            }
            $ownerId = $item['owner_id'] !== null ? (int)$item['owner_id'] : null;
            $filesystem[] = [
                'path' => $path,
                'type' => $item['type'],
                'content' => $item['content'],
                'is_hidden' => (bool)$item['is_hidden'],
                'password_hash' => $item['password'],
                'password_hint' => $item['password_hint'],
                'owner_username' => ($ownerId !== null && isset($usersById[$ownerId])) ? $usersById[$ownerId] : null,
            ];
        }

        // Stable order: shorter paths first so import creates parents before children
        usort($filesystem, function ($a, $b) {
            $da = substr_count($a['path'], '/');
            $db = substr_count($b['path'], '/');
            if ($da === $db) {
                return strcmp($a['path'], $b['path']);
            }
            return $da - $db;
        });

        return [
            'success' => true,
            'data' => [
                'format' => 'termi-story-export',
                'version' => 1,
                'exported_at' => gmdate('c'),
                'theme' => $this->getThemeSettings(),
                'filesystem' => $filesystem,
            ],
        ];
    }

    public function importStory($payload, $mode = 'merge', $includeTheme = false) {
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'Invalid import payload.'];
        }

        $format = $payload['format'] ?? '';
        $version = $payload['version'] ?? null;
        if ($format !== 'termi-story-export' || (int)$version !== 1) {
            return ['success' => false, 'message' => 'Unrecognized export format. Expected termi-story-export version 1.'];
        }

        $filesystem = $payload['filesystem'] ?? null;
        if (!is_array($filesystem)) {
            return ['success' => false, 'message' => 'Export is missing a filesystem array.'];
        }

        $mode = ($mode === 'replace') ? 'replace' : 'merge';
        $includeTheme = (bool)$includeTheme;

        // Validate entries before mutating
        $normalized = [];
        foreach ($filesystem as $index => $entry) {
            if (!is_array($entry)) {
                return ['success' => false, 'message' => "Filesystem entry #{$index} is invalid."];
            }
            $path = isset($entry['path']) ? trim((string)$entry['path']) : '';
            $type = $entry['type'] ?? '';
            if ($path === '' || $path === '/') {
                return ['success' => false, 'message' => "Filesystem entry #{$index} has an invalid path."];
            }
            if ($path[0] !== '/') {
                return ['success' => false, 'message' => "Path must be absolute: {$path}"];
            }
            if (!in_array($type, ['dir', 'txt', 'app', 'img'], true)) {
                return ['success' => false, 'message' => "Unsupported type '{$type}' for {$path}."];
            }
            $name = basename($path);
            if ($name === '' || $name === '.' || $name === '..') {
                return ['success' => false, 'message' => "Invalid name derived from path: {$path}"];
            }
            $normalized[] = [
                'path' => $path,
                'type' => $type,
                'content' => array_key_exists('content', $entry) ? $entry['content'] : null,
                'is_hidden' => !empty($entry['is_hidden']) ? 1 : 0,
                'password_hash' => $entry['password_hash'] ?? null,
                'password_hint' => $entry['password_hint'] ?? null,
                'owner_username' => $entry['owner_username'] ?? null,
                'depth' => substr_count($path, '/'),
            ];
        }

        usort($normalized, function ($a, $b) {
            if ($a['depth'] === $b['depth']) {
                return strcmp($a['path'], $b['path']);
            }
            return $a['depth'] - $b['depth'];
        });

        $this->db->query("SELECT id, username FROM users");
        $usersByName = [];
        foreach ($this->db->resultSet() as $user) {
            $usersByName[$user['username']] = (int)$user['id'];
        }

        try {
            $this->db->beginTransaction();

            if ($mode === 'replace') {
                // Children first: delete everything except root
                $this->db->query("DELETE FROM filesystem WHERE id <> 1");
                $this->db->execute();
            }

            // Rebuild path map from current DB state
            $pathToId = $this->buildPathToIdMap();

            $created = 0;
            $updated = 0;
            $skippedOwners = 0;

            foreach ($normalized as $entry) {
                $path = $entry['path'];
                $parentPath = dirname($path);
                if ($parentPath === '\\' || $parentPath === '.') {
                    $parentPath = '/';
                }
                // Normalize dirname of /foo -> /
                if ($parentPath !== '/') {
                    $parentPath = rtrim(str_replace('\\', '/', $parentPath), '/');
                    if ($parentPath === '') {
                        $parentPath = '/';
                    }
                }

                if (!isset($pathToId[$parentPath])) {
                    throw new Exception("Missing parent directory for {$path}. Ensure parent folders are included in the export.");
                }
                $parentId = $pathToId[$parentPath];
                $name = basename($path);

                $ownerId = null;
                if (!empty($entry['owner_username'])) {
                    if (isset($usersByName[$entry['owner_username']])) {
                        $ownerId = $usersByName[$entry['owner_username']];
                    } else {
                        $skippedOwners++;
                    }
                }

                $content = ($entry['type'] === 'dir') ? null : $entry['content'];
                $password = !empty($entry['password_hash']) ? $entry['password_hash'] : null;
                $hint = !empty($entry['password_hint']) ? $entry['password_hint'] : null;

                if (isset($pathToId[$path])) {
                    $id = $pathToId[$path];
                    $existing = $this->getItem($id);
                    if (!$existing) {
                        throw new Exception("Expected existing item missing for {$path}.");
                    }
                    if ($existing['type'] !== $entry['type']) {
                        throw new Exception("Type mismatch for {$path}: existing is {$existing['type']}, import is {$entry['type']}.");
                    }
                    $this->db->query("UPDATE filesystem SET
                            content = :content,
                            password = :password,
                            password_hint = :password_hint,
                            is_hidden = :is_hidden,
                            owner_id = :owner_id
                        WHERE id = :id");
                    $this->db->bind(':content', $content);
                    $this->db->bind(':password', $password);
                    $this->db->bind(':password_hint', $hint);
                    $this->db->bind(':is_hidden', $entry['is_hidden']);
                    $this->db->bind(':owner_id', $ownerId);
                    $this->db->bind(':id', $id);
                    $this->db->execute();
                    $updated++;
                    continue;
                }

                $this->db->query("INSERT INTO filesystem (parent_id, name, type, content, password, password_hint, is_hidden, owner_id)
                    VALUES (:parent_id, :name, :type, :content, :password, :password_hint, :is_hidden, :owner_id)");
                $this->db->bind(':parent_id', $parentId);
                $this->db->bind(':name', $name);
                $this->db->bind(':type', $entry['type']);
                $this->db->bind(':content', $content);
                $this->db->bind(':password', $password);
                $this->db->bind(':password_hint', $hint);
                $this->db->bind(':is_hidden', $entry['is_hidden']);
                $this->db->bind(':owner_id', $ownerId);
                $this->db->execute();
                $newId = (int)$this->db->lastInsertId();
                $pathToId[$path] = $newId;
                $created++;
            }

            if ($includeTheme && isset($payload['theme']) && is_array($payload['theme'])) {
                $allowed = [
                    'background_color', 'text_color', 'prompt_color_user', 'prompt_color_path',
                    'terminal_title', 'login_greeting', 'motd'
                ];
                $themeData = [];
                foreach ($allowed as $key) {
                    if (array_key_exists($key, $payload['theme'])) {
                        $themeData[$key] = $payload['theme'][$key];
                    }
                }
                if (!empty($themeData)) {
                    $this->updateThemeSettings($themeData);
                }
            }

            $this->db->commit();

            $message = "Import complete ({$mode}): {$created} created, {$updated} updated.";
            if ($skippedOwners > 0) {
                $message .= " {$skippedOwners} owner username(s) not found locally and were left as All Users.";
            }
            return [
                'success' => true,
                'message' => $message,
                'created' => $created,
                'updated' => $updated,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Import failed: ' . $e->getMessage()];
        }
    }

    private function buildPathToIdMap() {
        $this->db->query("SELECT id, parent_id, name FROM filesystem");
        $items = $this->db->resultSet();
        $itemsById = [];
        foreach ($items as $item) {
            $itemsById[(int)$item['id']] = $item;
        }

        $paths = [];
        $build = function ($itemId) use (&$build, &$paths, $itemsById) {
            if (isset($paths[$itemId])) {
                return $paths[$itemId];
            }
            if (!isset($itemsById[$itemId])) {
                return null;
            }
            $item = $itemsById[$itemId];
            if ($item['parent_id'] === null) {
                $paths[$itemId] = '/';
                return '/';
            }
            $parentPath = $build((int)$item['parent_id']);
            if ($parentPath === null) {
                return null;
            }
            $path = rtrim($parentPath, '/') . '/' . $item['name'];
            $paths[$itemId] = $path;
            return $path;
        };

        $map = [];
        foreach ($itemsById as $id => $item) {
            $path = $build($id);
            if ($path !== null) {
                $map[$path] = $id;
            }
        }
        return $map;
    }

}
