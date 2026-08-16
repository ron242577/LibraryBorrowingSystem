<?php
/**
 * Secure admin logout.
 */
require_once __DIR__ . '/db.php';
startSecureSession();

if (!empty($_SESSION['user_id'])) {
    auditLogEvent($conn, 'admin_logout', 'logout', 'Admin logged out of the system.', 'success');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?: '',
        'secure' => (bool)$params['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
session_destroy();
header('Location: /LibraryBorrowingSystem/login.php?logout=1');
exit();
