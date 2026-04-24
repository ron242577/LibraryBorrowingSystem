<?php
/**
 * Librarian Dashboard - Library Borrowing System
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

// Check if user is librarian (librarian can also be accessed by super_admin for testing)
if (!isLibrarian() && !isSuperAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

// Initialize statistics
$total_students = 0;
$total_books = 0;
$borrowed_books = 0;
$overdue_books = 0;
$latest_transactions = [];

try {
    // Get total active students
    $result = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'active'");
    if ($result && $row = $result->fetch_assoc()) {
        $total_students = $row['count'];
    }
    
    // Get total books
    $result = $conn->query("SELECT COUNT(*) as count FROM books");
    if ($result && $row = $result->fetch_assoc()) {
        $total_books = $row['count'];
    }
    
    // Get borrowed books (active transactions)
    $result = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status = 'borrowed'");
    if ($result && $row = $result->fetch_assoc()) {
        $borrowed_books = $row['count'];
    }
    
    // Get overdue books
    $result = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status = 'overdue'");
    if ($result && $row = $result->fetch_assoc()) {
        $overdue_books = $row['count'];
    }
    
    // Get latest 10 transactions with related data
    $query = "SELECT 
                t.transaction_id,
                t.date_borrowed,
                t.due_date,
                t.return_date,
                t.status,
                s.full_name as student_name,
                b.title as book_title,
                b.author
              FROM transactions t
              LEFT JOIN students s ON t.student_id = s.student_id
              LEFT JOIN books b ON t.book_id = b.book_id
              ORDER BY t.date_borrowed DESC
              LIMIT 10";
    
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $latest_transactions[] = $row;
        }
    }
} catch (Exception $e) {
    logError('Error fetching librarian dashboard statistics: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Librarian Dashboard - Library Borrowing System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            color: #333;
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
            color: #003366;
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
            color: #333;
            margin-bottom: 10px;
        }
        
        .welcome-section p {
            color: #666;
            line-height: 1.8;
            margin-bottom: 10px;
        }
        
        .role-badge {
            display: inline-block;
            background: #8B0000;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 10px;
        }
        
        .features-list {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            margin-top: 20px;
        }
        
        .features-list h3 {
            color: #333;
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
            color: #8B0000;
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
            background: #003366;
            color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 51, 102, 0.3);
            text-align: center;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 20px rgba(139, 0, 0, 0.4);
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
        
        .transactions-section {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }
        
        .transactions-section h3 {
            color: #333;
            margin-bottom: 20px;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        thead {
            background: #f9f9f9;
        }
        
        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #ddd;
            font-size: 14px;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }
        
        tbody tr:hover {
            background: #f9f9f9;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            display: inline-block;
        }
        
        .status-borrowed {
            background: #FFF3CD;
            color: #856404;
        }
        
        .status-returned {
            background: #D4EDDA;
            color: #155724;
        }
        
        .status-overdue {
            background: #F8D7DA;
            color: #721c24;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
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
            <p>You are logged in as a <strong>Librarian</strong>.</p>
            <p>This dashboard allows you to manage day-to-day library operations including book borrowing, student records, and transaction tracking.</p>
            <span class="role-badge">LIBRARIAN</span>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Students</div>
                <div class="stat-number"><?php echo $total_students; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Total Books</div>
                <div class="stat-number"><?php echo $total_books; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Borrowed Books</div>
                <div class="stat-number"><?php echo $borrowed_books; ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Overdue Books</div>
                <div class="stat-number"><?php echo $overdue_books; ?></div>
            </div>
        </div>
        
        <div class="transactions-section">
            <h3>Latest Transactions (Last 10)</h3>
            
            <?php if (count($latest_transactions) > 0): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Book</th>
                                <th>Author</th>
                                <th>Borrowed</th>
                                <th>Due Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($latest_transactions as $transaction): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($transaction['student_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['book_title'] ?? 'Unknown'); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['author'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($transaction['date_borrowed'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($transaction['due_date'])); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                            <?php echo ucfirst($transaction['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <p>No transactions yet.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="dashboard-grid">
            <a href="/LibraryBorrowingSystem/librarian/add_student.php" class="card-link">
                <div class="card">
                    <h2>Add Student</h2>
                    <p>Register new students and auto-generate unique QR codes for tracking.</p>
                </div>
            </a>
            
            <a href="/LibraryBorrowingSystem/librarian/add_book.php" class="card-link">
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
            
            <a href="/LibraryBorrowingSystem/librarian/search.php" class="card-link">
                <div class="card">
                    <h2>Student Search</h2>
                    <p>Search and view student information and borrowing history.</p>
                </div>
            </a>

            <a href="/LibraryBorrowingSystem/librarian/inventory.php" class="card-link">
                <div class="card">
                    <h2>Inventory Status</h2>
                    <p>View current book availability and status of all library items.</p>
                </div>
            </a>
            
            <a href="/LibraryBorrowingSystem/librarian/overdue.php" class="card-link">
                <div class="card">
                    <h2>Overdue Books</h2>
                    <p>Monitor and track overdue books with penalty calculations.</p>
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
