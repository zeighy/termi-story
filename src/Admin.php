<?php
// src/Admin.php

class Admin {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // --- Filesystem Methods ---

    public function getFilesystemTree() {
        $this->db->query("SELECT * FROM filesystem ORDER BY parent_id, type DESC, name");
        $items = $this->db->resultSet();
        
        $children = [];
        foreach ($items as $item) {
            if ($item['parent_id'] !== null) {
                $children[$item['parent_id']][] = $item;
            }
        }
        
        $rootItem = null;
        foreach($items as $item) {
            if($item['id'] == 1) {
                $rootItem = $item;
                break;
            }
        }
        
        return $this->buildTreeHtml([$rootItem], $children, '/');
    }

    private function buildTreeHtml($parentItems, $children, $currentPath) {
        $html = '<ul>';
        foreach ($parentItems as $item) {
            $isDir = $item['type'] === 'dir';
            $class = $isDir ? 'dir' : 'file';
            $icon = $isDir ? '[D]' : '[F]';
            $path = rtrim($currentPath, '/') . '/' . $item['name'];
            
            $buttons = '';
            if ($item['id'] != 1) {
                $buttons = "<div class='item-actions'>
                                <button class='edit-btn' data-id='{$item['id']}'>Edit</button>
                                <button class='delete-btn' data-id='{$item['id']}'>Delete</button>
                            </div>";
            }
            
            $html .= "<li class='{$class}' data-id='{$item['id']}' data-path='{$path}'>";
            $html .= "<span class='item-name'>{$icon} " . htmlspecialchars($item['name']) . "</span>" . $buttons;
            
            if ($isDir && isset($children[$item['id']])) {
                $html .= $this->buildTreeHtml($children[$item['id']], $children, $path);
            }
            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
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
        }
        
        $password = !empty($data['password']) ? password_hash($data['password'], PASSWORD_ARGON2ID) : null;
        
        $sql = "INSERT INTO filesystem (parent_id, name, type, content, password, password_hint, is_hidden, owner_id) 
                VALUES (:parent_id, :name, :type, :content, :password, :password_hint, :is_hidden, 1)";
                
        $this->db->query($sql);
        $this->db->bind(':parent_id', $data['parent_id']);
        $this->db->bind(':name', $name);
        $this->db->bind(':type', $data['type']);
        $this->db->bind(':content', ($data['type'] !== 'dir') ? ($data['content'] ?? '') : null);
        $this->db->bind(':password', $password);
        $this->db->bind(':password_hint', !empty($data['password_hint']) ? $data['password_hint'] : null);
        $this->db->bind(':is_hidden', isset($data['is_hidden']) ? 1 : 0);

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
                    is_hidden = :is_hidden
                WHERE id = :id";
        
        $this->db->query($sql);
        $this->db->bind(':id', $data['id']);
        $this->db->bind(':name', $name);
        $this->db->bind(':content', ($item['type'] !== 'dir') ? ($data['content'] ?? '') : null);
        $this->db->bind(':password', $password);
        $this->db->bind(':password_hint', !empty($data['password_hint']) ? $data['password_hint'] : null);
        $this->db->bind(':is_hidden', isset($data['is_hidden']) && $data['is_hidden'] ? 1 : 0);

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
}