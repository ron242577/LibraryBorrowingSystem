<?php
/**
 * Inventory Status Dashboard - Librarian Panel
 * Real-time monitoring of library book inventory
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

// Check if user is librarian or super admin
if (!isLibrarian() && !isSuperAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$message = '';
$message_type = '';

// Create QR codes directory reference
$qr_dir = __DIR__ . '/../qr_codes';

// Handle add copies action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add_copies') {
        $book_id = intval($_POST['book_id'] ?? 0);
        $copies_to_add = intval($_POST['copies_to_add'] ?? 0);
        
        if ($book_id <= 0) {
            $message = 'Invalid book ID.';
            $message_type = 'error';
        } elseif ($copies_to_add <= 0) {
            $message = 'Number of copies must be greater than 0.';
            $message_type = 'error';
        } else {
            try {
                // Get current book inventory
                $book_stmt = $conn->prepare("SELECT title, qr_code, total_copies, available_copies FROM books WHERE book_id = ?");
                $book_stmt->bind_param('i', $book_id);
                $book_stmt->execute();
                $book_result = $book_stmt->get_result();
                
                if ($book_result->num_rows === 0) {
                    $message = 'Book not found.';
                    $message_type = 'error';
                } else {
                    $book = $book_result->fetch_assoc();
                    
                    // Check if book has valid QR code
                    if (!file_exists($qr_dir . '/' . $book['qr_code'] . '.png')) {
                        $message = 'Book does not have a valid QR code.';
                        $message_type = 'error';
                    } else {
                        // Update inventory
                        $new_total = $book['total_copies'] + $copies_to_add;
                        $new_available = $book['available_copies'] + $copies_to_add;
                        
                        $update_stmt = $conn->prepare("UPDATE books SET total_copies = ?, available_copies = ? WHERE book_id = ?");
                        $update_stmt->bind_param('iii', $new_total, $new_available, $book_id);
                        
                        if ($update_stmt->execute()) {
                            $message = 'Added ' . $copies_to_add . ' copies to "' . htmlspecialchars($book['title']) . '". New total: ' . $new_total;
                            $message_type = 'success';
                        } else {
                            $message = 'Error updating inventory: ' . $conn->error;
                            $message_type = 'error';
                        }
                        $update_stmt->close();
                    }
                }
                $book_stmt->close();
            } catch (Exception $e) {
                $message = 'Error: ' . htmlspecialchars($e->getMessage());
                $message_type = 'error';
                logError('Inventory update error: ' . $e->getMessage());
            }
        }
    }
}

// Get inventory statistics
$stats = [
    'total_titles' => 0,
    'total_copies' => 0,
    'available_copies' => 0,
    'borrowed_copies' => 0,
    'lost_copies' => 0
];

try {
    $stats_result = $conn->query("
        SELECT 
            COUNT(DISTINCT book_id) as total_titles,
            SUM(total_copies) as total_copies,
            SUM(available_copies) as available_copies,
            SUM(borrowed_copies) as borrowed_copies,
            SUM(lost_copies) as lost_copies
        FROM books
    ");
    
    if ($stats_result) {
        $raw_stats = $stats_result->fetch_assoc();
        // Filter to only count books with QR codes
        $stats_with_qr = [
            'total_titles' => 0,
            'total_copies' => 0,
            'available_copies' => 0,
            'borrowed_copies' => 0,
            'lost_copies' => 0
        ];
        
        $qr_books = $conn->query("SELECT * FROM books");
        if ($qr_books) {
            while ($book = $qr_books->fetch_assoc()) {
                if (file_exists($qr_dir . '/' . $book['qr_code'] . '.png')) {
                    $stats_with_qr['total_titles']++;
                    $stats_with_qr['total_copies'] += $book['total_copies'];
                    $stats_with_qr['available_copies'] += $book['available_copies'];
                    $stats_with_qr['borrowed_copies'] += $book['borrowed_copies'];
                    $stats_with_qr['lost_copies'] += $book['lost_copies'];
                }
            }
        }
        $stats = $stats_with_qr;
    }
} catch (Exception $e) {
    logError('Error fetching inventory stats: ' . $e->getMessage());
}

// Get low stock books (available_copies <= 2)
$low_stock_books = [];
try {
    $low_stock_result = $conn->query("
        SELECT book_id, title, author, qr_code, available_copies, total_copies 
        FROM books 
        WHERE available_copies <= 2 
        ORDER BY available_copies ASC
    ");
    
    if ($low_stock_result) {
        while ($row = $low_stock_result->fetch_assoc()) {
            // Only include books that have a QR code file
            if (file_exists($qr_dir . '/' . $row['qr_code'] . '.png')) {
                $low_stock_books[] = $row;
            }
        }
    }
} catch (Exception $e) {
    logError('Error fetching low stock books: ' . $e->getMessage());
}

// Get all books with inventory details
$all_books = [];
try {
    $books_result = $conn->query("
        SELECT 
            book_id, 
            title, 
            author, 
            qr_code,
            total_copies, 
            available_copies, 
            borrowed_copies, 
            lost_copies, 
            book_status 
        FROM books 
        ORDER BY title ASC
    ");
    
    if ($books_result) {
        while ($row = $books_result->fetch_assoc()) {
            // Only include books that have a QR code file
            if (file_exists($qr_dir . '/' . $row['qr_code'] . '.png')) {
                $all_books[] = $row;
            }
        }
    }
} catch (Exception $e) {
    logError('Error fetching books inventory: ' . $e->getMessage());
}

// Helper function to get status badge
function getStatusBadge($available_copies, $total_copies) {
    if ($available_copies <= 0) {
        return '<span class="badge badge-danger">Out of Stock</span>';
    } elseif ($available_copies <= 2) {
        return '<span class="badge badge-warning">Low Stock</span>';
    } else {
        return '<span class="badge badge-success">Available</span>';
    }
}

// Include navbar
require_once __DIR__ . '/../navbar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Status - Library Borrowing System</title>
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
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
            margin-top: 80px;
        }
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-header h1 {
            color: #333;
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .page-header p {
            color: #666;
            font-size: 14px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        /* Summary Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.12);
        }
        
        .stat-card .icon {
            font-size: 32px;
            margin-bottom: 15px;
        }
        
        .stat-card .label {
            font-size: 13px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .stat-card .value {
            font-size: 36px;
            font-weight: 700;
            color: #333;
        }
        
        .stat-card.blue .value {
            color: #2196F3;
        }
        
        .stat-card.green .value {
            color: #8B0000;
        }
        
        .stat-card.orange .value {
            color: #FF9800;
        }
        
        .stat-card.red .value {
            color: #F44336;
        }
        
        /* Low Stock Alert Panel */
        .alert-panel {
            background: white;
            border-left: 5px solid #FF9800;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .alert-panel h3 {
            color: #FF9800;
            margin-bottom: 15px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-panel-content {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .low-stock-item {
            background: #fff8f0;
            border: 1px solid #FFE0B2;
            padding: 15px;
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .low-stock-item-info h4 {
            font-size: 15px;
            color: #333;
            margin-bottom: 5px;
        }
        
        .low-stock-item-info p {
            font-size: 13px;
            color: #666;
        }
        
        .low-stock-badge {
            background: #FF9800;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .no-low-stock {
            color: #8B0000;
            font-weight: 500;
        }
        
        /* Inventory Table */
        .table-section {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .table-section h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 22px;
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
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #555;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }
        
        tbody tr {
            transition: background-color 0.2s ease;
        }
        
        tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .book-title {
            font-weight: 600;
            color: #333;
        }
        
        .inventory-bar {
            display: flex;
            gap: 5px;
            align-items: center;
            margin-bottom: 5px;
        }
        
        .progress-bar {
            flex: 1;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: #8B0000;
            border-radius: 4px;
        }
        
        .progress-fill.warning {
            background: linear-gradient(90deg, #FF9800 0%, #FFC107 100%);
        }
        
        .progress-fill.danger {
            background: linear-gradient(90deg, #F44336 0%, #E91E63 100%);
        }
        
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            min-width: 100px;
        }
        
        .badge-success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .badge-warning {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .badge-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .btn {
            padding: 8px 12px;
            background: #2196F3;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s ease;
            border: none;
        }
        
        .btn:hover {
            background: #1976D2;
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(33, 150, 243, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 8px;
            padding: 30px;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            animation: modalSlide 0.3s ease;
        }
        
        @keyframes modalSlide {
            from {
                transform: scale(0.9);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        .modal-header {
            margin-bottom: 20px;
        }
        
        .modal-header h2 {
            color: #333;
            font-size: 22px;
        }
        
        .modal-header .close {
            float: right;
            font-size: 28px;
            font-weight: bold;
            color: #999;
            cursor: pointer;
            border: none;
            background: none;
            padding: 0;
            line-height: 1;
        }
        
        .modal-header .close:hover {
            color: #333;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        
        input[type="number"],
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        input[type="number"]:focus,
        input[type="text"]:focus {
            outline: none;
            border-color: #2196F3;
            box-shadow: 0 0 5px rgba(33, 150, 243, 0.2);
        }
        
        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        
        .modal-buttons button {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .btn-primary {
            background: #2196F3;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1976D2;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #f0f0f0;
            color: #333;
        }
        
        .btn-secondary:hover {
            background: #e0e0e0;
        }
        
        .empty-message {
            text-align: center;
            color: #999;
            padding: 40px;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h1>📦 Inventory Status</h1>
            <p>Real-time monitoring of library book inventory</p>
        </div>
        
        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="icon">📚</div>
                <div class="label">Total Book Titles</div>
                <div class="value"><?php echo $stats['total_titles'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card green">
                <div class="icon">📖</div>
                <div class="label">Total Copies</div>
                <div class="value"><?php echo $stats['total_copies'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card green">
                <div class="icon">✓</div>
                <div class="label">Available Copies</div>
                <div class="value"><?php echo $stats['available_copies'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card orange">
                <div class="icon">📤</div>
                <div class="label">Borrowed Copies</div>
                <div class="value"><?php echo $stats['borrowed_copies'] ?? 0; ?></div>
            </div>
            
            <div class="stat-card red">
                <div class="icon">⚠️</div>
                <div class="label">Lost/Damaged</div>
                <div class="value"><?php echo $stats['lost_copies'] ?? 0; ?></div>
            </div>
        </div>
        
        <!-- Low Stock Alert -->
        <div class="alert-panel">
            <h3>⚠️ Low Stock Alert</h3>
            <div class="alert-panel-content">
                <?php if (empty($low_stock_books)): ?>
                    <p class="no-low-stock">✓ All books have sufficient stock</p>
                <?php else: ?>
                    <?php foreach ($low_stock_books as $book): ?>
                        <div class="low-stock-item">
                            <div class="low-stock-item-info">
                                <h4><?php echo htmlspecialchars($book['title']); ?></h4>
                                <p><?php echo htmlspecialchars($book['author']); ?></p>
                                <p style="color: #F44336; font-weight: 600;">Available: <?php echo $book['available_copies']; ?>/<?php echo $book['total_copies']; ?></p>
                            </div>
                            <span class="low-stock-badge">Consider Restocking</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Inventory Table -->
        <div class="table-section">
            <h2>All Books Inventory</h2>
            
            <?php if (empty($all_books)): ?>
                <div class="empty-message">No books in inventory</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Total</th>
                                <th>Available</th>
                                <th>Borrowed</th>
                                <th>Lost</th>
                                <th>Availability</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_books as $book): ?>
                                <?php
                                // Calculate percentage for availability bar
                                $percentage = ($book['total_copies'] > 0) 
                                    ? round(($book['available_copies'] / $book['total_copies']) * 100) 
                                    : 0;
                                $bar_class = ($book['available_copies'] <= 0) 
                                    ? 'danger' 
                                    : (($book['available_copies'] <= 2) ? 'warning' : '');
                                ?>
                                <tr>
                                    <td class="book-title"><?php echo htmlspecialchars($book['title']); ?></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><?php echo $book['total_copies']; ?></td>
                                    <td><strong><?php echo $book['available_copies']; ?></strong></td>
                                    <td><?php echo $book['borrowed_copies']; ?></td>
                                    <td><?php echo $book['lost_copies']; ?></td>
                                    <td>
                                        <div class="inventory-bar">
                                            <div class="progress-bar">
                                                <div class="progress-fill <?php echo $bar_class; ?>" style="width: <?php echo $percentage; ?>%"></div>
                                            </div>
                                            <span style="font-size: 12px; color: #666; min-width: 35px;"><?php echo $percentage; ?>%</span>
                                        </div>
                                    </td>
                                    <td><?php echo getStatusBadge($book['available_copies'], $book['total_copies']); ?></td>
                                    <td>
                                        <button class="btn" onclick="openAddCopiesModal(<?php echo $book['book_id']; ?>, '<?php echo htmlspecialchars($book['title'], ENT_QUOTES); ?>')">+ Add</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Add Copies Modal -->
    <div id="addCopiesModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add Book Copies</h2>
                <button class="close" onclick="closeAddCopiesModal()">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="add_copies">
                <input type="hidden" id="bookId" name="book_id">
                
                <div class="form-group">
                    <label>Book Title</label>
                    <input type="text" id="bookTitle" readonly style="background: #f5f5f5; cursor: not-allowed;">
                </div>
                
                <div class="form-group">
                    <label for="copiesToAdd">Number of Copies to Add</label>
                    <input type="number" id="copiesToAdd" name="copies_to_add" min="1" max="100" required placeholder="Enter number of copies">
                </div>
                
                <div class="modal-buttons">
                    <button type="submit" class="btn-primary">Add Copies</button>
                    <button type="button" class="btn-secondary" onclick="closeAddCopiesModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openAddCopiesModal(bookId, bookTitle) {
            document.getElementById('bookId').value = bookId;
            document.getElementById('bookTitle').value = bookTitle;
            document.getElementById('copiesToAdd').value = '';
            document.getElementById('addCopiesModal').classList.add('active');
            document.getElementById('copiesToAdd').focus();
        }
        
        function closeAddCopiesModal() {
            document.getElementById('addCopiesModal').classList.remove('active');
        }
        
        // Close modal when clicking outside
        document.getElementById('addCopiesModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeAddCopiesModal();
            }
        });
        
        // Allow Enter key to submit
        document.getElementById('copiesToAdd').addEventListener('keypress', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                document.querySelector('#addCopiesModal form').submit();
            }
        });
    </script>
</body>
</html>
