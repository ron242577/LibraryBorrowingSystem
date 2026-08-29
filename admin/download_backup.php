<?php
require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/backup_helper.php';

if (!isAdmin()) {
    header('HTTP/1.0 403 Forbidden');
    exit('Access Denied');
}

try {
    $filename = safeBackupFilename((string)($_GET['file'] ?? ''));
    $path = backupStorageDir() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit('Backup file not found.');
    }

    if (function_exists('auditLogEvent')) {
        auditLogEvent($conn, 'backup_download', 'admin/backup_management', 'Downloaded database backup.', 'success', 'backup', null, ['filename' => $filename, 'size' => filesize($path) ?: 0]);
    }

    header('Content-Type: application/sql');
    header('Content-Length: ' . (string)(filesize($path) ?: 0));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit();
} catch (Throwable $e) {
    logError('Backup download error: ' . $e->getMessage());
    http_response_code(400);
    exit('Unable to download the requested backup.');
}
