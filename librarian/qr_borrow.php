<?php
/**
 * Unified Book Transactions Page - Librarian Panel
 * Scans the book QR and automatically decides whether to borrow or return.
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

if (!isLibrarian() && !isSuperAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function getStudentByQr($conn, $student_qr) {
    $stmt = $conn->prepare('
        SELECT student_id, student_no, full_name, student_group, department, year_level, contact_number, email, card_valid_until, qr_code, status
        FROM students
        WHERE qr_code = ? AND status = "active"
        LIMIT 1
    ');
    $stmt->bind_param('s', $student_qr);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->num_rows > 0 ? $result->fetch_assoc() : null;
    $stmt->close();
    return $student;
}

function getBookStateByQr($conn, $book_qr) {
    $stmt = $conn->prepare('
        SELECT
            b.book_id,
            b.title,
            b.author,
            b.qr_code,
            b.book_status,
            b.total_copies,
            b.available_copies,
            b.borrowed_copies,
            t.transaction_id,
            t.student_id,
            t.date_borrowed,
            t.due_date,
            s.full_name AS borrower_name,
            s.student_no AS borrower_student_no
        FROM books b
        LEFT JOIN transactions t ON b.book_id = t.book_id AND t.status = "borrowed"
        LEFT JOIN students s ON t.student_id = s.student_id
        WHERE b.qr_code = ?
        ORDER BY t.date_borrowed ASC
        LIMIT 1
    ');
    $stmt->bind_param('s', $book_qr);
    $stmt->execute();
    $result = $stmt->get_result();
    $book = $result->num_rows > 0 ? $result->fetch_assoc() : null;
    $stmt->close();
    return $book;
}

function detectBookMode($book) {
    if (!$book) {
        return 'not_found';
    }
    if (!empty($book['transaction_id'])) {
        return 'return';
    }
    if ((int)$book['available_copies'] > 0 && in_array($book['book_status'], ['available', 'out_of_stock'], true)) {
        return 'borrow';
    }
    return 'unavailable';
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api'])) {
    header('Content-Type: application/json');

    if ($_GET['api'] === 'lookup_student') {
        $student_qr = trim($_GET['student_qr'] ?? '');
        $student = $student_qr !== '' ? getStudentByQr($conn, $student_qr) : null;
        echo json_encode([
            'found' => $student !== null,
            'student' => $student,
            'message' => $student ? 'Student found.' : 'Student not found or inactive.'
        ]);
        exit();
    }

    if ($_GET['api'] === 'lookup_book') {
        $book_qr = trim($_GET['book_qr'] ?? '');
        $book = $book_qr !== '' ? getBookStateByQr($conn, $book_qr) : null;
        $mode = detectBookMode($book);
        echo json_encode([
            'found' => $book !== null,
            'mode' => $mode,
            'book' => $book,
            'message' => $mode === 'return'
                ? 'This book has an active borrowing record and will be returned.'
                : ($mode === 'borrow'
                    ? 'This book is available and will be borrowed.'
                    : ($mode === 'unavailable' ? 'This book is unavailable.' : 'Book not found.'))
        ]);
        exit();
    }

    echo json_encode(['found' => false, 'message' => 'Unknown API request.']);
    exit();
}

$message = '';
$message_type = '';
$transaction_details = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_transaction') {
    $student_qr = trim($_POST['student_qr'] ?? '');
    $book_qr = trim($_POST['book_qr'] ?? '');

    if ($book_qr === '') {
        $message = 'Book QR code is required.';
        $message_type = 'error';
    } else {
        try {
            $book = getBookStateByQr($conn, $book_qr);
            $mode = detectBookMode($book);

            if ($mode === 'not_found') {
                $message = 'Book not found. Please scan a valid book QR code.';
                $message_type = 'error';
            } elseif ($mode === 'unavailable') {
                $message = 'This book is unavailable and cannot be borrowed or returned from this screen.';
                $message_type = 'error';
            } elseif ($mode === 'return') {
                $transaction_id = (int)$book['transaction_id'];
                $book_id = (int)$book['book_id'];
                $due_date = $book['due_date'];
                $today = date('Y-m-d');
                $due_date_only = date('Y-m-d', strtotime($due_date));
                $days_late = 0;
                $penalty_amount = 0.00;

                if (strtotime($today) > strtotime($due_date_only)) {
                    $days_late = (int)floor((strtotime($today) - strtotime($due_date_only)) / 86400);
                    $penalty_amount = $days_late * 5;
                }

                $conn->begin_transaction();
                try {
                    $return_date = date('Y-m-d H:i:s');
                    $status = 'returned';

                    $update_transaction = $conn->prepare('
                        UPDATE transactions
                        SET return_date = ?, status = ?, penalty_amount = ?
                        WHERE transaction_id = ?
                    ');
                    $update_transaction->bind_param('ssdi', $return_date, $status, $penalty_amount, $transaction_id);
                    if (!$update_transaction->execute()) {
                        throw new Exception('Failed to update transaction: ' . $update_transaction->error);
                    }

                    $new_borrowed = max(0, (int)$book['borrowed_copies'] - 1);
                    $new_available = (int)$book['available_copies'] + 1;
                    $book_status = 'available';

                    $update_book = $conn->prepare('
                        UPDATE books
                        SET borrowed_copies = ?, available_copies = ?, book_status = ?
                        WHERE book_id = ?
                    ');
                    $update_book->bind_param('iisi', $new_borrowed, $new_available, $book_status, $book_id);
                    if (!$update_book->execute()) {
                        throw new Exception('Failed to update book: ' . $update_book->error);
                    }

                    $conn->commit();
                    $message = $penalty_amount > 0
                        ? 'Book returned successfully. Penalty: PHP ' . number_format($penalty_amount, 2) . ' (' . $days_late . ' day/s late).'
                        : 'Book returned successfully. No penalty.';
                    $message_type = 'success';
                    $transaction_details = [
                        'mode' => 'Return',
                        'transaction_id' => $transaction_id,
                        'student_name' => $book['borrower_name'] ?? 'Unknown',
                        'student_no' => $book['borrower_student_no'] ?? 'N/A',
                        'book_title' => $book['title'],
                        'book_author' => $book['author'],
                        'date_borrowed' => $book['date_borrowed'],
                        'due_date' => $due_date,
                        'return_date' => $return_date,
                        'penalty_amount' => $penalty_amount
                    ];
                    $update_transaction->close();
                    $update_book->close();
                } catch (Exception $e) {
                    $conn->rollback();
                    throw $e;
                }
            } elseif ($mode === 'borrow') {
                if ($student_qr === '') {
                    $message = 'Student QR code is required to borrow an available book.';
                    $message_type = 'error';
                } else {
                    $student = getStudentByQr($conn, $student_qr);
                    if (!$student) {
                        $message = 'Student not found or account is inactive. Please scan a valid student QR code.';
                        $message_type = 'error';
                    } elseif ((int)$book['available_copies'] <= 0) {
                        $message = 'No available copies of this book. Please select another book.';
                        $message_type = 'error';
                    } else {
                        $conn->begin_transaction();
                        try {
                            $student_id = (int)$student['student_id'];
                            $book_id = (int)$book['book_id'];
                            $date_borrowed = date('Y-m-d H:i:s');
                            $due_date = date('Y-m-d H:i:s', strtotime('+7 days'));
                            $status = 'borrowed';

                            $insert = $conn->prepare('
                                INSERT INTO transactions (student_id, book_id, date_borrowed, due_date, status)
                                VALUES (?, ?, ?, ?, ?)
                            ');
                            $insert->bind_param('iisss', $student_id, $book_id, $date_borrowed, $due_date, $status);
                            if (!$insert->execute()) {
                                throw new Exception('Failed to create transaction: ' . $insert->error);
                            }
                            $transaction_id = $conn->insert_id;

                            $new_borrowed = (int)$book['borrowed_copies'] + 1;
                            $new_available = (int)$book['available_copies'] - 1;
                            $book_status = $new_available <= 0 ? 'out_of_stock' : 'available';

                            $update = $conn->prepare('
                                UPDATE books
                                SET borrowed_copies = ?, available_copies = ?, book_status = ?
                                WHERE book_id = ?
                            ');
                            $update->bind_param('iisi', $new_borrowed, $new_available, $book_status, $book_id);
                            if (!$update->execute()) {
                                throw new Exception('Failed to update inventory: ' . $update->error);
                            }

                            $conn->commit();
                            $message = 'Book borrowed successfully. Transaction ID: ' . $transaction_id . '.';
                            $message_type = 'success';
                            $transaction_details = [
                                'mode' => 'Borrow',
                                'transaction_id' => $transaction_id,
                                'student_name' => $student['full_name'],
                                'student_no' => $student['student_no'] ?? 'N/A',
                                'book_title' => $book['title'],
                                'book_author' => $book['author'],
                                'date_borrowed' => $date_borrowed,
                                'due_date' => $due_date,
                                'return_date' => null,
                                'penalty_amount' => 0
                            ];
                            $insert->close();
                            $update->close();
                        } catch (Exception $e) {
                            $conn->rollback();
                            throw $e;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $message = 'Error: ' . h($e->getMessage());
            $message_type = 'error';
            logError('Unified QR transaction error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - Library Borrowing System</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.4/html5-qrcode.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #F3F7FC; color: #202A44; }
        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; margin-bottom: 25px; }
        .page-header h2 { color: #202A44; font-size: 28px; margin-bottom: 8px; }
        .page-header p { color: #666; font-size: 14px; line-height: 1.5; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; font-size: 14px; line-height: 1.5; }
        .alert-success { background: #EDF5DD; color: #344E15; border: 1px solid #B5D27A; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .section { background: #fff; padding: 25px; border-radius: 10px; margin-bottom: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); scroll-margin-top: 24px; }
        .section h3 { color: #202A44; margin-bottom: 18px; font-size: 20px; padding-bottom: 10px; border-bottom: 2px solid #141F52; }
        .step-panel { position: sticky; top: 10px; z-index: 20; }
        .step-indicator { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
        .step { padding: 18px; border-radius: 10px; text-align: center; background: #F7F9FC; border: 2px solid #D2E2F6; transition: all 0.25s; cursor: pointer; }
        .step:hover:not(.disabled) { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,0.08); }
        .step.active { background: #EDF3FA; border-color: #141F52; box-shadow: 0 0 0 3px rgba(244,249,22,.35); }
        .step.completed { background: #EDF5DD; border-color: #567D1F; }
        .step.disabled { background: #E7EEF7; border-color: #bbb; color: #999; cursor: not-allowed; opacity: 0.6; }
        .step.disabled .step-number { background: #bbb; color: #fff; }
        .step.disabled h4 { color: #999; }
        .step.disabled p { color: #bbb; }
        .step-number { display: inline-block; width: 38px; height: 38px; line-height: 38px; background: #D2E2F6; border-radius: 50%; font-weight: 700; margin-bottom: 10px; font-size: 18px; }
        .step.active .step-number { background: #141F52; color: #fff; }
        .step.completed .step-number { background: #567D1F; color: #fff; }
        .step h4 { color: #202A44; margin-bottom: 5px; }
        .step p { color: #666; font-size: 13px; }
        .scanner-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .scanner-title { font-size: 18px; font-weight: 700; color: #202A44; }
        .scanner-subtitle { font-size: 13px; color: #666; margin-top: 2px; }
        .status-pill { display: inline-flex; align-items: center; padding: 7px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; background: #eee; color: #52618D; }
        .status-pill.ready { background: #EDF5DD; color: #344E15; }
        .status-pill.pending { background: #FBFDCB; color: #5C5F05; }
        .status-pill.error { background: #f8d7da; color: #721c24; }
        .info-box { background: #EDF3FA; padding: 15px; border-radius: 6px; margin: 15px 0; border-left: 4px solid #141F52; font-size: 13px; color: #202A44; line-height: 1.6; }
        .scanner-wrapper { text-align: center; background: #E7EEF7; padding: 18px; border-radius: 8px; margin: 18px 0; }
        #qr-reader-student, #qr-reader-book { width: 100%; max-width: 500px; margin: 0 auto; border-radius: 8px; overflow: hidden; }
        .scanner-controls, .controls-row { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; margin-top: 15px; }
        button, .btn { padding: 12px 22px; background: #141F52; color: #fff; border: none; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        button:hover:not(:disabled), .btn:hover { background: #52618D; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(20, 31, 82,0.32); }
        button:disabled { background: #999; cursor: not-allowed; opacity: 0.65; }
        .btn-small { padding: 8px 16px; font-size: 12px; }
        .btn-secondary { background: #666; }
        .btn-danger { background: #c0392b; }
        .form-grid { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: end; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 7px; color: #202A44; font-weight: 600; font-size: 14px; }
        input[type="text"] { width: 100%; padding: 12px; border: 1px solid #D2E2F6; border-radius: 6px; font-size: 14px; }
        input[type="text"]:focus { outline: none; border-color: #141F52; box-shadow: 0 0 0 3px rgba(244,249,22,.35); }
        .summary-card { border: 1px solid #e5e5e5; border-radius: 10px; overflow: hidden; margin: 15px 0; display: none; }
        .summary-card.show { display: block; }
        .summary-card-header { padding: 14px 18px; background: #F7F9FC; border-bottom: 1px solid #e5e5e5; font-weight: 700; }
        .summary-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0; }
        .summary-item { padding: 14px 18px; border-right: 1px solid #E7EEF7; border-bottom: 1px solid #E7EEF7; }
        .summary-label { font-size: 11px; text-transform: uppercase; color: #888; font-weight: 700; letter-spacing: .4px; margin-bottom: 4px; }
        .summary-value { font-size: 15px; font-weight: 700; color: #202A44; }
        .mode-banner { padding: 16px; border-radius: 8px; margin-bottom: 15px; border-left: 5px solid #141F52; background: #EDF3FA; font-size: 14px; line-height: 1.5; }
        .mode-banner.return { background: #FBFDCB; border-left-color: #F4F916; color: #5C5F05; }
        .mode-banner.borrow { background: #EDF5DD; border-left-color: #567D1F; color: #344E15; }
        .result-details { background: #fff; border-radius: 10px; padding: 18px; margin-bottom: 20px; border: 1px solid #e5e5e5; }
        .result-details h3 { border-bottom: 2px solid #141F52; margin-bottom: 14px; padding-bottom: 8px; }
        .section.disabled { background: #F3F7FC; opacity: 0.5; pointer-events: none; }
        .section.step-panel.disabled { background: #fff; opacity: 1; pointer-events: auto; }
        @media (max-width: 768px) {
            .page-header { flex-direction: column; }
            .step-indicator, .summary-grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .controls-row button { width: 100%; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>

    <div class="container">
        <div class="page-header">
            <div>
                <h2>Book Transactions</h2>
                <p>Scan a book QR code and the system will automatically detect whether the transaction is for borrowing or returning.</p>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo h($message_type); ?>">
                <span><?php echo $message_type === 'success' ? 'OK' : 'ERROR'; ?></span>
                <span><?php echo h($message); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($transaction_details): ?>
            <div class="result-details">
                <h3><?php echo h($transaction_details['mode']); ?> Transaction Details</h3>
                <div class="summary-grid">
                    <div class="summary-item"><div class="summary-label">Transaction ID</div><div class="summary-value">#<?php echo h($transaction_details['transaction_id']); ?></div></div>
                    <div class="summary-item"><div class="summary-label">Student</div><div class="summary-value"><?php echo h($transaction_details['student_name']); ?> (<?php echo h($transaction_details['student_no']); ?>)</div></div>
                    <div class="summary-item"><div class="summary-label">Book</div><div class="summary-value"><?php echo h($transaction_details['book_title']); ?></div></div>
                    <div class="summary-item"><div class="summary-label">Author</div><div class="summary-value"><?php echo h($transaction_details['book_author']); ?></div></div>
                    <div class="summary-item"><div class="summary-label">Borrowed</div><div class="summary-value"><?php echo h(date('M d, Y', strtotime($transaction_details['date_borrowed']))); ?></div></div>
                    <div class="summary-item"><div class="summary-label">Due Date</div><div class="summary-value"><?php echo h(date('M d, Y', strtotime($transaction_details['due_date']))); ?></div></div>
                    <div class="summary-item"><div class="summary-label">Returned</div><div class="summary-value"><?php echo $transaction_details['return_date'] ? h(date('M d, Y', strtotime($transaction_details['return_date']))) : 'N/A'; ?></div></div>
                    <div class="summary-item"><div class="summary-label">Penalty</div><div class="summary-value">PHP <?php echo h(number_format((float)$transaction_details['penalty_amount'], 2)); ?></div></div>
                </div>
            </div>
        <?php endif; ?>

        <div class="section step-panel">
            <div class="step-indicator">
                <div class="step" id="step1" onclick="goToStep(1)">
                    <div class="step-number">1</div>
                    <h4>Student QR</h4>
                    <p>Required only for borrowing</p>
                </div>
                <div class="step" id="step2" onclick="goToStep(2)">
                    <div class="step-number">2</div>
                    <h4>Book QR</h4>
                    <p>System detects borrow or return</p>
                </div>
                <div class="step" id="step3" onclick="goToStep(3)">
                    <div class="step-number">3</div>
                    <h4>Process</h4>
                    <p>Confirm the transaction</p>
                </div>
            </div>
        </div>

        <form method="POST" id="transactionForm">
            <input type="hidden" name="action" value="process_transaction">

            <div class="section" id="section1">
                <div class="scanner-header">
                    <div>
                        <div class="scanner-title">Step 1: Scan Student QR Code</div>
                        <div class="scanner-subtitle">Required when the book is available and will be borrowed. Optional for returns.</div>
                    </div>
                    <span class="status-pill pending" id="studentStatus">Waiting</span>
                </div>

                <div class="info-box">
                    For borrowing, scan or enter the student's QR code first. For returning a borrowed book, you may skip this step and scan the book QR code.
                </div>

                <div class="scanner-wrapper">
                    <div id="qr-reader-student"></div>
                </div>

                <div class="scanner-controls">
                    <button type="button" class="btn-small" id="startBtnStudent" onclick="startScannerStudent()">Start Student Camera</button>
                    <button type="button" class="btn-small btn-danger" id="stopBtnStudent" onclick="stopScannerStudent()" style="display:none;">Stop Camera</button>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="student_qr">Student QR Code</label>
                        <input type="text" id="student_qr" name="student_qr" placeholder="Scan or type student QR code">
                    </div>
                    <button type="button" onclick="confirmStudentManual()">Confirm Student</button>
                </div>

                <div class="summary-card" id="studentSummary">
                    <div class="summary-card-header">Student Details</div>
                    <div class="summary-grid" id="studentSummaryGrid"></div>
                </div>
            </div>

            <div class="section" id="section2">
                <div class="scanner-header">
                    <div>
                        <div class="scanner-title">Step 2: Scan Book QR Code</div>
                        <div class="scanner-subtitle">The system checks if this book should be borrowed or returned.</div>
                    </div>
                    <span class="status-pill pending" id="bookStatus">Waiting</span>
                </div>

                <div class="info-box">
                    If the scanned book has an active borrowed transaction, the final action will be Return. If it has available copies and no active transaction, the final action will be Borrow.
                </div>

                <div class="scanner-wrapper">
                    <div id="qr-reader-book"></div>
                </div>

                <div class="scanner-controls">
                    <button type="button" class="btn-small" id="startBtnBook" onclick="startScannerBook()">Start Book Camera</button>
                    <button type="button" class="btn-small btn-danger" id="stopBtnBook" onclick="stopScannerBook()" style="display:none;">Stop Camera</button>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="book_qr">Book QR Code</label>
                        <input type="text" id="book_qr" name="book_qr" placeholder="Scan or type book QR code">
                    </div>
                    <button type="button" onclick="confirmBookManual()">Confirm Book</button>
                </div>

                <div class="summary-card" id="bookSummary">
                    <div class="summary-card-header">Book Details</div>
                    <div class="summary-grid" id="bookSummaryGrid"></div>
                </div>
            </div>

            <div class="section" id="section3">
                <h3>Step 3: Complete Transaction</h3>
                <div id="modeBanner" class="mode-banner">
                    Scan a book QR code first. The system will automatically identify whether this transaction is a Borrow or Return.
                </div>
                <div class="controls-row">
                    <button type="submit" id="submitBtn" disabled>Process Transaction</button>
                    <button type="button" class="btn-secondary" onclick="resetForm()">Reset All</button>
                </div>
            </div>
        </form>
    </div>

    <script>
        let studentScanner = null;
        let bookScanner = null;
        let studentValid = false;
        let bookValid = false;
        let transactionMode = null;

        document.addEventListener('DOMContentLoaded', function() {
            updateSteps();
        });

        function goToStep(stepNumber) {
            const step = document.getElementById('step' + stepNumber);
            if (step && step.classList.contains('disabled')) {
                return; // Prevent navigation to disabled steps
            }
            const section = document.getElementById('section' + stepNumber);
            if (section) {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            setActiveStep(stepNumber);
        }

        function setActiveStep(stepNumber) {
            [1,2,3].forEach(number => {
                document.getElementById('step' + number).classList.remove('active');
            });
            document.getElementById('step' + stepNumber).classList.add('active');
        }

        function startScannerStudent() {
            document.getElementById('startBtnStudent').style.display = 'none';
            document.getElementById('stopBtnStudent').style.display = 'inline-flex';
            studentScanner = new Html5QrcodeScanner('qr-reader-student', { facingMode: 'environment', qrbox: 250 }, false);
            studentScanner.render(onScanSuccessStudent, onScanError);
        }

        function stopScannerStudent() {
            if (studentScanner) {
                studentScanner.clear();
                studentScanner = null;
            }
            document.getElementById('startBtnStudent').style.display = 'inline-flex';
            document.getElementById('stopBtnStudent').style.display = 'none';
        }

        function startScannerBook() {
            document.getElementById('startBtnBook').style.display = 'none';
            document.getElementById('stopBtnBook').style.display = 'inline-flex';
            bookScanner = new Html5QrcodeScanner('qr-reader-book', { facingMode: 'environment', qrbox: 250 }, false);
            bookScanner.render(onScanSuccessBook, onScanError);
        }

        function stopScannerBook() {
            if (bookScanner) {
                bookScanner.clear();
                bookScanner = null;
            }
            document.getElementById('startBtnBook').style.display = 'inline-flex';
            document.getElementById('stopBtnBook').style.display = 'none';
        }

        function onScanSuccessStudent(decodedText) {
            const text = decodedText.trim();
            if (!text.startsWith('STU')) {
                setStatus('studentStatus', 'Invalid student QR', 'error');
                return;
            }
            document.getElementById('student_qr').value = text;
            stopScannerStudent();
            lookupStudent(text, true);
        }

        function onScanSuccessBook(decodedText) {
            const text = decodedText.trim();
            if (!text.startsWith('BOOK')) {
                setStatus('bookStatus', 'Invalid book QR', 'error');
                return;
            }
            document.getElementById('book_qr').value = text;
            stopScannerBook();
            lookupBook(text, true);
        }

        function onScanError(error) {
            console.log('Scan error:', error);
        }

        function confirmStudentManual() {
            const code = document.getElementById('student_qr').value.trim();
            if (!code) {
                setStatus('studentStatus', 'Missing QR', 'error');
                return;
            }
            lookupStudent(code, false);
        }

        function confirmBookManual() {
            const code = document.getElementById('book_qr').value.trim();
            if (!code) {
                setStatus('bookStatus', 'Missing QR', 'error');
                return;
            }
            lookupBook(code, false);
        }

        function lookupStudent(code, jumpNext) {
            setStatus('studentStatus', 'Checking...', 'pending');
            fetch('?api=lookup_student&student_qr=' + encodeURIComponent(code))
                .then(response => response.json())
                .then(data => {
                    if (data.found) {
                        studentValid = true;
                        setStatus('studentStatus', 'Confirmed', 'ready');
                        renderStudentSummary(data.student);
                        if (jumpNext) goToStep(2);
                    } else {
                        studentValid = false;
                        setStatus('studentStatus', 'Not found', 'error');
                        document.getElementById('studentSummary').classList.remove('show');
                    }
                    updateSteps();
                })
                .catch(() => {
                    studentValid = false;
                    setStatus('studentStatus', 'Lookup failed', 'error');
                    updateSteps();
                });
        }

        function lookupBook(code, jumpNext) {
            setStatus('bookStatus', 'Checking...', 'pending');
            fetch('?api=lookup_book&book_qr=' + encodeURIComponent(code))
                .then(response => response.json())
                .then(data => {
                    if (data.found && (data.mode === 'borrow' || data.mode === 'return')) {
                        bookValid = true;
                        transactionMode = data.mode;
                        setStatus('bookStatus', data.mode === 'return' ? 'Return detected' : 'Borrow detected', 'ready');
                        renderBookSummary(data.book, data.mode);
                        if (jumpNext) goToStep(3);
                    } else {
                        bookValid = false;
                        transactionMode = null;
                        setStatus('bookStatus', data.message || 'Unavailable', 'error');
                        document.getElementById('bookSummary').classList.remove('show');
                    }
                    updateSteps();
                })
                .catch(() => {
                    bookValid = false;
                    transactionMode = null;
                    setStatus('bookStatus', 'Lookup failed', 'error');
                    updateSteps();
                });
        }

        function renderStudentSummary(student) {
            document.getElementById('studentSummaryGrid').innerHTML =
                summaryItem('Name', student.full_name) +
                summaryItem('Student No', student.student_no || 'N/A') +
                summaryItem('Group', student.student_group || 'N/A') +
                summaryItem('Department', student.department || 'N/A') +
                summaryItem('Year Level', student.year_level || 'N/A') +
                summaryItem('Contact', student.contact_number || 'N/A') +
                summaryItem('Email', student.email || 'N/A') +
                summaryItem('Card Validity', formatDate(student.card_valid_until));
            document.getElementById('studentSummary').classList.add('show');
        }

        function renderBookSummary(book, mode) {
            let activeDetails = '';
            if (mode === 'return') {
                activeDetails = summaryItem('Borrower', (book.borrower_name || 'Unknown') + ' (' + (book.borrower_student_no || 'N/A') + ')') +
                                summaryItem('Due Date', formatDate(book.due_date));
            }

            document.getElementById('bookSummaryGrid').innerHTML =
                summaryItem('Action', mode === 'return' ? 'Return Book' : 'Borrow Book') +
                summaryItem('Title', book.title) +
                summaryItem('Author', book.author) +
                summaryItem('Available Copies', book.available_copies) +
                summaryItem('Borrowed Copies', book.borrowed_copies) +
                summaryItem('Total Copies', book.total_copies) +
                activeDetails;
            document.getElementById('bookSummary').classList.add('show');
        }

        function summaryItem(label, value) {
            return `<div class="summary-item"><div class="summary-label">${escapeHtml(label)}</div><div class="summary-value">${escapeHtml(value == null || value === '' ? 'N/A' : value)}</div></div>`;
        }

        function updateSteps() {
            [1,2,3].forEach(number => {
                document.getElementById('step' + number).classList.remove('completed');
                document.getElementById('section' + number).classList.remove('disabled');
            });

            // Step 1 is always enabled
            // Step 2 is enabled only when Step 1 is completed
            if (!studentValid) {
                document.getElementById('section2').classList.add('disabled');
                document.getElementById('section3').classList.add('disabled');
            } else {
                // Step 3 is enabled only when Step 2 is completed
                if (!bookValid) {
                    document.getElementById('section3').classList.add('disabled');
                }
            }

            if (studentValid) document.getElementById('step1').classList.add('completed');
            if (bookValid) document.getElementById('step2').classList.add('completed');

            const submitBtn = document.getElementById('submitBtn');
            const modeBanner = document.getElementById('modeBanner');

            if (!bookValid || !transactionMode) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Process Transaction';
                modeBanner.className = 'mode-banner';
                modeBanner.textContent = 'Scan a book QR code first. The system will automatically identify whether this transaction is a Borrow or Return.';
            } else if (transactionMode === 'return') {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Process Book Return';
                modeBanner.className = 'mode-banner return';
                modeBanner.textContent = 'Return detected. This book has an active borrowed transaction, so the system will process it as a return. Student QR is not required.';
                document.getElementById('step3').classList.add('completed');
            } else if (transactionMode === 'borrow' && studentValid) {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Process Book Borrowing';
                modeBanner.className = 'mode-banner borrow';
                modeBanner.textContent = 'Borrow detected. This book is available and the student is confirmed. You can now process the borrowing transaction.';
                document.getElementById('step3').classList.add('completed');
            } else {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Process Book Borrowing';
                modeBanner.className = 'mode-banner borrow';
                modeBanner.textContent = 'Borrow detected. Please complete Step 1 by scanning or confirming the student QR code before processing.';
            }
        }

        function resetForm() {
            stopScannerStudent();
            stopScannerBook();
            document.getElementById('transactionForm').reset();
            studentValid = false;
            bookValid = false;
            transactionMode = null;
            document.getElementById('studentSummary').classList.remove('show');
            document.getElementById('bookSummary').classList.remove('show');
            setStatus('studentStatus', 'Waiting', 'pending');
            setStatus('bookStatus', 'Waiting', 'pending');
            updateSteps();
            goToStep(1);
        }

        function setStatus(id, text, type) {
            const el = document.getElementById(id);
            el.textContent = text;
            el.className = 'status-pill ' + type;
        }

        function formatDate(value) {
            if (!value) return 'N/A';
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return value;
            return date.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: '2-digit' });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }
    </script>
</body>
</html>
