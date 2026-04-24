<?php
/**
 * Overdue Books Tracking - Librarian Panel
 * Monitor and track overdue books with penalty calculations
 * Penalty: 5 PHP per day
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

// Check if user is librarian
if (!isLibrarian() && !isSuperAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$overdue_books = [];
$total_penalty = 0;
$total_overdue = 0;

try {
    // Get all overdue books with penalty calculations
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
                s.qr_code,
                b.book_id,
                b.title as book_title,
                b.author,
                DATEDIFF(CURDATE(), t.due_date) as days_overdue
              FROM transactions t
              JOIN students s ON t.student_id = s.student_id
              JOIN books b ON t.book_id = b.book_id
              WHERE t.status = 'borrowed' AND t.due_date < CURDATE()
              ORDER BY t.due_date ASC";
    
    $result = $conn->query($query);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Calculate penalty: 5 PHP per day
            $days_late = max(0, (int)$row['days_overdue']);
            $calculated_penalty = $days_late * 5; // 5 PHP per day
            
            $row['calculated_penalty'] = $calculated_penalty;
            $overdue_books[] = $row;
            
            $total_penalty += $calculated_penalty;
            $total_overdue++;
        }
    }
} catch (Exception $e) {
    logError('Error fetching overdue books: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overdue Books - Library Borrowing System</title>
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
            max-width: 1200px;
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
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-left: 5px solid;
            transition: transform 0.3s;
        }
        
        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }
        
        .summary-card-overdue {
            border-left-color: #e74c3c;
        }
        
        .summary-card-penalty {
            border-left-color: #f39c12;
        }
        
        .summary-label {
            font-size: 13px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .summary-value {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .summary-card-overdue .summary-value {
            color: #e74c3c;
        }
        
        .summary-card-penalty .summary-value {
            color: #f39c12;
        }
        
        .summary-subtext {
            font-size: 12px;
            color: #95a5a6;
            margin-top: 8px;
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
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #555;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 12px;
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
        
        .book-cell {
            max-width: 150px;
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
        
        .days-overdue {
            font-weight: 700;
            padding: 6px 10px;
            border-radius: 6px;
            text-align: center;
            display: inline-block;
            background: #ffe6e6;
            color: #e74c3c;
            font-size: 12px;
        }
        
        .penalty-amount {
            font-weight: 700;
            color: #f39c12;
            font-size: 14px;
        }
        
        .penalty-currency {
            font-size: 11px;
            color: #7f8c8d;
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
        
        .empty-state-text strong {
            color: #27ae60;
            display: block;
            margin-top: 10px;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-label {
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .filter-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 8px 14px;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            color: #2c3e50;
            transition: all 0.3s;
        }
        
        .filter-btn:hover, .filter-btn.active {
            background: #003366;
            border-color: transparent;
            color: white;
        }
        
        @media (max-width: 768px) {
            .header h1 {
                font-size: 24px;
            }
            
            .summary-grid {
                grid-template-columns: 1fr;
            }
            
            .table-wrapper {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>Overdue Books Tracking</h1>
            <p>Monitor students with overdue books and track penalty calculations (5 PHP per day)</p>
        </div>
        
        <!-- Summary Cards -->
        <div class="summary-grid">
            <div class="summary-card summary-card-overdue">
                <div class="summary-label">Total Overdue</div>
                <div class="summary-value"><?php echo $total_overdue; ?></div>
                <div class="summary-subtext">Books past due date</div>
            </div>
            
            <div class="summary-card summary-card-penalty">
                <div class="summary-label">Total Penalty</div>
                <div class="summary-value">₱<?php echo number_format($total_penalty, 2); ?></div>
                <div class="summary-subtext">Accumulated fines</div>
            </div>
        </div>
        
        <!-- Table Section -->
        <div class="table-section">
            <h2>Overdue Book List</h2>
            
            <?php if (count($overdue_books) > 0): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Contact</th>
                                <th>Book</th>
                                <th>Due Date</th>
                                <th>Days Overdue</th>
                                <th>Penalty (5 PHP/day)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overdue_books as $book): ?>
                                <tr>
                                    <td class="student-cell">
                                        <?php echo htmlspecialchars($book['student_name']); ?>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars($book['contact_number'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td class="book-cell">
                                        <div class="book-title"><?php echo htmlspecialchars(substr($book['book_title'], 0, 25)); ?></div>
                                        <div class="book-author"><?php echo htmlspecialchars(substr($book['author'], 0, 30)); ?></div>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($book['due_date'])); ?>
                                    </td>
                                    <td>
                                        <span class="days-overdue"><?php echo $book['days_overdue']; ?> days</span>
                                    </td>
                                    <td>
                                        <span class="penalty-amount">₱<?php echo number_format($book['calculated_penalty'], 2); ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-text">
                        No overdue books
                        <strong>✓ All books have been returned on time!</strong>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
