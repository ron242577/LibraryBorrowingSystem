<?php
/**
 * QR Borrow Page - Librarian Panel
 * Allows librarians to process book borrowing using QR code scanner
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
$scanned_student = null;
$scanned_book = null;

// Handle transaction creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'process_borrow') {
        $student_qr = trim($_POST['student_qr'] ?? '');
        $book_qr = trim($_POST['book_qr'] ?? '');
        
        if (empty($student_qr) || empty($book_qr)) {
            $message = 'Both student and book QR codes are required.';
            $message_type = 'error';
        } else {
            try {
                // Get student by QR code
                $student_stmt = $conn->prepare("SELECT student_id, full_name FROM students WHERE qr_code = ? AND status = 'active'");
                $student_stmt->bind_param('s', $student_qr);
                $student_stmt->execute();
                $student_result = $student_stmt->get_result();
                
                if ($student_result->num_rows === 0) {
                    $message = 'Student not found or account is inactive. Please scan a valid student QR code.';
                    $message_type = 'error';
                } else {
                    $student = $student_result->fetch_assoc();
                    $student_id = $student['student_id'];
                    
                    // Get book by QR code
                    $book_stmt = $conn->prepare("SELECT book_id, title, available_copies, borrowed_copies, total_copies FROM books WHERE qr_code = ?");
                    $book_stmt->bind_param('s', $book_qr);
                    $book_stmt->execute();
                    $book_result = $book_stmt->get_result();
                    
                    if ($book_result->num_rows === 0) {
                        $message = 'Book not found. Please scan a valid book QR code.';
                        $message_type = 'error';
                    } else {
                        $book = $book_result->fetch_assoc();
                        $book_id = $book['book_id'];
                        
                        // Check if available copies > 0
                        if ($book['available_copies'] <= 0) {
                            $message = 'No available copies of this book. Please select another book.';
                            $message_type = 'error';
                        } else {
                            // Calculate dates
                            $date_borrowed = date('Y-m-d H:i:s');
                            $due_date = date('Y-m-d H:i:s', strtotime('+14 days'));
                            $status = 'borrowed';
                            $book_status = 'borrowed';
                            
                            // Begin transaction
                            $conn->begin_transaction();
                            
                            try {
                                // Insert transaction
                                $insert_stmt = $conn->prepare("INSERT INTO transactions (student_id, book_id, date_borrowed, due_date, status) VALUES (?, ?, ?, ?, ?)");
                                $insert_stmt->bind_param('iisss', $student_id, $book_id, $date_borrowed, $due_date, $status);
                                
                                if (!$insert_stmt->execute()) {
                                    throw new Exception('Failed to create transaction: ' . $insert_stmt->error);
                                }
                                
                                $transaction_id = $conn->insert_id;
                                
                                // Update inventory: increment borrowed_copies, decrement available_copies
                                $new_borrowed = $book['borrowed_copies'] + 1;
                                $new_available = $book['available_copies'] - 1;
                                
                                // Set book_status to 'out_of_stock' if no copies available
                                $book_status = ($new_available === 0) ? 'out_of_stock' : 'available';
                                
                                // Update book with new inventory counts
                                $update_stmt = $conn->prepare("UPDATE books SET borrowed_copies = ?, available_copies = ?, book_status = ? WHERE book_id = ?");
                                $update_stmt->bind_param('iisi', $new_borrowed, $new_available, $book_status, $book_id);
                                
                                if (!$update_stmt->execute()) {
                                    throw new Exception('Failed to update inventory: ' . $update_stmt->error);
                                }
                                
                                // Commit transaction
                                $conn->commit();
                                
                                $message = 'Book borrowed successfully! Transaction ID: ' . $transaction_id . ' | Student: ' . htmlspecialchars($student['full_name']) . ' | Book: ' . htmlspecialchars($book['title']) . ' | Due: ' . date('M d, Y', strtotime($due_date));
                                $message_type = 'success';
                                
                                // Clear form for next borrow
                                header("Refresh: 3; url=/LibraryBorrowingSystem/librarian/qr_borrow.php");
                                
                                $insert_stmt->close();
                                $update_stmt->close();
                            } catch (Exception $e) {
                                $conn->rollback();
                                $message = 'Error: ' . htmlspecialchars($e->getMessage());
                                $message_type = 'error';
                            }
                        }
                    }
                    $book_stmt->close();
                }
                $student_stmt->close();
            } catch (Exception $e) {
                $message = 'Error: ' . htmlspecialchars($e->getMessage());
                $message_type = 'error';
                logError('QR Borrow error: ' . $e->getMessage());
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
    <title>QR Code Borrowing - Library Borrowing System</title>
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
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 30px;
        }

        .page-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .action-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 18px;
            background: #8B0000;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
            white-space: nowrap;
        }

        .action-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(139, 0, 0, 0.4);
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
            }
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
            border-bottom: 2px solid #8B0000;
        }
        
        .step-indicator {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .step {
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            background: #f9f9f9;
            border: 2px solid #ddd;
            transition: all 0.3s;
        }
        
        .step.active {
            background: #e8f5e9;
            border-color: #4CAF50;
        }
        
        .step.completed {
            background: #d4edda;
            border-color: #4caf50;
        }
        
        .step-number {
            display: inline-block;
            width: 40px;
            height: 40px;
            line-height: 40px;
            background: #ddd;
            border-radius: 50%;
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .step.active .step-number {
            background: #4CAF50;
            color: white;
        }
        
        .step.completed .step-number {
            background: #4caf50;
            color: white;
        }
        
        .step h4 {
            color: #333;
            margin-bottom: 5px;
        }
        
        .step p {
            color: #666;
            font-size: 13px;
        }
        
        #qr-reader-student {
            width: 100%;
            max-width: 500px;
            margin: 20px auto;
            border-radius: 8px;
            overflow: hidden;
        }
        
        #qr-reader-book {
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
        
        .scanner-section {
            margin-bottom: 40px;
        }
        
        .scanner-section.disabled {
            opacity: 0.5;
            pointer-events: none;
        }
        
        .scanner-locked-notice {
            background: #fff3cd;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
            border-left: 4px solid #ffc107;
            font-size: 13px;
            color: #856404;
        }
        
        .scanner-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .scanner-icon {
            font-size: 24px;
        }
        
        .scanner-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }
        
        .scanner-subtitle {
            font-size: 13px;
            color: #666;
            margin-top: 2px;
        }
        
        .scanner-progress {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #e8f5e9;
            border-radius: 4px;
            border-left: 3px solid #4CAF50;
            margin-bottom: 15px;
            font-size: 13px;
            color: #155724;
        }
        
        .scanner-progress.pending {
            background: #f0f0f0;
            border-left-color: #ddd;
            color: #666;
        }
        
        .progress-indicator {
            display: inline-block;
            width: 20px;
            height: 20px;
            line-height: 20px;
            text-align: center;
            background: #4CAF50;
            color: white;
            border-radius: 50%;
            font-size: 12px;
            font-weight: bold;
        }
        
        .progress-indicator.pending {
            background: #ddd;
            color: #666;
        }
        
        .divider-section {
            text-align: center;
            padding: 20px 0;
            margin: 20px 0;
            position: relative;
        }
        
        .divider-section::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: #ddd;
        }
        
        .divider-label {
            background: white;
            padding: 0 15px;
            position: relative;
            z-index: 1;
            color: #666;
            font-size: 13px;
            font-weight: 500;
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
            border-color: #8B0000;
            box-shadow: 0 0 5px rgba(139, 0, 0, 0.2);
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        
        button {
            padding: 12px 24px;
            background: #8B0000;
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
            box-shadow: 0 5px 15px rgba(139, 0, 0, 0.4);
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
            background: #f8f0f0;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border-left: 4px solid #8B0000;
            font-size: 13px;
            color: #333;
        }
        
        .info-box strong {
            color: #155724;
        }
        
        .scanned-info {
            background: #f5e8e8;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border: 2px solid #8B0000;
        }
        
        .scanned-info p {
            margin: 5px 0;
            font-size: 14px;
        }
        
        .scanned-info strong {
            color: #155724;
        }
        
        .controls-row {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        @media (max-width: 768px) {
            .step-indicator {
                grid-template-columns: 1fr;
            }
            
            #qr-reader {
                max-width: 100%;
            }
            
            .controls-row {
                flex-direction: column;
            }
            
            .controls-row button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <div>
                <h2>QR Code Book Borrowing System</h2>
                <p style="color: #666; margin-top: 10px;">Complete the student and book scanning process to process a book borrowing transaction</p>
            </div>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <span><?php echo $message_type === 'success' ? '✓' : ($message_type === 'error' ? '✕' : 'ℹ'); ?></span>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Step Indicator -->
        <div class="section">
            <div class="step-indicator">
                <div class="step" id="step1">
                    <div class="step-number">1</div>
                    <h4>Scan Student QR</h4>
                    <p>Identify the student</p>
                </div>
                <div class="step" id="step2">
                    <div class="step-number">2</div>
                    <h4>Scan Book QR</h4>
                    <p>Select the book to borrow</p>
                </div>
            </div>
        </div>
        
        <form method="POST" id="borrowForm">
            <input type="hidden" name="action" value="process_borrow">
            
            <!-- STEP 1: STUDENT QR SCANNER -->
            <div class="section scanner-section" id="studentScannerSection">
                <div class="scanner-header">
                    <div>
                        <div class="scanner-title">Step 1: Scan Student QR Code</div>
                        <div class="scanner-subtitle">Identify which student is borrowing the book</div>
                    </div>
                </div>
                
                <div class="scanner-progress pending" id="studentProgress">
                    <span class="progress-indicator pending">○</span>
                    <span>Waiting for student QR scan...</span>
                </div>
                
                <div class="info-box">
                    <strong>Instructions:</strong><br>
                    1. Click "Start Camera" button below<br>
                    2. Point your camera at the student's QR code<br>
                    3. The system will automatically detect and scan it<br>
                    4. Once scanned, the student information will be confirmed
                </div>
                
                <div class="scanner-wrapper">
                    <div id="qr-reader-student"></div>
                </div>
                
                <div class="scanner-controls">
                    <button type="button" class="btn-small" id="startBtnStudent" onclick="startScannerStudent()">Start Camera</button>
                    <button type="button" class="btn-small" id="stopBtnStudent" onclick="stopScannerStudent()" style="display: none; background: #f44336;">Stop Camera</button>
                </div>
                
                <div class="form-group">
                    <label for="student_qr">Student QR Code</label>
                    <input type="text" id="student_qr" name="student_qr" readonly placeholder="Scanned student QR code will appear here">
                </div>
                
                <?php if (!empty($_POST['student_qr'])): ?>
                    <div class="scanned-info">
                        <p><strong>✓ Student Confirmed:</strong></p>
                        <p>Code: <?php echo htmlspecialchars($_POST['student_qr']); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- DIVIDER -->
            <div class="divider-section">
                <span class="divider-label">Then Proceed To Step 2</span>
            </div>
            
            <!-- STEP 2: BOOK QR SCANNER -->
            <div class="section scanner-section" id="bookScannerSection">
                <div class="scanner-locked-notice" id="bookLockedNotice" style="display: none;">
                    Please complete Step 1 (Student QR) first to enable this scanner
                </div>
                
                <div class="scanner-header">
                    
                    <div>
                        <div class="scanner-title">Step 2: Scan Book QR Code</div>
                        <div class="scanner-subtitle">Select which book to borrow</div>
                    </div>
                </div>
                
                <div class="scanner-progress pending" id="bookProgress">
                    <span class="progress-indicator pending">○</span>
                    <span>Waiting for book QR scan...</span>
                </div>
                
                <div class="info-box">
                    <strong>Instructions:</strong><br>
                    1. Click "Start Camera" button below<br>
                    2. Point your camera at the book's QR code<br>
                    3. The system will automatically detect and scan it<br>
                    4. Once scanned, you can complete the borrowing transaction
                </div>
                
                <div class="scanner-wrapper">
                    <div id="qr-reader-book"></div>
                </div>
                
                <div class="scanner-controls">
                    <button type="button" class="btn-small" id="startBtnBook" onclick="startScannerBook()">Start Camera</button>
                    <button type="button" class="btn-small" id="stopBtnBook" onclick="stopScannerBook()" style="display: none; background: #f44336;">Stop Camera</button>
                </div>
                
                <div class="form-group">
                    <label for="book_qr">Book QR Code</label>
                    <input type="text" id="book_qr" name="book_qr" readonly placeholder="Scanned book QR code will appear here">
                </div>
                
                <?php if (!empty($_POST['book_qr'])): ?>
                    <div class="scanned-info">
                        <p><strong>✓ Book Confirmed:</strong></p>
                        <p>Code: <?php echo htmlspecialchars($_POST['book_qr']); ?></p>
                    </div>
                <?php endif; ?>
                
                <div class="info-box">
                    <strong>Borrowing Terms:</strong><br>
                    • Loan Period: 14 days<br>
                    • Status: Active borrowing<br>
                    • Auto-calculation of due date
                </div>
            </div>
            
            <!-- SUBMIT SECTION -->
            <div class="section">
                <h3>✓ Complete Transaction</h3>
                
                <div class="info-box">
                    <strong>⚡ Ready to Process:</strong><br>
                    Once both QR codes are scanned and confirmed above, click the button below to finalize the borrowing transaction.
                </div>
                
                <div class="controls-row">
                    <button type="submit" id="submitBtn" onclick="updateSteps()">✓ Process Book Borrowing</button>
                    <button type="button" onclick="resetForm()" style="background: #999;">🔄 Reset All</button>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        let html5QrcodeScannerStudent;
        let html5QrcodeScannerBook;
        let currentStep = 1;
        let studentScanned = false;
        let bookScanned = false;
        
        // ============ STUDENT QR SCANNER ============
        function startScannerStudent() {
            document.getElementById('startBtnStudent').style.display = 'none';
            document.getElementById('stopBtnStudent').style.display = 'inline-block';
            
            html5QrcodeScannerStudent = new Html5QrcodeScanner(
                "qr-reader-student",
                { facingMode: "environment", qrbox: 250 },
                false
            );
            
            html5QrcodeScannerStudent.render(onScanSuccessStudent, onScanError);
        }
        
        function stopScannerStudent() {
            if (html5QrcodeScannerStudent) {
                html5QrcodeScannerStudent.clear();
            }
            document.getElementById('startBtnStudent').style.display = 'inline-block';
            document.getElementById('stopBtnStudent').style.display = 'none';
        }
        
        function onScanSuccessStudent(decodedText, decodedResult) {
            const text = decodedText.trim();
            
            // Check if it's a student QR (starts with STU)
            if (text.startsWith('STU')) {
                if (!studentScanned) {
                    document.getElementById('student_qr').value = text;
                    studentScanned = true;
                    stopScannerStudent();
                    updateSteps();
                    enableBookScanner();
                    
                    alert('✓ Student QR scanned successfully!\n\nStudent Code: ' + text + '\n\nNow proceed to Step 2 and scan the book QR code.');
                }
            } else {
                alert('Invalid QR Code\n\nPlease scan a student QR code (starts with STU-)');
            }
        }
        
        // ============ BOOK QR SCANNER ============
        function startScannerBook() {
            if (!studentScanned) {
                alert('Please complete Step 1 first!\n\nScan the student QR code before scanning the book.');
                return;
            }
            
            document.getElementById('startBtnBook').style.display = 'none';
            document.getElementById('stopBtnBook').style.display = 'inline-block';
            
            html5QrcodeScannerBook = new Html5QrcodeScanner(
                "qr-reader-book",
                { facingMode: "environment", qrbox: 250 },
                false
            );
            
            html5QrcodeScannerBook.render(onScanSuccessBook, onScanError);
        }
        
        function stopScannerBook() {
            if (html5QrcodeScannerBook) {
                html5QrcodeScannerBook.clear();
            }
            document.getElementById('startBtnBook').style.display = 'inline-block';
            document.getElementById('stopBtnBook').style.display = 'none';
        }
        
        function onScanSuccessBook(decodedText, decodedResult) {
            const text = decodedText.trim();
            
            // Check if it's a book QR (starts with BOOK)
            if (text.startsWith('BOOK')) {
                if (studentScanned && !bookScanned) {
                    document.getElementById('book_qr').value = text;
                    bookScanned = true;
                    stopScannerBook();
                    updateSteps();
                    
                    alert('✓ Book QR scanned successfully!\n\nBook Code: ' + text + '\n\nClick "Process Book Borrowing" to complete the transaction.');
                }
            } else {
                alert('Invalid QR Code\n\nPlease scan a book QR code (starts with BOOK-)');
            }
        }
        
        function onScanError(error) {
            console.log('Scan error: ' + error);
        }
        
        // ============ UI CONTROLS ============
        function enableBookScanner() {
            document.getElementById('bookScannerSection').classList.remove('disabled');
            document.getElementById('bookLockedNotice').style.display = 'none';
            
            const bookProgress = document.getElementById('bookProgress');
            bookProgress.classList.remove('pending');
            bookProgress.innerHTML = '<span class="progress-indicator pending">→</span><span>Ready to scan book QR code</span>';
        }
        
        function disableBookScanner() {
            document.getElementById('bookScannerSection').classList.add('disabled');
            document.getElementById('bookLockedNotice').style.display = 'block';
            
            const bookProgress = document.getElementById('bookProgress');
            bookProgress.classList.add('pending');
            bookProgress.innerHTML = '<span class="progress-indicator pending">○</span><span>Waiting for student QR scan...</span>';
        }
        
        function updateSteps() {
            const step1 = document.getElementById('step1');
            const step2 = document.getElementById('step2');
            const studentProgress = document.getElementById('studentProgress');
            const bookProgress = document.getElementById('bookProgress');
            
            // Update student step
            if (studentScanned) {
                step1.classList.add('completed');
                step1.classList.remove('active');
                studentProgress.classList.remove('pending');
                studentProgress.innerHTML = '<span class="progress-indicator">✓</span><span>Student QR confirmed</span>';
            } else {
                step1.classList.add('active');
                step1.classList.remove('completed');
                studentProgress.classList.add('pending');
                studentProgress.innerHTML = '<span class="progress-indicator pending">○</span><span>Waiting for student QR scan...</span>';
            }
            
            // Update book step
            if (bookScanned) {
                step2.classList.add('completed');
                step2.classList.remove('active');
                bookProgress.classList.remove('pending');
                bookProgress.innerHTML = '<span class="progress-indicator">✓</span><span>Book QR confirmed</span>';
            } else if (studentScanned) {
                step2.classList.add('active');
                step2.classList.remove('completed');
                bookProgress.classList.remove('pending');
                bookProgress.innerHTML = '<span class="progress-indicator pending">→</span><span>Ready to scan book QR code</span>';
            } else {
                step2.classList.remove('active', 'completed');
                bookProgress.classList.add('pending');
                bookProgress.innerHTML = '<span class="progress-indicator pending">○</span><span>Waiting for student QR scan...</span>';
            }
        }
        
        function resetForm() {
            // Stop both scanners
            stopScannerStudent();
            stopScannerBook();
            
            // Clear form
            document.getElementById('borrowForm').reset();
            document.getElementById('student_qr').value = '';
            document.getElementById('book_qr').value = '';
            
            // Reset state
            studentScanned = false;
            bookScanned = false;
            
            // Disable book scanner
            disableBookScanner();
            
            // Update UI
            updateSteps();
            
            // Scroll to top
            window.scrollTo(0, 0);
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSteps();
            disableBookScanner();
        });
    </script>
</body>
</html>
