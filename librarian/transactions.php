<?php
/**
 * Transactions Report - Librarian Panel
 * View all borrowing and return transactions with detailed information
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

// Check if user is librarian
if (!isLibrarian() && !isSuperAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$transactions = [];
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_query = isset($_GET['search']) ? $_GET['search'] : '';

try {
    // Build query based on filters
    $query = "SELECT 
                t.transaction_id,
                t.date_borrowed,
                t.due_date,
                t.return_date,
                t.penalty_amount,
                t.status,
                s.student_id,
                s.full_name as student_name,
                s.contact_number,
                b.book_id,
                b.title as book_title,
                b.author
              FROM transactions t
              JOIN students s ON t.student_id = s.student_id
              JOIN books b ON t.book_id = b.book_id
              WHERE 1=1";
    
    // Add status filter
    if ($filter_status !== 'all') {
        $query .= " AND t.status = '" . $conn->real_escape_string($filter_status) . "'";
    }
    
    // Add search filter
    if (!empty($search_query)) {
        $search = $conn->real_escape_string($search_query);
        $query .= " AND (s.full_name LIKE '%$search%' OR b.title LIKE '%$search%' OR b.author LIKE '%$search%')";
    }
    
    $query .= " ORDER BY t.date_borrowed DESC";
    
    $result = $conn->query($query);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
    }
} catch (Exception $e) {
    logError('Error fetching transactions: ' . $e->getMessage());
}

// Count transactions by status for stats
$status_counts = ['all' => 0, 'borrowed' => 0, 'returned' => 0, 'overdue' => 0];
try {
    $count_query = "SELECT status, COUNT(*) as count FROM transactions GROUP BY status";
    $count_result = $conn->query($count_query);
    
    if ($count_result) {
        while ($row = $count_result->fetch_assoc()) {
            $status_counts[$row['status']] = $row['count'];
        }
        $status_counts['all'] = array_sum($status_counts);
    }
} catch (Exception $e) {
    logError('Error counting transactions: ' . $e->getMessage());
}

// Include navbar
require_once __DIR__ . '/../navbar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - Library Borrowing System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: #f5f7fa;
            color: #2c3e50;
            padding-bottom: 40px;
        }
        
        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
            margin-top: 100px;
        }
        
        .header {
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 28px;
            color: #2c3e50;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .header p {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-left: 5px solid #003366;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }
        
        .stat-card.active {
            background: linear-gradient(135deg, #00336615 0%, #00336215 100%);
            border-left-color: #003366;
        }
        
        .stat-card-all {
            border-left-color: #003366;
        }
        
        .stat-card-borrowed {
            border-left-color: #f39c12;
        }
        
        .stat-card-returned {
            border-left-color: #27ae60;
        }
        
        .stat-card-overdue {
            border-left-color: #e74c3c;
        }
        
        .stat-label {
            font-size: 13px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .stat-card-borrowed .stat-value {
            color: #f39c12;
        }
        
        .stat-card-returned .stat-value {
            color: #27ae60;
        }
        
        .stat-card-overdue .stat-value {
            color: #e74c3c;
        }
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }
        
        .filter-section h3 {
            font-size: 16px;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .filter-controls {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .search-box {
            flex: 1;
            min-width: 250px;
            display: flex;
            gap: 10px;
        }
        
        .search-box input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: #003366;
            box-shadow: 0 0 8px rgba(0, 51, 102, 0.2);
            background: #f8f9ff;
        }
        
        .btn {
            padding: 12px 24px;
            background: #003366;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(0, 51, 102, 0.3);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 16px rgba(0, 51, 102, 0.4);
        }
        
        .btn-reset {
            background: #95a5a6;
            box-shadow: 0 2px 8px rgba(149, 165, 166, 0.3);
        }
        
        .btn-reset:hover {
            box-shadow: 0 5px 16px rgba(149, 165, 166, 0.4);
        }
        
        .table-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .table-section h2 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #2c3e50;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f8f9fa;
            border-bottom: 2px solid #e0e0e0;
        }
        
        th {
            padding: 14px;
            text-align: left;
            font-weight: 600;
            color: #555;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 14px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        .student-cell {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .student-contact {
            font-size: 11px;
            color: #7f8c8d;
        }
        
        .book-cell {
            max-width: 180px;
        }
        
        .book-title {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 4px;
        }
        
        .book-author {
            font-size: 11px;
            color: #7f8c8d;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-borrowed {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-returned {
            background: #d4edda;
            color: #155724;
        }
        
        .status-overdue {
            background: #f8d7da;
            color: #721c24;
        }
        
        .penalty-amount {
            font-weight: 700;
            color: #f39c12;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 15px;
        }
        
        .empty-state-text {
            color: #95a5a6;
            font-size: 14px;
        }
        
        .result-count {
            color: #7f8c8d;
            font-size: 13px;
            margin-bottom: 15px;
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            .header h1 {
                font-size: 24px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-controls {
                flex-direction: column;
            }
            
            .search-box {
                flex-direction: column;
            }
            
            .table-wrapper {
                font-size: 12px;
            }
            
            th, td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📋 All Transactions</h1>
            <p>View complete borrowing and return transaction history with detailed information</p>
        </div>
        
        <!-- Status Filter Cards -->
        <div class="stats-grid">
            <a href="?status=all" class="stat-card stat-card-all <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                <div class="stat-label">All Transactions</div>
                <div class="stat-value"><?php echo $status_counts['all']; ?></div>
            </a>
            
            <a href="?status=borrowed" class="stat-card stat-card-borrowed <?php echo $filter_status === 'borrowed' ? 'active' : ''; ?>">
                <div class="stat-label">Borrowed</div>
                <div class="stat-value"><?php echo $status_counts['borrowed']; ?></div>
            </a>
            
            <a href="?status=returned" class="stat-card stat-card-returned <?php echo $filter_status === 'returned' ? 'active' : ''; ?>">
                <div class="stat-label">Returned</div>
                <div class="stat-value"><?php echo $status_counts['returned']; ?></div>
            </a>
            
            <a href="?status=overdue" class="stat-card stat-card-overdue <?php echo $filter_status === 'overdue' ? 'active' : ''; ?>">
                <div class="stat-label">Overdue</div>
                <div class="stat-value"><?php echo $status_counts['overdue']; ?></div>
            </a>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <h3>🔍 Search & Filter</h3>
            <form method="GET" class="filter-controls">
                <div class="search-box">
                    <input type="text" 
                           name="search" 
                           placeholder="Search by student name, book title, or author..." 
                           value="<?php echo htmlspecialchars($search_query); ?>">
                    <button type="submit" class="btn">Search</button>
                </div>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                <a href="/LibraryBorrowingSystem/librarian/transactions.php" class="btn btn-reset">Reset Filters</a>
            </form>
        </div>
        
        <!-- Transactions Table Section -->
        <div class="table-section">
            <h2>📊 Transaction Records</h2>
            
            <?php if (count($transactions) > 0): ?>
                <div class="result-count">
                    Showing <?php echo count($transactions); ?> transaction(s)
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Transaction ID</th>
                                <th>Student</th>
                                <th>Book</th>
                                <th>Borrowed Date</th>
                                <th>Due Date</th>
                                <th>Return Date</th>
                                <th>Status</th>
                                <th>Penalty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td>
                                        <small style="color: #003366; font-family: monospace; font-weight: 600;">
                                            #<?php echo str_pad($transaction['transaction_id'], 4, '0', STR_PAD_LEFT); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="student-cell"><?php echo htmlspecialchars($transaction['student_name']); ?></div>
                                        <div class="student-contact"><?php echo htmlspecialchars($transaction['contact_number'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td class="book-cell">
                                        <div class="book-title"><?php echo htmlspecialchars(substr($transaction['book_title'], 0, 25)); ?></div>
                                        <div class="book-author"><?php echo htmlspecialchars(substr($transaction['author'], 0, 30)); ?></div>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($transaction['date_borrowed'])); ?>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($transaction['due_date'])); ?>
                                    </td>
                                    <td>
                                        <?php if ($transaction['return_date']): ?>
                                            <?php echo date('M d, Y', strtotime($transaction['return_date'])); ?>
                                        <?php else: ?>
                                            <span style="color: #95a5a6;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                            <?php echo ucfirst($transaction['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($transaction['penalty_amount'] > 0): ?>
                                            <span class="penalty-amount">₱<?php echo number_format($transaction['penalty_amount'], 2); ?></span>
                                        <?php else: ?>
                                            <span style="color: #95a5a6;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📚</div>
                    <div class="empty-state-text">
                        <?php if (!empty($search_query) || $filter_status !== 'all'): ?>
                            No transactions found matching your criteria
                        <?php else: ?>
                            No transactions yet
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
