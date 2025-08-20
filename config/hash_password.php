<?php
// hash_password.php
if (isset($argv[1])) {
    $password = $argv[1];
    $hash = password_hash($password, PASSWORD_ARGON2ID);
    echo "Password: " . $password . "\n";
    echo "Hash:     " . $hash . "\n";
} else {
    echo "Usage: php hash_password.php 'YourPasswordHere'\n";
}
?>