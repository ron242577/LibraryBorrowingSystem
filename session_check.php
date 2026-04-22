<?php
/**
 * Session Check Helper - Library Borrowing System
 * Include this file at the top of protected pages to verify user authentication
 * 
 * Usage: require_once __DIR__ . '/../session_check.php';
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    // Redirect to login page if not authenticated
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

// Optional: Check if session has expired (30 minutes timeout)
$timeout_duration = 30 * 60; // 30 minutes in seconds
$current_time = time();

if (isset($_SESSION['login_time']) && ($current_time - $_SESSION['login_time']) > $timeout_duration) {
    // Session expired
    session_destroy();
    header('Location: /LibraryBorrowingSystem/login.php?expired=1');
    exit();
}

// Update session activity time
$_SESSION['login_time'] = $current_time;

/**
 * Check if user has a specific role
 * 
 * @param string $role The role to check ('super_admin' or 'librarian')
 * @return bool True if user has the role, false otherwise
 */
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * Check if user is super admin
 * 
 * @return bool True if user is super admin, false otherwise
 */
function isSuperAdmin() {
    return hasRole('super_admin');
}

/**
 * Check if user is librarian
 * 
 * @return bool True if user is librarian, false otherwise
 */
function isLibrarian() {
    return hasRole('librarian');
}

/**
 * Get current user's full name
 * 
 * @return string User's full name
 */
function getUserFullName() {
    return isset($_SESSION['full_name']) ? $_SESSION['full_name'] : 'User';
}

/**
 * Get current user's username
 * 
 * @return string User's username
 */
function getUsername() {
    return isset($_SESSION['username']) ? $_SESSION['username'] : '';
}

/**
 * Get current user's role
 * 
 * @return string User's role
 */
function getUserRole() {
    return isset($_SESSION['role']) ? $_SESSION['role'] : '';
}
