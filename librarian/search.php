<?php
/**
 * Student Search & View - Librarian Panel
 * Search for students and view their borrowing history
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

// Check if user is librarian
if (!isLibrarian() && !isSuperAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$search_results = [];
$selected_student = null;
$student_transactions = [];
$search_query = '';

// Handle search
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['search'])) {
    $search_query = trim($_POST['search_query'] ?? $_GET['search'] ?? '');
    
    if (!empty($search_query)) {
        try {
            // Search students by name, ID, or QR code
            $search_stmt = $conn->prepare("
                SELECT 
                    student_id,
                    full_name,
                    contact_number,
                    qr_code,
                    status,
                    created_at
                FROM students
                WHERE (full_name LIKE ? OR qr_code LIKE ? OR student_id LIKE ?)
                AND status = 'active'
                ORDER BY full_name ASC
            ");
            
            $search_param = '%' . $search_query . '%';
            $search_stmt->bind_param('sss', $search_param, $search_param, $search_param);
            $search_stmt->execute();
            $search_result = $search_stmt->get_result();
            
            while ($row = $search_result->fetch_assoc()) {
                $search_results[] = $row;
            }
            $search_stmt->close();
        } catch (Exception $e) {
            logError('Student search error: ' . $e->getMessage());
        }
    }
}

// Handle student selection
if (isset($_GET['student_id'])) {
    $student_id = intval($_GET['student_id']);
    
    try {
        // Get student info
        $student_stmt = $conn->prepare("
            SELECT 
                student_id,
                full_name,
                contact_number,
                qr_code,
                status,
                created_at
            FROM students
            WHERE student_id = ? AND status = 'active'
        ");
        $student_stmt->bind_param('i', $student_id);
        $student_stmt->execute();
        $student_result = $student_stmt->get_result();
        
        if ($student_result->num_rows > 0) {
            $selected_student = $student_result->fetch_assoc();
            
            // Get student borrowing history
            $history_stmt = $conn->prepare("
                SELECT 
                    t.transaction_id,
                    t.date_borrowed,
                    t.due_date,
                    t.return_date,
                    t.penalty_amount,
                    t.status,
                    b.book_id,
                    b.title,
                    b.author
                FROM transactions t
                JOIN books b ON t.book_id = b.book_id
                WHERE t.student_id = ?
                ORDER BY t.date_borrowed DESC
            ");
            $history_stmt->bind_param('i', $student_id);
            $history_stmt->execute();
            $history_result = $history_stmt->get_result();
            
            while ($row = $history_result->fetch_assoc()) {
                $student_transactions[] = $row;
            }
            $history_stmt->close();
        }
        $student_stmt->close();
    } catch (Exception $e) {
        logError('Error fetching student details: ' . $e->getMessage());
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
    <title>Student Search - Library Borrowing System</title>
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
        }
        
        .header p {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .search-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }
        
        .search-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .search-form input {
            flex: 1;
            min-width: 250px;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .search-form input:focus {
            outline: none;
            border-color: #003366;
            box-shadow: 0 0 8px rgba(0, 51, 102, 0.2);
            background: #f8f9ff;
        }
        
        .search-form button {
            padding: 12px 28px;
            background: #003366;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(0, 51, 102, 0.3);
        }
        
        .search-form button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 16px rgba(0, 51, 102, 0.4);
        }
        
        .grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
            align-items: start;
        }
        
        /* Results Column */
        .results-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .results-section h2 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #2c3e50;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .results-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .result-item {
            padding: 15px;
            background: #f8f9fa;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .result-item:hover, .result-item.active {
            background: linear-gradient(135deg, #00336615 0%, #00336215 100%);
            border-color: #003366;
            transform: translateX(4px);
        }
        
        .result-icon {
            font-size: 24px;
        }
        
        .result-info {
            flex: 1;
        }
        
        .result-name {
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .result-id {
            font-size: 12px;
            color: #7f8c8d;
        }
        
        .empty-results {
            text-align: center;
            padding: 40px 20px;
            color: #95a5a6;
        }
        
        .empty-results-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        /* Student Details Column */
        .student-details {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .student-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .student-avatar {
            font-size: 48px;
            width: 70px;
            height: 70px;
            background: #003366;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .student-info h2 {
            font-size: 20px;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .student-info p {
            color: #7f8c8d;
            font-size: 13px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .info-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid #003366;
        }
        
        .info-label {
            font-size: 11px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            font-weight: 600;
        }
        
        .info-value {
            font-size: 15px;
            color: #2c3e50;
            font-weight: 600;
        }
        
        .qr-section {
            background: linear-gradient(135deg, #f5f7fa 0%, #f0f4f8 100%);
            border: 2px dashed #003366;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 25px;
        }
        
        .qr-section h4 {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            font-weight: 600;
        }
        
        .qr-section img {
            max-width: 150px;
            height: auto;
            border-radius: 8px;
            background: white;
            padding: 8px;
        }
        
        .qr-text {
            font-size: 11px;
            color: #003366;
            font-family: 'Courier New', monospace;
            margin-top: 10px;
            font-weight: 600;
        }
        
        /* Transactions Table */
        .transactions-section h3 {
            font-size: 16px;
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 10px;
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
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
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
        
        .no-selection {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 400px;
            color: #95a5a6;
        }
        
        .no-selection-icon {
            font-size: 64px;
            margin-bottom: 15px;
        }
        
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🔍 Student Search & Borrowing History</h1>
            <p>Search for students and view their complete borrowing history</p>
        </div>
        
        <!-- Search Section -->
        <div class="search-section">
            <form class="search-form" method="POST">
                <input type="text" 
                       name="search_query" 
                       placeholder="Search by name, QR code, or ID..." 
                       value="<?php echo htmlspecialchars($search_query); ?>"
                       autofocus>
                <button type="submit">Search Students</button>
            </form>
        </div>
        
        <!-- Results & Details Grid -->
        <div class="grid">
            <!-- Search Results Column -->
            <div class="results-section">
                <h2>📋 Students (<?php echo count($search_results); ?>)</h2>
                
                <?php if (empty($search_results) && !empty($search_query)): ?>
                    <div class="empty-results">
                        <div class="empty-results-icon">🔎</div>
                        <p>No students found matching "<?php echo htmlspecialchars($search_query); ?>"</p>
                    </div>
                <?php elseif (empty($search_results)): ?>
                    <div class="empty-results">
                        <div class="empty-results-icon">📚</div>
                        <p>Enter a search query to find students</p>
                    </div>
                <?php else: ?>
                    <div class="results-list">
                        <?php foreach ($search_results as $student): ?>
                            <div class="result-item <?php echo ($selected_student && $selected_student['student_id'] === $student['student_id']) ? 'active' : ''; ?>" 
                                 onclick="location.href='?search=<?php echo urlencode($search_query); ?>&student_id=<?php echo $student['student_id']; ?>'">
                                <div class="result-icon">👤</div>
                                <div class="result-info">
                                    <div class="result-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                    <div class="result-id"><?php echo htmlspecialchars($student['qr_code']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Student Details Column -->
            <div class="student-details">
                <?php if ($selected_student): ?>
                    <!-- Student Header -->
                    <div class="student-header">
                        <div class="student-avatar">👨‍🎓</div>
                        <div class="student-info">
                            <h2><?php echo htmlspecialchars($selected_student['full_name']); ?></h2>
                            <p>Student Since: <?php echo date('M d, Y', strtotime($selected_student['created_at'])); ?></p>
                        </div>
                    </div>
                    
                    <!-- Info Grid -->
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Student ID</div>
                            <div class="info-value">STU-<?php echo str_pad($selected_student['student_id'], 4, '0', STR_PAD_LEFT); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Contact</div>
                            <div class="info-value"><?php echo htmlspecialchars($selected_student['contact_number'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Status</div>
                            <div class="info-value" style="color: #27ae60;">✓ Active</div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Total Transactions</div>
                            <div class="info-value"><?php echo count($student_transactions); ?></div>
                        </div>
                    </div>
                    
                    <!-- QR Code -->
                    <div class="qr-section">
                        <h4>QR Code</h4>
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($selected_student['qr_code']); ?>" 
                             alt="Student QR Code">
                        <div class="qr-text"><?php echo htmlspecialchars($selected_student['qr_code']); ?></div>
                    </div>
                    
                    <!-- Borrowing History -->
                    <?php if (!empty($student_transactions)): ?>
                        <div class="transactions-section">
                            <h3>📚 Borrowing History</h3>
                            <div class="table-wrapper">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Book</th>
                                            <th>Borrowed</th>
                                            <th>Due</th>
                                            <th>Returned</th>
                                            <th>Status</th>
                                            <th>Penalty</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($student_transactions as $transaction): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars(substr($transaction['title'], 0, 20)); ?></strong><br>
                                                    <small style="color: #7f8c8d;"><?php echo htmlspecialchars($transaction['author']); ?></small>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($transaction['date_borrowed'])); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($transaction['due_date'])); ?></td>
                                                <td><?php echo $transaction['return_date'] ? date('M d, Y', strtotime($transaction['return_date'])) : '—'; ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                                        <?php echo ucfirst($transaction['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($transaction['penalty_amount'] > 0): ?>
                                                        <strong style="color: #d35400;">$<?php echo number_format($transaction['penalty_amount'], 2); ?></strong>
                                                    <?php else: ?>
                                                        <span style="color: #95a5a6;">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px 20px; color: #95a5a6;">
                            <div style="font-size: 48px; margin-bottom: 15px;">📚</div>
                            <p>No borrowing history</p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-selection">
                        <div class="no-selection-icon">👈</div>
                        <p>Select a student to view details</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
