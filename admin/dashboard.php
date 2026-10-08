<?php
/**
 * Chief Librarian Dashboard - Jose Abad Santos High School Library Borrowing System
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/library_rules.php';
require_once __DIR__ . '/../includes/notification_helper.php';

// Check if user is admin
if (!isAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

// Get statistics from database
$total_teachers = 0;
$total_students = 0;
$total_books = 0;
$today_attendance = 0;
$visitors_inside = 0;
$backup_message = '';

try {
    ensureNotificationTable($conn);
    $chiefLibrarianId = (int)($_SESSION['user_id'] ?? 0);

    $lowStock = 0;
    $low = $conn->query("SELECT COUNT(*) AS total FROM books WHERE COALESCE(is_archived,0)=0 AND available_copies <= 2");
    if ($low) $lowStock = (int)$low->fetch_assoc()['total'];

    if ($lowStock > 0 && $chiefLibrarianId > 0) {
        $title = 'Low Stock Alert';
        $msg = $lowStock . ' active book title(s) have two or fewer available copies.';
        $check = $conn->prepare("SELECT notification_id FROM notifications WHERE user_type='admin' AND user_id=? AND title=? AND message=? AND created_at >= (NOW() - INTERVAL 1 DAY) LIMIT 1");
        $check->bind_param('iss',$chiefLibrarianId,$title,$msg);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        if (!$exists) createNotification($conn,'admin',$chiefLibrarianId,$title,$msg,'/LibraryBorrowingSystem/admin/inventory.php');
    }

    $attendanceDate = $conn->real_escape_string(date('Y-m-d'));
    $attendanceStats = $conn->query("SELECT COUNT(*) AS visits, SUM(time_out IS NULL) AS inside FROM library_attendance WHERE visit_date = '{$attendanceDate}'");
    if ($attendanceStats && ($attendanceRow = $attendanceStats->fetch_assoc())) {
        $today_attendance = (int)($attendanceRow['visits'] ?? 0);
        $visitors_inside = (int)($attendanceRow['inside'] ?? 0);
    }

    if ($chiefLibrarianId > 0 && $conn->query("SHOW TABLES LIKE 'book_reservations'")->num_rows > 0) {
        $pending = $conn->query("SELECT COUNT(*) AS total FROM book_reservations WHERE status='pending'");
        $pendingCount = $pending ? (int)$pending->fetch_assoc()['total'] : 0;
        if ($pendingCount > 0) {
            $title='Pending Reservations';
            $msg=$pendingCount . ' reservation(s) are waiting to be processed.';
            $check=$conn->prepare("SELECT notification_id FROM notifications WHERE user_type='admin' AND user_id=? AND title=? AND message=? AND created_at >= (NOW() - INTERVAL 1 DAY) LIMIT 1");
            $check->bind_param('iss',$chiefLibrarianId,$title,$msg); $check->execute(); $exists=$check->get_result()->num_rows>0; $check->close();
            if(!$exists) createNotification($conn,'admin',$chiefLibrarianId,$title,$msg,'/LibraryBorrowingSystem/admin/reservations.php');
        }
    }
} catch (Throwable $notificationError) {
    logError('Dashboard notification generation error: ' . $notificationError->getMessage());
}

try {
    // Get all registered teacher accounts
    $result = $conn->query("SELECT COUNT(*) as count FROM teachers");
    if ($result && $row = $result->fetch_assoc()) {
        $total_teachers = $row['count'];
    }
    
    // Get total students
    $result = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'active'");
    if ($result && $row = $result->fetch_assoc()) {
        $total_students = $row['count'];
    }
    
    // Get total books
    $result = $conn->query("SELECT COUNT(*) as count FROM books");
    if ($result && $row = $result->fetch_assoc()) {
        $total_books = $row['count'];
    }
} catch (Exception $e) {
    logError('Error fetching dashboard statistics: ' . $e->getMessage());
}

// Handle database backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'backup') {
    try { requireValidCsrf($_POST['csrf_token'] ?? ''); } catch (Throwable $e) { $backup_message = $e->getMessage(); }
    if ($backup_message === '') try {
        // Get database name
        $db_name = 'library_borrowing_system';
        
        // Get all tables
        $tables = [];
        $result = $conn->query("SHOW TABLES");
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        
        // Generate SQL dump
        $sql_backup = "-- Library Borrowing System Database Backup\n";
        $sql_backup .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql_backup .= "-- Database: " . $db_name . "\n\n";
        $sql_backup .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
        foreach ($tables as $table) {
            // Get create table statement
            $result = $conn->query("SHOW CREATE TABLE `$table`");
            if ($result && $row = $result->fetch_row()) {
                $sql_backup .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql_backup .= $row[1] . ";\n\n";
            }
            
            // Get table data
            $result = $conn->query("SELECT * FROM `$table`");
            if ($result && $result->num_rows > 0) {
                $columns_result = $conn->query("SHOW COLUMNS FROM `$table`");
                $columns = [];
                while ($col = $columns_result->fetch_assoc()) {
                    $columns[] = $col['Field'];
                }
                
                while ($row = $result->fetch_assoc()) {
                    $values = [];
                    foreach ($columns as $col) {
                        if ($row[$col] === null) {
                            $values[] = "NULL";
                        } else {
                            $values[] = "'" . $conn->real_escape_string($row[$col]) . "'";
                        }
                    }
                    $sql_backup .= "INSERT INTO `$table` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n";
                }
                $sql_backup .= "\n";
            }
        }
        
        $sql_backup .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        // Send file for download
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="library_backup_' . date('Y-m-d_H-i-s') . '.sql"');
        echo $sql_backup;
        exit();
    } catch (Exception $e) {
        $backup_message = 'Unable to create the database backup right now.';
        logError('Backup error: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Chief Librarian Dashboard - Library Borrowing System</title>
    <style>
        /* Same base styles as the other admin pages (navbar.php + header.php rely on these) */
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter','Segoe UI',system-ui,-apple-system,Roboto,sans-serif; background:#F3F7FC; color:#202A44; overflow-x:hidden; padding-bottom:40px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../navbar.php'; ?>
<?php include __DIR__ . '/../header.php'; ?>

<?php
require_once __DIR__ . '/../includes/librarian_dashboard.php';
renderLibrarianDashboard($conn, getUserFullName());
?>
</body>
</html>
