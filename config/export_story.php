<?php
// export_story.php - A command-line utility to export your Termi-Story content.
// Place this file in the root directory of your project.
// Usage from your terminal:
//   php export_story.php --single=my_story.txt
//   php export_story.php --individual=my_story_folder

// --- Environment Setup ---
// Ensure this script is run from the command line, not a web browser.
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

// Include necessary files
require_once './config/database.php';
require_once './src/Database.php';

// --- Argument Parsing ---
$options = getopt("", ["single::", "individual::"]);

if (empty($options)) {
    echo "Termi-Story Exporter\n";
    echo "Usage:\n";
    echo "  php export_story.php --single[=filename.txt]    Export all content to a single text file.\n";
    echo "  php export_story.php --individual[=directory]   Export content to individual files in a directory structure.\n";
    exit;
}

echo "Connecting to database...\n";
$db = new Database();

echo "Fetching filesystem data...\n";
$db->query("SELECT * FROM filesystem ORDER BY parent_id, name");
$items = $db->resultSet();

// --- Data Processing ---
// Build a map of items and their full paths
$paths = [];
$itemsById = [];
foreach ($items as $item) {
    $itemsById[$item['id']] = $item;
}

function getPath($itemId, $itemsById) {
    global $paths;
    if (isset($paths[$itemId])) {
        return $paths[$itemId];
    }
    if (!isset($itemsById[$itemId])) {
        return '';
    }
    $item = $itemsById[$itemId];
    if ($item['parent_id'] === null) {
        return '/';
    }
    $path = rtrim(getPath($item['parent_id'], $itemsById), '/') . '/' . $item['name'];
    $paths[$itemId] = $path;
    return $path;
}

foreach ($items as $item) {
    getPath($item['id'], $itemsById);
}

// --- Export Logic ---
echo "WARNING: This process may take a long time depending on the amount of data.\n";
sleep(2); // Give user a moment to read the warning

if (isset($options['single'])) {
    $filename = $options['single'] ?: 'story_export.txt';
    echo "Exporting to single file: {$filename}\n";
    $fileContent = "Termi-Story Export\nGenerated on: " . date('Y-m-d H:i:s') . "\n\n";

    foreach ($items as $item) {
        if ($item['type'] === 'dir') continue; // Skip directories in single file export

        $fileContent .= str_repeat("=", 80) . "\n";
        $fileContent .= "PATH: " . ($paths[$item['id']] ?? 'N/A') . "\n";
        $fileContent .= "TYPE: [" . strtoupper($item['type']) . "]\n";
        $fileContent .= "HIDDEN: " . ($item['is_hidden'] ? 'Yes' : 'No') . "\n";
        if ($item['password']) {
            $fileContent .= "PROTECTED: Yes (Hint: " . ($item['password_hint'] ?? 'No hint') . ")\n";
        }
        $fileContent .= str_repeat("-", 80) . "\n";
        $fileContent .= $item['content'] . "\n\n";
    }

    file_put_contents($filename, $fileContent);
    echo "Successfully exported all content to {$filename}\n";
}

if (isset($options['individual'])) {
    $dirname = $options['individual'] ?: 'story_export';
    echo "Exporting to individual files in directory: {$dirname}\n";

    if (!is_dir($dirname)) {
        mkdir($dirname, 0755, true);
    }

    foreach ($items as $item) {
        $itemPath = $paths[$item['id']] ?? null;
        if (!$itemPath) continue;
        
        $targetPath = $dirname . $itemPath;

        if ($item['type'] === 'dir') {
            if (!is_dir($targetPath)) {
                mkdir($targetPath, 0755, true);
            }
        } else {
            file_put_contents($targetPath, $item['content']);
        }
    }
    echo "Successfully exported all content to the '{$dirname}' directory.\n";
}

echo "Export complete.\n";

