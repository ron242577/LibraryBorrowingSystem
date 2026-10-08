<?php
/**
 * Legacy route for catalogue book QR printing.
 * The canonical implementation lives in admin/qr_print.php.
 */
require_once __DIR__ . '/../session_check.php';

if (!isAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$params = [];
foreach (['qr_size', 'book_font', 'qrid_font', 'title_font'] as $key) {
    if (isset($_GET[$key]) && is_scalar($_GET[$key]) && $_GET[$key] !== '') {
        $params[$key] = (string)$_GET[$key];
    }
}

$target = '/LibraryBorrowingSystem/admin/qr_print.php';
if ($params) {
    $target .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: ' . $target, true, 302);
exit();
