<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/notification_helper.php';

header('Content-Type: application/json; charset=utf-8');

$userType = null;
$userId = 0;

if (isset($_SESSION['student_id'])) {
    $userType = 'student';
    $userId = (int)$_SESSION['student_id'];
} elseif (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin') {
    $userType = 'admin';
    $userId = (int)$_SESSION['user_id'];
}

if (!$userType || $userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'message'=>'Not authenticated.']);
    exit;
}

try {
    ensureNotificationTable($conn);
    $action = $_GET['action'] ?? '';

    if ($action === 'list') {
        echo json_encode([
            'ok'=>true,
            'unread'=>getUnreadNotificationCount($conn,$userType,$userId),
            'notifications'=>getRecentNotifications($conn,$userType,$userId,12)
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'read') {
        $id=(int)($_POST['notification_id'] ?? 0);
        if ($id>0) {
            $stmt=$conn->prepare("UPDATE notifications SET is_read=1 WHERE notification_id=? AND user_type=? AND user_id=?");
            $stmt->bind_param('isi',$id,$userType,$userId);
            $stmt->execute();
            $stmt->close();
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'read_all') {
        $stmt=$conn->prepare("UPDATE notifications SET is_read=1 WHERE user_type=? AND user_id=? AND is_read=0");
        $stmt->bind_param('si',$userType,$userId);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['ok'=>true]);
        exit;
    }

    echo json_encode(['ok'=>false,'message'=>'Unknown notification action.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Notification service is temporarily unavailable.']);
}
