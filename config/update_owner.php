<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../src/Database.php';

try {
    $db = new Database();

    // Change owner_id to allow NULL and set default to NULL
    $sql1 = "ALTER TABLE `filesystem` MODIFY `owner_id` int(11) DEFAULT NULL";
    $db->query($sql1);
    $db->execute();

    // Set existing records to NULL (All users)
    $sql2 = "UPDATE `filesystem` SET `owner_id` = NULL";
    $db->query($sql2);
    $db->execute();

    echo "Database updated successfully.\n";
} catch (Exception $e) {
    echo "Error updating database: " . $e->getMessage() . "\n";
}
