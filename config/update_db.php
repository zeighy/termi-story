<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../src/Database.php';

try {
    $db = new Database();
    $sql = "ALTER TABLE `filesystem` MODIFY `type` ENUM('dir','txt','app','img') NOT NULL";
    $db->query($sql);
    $db->execute();
    echo "Database updated successfully.\n";
} catch (Exception $e) {
    echo "Error updating database: " . $e->getMessage() . "\n";
}
