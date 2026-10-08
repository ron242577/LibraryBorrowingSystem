<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session_check.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'closed' => 0]);
    exit();
}

header('Content-Type: application/json; charset=UTF-8');
$closed = autoCloseForgottenAttendance($conn);
echo json_encode(['ok' => true, 'closed' => $closed, 'server_time' => date('Y-m-d H:i:s')]);
