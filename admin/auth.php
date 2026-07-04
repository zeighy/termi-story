<?php
session_start();

// --- Configuration ---
const ADMIN_USERNAME = 'storymanager';
// Replace this with the hash you generated from the command line from hash_password.php
const ADMIN_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$cFZOMXNKL0sxY1NUbnA5dw$izCEnEsvk4Tr4SqhMMNY6Z9KqRhUrY1JoO6R/L6nnWA';

// --- Authentication Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Verify the password against the hash
    if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD_HASH)) {
        // Login successful
        $_SESSION['is_admin_logged_in'] = true;
        header('Location: index.php');
        exit;
    }
}

// Login failed
header('Location: login.php?error=1');
exit;