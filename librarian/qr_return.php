<?php
/**
 * QR Return Page - Librarian Panel
 * Allows librarians to process book returns using QR code scanner
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

// Check if user is librarian
if (!isLibrarian() && !isSuperAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$message = '';
$message_type = '';
$return_details = null;

// Handle book return
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'process_return') {
        $book_qr = trim($_POST['book_qr'] ?? '');
        
        if (empty($book_qr)) {
            $message = 'Book QR code is required.';
            $message_type = 'error';
        } else {
            try {
                // Get book by QR code
                $book_stmt = $conn->prepare("SELECT book_id, title, author, available_copies, borrowed_copies FROM books WHERE qr_code = ?");
                $book_stmt->bind_param('s', $book_qr);
                $book_stmt->execute();
                $book_result = $book_stmt->get_result();
                
                if ($book_result->num_rows === 0) {
                    $message = 'Book not found. Please scan a valid book QR code.';
                    $message_type = 'error';
                } else {
                    $book = $book_result->fetch_assoc();
                    $book_id = $book['book_id'];
                    
                    // Get active transaction for this book
                    $trans_stmt = $conn->prepare("
                        SELECT 
                            t.transaction_id,
                            t.student_id,
                            t.due_date,
                            s.full_name as student_name
                        FROM transactions t
                        LEFT JOIN students s ON t.student_id = s.student_id
                        WHERE t.book_id = ? AND t.status = 'borrowed'
                        LIMIT 1
                    ");
                    $trans_stmt->bind_param('i', $book_id);
                    $trans_stmt->execute();
                    $trans_result = $trans_stmt->get_result();
                    
                    if ($trans_result->num_rows === 0) {
                        $message = 'No active borrowing found for this book. The book may not have been borrowed.';
                        $message_type = 'error';
                    } else {
                        $transaction = $trans_result->fetch_assoc();
                        $transaction_id = $transaction['transaction_id'];
                        $due_date = $transaction['due_date'];
                        $student_name = $transaction['student_name'] ?? 'Unknown';
                        
                        // Calculate penalty
                        $today = date('Y-m-d');
                        $due_date_only = date('Y-m-d', strtotime($due_date));
                        $days_late = 0;
                        $penalty_amount = 0;
                        
                        // Only calculate penalty if returned after due date
                        if (strtotime($today) > strtotime($due_date_only)) {
                            $days_late = floor((strtotime($today) - strtotime($due_date_only)) / 86400);
                            $penalty_amount = $days_late * 5; // 5 per day late
                        }
                        
                        // Begin transaction
                        $conn->begin_transaction();
                        
                        try {
                            // Update transaction
                            $return_date = date('Y-m-d H:i:s');
                            $status = 'returned';
                            
                            $update_trans_stmt = $conn->prepare("
                                UPDATE transactions 
                                SET return_date = ?, status = ?, penalty_amount = ? 
                                WHERE transaction_id = ?
                            ");
                            $update_trans_stmt->bind_param('ssdi', $return_date, $status, $penalty_amount, $transaction_id);
                            
                            if (!$update_trans_stmt->execute()) {
                                throw new Exception('Failed to update transaction: ' . $update_trans_stmt->error);
                            }
                            
                            // Update inventory: decrement borrowed_copies, increment available_copies
                            $new_borrowed = max(0, $book['borrowed_copies'] - 1);
                            $new_available = $book['available_copies'] + 1;
                            
                            // Set book_status to 'available' (copies are now available)
                            $book_status = 'available';
                            
                            // Update book with new inventory counts
                            $update_book_stmt = $conn->prepare("UPDATE books SET borrowed_copies = ?, available_copies = ?, book_status = ? WHERE book_id = ?");
                            $update_book_stmt->bind_param('iisi', $new_borrowed, $new_available, $book_status, $book_id);
                            
                            if (!$update_book_stmt->execute()) {
                                throw new Exception('Failed to update book status: ' . $update_book_stmt->error);
                            }
                            
                            // Commit transaction
                            $conn->commit();
                            
                            // Prepare return details
                            $return_details = [
                                'transaction_id' => $transaction_id,
                                'book_title' => $book['title'],
                                'book_author' => $book['author'],
                                'student_name' => $student_name,
                                'return_date' => date('M d, Y', strtotime($return_date)),
                                'due_date' => date('M d, Y', strtotime($due_date)),
                                'days_late' => $days_late,
                                'penalty_amount' => $penalty_amount
                            ];
                            
                            if ($penalty_amount > 0) {
                                $message = 'Book returned successfully! Penalty: $' . $penalty_amount . ' (' . $days_late . ' days late)';
                            } else {
                                $message = 'Book returned successfully! No penalty.';
                            }
                            $message_type = 'success';
                            
                            // Clear form for next return
                            header("Refresh: 4; url=/LibraryBorrowingSystem/librarian/qr_return.php");
                            
                            $update_trans_stmt->close();
                            $update_book_stmt->close();
                        } catch (Exception $e) {
                            $conn->rollback();
                            $message = 'Error: ' . htmlspecialchars($e->getMessage());
                            $message_type = 'error';
                        }
                    }
                    $trans_stmt->close();
                }
                $book_stmt->close();
            } catch (Exception $e) {
                $message = 'Error: ' . htmlspecialchars($e->getMessage());
                $message_type = 'error';
                logError('QR Return error: ' . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code Book Return - Library Borrowing System</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.4/html5-qrcode.min.js"></script>
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
            max-width: 1000px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-header h2 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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
        
        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .section {
            background: white;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .section h3 {
            color: #333;
            margin-bottom: 20px;
            font-size: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #2196F3;
        }
        
        #qr-reader {
            width: 100%;
            max-width: 500px;
            margin: 20px auto;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .scanner-wrapper {
            text-align: center;
            background: #f0f0f0;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
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
        
        input[type="text"],
        input[type="hidden"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: #2196F3;
            box-shadow: 0 0 5px rgba(33, 150, 243, 0.2);
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        
        button {
            padding: 12px 24px;
            background: linear-gradient(135deg, #2196F3 0%, #1976D2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(33, 150, 243, 0.4);
        }
        
        button:active {
            transform: translateY(0);
        }
        
        button:disabled {
            background: #999;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        button[type="reset"] {
            background: #999;
        }
        
        button[type="reset"]:hover {
            box-shadow: 0 5px 15px rgba(150, 150, 150, 0.4);
        }
        
        .scanner-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .btn-small {
            padding: 8px 16px;
            font-size: 12px;
            border-radius: 4px;
        }
        
        .info-box {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border-left: 4px solid #2196F3;
            font-size: 13px;
            color: #333;
        }
        
        .info-box strong {
            color: #1565c0;
        }
        
        .return-details {
            background: #f5f5f5;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            border: 2px solid #2196F3;
        }
        
        .return-details h4 {
            color: #1565c0;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .detail-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 12px;
        }
        
        .detail-item {
            font-size: 14px;
        }
        
        .detail-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }
        
        .detail-value {
            color: #666;
        }
        
        .penalty-box {
            background: #fff3cd;
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
            border-left: 4px solid #ffc107;
        }
        
        .penalty-box strong {
            color: #856404;
        }
        
        .penalty-amount {
            font-size: 18px;
            font-weight: bold;
            color: #f57c00;
        }
        
        @media (max-width: 768px) {
            .detail-row {
                grid-template-columns: 1fr;
            }
            
            #qr-reader {
                max-width: 100%;
            }
            
            .scanner-controls {
                flex-direction: column;
            }
            
            .scanner-controls button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h2>📥 QR Code Book Return</h2>
            <p style="color: #666; margin-top: 10px;">Scan the book QR code to process the return and calculate any applicable penalties</p>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <span><?php echo $message_type === 'success' ? '✓' : ($message_type === 'error' ? '✕' : 'ℹ'); ?></span>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($return_details): ?>
            <div class="section">
                <h3>📋 Return Details</h3>
                
                <div class="return-details">
                    <h4>✓ Book Successfully Returned</h4>
                    
                    <div class="detail-row">
                        <div class="detail-item">
                            <div class="detail-label">Transaction ID</div>
                            <div class="detail-value"><?php echo htmlspecialchars($return_details['transaction_id']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Book Title</div>
                            <div class="detail-value"><?php echo htmlspecialchars($return_details['book_title']); ?></div>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-item">
                            <div class="detail-label">Author</div>
                            <div class="detail-value"><?php echo htmlspecialchars($return_details['book_author']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Student</div>
                            <div class="detail-value"><?php echo htmlspecialchars($return_details['student_name']); ?></div>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-item">
                            <div class="detail-label">Due Date</div>
                            <div class="detail-value"><?php echo $return_details['due_date']; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Return Date</div>
                            <div class="detail-value"><?php echo $return_details['return_date']; ?></div>
                        </div>
                    </div>
                    
                    <?php if ($return_details['days_late'] > 0): ?>
                        <div class="penalty-box">
                            <strong>⚠️ Late Return Penalty</strong><br>
                            Days Late: <?php echo $return_details['days_late']; ?> days<br>
                            Penalty Rate: $5 per day<br>
                            <div class="penalty-amount">Total Penalty: $<?php echo number_format($return_details['penalty_amount'], 2); ?></div>
                        </div>
                    <?php else: ?>
                        <div style="background: #d4edda; padding: 15px; border-radius: 5px; margin-top: 15px; border-left: 4px solid #28a745; color: #155724;">
                            <strong>✓ On-Time Return</strong><br>
                            No penalty charges.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- QR Scanner Section -->
        <div class="section">
            <h3>📷 QR Code Scanner</h3>
            
            <div class="info-box">
                <strong>ℹ️ How to use:</strong><br>
                1. Click "Start Camera" to begin scanning<br>
                2. Position the book QR code in front of your camera<br>
                3. Allow the browser to access your camera when prompted<br>
                4. The QR code will be automatically detected and processed
            </div>
            
            <div class="scanner-wrapper">
                <div id="qr-reader"></div>
            </div>
            
            <div class="scanner-controls">
                <button type="button" class="btn-small" id="startBtn" onclick="startScanner()">📷 Start Camera</button>
                <button type="button" class="btn-small" id="stopBtn" onclick="stopScanner()" style="display: none; background: #f44336;">🛑 Stop Camera</button>
                <button type="button" class="btn-small" id="resetBtn" onclick="resetForm()">🔄 Reset</button>
            </div>
        </div>
        
        <!-- Form Section -->
        <div class="section">
            <h3>📋 Return Information</h3>
            
            <form method="POST" id="returnForm">
                <input type="hidden" name="action" value="process_return">
                
                <div class="form-group">
                    <label for="book_qr">Book QR Code</label>
                    <input type="text" id="book_qr" name="book_qr" readonly placeholder="Scanned book QR code will appear here">
                </div>
                
                <div class="info-box">
                    <strong>📅 Return Process:</strong><br>
                    • Penalty calculated automatically if returned after due date<br>
                    • Penalty: $5 per day late<br>
                    • Book status updated to "Available" immediately<br>
                    • Transaction record saved permanently
                </div>
                
                <div class="button-group">
                    <button type="submit" id="submitBtn">✓ Process Book Return</button>
                    <button type="reset">Clear Form</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let html5QrcodeScanner;
        let bookScanned = false;
        
        function startScanner() {
            document.getElementById('startBtn').style.display = 'none';
            document.getElementById('stopBtn').style.display = 'inline-block';
            
            html5QrcodeScanner = new Html5QrcodeScanner(
                "qr-reader",
                { facingMode: "environment", qrbox: 250 },
                false
            );
            
            html5QrcodeScanner.render(onScanSuccess, onScanError);
        }
        
        function stopScanner() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear();
            }
            document.getElementById('startBtn').style.display = 'inline-block';
            document.getElementById('stopBtn').style.display = 'none';
        }
        
        function onScanSuccess(decodedText, decodedResult) {
            // Parse the scanned data
            const text = decodedText.trim();
            
            // Check if it's a book QR (starts with BOOK)
            if (text.startsWith('BOOK')) {
                if (!bookScanned) {
                    document.getElementById('book_qr').value = text;
                    bookScanned = true;
                    
                    // Alert user to submit
                    alert('✓ Book QR scanned!\n\nClick "Process Book Return" to complete the return and calculate penalties.');
                }
            } else {
                alert('⚠️ Invalid QR code.\n\nPlease scan a book (BOOK-...) QR code.');
            }
        }
        
        function onScanError(error) {
            // Handle scan error
            console.log('Scan error: ' + error);
        }
        
        function resetForm() {
            document.getElementById('returnForm').reset();
            document.getElementById('book_qr').value = '';
            bookScanned = false;
            stopScanner();
        }
    </script>
</body>
</html>
