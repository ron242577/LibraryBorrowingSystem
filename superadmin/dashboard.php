<?php
/**
 * Super Admin Dashboard - Library Borrowing System
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

// Check if user is super admin
if (!isSuperAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

// Get statistics from database
$total_librarians = 0;
$total_students = 0;
$total_books = 0;
$backup_message = '';

try {
    // Get total librarians
    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'librarian' AND status = 'active'");
    if ($result && $row = $result->fetch_assoc()) {
        $total_librarians = $row['count'];
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
    try {
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
        $backup_message = 'Error: ' . htmlspecialchars($e->getMessage());
        logError('Backup error: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - Library Borrowing System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #F3F7FC;
            color: #202A44;
        }
        
        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.12);
        }
        
        .card h2 {
            color: #141F52;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .card p {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .card-icon {
            font-size: 40px;
            margin-bottom: 15px;
        }
        
        .welcome-section {
            background: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .welcome-section h2 {
            color: #202A44;
            margin-bottom: 10px;
        }
        
        .welcome-section p {
            color: #666;
            line-height: 1.8;
            margin-bottom: 10px;
        }
        
        .role-badge {
            display: inline-block;
            background: #141F52;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 10px;
        }
        
        .features-list {
            background: #F7F9FC;
            padding: 20px;
            border-radius: 5px;
            margin-top: 20px;
        }
        
        .features-list h3 {
            color: #202A44;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .features-list ul {
            list-style: none;
            padding-left: 0;
        }
        
        .features-list li {
            padding: 8px 0;
            color: #666;
            display: flex;
            align-items: center;
        }
        
        .features-list li:before {
            content: "✓";
            color: #141F52;
            font-weight: bold;
            margin-right: 10px;
            font-size: 18px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: #141F52;
            color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(20, 31, 82, 0.3);
            text-align: center;
            transition: transform 0.3s;
            border-bottom: 4px solid #F4F916;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 48px;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .stat-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .backup-section {
            background: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .backup-section h3 {
            color: #202A44;
            margin-bottom: 15px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .backup-section p {
            color: #666;
            margin-bottom: 15px;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .backup-btn {
            background: #141F52;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .backup-btn:hover {
            background: #52618D;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(82, 97, 141, 0.4);
        }
        
        .backup-btn:active {
            transform: translateY(0);
        }
        
        .backup-info {
            background: #EDF3FA;
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
            border-left: 4px solid #141F52;
            font-size: 13px;
            color: #52618D;
        }
        
        .card-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .card-link:hover .card {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.12);
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>
    
    <div class="container">
        <div class="welcome-section">
            <h2>Welcome, <?php echo htmlspecialchars(getUserFullName()); ?>!</h2>
            <p>You are logged in as a <strong>Super Administrator</strong>.</p>
            <p>This dashboard provides you with full control over the Library Borrowing System. As a super admin, you have access to all features including user management, system configuration, and reporting.</p>
            <span class="role-badge">SUPER ADMIN</span>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Librarians</div>
                <div class="stat-number"><?php echo $total_librarians; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Total Students</div>
                <div class="stat-number"><?php echo $total_students; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Total Books</div>
                <div class="stat-number"><?php echo $total_books; ?></div>
            </div>
        </div>
        <div class="dashboard-grid">
            <a href="/LibraryBorrowingSystem/superadmin/librarian_management.php" class="card-link">
                <div class="card">
                    <h2>Librarian Management</h2>
                    <p>Add new librarians, reset passwords, and manage account status.</p>
                </div>
            </a>
            
            <a href="/LibraryBorrowingSystem/superadmin/reports.php" class="card-link">
                <div class="card">
                    <h2>Reports & Analytics</h2>
                    <p>Generate detailed reports on borrowing trends, overdue books, and system usage.</p>
                </div>
            </a>
            
            <a href="/LibraryBorrowingSystem/superadmin/student_records.php" class="card-link">
                <div class="card">
                    <h2>Student Records</h2>
                    <p>Access and manage student information and borrowing history.</p>
                </div>
            </a>

            <a href="/LibraryBorrowingSystem/librarian/inventory.php" class="card-link">
                <div class="card">
                    <h2>Add Book</h2>
                    <p>Register new books and auto-generate unique QR codes for inventory.</p>
                </div>
            </a>
            
            <a href="/LibraryBorrowingSystem/librarian/qr_borrow.php" class="card-link">
                <div class="card">
                    <h2>Book Borrowing</h2>
                    <p>Process student book borrowing using QR code scanner.</p>
                </div>
            </a>
            
            <a href="/LibraryBorrowingSystem/librarian/qr_return.php" class="card-link">
                <div class="card">
                    <h2>Book Returns</h2>
                    <p>Record book returns using QR scanner and calculate penalties.</p>
                </div>
            </a>

            <a href="/LibraryBorrowingSystem/librarian/inventory.php" class="card-link">
                <div class="card">
                    <h2>Inventory Status</h2>
                    <p>View current book availability and status of all library items.</p>
                </div>
            </a>
            
            <a href="/LibraryBorrowingSystem/librarian/transactions.php" class="card-link">
                <div class="card">
                    <h2>Transactions</h2>
                    <p>View all borrowing and return transactions with detailed information.</p>
                </div>
            </a>
        </div>
    </div>
</body>
</html>
