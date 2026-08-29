<?php
/**
 * Database notification helpers.
 *
 * Notifications may optionally contain a target_url. Older databases are
 * upgraded automatically so existing notification records remain valid.
 */

function notificationColumnExists(mysqli $conn, string $column): bool
{
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM notifications LIKE '{$column}'");
    return $result && $result->num_rows > 0;
}

function ensureNotificationTable(mysqli $conn): void
{
    static $done = false;
    if ($done) return;

    $sql = "CREATE TABLE IF NOT EXISTS notifications (
        notification_id INT NOT NULL AUTO_INCREMENT,
        user_type VARCHAR(30) NOT NULL,
        user_id INT NULL,
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        target_url VARCHAR(500) NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (notification_id),
        KEY idx_notifications_user_read (user_type, user_id, is_read, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Unable to initialize notifications.');
    }

    if (!notificationColumnExists($conn, 'target_url')) {
        if (!$conn->query("ALTER TABLE notifications ADD COLUMN target_url VARCHAR(500) NULL AFTER message")) {
            throw new RuntimeException('Unable to upgrade notification records.');
        }
    }

    $done = true;
}

function createNotification(
    mysqli $conn,
    string $userType,
    int $userId,
    string $title,
    string $message,
    ?string $targetUrl = null
): bool {
    ensureNotificationTable($conn);

    if ($targetUrl !== null) {
        $targetUrl = substr($targetUrl, 0, 500);
    }

    $stmt = $conn->prepare(
        "INSERT INTO notifications (user_type, user_id, title, message, target_url)
         VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) return false;
    $stmt->bind_param('sisss', $userType, $userId, $title, $message, $targetUrl);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function inferNotificationTarget(string $userType, string $title, string $message): string
{
    $text = strtolower($title . ' ' . $message);

    if ($userType === 'admin') {
        if (str_contains($text, 'reservation')) {
            return '/LibraryBorrowingSystem/admin/reservations.php';
        }
        if (str_contains($text, 'low stock') || str_contains($text, 'book')) {
            return '/LibraryBorrowingSystem/admin/inventory.php';
        }
        if (str_contains($text, 'backup') || str_contains($text, 'restore')) {
            return '/LibraryBorrowingSystem/admin/backup_management.php';
        }
        if (str_contains($text, 'student')) {
            return '/LibraryBorrowingSystem/admin/student_records.php';
        }
        if (str_contains($text, 'transaction') || str_contains($text, 'borrow') || str_contains($text, 'return')) {
            return '/LibraryBorrowingSystem/admin/transactions.php';
        }
        return '/LibraryBorrowingSystem/admin/dashboard.php';
    }

    if (str_contains($text, 'reservation')) {
        return '/LibraryBorrowingSystem/student/profile.php#reservations';
    }
    if (str_contains($text, 'borrow') || str_contains($text, 'return')) {
        return '/LibraryBorrowingSystem/student/profile.php#borrowing-history';
    }

    return '/LibraryBorrowingSystem/student/profile.php';
}

function getUnreadNotificationCount(mysqli $conn, string $userType, int $userId): int
{
    ensureNotificationTable($conn);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM notifications
         WHERE user_type = ? AND user_id = ? AND is_read = 0"
    );
    if (!$stmt) return 0;
    $stmt->bind_param('si', $userType, $userId);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $count;
}

function getRecentNotifications(mysqli $conn, string $userType, int $userId, int $limit = 10): array
{
    ensureNotificationTable($conn);
    $limit = max(1, min(50, $limit));
    $rows = [];
    $stmt = $conn->prepare(
        "SELECT notification_id, title, message, target_url, is_read, created_at
         FROM notifications
         WHERE user_type = ? AND user_id = ?
         ORDER BY created_at DESC
         LIMIT {$limit}"
    );
    if (!$stmt) return $rows;
    $stmt->bind_param('si', $userType, $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        if (empty($row['target_url'])) {
            $row['target_url'] = inferNotificationTarget($userType, $row['title'], $row['message']);
        }
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}
?>
