<?php
/**
 * Logout Page - Library Borrowing System
 * Destroys user session and redirects to login page
 */

session_start();

// Destroy all session data
$_SESSION = [];

// If there's a session cookie, delete it
if (ini_get('session.use_cookies') === '1') {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session
session_destroy();

// Redirect to login page with logout message
header('Location: /LibraryBorrowingSystem/login.php?logout=1');
exit();
