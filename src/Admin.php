<?php
// src/Admin.php
require_once __DIR__ . '/Security.php';

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
                // jstree renders 'text' as HTML, so it is escaped; use data.name wherever plain text is needed
                'text' => htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8'),
                'type' => $item['type'],
                'data' => ['name' => $item['name']],
            ];
        }
        
        return json_encode($tree);
    }

    public function getItem($id) {
        $this->db->query("SELECT * FROM filesystem WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }

    // --- Content encoding ---
    // txt/app content is stored HTML-encoded exactly once. Encode on save; decode
    // before showing it in the editor, displaying it to players, or running a script.

    public static function encodeContent($raw) {
        return htmlspecialchars((string)$raw, ENT_QUOTES, 'UTF-8');
    }

    public static function decodeContent($stored) {
        return htmlspecialchars_decode((string)$stored, ENT_QUOTES);
    }

    /**
     * An item as the admin editor needs it: txt/app content decoded to the text the
     * author wrote (so saving it again doesn't double-encode), and no password hash.
     */
    public function getItemForEditor($id) {
        $item = $this->getItem($id);
        if (!$item) {
            return false;
        }
        if ($item['type'] === 'txt' || $item['type'] === 'app') {
            $item['content'] = self::decodeContent($item['content']);
        }
        $item['has_password'] = !empty($item['password']);
        unset($item['password']);
        return $item;
    }

    // --- Input validation helpers ---

    const ITEM_TYPES = ['dir', 'txt', 'app', 'img'];
    const THEME_KEYS = [
        'background_color', 'text_color', 'prompt_color_user', 'prompt_color_path',
        'terminal_title', 'login_greeting', 'motd',
    ];
    const THEME_COLOR_KEYS = ['background_color', 'text_color', 'prompt_color_user', 'prompt_color_path'];

    private static function fail($message) {
        return ['success' => false, 'message' => $message];
    }

    /**
     * A positive integer ID from request input, or null. Strings must be all digits, so
     * values like "1abc" (which MySQL would silently treat as 1) are rejected.
     */
    public static function toId($value) {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            $id = (int)$value;
            return $id > 0 ? $id : null;
        }
        return null;
    }

    private static function withExtension($name, $type) {
        $extensions = ['txt' => '.txt', 'app' => '.app', 'img' => '.img'];
        if (isset($extensions[$type]) && substr($name, -4) !== $extensions[$type]) {
            $name .= $extensions[$type];
        }
        return $name;
    }

    /** Error message for an invalid item name, or null when it is fine. */
    private static function validateName($name) {
        if ($name === '.' || $name === '..') {
            return 'That name is reserved.';
        }
        if (preg_match('/[\/\\\\]/', $name)) {
            return 'Names cannot contain slashes.';
        }
        if (preg_match('/\s/u', $name)) {
            return 'Names cannot contain spaces: players type them as a single word (e.g. cat my_file.txt).';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return 'Names cannot contain control characters.';
        }
        if (mb_strlen($name) > 100) {
            return 'Names can be at most 100 characters (including the extension).';
        }
        return null;
    }

    /** Name from the form, trimmed and with its type's extension. Returns [name, error]. */
    private static function prepareName($rawName, $type) {
        $raw = is_string($rawName) ? trim($rawName) : '';
        if ($raw === '') {
            return [null, 'Name is required.'];
        }
        $name = self::withExtension($raw, $type);
        return [$name, self::validateName($name)];
    }

    private function userExists($id) {
        $this->db->query("SELECT id FROM users WHERE id = :id");
        $this->db->bind(':id', $id);
        return (bool)$this->db->single();
    }

    /** Owner from the form: null for All Users, an existing user's ID, or false if invalid. */
    private function resolveOwnerId($value) {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        $id = self::toId($value);
        if ($id === null || !$this->userExists($id)) {
            return false;
        }
        return $id;
    }

    private static function optionalString($value) {
        return (is_string($value) && $value !== '') ? $value : null;
    }

    private static function isDuplicateKey(PDOException $e) {
        return $e->getCode() == 23000;
    }

    // --- Filesystem changes ---

    public function addItem($data) {
        if (!is_array($data)) {
            return self::fail('Invalid request.');
        }
        $type = $data['type'] ?? '';
        if (!in_array($type, self::ITEM_TYPES, true)) {
            return self::fail('Invalid item type.');
        }
        $parentId = self::toId($data['parent_id'] ?? null);
        $parent = $parentId !== null ? $this->getItem($parentId) : false;
        if (!$parent || $parent['type'] !== 'dir') {
            return self::fail('Choose an existing directory to add the item to.');
        }
        [$name, $nameError] = self::prepareName($data['name'] ?? null, $type);
        if ($nameError !== null) {
            return self::fail($nameError);
        }
        $ownerId = $this->resolveOwnerId($data['owner_id'] ?? null);
        if ($ownerId === false) {
            return self::fail('The selected owner does not exist.');
        }

        $plainPassword = self::optionalString($data['password'] ?? null);
        $password = $plainPassword !== null ? password_hash($plainPassword, PASSWORD_ARGON2ID) : null;

        if ($type === 'img') {
            $content = (string)($data['image_width'] ?? '') . ';' . (string)($data['image_content'] ?? '');
        } elseif ($type === 'dir') {
            $content = null;
        } else {
            $content = self::encodeContent($data['content'] ?? '');
        }

        $this->db->query("INSERT INTO filesystem (parent_id, name, type, content, password, password_hint, is_hidden, owner_id)
                VALUES (:parent_id, :name, :type, :content, :password, :password_hint, :is_hidden, :owner_id)");
        $this->db->bind(':parent_id', $parentId);
        $this->db->bind(':name', $name);
        $this->db->bind(':type', $type);
        $this->db->bind(':content', $content);
        $this->db->bind(':password', $password);
        $this->db->bind(':password_hint', $password !== null ? self::optionalString($data['password_hint'] ?? null) : null);
        $this->db->bind(':is_hidden', !empty($data['is_hidden']) ? 1 : 0);
        $this->db->bind(':owner_id', $ownerId);

        try {
            $this->db->execute();
            return ['success' => true, 'message' => 'Item added successfully!'];
        } catch (PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return self::fail('Error: An item with this name already exists in this directory.');
            }
            error_log('termi-story: addItem failed: ' . $e->getMessage());
            return self::fail('Database error.');
        }
    }

    public function updateItem($data) {
        if (!is_array($data)) {
            return self::fail('Invalid request.');
        }
        $id = self::toId($data['id'] ?? null);
        if ($id === null) {
            return self::fail('ID and Name are required.');
        }
        if ($id === 1) {
            return self::fail('The root directory cannot be edited.');
        }
        $item = $this->getItem($id);
        if (!$item) {
            return self::fail('Item not found.');
        }
        [$name, $nameError] = self::prepareName($data['name'] ?? null, $item['type']);
        if ($nameError !== null) {
            return self::fail($nameError);
        }
        $ownerId = $this->resolveOwnerId($data['owner_id'] ?? null);
        if ($ownerId === false) {
            return self::fail('The selected owner does not exist.');
        }

        // Lock: remove it, replace it, or keep the current one (blank password field)
        if (!empty($data['remove_password'])) {
            $password = null;
            $hint = null;
        } else {
            $newPassword = self::optionalString($data['password'] ?? null);
            $password = $newPassword !== null ? password_hash($newPassword, PASSWORD_ARGON2ID) : $item['password'];
            $hint = $password !== null ? self::optionalString($data['password_hint'] ?? null) : null;
        }

        if ($item['type'] === 'img') {
            if (!empty($data['edit_image_content'])) {
                $content = (string)($data['edit_image_width'] ?? '') . ';' . (string)$data['edit_image_content'];
            } else {
                $content = $item['content'];
            }
        } elseif ($item['type'] === 'dir') {
            $content = null;
        } else {
            $content = self::encodeContent($data['content'] ?? '');
        }

        $this->db->query("UPDATE filesystem SET
                    name = :name,
                    content = :content,
                    password = :password,
                    password_hint = :password_hint,
                    is_hidden = :is_hidden,
                    owner_id = :owner_id
                WHERE id = :id");
        $this->db->bind(':id', $id);
        $this->db->bind(':name', $name);
        $this->db->bind(':content', $content);
        $this->db->bind(':password', $password);
        $this->db->bind(':password_hint', $hint);
        $this->db->bind(':is_hidden', !empty($data['is_hidden']) ? 1 : 0);
        $this->db->bind(':owner_id', $ownerId);

        try {
            $this->db->execute();
            return ['success' => true, 'message' => 'Item updated successfully!'];
        } catch (PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return self::fail('Error: An item with this name already exists in this directory.');
            }
            error_log('termi-story: updateItem failed: ' . $e->getMessage());
            return self::fail('Database error.');
        }
    }

    public function deleteItem($id) {
        $id = self::toId($id);
        if ($id === null) {
            return self::fail('Invalid item ID.');
        }
        if ($id === 1) {
            return self::fail('Cannot delete the root directory.');
        }
        if (!$this->getItem($id)) {
            return self::fail('Item not found.');
        }

        $this->db->query("SELECT id FROM filesystem WHERE parent_id = :id");
        $this->db->bind(':id', $id);
        if (count($this->db->resultSet()) > 0) {
            return self::fail('Cannot delete a directory that is not empty.');
        }

        $this->db->query("DELETE FROM filesystem WHERE id = :id");
        $this->db->bind(':id', $id);
        $this->db->execute();
        return ['success' => true, 'message' => 'Item deleted successfully.'];
    }

    public function moveItem($data) {
        $id = self::toId(is_array($data) ? ($data['id'] ?? null) : null);
        $newParentId = self::toId(is_array($data) ? ($data['new_parent_id'] ?? null) : null);
        if ($id === null || $newParentId === null) {
            return self::fail('Item ID and New Parent ID are required.');
        }
        if ($id === 1) {
            return self::fail('Cannot move the root directory.');
        }
        if ($id === $newParentId) {
            return self::fail('Cannot move an item into itself.');
        }

        $item = $this->getItem($id);
        if (!$item) {
            return self::fail('Item not found.');
        }

        $newParent = $this->getItem($newParentId);
        if (!$newParent || $newParent['type'] !== 'dir') {
            return self::fail('Invalid destination directory.');
        }

        // Prevent moving a directory into its own descendants
        if ($item['type'] === 'dir') {
            $checkId = $newParentId;
            for ($depth = 0; $checkId !== null && $checkId !== 1; $depth++) {
                if ($checkId === $id) {
                    return self::fail('Cannot move a directory into its own descendant.');
                }
                if ($depth > 1000) {
                    return self::fail('The directory tree is too deep or contains a loop.');
                }
                $ancestor = $this->getItem($checkId);
                $checkId = ($ancestor && $ancestor['parent_id'] !== null) ? (int)$ancestor['parent_id'] : null;
            }
        }

        $this->db->query("UPDATE filesystem SET parent_id = :new_parent_id WHERE id = :id");
        $this->db->bind(':new_parent_id', $newParentId);
        $this->db->bind(':id', $id);

        try {
            $this->db->execute();
            return ['success' => true, 'message' => 'Item moved successfully!'];
        } catch (PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return self::fail('Error: An item with this name already exists in the destination directory.');
            }
            error_log('termi-story: moveItem failed: ' . $e->getMessage());
            return self::fail('Database error.');
        }
    }

    // --- User Methods ---

    public function getAllUsers() {
        $this->db->query("SELECT id, username FROM users ORDER BY username");
        return $this->db->resultSet();
    }

    /** Error message for an invalid username, or null. */
    private static function validateUsername($username) {
        if (!is_string($username) || trim($username) === '') {
            return 'Username is required.';
        }
        if ($username !== trim($username)) {
            return 'Usernames cannot start or end with spaces.';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $username)) {
            return 'Usernames cannot contain control characters.';
        }
        if (mb_strlen($username) > 50) {
            return 'Usernames can be at most 50 characters.';
        }
        return null;
    }

    public function addUser($data) {
        $username = is_array($data) ? ($data['username'] ?? null) : null;
        $password = is_array($data) ? self::optionalString($data['password'] ?? null) : null;
        if ($error = self::validateUsername($username)) {
            return self::fail($error);
        }
        if ($password === null) {
            return self::fail('Username and password are required.');
        }

        $this->db->query("INSERT INTO users (username, password, home_directory_id) VALUES (:username, :password, 1)");
        $this->db->bind(':username', $username);
        $this->db->bind(':password', password_hash($password, PASSWORD_ARGON2ID));

        try {
            $this->db->execute();
            return ['success' => true, 'message' => 'User added successfully.'];
        } catch (PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return self::fail('Username already exists.');
            }
            error_log('termi-story: addUser failed: ' . $e->getMessage());
            return self::fail('Database error.');
        }
    }

    public function updateUser($data) {
        $id = self::toId(is_array($data) ? ($data['user_id'] ?? null) : null);
        $username = is_array($data) ? ($data['username'] ?? null) : null;
        if ($id === null) {
            return self::fail('User ID and username are required.');
        }
        if ($error = self::validateUsername($username)) {
            return self::fail($error);
        }
        if (!$this->userExists($id)) {
            return self::fail('User not found.');
        }

        $password = self::optionalString($data['password'] ?? null);
        if ($password === null) {
            $this->db->query("UPDATE users SET username = :username WHERE id = :id");
        } else {
            $this->db->query("UPDATE users SET username = :username, password = :password WHERE id = :id");
            $this->db->bind(':password', password_hash($password, PASSWORD_ARGON2ID));
        }
        $this->db->bind(':id', $id);
        $this->db->bind(':username', $username);

        try {
            $this->db->execute();
            return ['success' => true, 'message' => 'User updated successfully.'];
        } catch (PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return self::fail('Username already exists.');
            }
            error_log('termi-story: updateUser failed: ' . $e->getMessage());
            return self::fail('Database error.');
        }
    }

    public function deleteUser($id) {
        $id = self::toId($id);
        if ($id === null) {
            return self::fail('User ID is required.');
        }
        $this->db->query("DELETE FROM users WHERE id = :id");
        $this->db->bind(':id', $id);
        $this->db->execute();
        return ['success' => true, 'message' => 'User deleted successfully.'];
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

    /**
     * Validate theme settings. Returns [cleanSettings, error]. Unknown keys are ignored;
     * colours must be hex (#rgb, #rgba, #rrggbb or #rrggbbaa) because they are written into CSS.
     */
    public static function validateThemeSettings($data) {
        if (!is_array($data)) {
            return [null, 'Invalid theme settings.'];
        }
        $clean = [];
        foreach (self::THEME_KEYS as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (!is_string($value)) {
                return [null, "Theme setting '{$key}' must be text."];
            }
            if (in_array($key, self::THEME_COLOR_KEYS, true) && !Security::isHexColor($value)) {
                return [null, "Theme setting '{$key}' must be a hex colour such as #1a1a1a."];
            }
            $clean[$key] = $value;
        }
        return [$clean, null];
    }

    public function updateThemeSettings($data) {
        [$clean, $error] = self::validateThemeSettings($data);
        if ($error !== null) {
            return self::fail($error);
        }
        $this->db->query("UPDATE themes SET value = :value WHERE name = :name");
        foreach ($clean as $name => $value) {
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
            $rawPath = isset($entry['path']) ? trim((string)$entry['path']) : '';
            $type = $entry['type'] ?? '';
            if ($rawPath === '' || $rawPath[0] !== '/') {
                return ['success' => false, 'message' => "Path must be absolute: {$rawPath}"];
            }
            // Normalize: backslashes to slashes, collapse repeated slashes, drop trailing slash
            $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $rawPath)), function ($seg) {
                return $seg !== '';
            }));
            if (empty($segments)) {
                return ['success' => false, 'message' => "Filesystem entry #{$index} has an invalid path."];
            }
            foreach ($segments as $seg) {
                if ($seg === '.' || $seg === '..') {
                    return ['success' => false, 'message' => "Invalid path segment in: {$rawPath}"];
                }
            }
            $path = '/' . implode('/', $segments);
            if (!is_string($type) || !in_array($type, ['dir', 'txt', 'app', 'img'], true)) {
                return ['success' => false, 'message' => "Unsupported type for {$path}."];
            }
            foreach (['content', 'password_hash', 'password_hint', 'owner_username'] as $field) {
                if (isset($entry[$field]) && !is_string($entry[$field])) {
                    return ['success' => false, 'message' => "Field '{$field}' must be a string for {$path}."];
                }
            }
            $normalized[] = [
                'path' => $path,
                'type' => $type,
                'content' => array_key_exists('content', $entry) ? $entry['content'] : null,
                'is_hidden' => !empty($entry['is_hidden']) ? 1 : 0,
                'password_hash' => $entry['password_hash'] ?? null,
                'password_hint' => $entry['password_hint'] ?? null,
                'owner_username' => $entry['owner_username'] ?? null,
                'depth' => count($segments),
            ];
        }

        usort($normalized, function ($a, $b) {
            if ($a['depth'] === $b['depth']) {
                return strcmp($a['path'], $b['path']);
            }
            return $a['depth'] - $b['depth'];
        });

        // Validate the theme up front too, so a bad colour can't fail the import halfway
        $themeData = [];
        if ($includeTheme && isset($payload['theme']) && is_array($payload['theme'])) {
            [$themeData, $themeError] = self::validateThemeSettings($payload['theme']);
            if ($themeError !== null) {
                return ['success' => false, 'message' => "Import theme is invalid: {$themeError}"];
            }
        }

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
                $pathKey = strtolower($path);
                $parentPath = dirname($path);
                $parentKey = strtolower($parentPath);

                if (!isset($pathToId[$parentKey])) {
                    throw new Exception("Missing parent directory for {$path}. Ensure parent folders are included in the export.");
                }
                $parentId = $pathToId[$parentKey];
                $name = basename($path);

                $ownerId = null;
                $ownerUnknown = false;
                if (!empty($entry['owner_username'])) {
                    if (isset($usersByName[$entry['owner_username']])) {
                        $ownerId = $usersByName[$entry['owner_username']];
                    } else {
                        $skippedOwners++;
                        $ownerUnknown = true;
                    }
                }

                $content = ($entry['type'] === 'dir') ? null : $entry['content'];
                $password = !empty($entry['password_hash']) ? $entry['password_hash'] : null;
                $hint = !empty($entry['password_hint']) ? $entry['password_hint'] : null;

                if (isset($pathToId[$pathKey])) {
                    $id = $pathToId[$pathKey];
                    $existing = $this->getItem($id);
                    if (!$existing) {
                        throw new Exception("Expected existing item missing for {$path}.");
                    }
                    if ($existing['type'] !== $entry['type']) {
                        throw new Exception("Type mismatch for {$path}: existing is {$existing['type']}, import is {$entry['type']}.");
                    }
                    // Unknown owner username: keep the existing local owner rather than clearing it
                    if ($ownerUnknown) {
                        $ownerId = $existing['owner_id'] !== null ? (int)$existing['owner_id'] : null;
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
                $pathToId[$pathKey] = $newId;
                $created++;
            }

            if (!empty($themeData)) {
                $this->updateThemeSettings($themeData);
            }

            $this->db->commit();

            $message = "Import complete ({$mode}): {$created} created, {$updated} updated.";
            if ($skippedOwners > 0) {
                $message .= " {$skippedOwners} owner username(s) not found locally (new items are All Users; existing items kept their current owner).";
            }
            return [
                'success' => true,
                'message' => $message,
                'created' => $created,
                'updated' => $updated,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
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
                $map[strtolower($path)] = $id; // DB collation is case-insensitive
            }
        }
        return $map;
    }

}
