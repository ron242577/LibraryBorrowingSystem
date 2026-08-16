<?php
/**
 * Protected admin session helper - Jose Abad Santos High School Library Borrowing System.
 */
require_once __DIR__ . '/db.php';
startSecureSession();

if (!isset($_SESSION['user_id'])) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$now = time();
$idleTimeout = 30 * 60;      // 30 minutes of inactivity
$absoluteTimeout = 8 * 60 * 60; // 8 hours maximum session lifetime

$fingerprint = createSessionFingerprint();
if (!empty($_SESSION['session_fingerprint']) && !hash_equals($_SESSION['session_fingerprint'], $fingerprint)) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: /LibraryBorrowingSystem/login.php?expired=1');
    exit();
}

$idleExpired = isset($_SESSION['login_time']) && ($now - (int)$_SESSION['login_time']) > $idleTimeout;
$absoluteExpired = isset($_SESSION['login_started_at']) && ($now - (int)$_SESSION['login_started_at']) > $absoluteTimeout;
if ($idleExpired || $absoluteExpired) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: /LibraryBorrowingSystem/login.php?expired=1');
    exit();
}

$_SESSION['login_time'] = $now;

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}
function isAdmin() { return hasRole('admin'); }
function getUserFullName() { return $_SESSION['full_name'] ?? 'User'; }
function getUsername() { return $_SESSION['username'] ?? ''; }
function getUserRole() { return $_SESSION['role'] ?? ''; }
