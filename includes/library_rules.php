<?php
/**
 * Borrowing and reservation policy helpers.
 * Defaults: maximum 3 active borrowed books; reservations expire after 3 days.
 */
function ensureLibraryRulesTable(mysqli $conn): void
{
    static $done = false;
    if ($done) return;

    $sql = "CREATE TABLE IF NOT EXISTS library_settings (
        setting_key VARCHAR(100) NOT NULL,
        setting_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Unable to initialize library settings.');
    }

    $defaults = [
        'max_active_books_per_student' => '3',
        'reservation_expiry_days' => '3'
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO library_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaults as $key => $value) {
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }
    $stmt->close();
    $done = true;
}

function getLibraryRule(mysqli $conn, string $key, int $default): int
{
    ensureLibraryRulesTable($conn);
    $stmt = $conn->prepare("SELECT setting_value FROM library_settings WHERE setting_key=? LIMIT 1");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $value = isset($row['setting_value']) ? (int)$row['setting_value'] : $default;
    return $value > 0 ? $value : $default;
}

function setLibraryRule(mysqli $conn, string $key, int $value): void
{
    ensureLibraryRulesTable($conn);
    if ($value <= 0) {
        throw new InvalidArgumentException('Setting value must be greater than zero.');
    }

    $stmt = $conn->prepare("
        INSERT INTO library_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
    ");
    $valueString = (string)$value;
    $stmt->bind_param('ss', $key, $valueString);
    $stmt->execute();
    $stmt->close();
}

function countActiveBorrowings(mysqli $conn, int $studentId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM transactions WHERE student_id=? AND status='borrowed'");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $total = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();
    return $total;
}

function expireStaleReservations(mysqli $conn): void
{
    try {
        ensureLibraryRulesTable($conn);

        $tableCheck = $conn->query("SHOW TABLES LIKE 'book_reservations'");
        if (!$tableCheck || $tableCheck->num_rows === 0) return;

        $days = getLibraryRule($conn, 'reservation_expiry_days', 3);
        $stmt = $conn->prepare("
            UPDATE book_reservations
            SET status='cancelled',
                cancelled_at=NOW(),
                notes=CASE
                    WHEN notes IS NULL OR notes='' THEN 'Automatically expired.'
                    ELSE CONCAT(notes, ' Automatically expired.')
                END
            WHERE status IN ('pending','ready')
              AND reserved_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->bind_param('i', $days);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        if (function_exists('logError')) logError('Reservation expiry error: ' . $e->getMessage());
    }
}

function getReadyReservationForBook(mysqli $conn, int $bookId): ?array
{
    $tableCheck = $conn->query("SHOW TABLES LIKE 'book_reservations'");
    if (!$tableCheck || $tableCheck->num_rows === 0) return null;

    $stmt = $conn->prepare("
        SELECT reservation_id, student_id, status, reserved_at
        FROM book_reservations
        WHERE book_id=? AND status='ready'
        ORDER BY reserved_at ASC
        LIMIT 1
    ");
    $stmt->bind_param('i', $bookId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function promoteOldestReservation(mysqli $conn, int $bookId): ?array
{
    $tableCheck = $conn->query("SHOW TABLES LIKE 'book_reservations'");
    if (!$tableCheck || $tableCheck->num_rows === 0) return null;

    $stmt = $conn->prepare("
        SELECT reservation_id, student_id
        FROM book_reservations
        WHERE book_id=? AND status='pending'
        ORDER BY reserved_at ASC
        LIMIT 1
    ");
    $stmt->bind_param('i', $bookId);
    $stmt->execute();
    $reservation = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$reservation) return null;

    $bookStmt=$conn->prepare("SELECT available_copies, title FROM books WHERE book_id=? LIMIT 1");
    $bookStmt->bind_param('i',$bookId);
    $bookStmt->execute();
    $book=$bookStmt->get_result()->fetch_assoc();
    $bookStmt->close();

    if (!$book || (int)$book['available_copies'] <= 0) return null;

    $readyStmt=$conn->prepare("
        UPDATE book_reservations SET status='ready', ready_at=NOW()
        WHERE reservation_id=? AND status='pending'
    ");
    $readyStmt->bind_param('i',$reservation['reservation_id']);
    $readyStmt->execute();
    $affected=$readyStmt->affected_rows;
    $readyStmt->close();

    if ($affected <= 0) return null;

    if (function_exists('createNotification')) {
        createNotification(
            $conn,
            'student',
            (int)$reservation['student_id'],
            'Reserved Book Available',
            'A copy of "' . $book['title'] . '" is now available. Your reservation is ready.'
        );
    }

    return $reservation;
}
?>
