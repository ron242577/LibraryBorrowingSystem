<?php
/**
 * Student QR Book Borrowing Page - Redesigned
 * Allows students to borrow books with an improved UI based on librarian search.php
 * Features: Student info display, book search, dynamic filtering, QR code display
 */

require_once __DIR__ . '/../db.php';

$student = null;
$student_error = null;
$books = [];
$modal_data = null;
$modal_type = '';
$borrowed_books = [];

// Get student from QR parameter
$student_qr = isset($_GET['qr']) ? trim($_GET['qr']) : null;

if ($student_qr) {
    try {
        $student_stmt = $conn->prepare("
            SELECT 
                student_id,
                full_name,
                contact_number,
                qr_code,
                status,
                created_at
            FROM students
            WHERE qr_code = ? AND status = 'active'
        ");
        $student_stmt->bind_param('s', $student_qr);
        $student_stmt->execute();
        $student_result = $student_stmt->get_result();
        
        if ($student_result->num_rows > 0) {
            $student = $student_result->fetch_assoc();
            $student_id = $student['student_id'];
            
            // Get student's currently borrowed books
            $borrowed_stmt = $conn->prepare("
                SELECT book_id FROM transactions 
                WHERE student_id = ? AND status = 'borrowed'
            ");
            $borrowed_stmt->bind_param('i', $student_id);
            $borrowed_stmt->execute();
            $borrowed_result = $borrowed_stmt->get_result();
            
            while ($row = $borrowed_result->fetch_assoc()) {
                $borrowed_books[] = $row['book_id'];
            }
            $borrowed_stmt->close();
        } else {
            $student_error = 'Student not found or inactive. Please try scanning your QR code again.';
        }
        $student_stmt->close();
    } catch (Exception $e) {
        $student_error = 'An error occurred while fetching student information.';
        logError('Student fetch error: ' . $e->getMessage());
    }
} else {
    $student_error = 'No student QR code provided. Please scan your student ID to begin.';
}

// Fetch all available books with QR codes, removing duplicates
if ($student) {
    try {
        $books_stmt = $conn->prepare("
            SELECT 
                book_id,
                title,
                author,
                qr_code,
                book_status,
                total_copies,
                available_copies,
                borrowed_copies
            FROM books
            WHERE book_status IN ('available', 'out_of_stock')
            AND qr_code IS NOT NULL
            AND qr_code != ''
            GROUP BY book_id
            ORDER BY title ASC
        ");
        $books_stmt->execute();
        $books_result = $books_stmt->get_result();
        
        while ($row = $books_result->fetch_assoc()) {
            $books[] = $row;
        }
        $books_stmt->close();
    } catch (Exception $e) {
        logError('Books fetch error: ' . $e->getMessage());
    }
}

// Handle AJAX book search
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api']) && $_GET['api'] === 'search_books') {
    header('Content-Type: application/json');
    
    $search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
    $results = [];
    
    try {
        if (!empty($search_query)) {
            $search_stmt = $conn->prepare("
                SELECT 
                    book_id,
                    title,
                    author,
                    qr_code,
                    book_status,
                    available_copies
                FROM books
                WHERE (title LIKE ? OR author LIKE ?) 
                AND book_status IN ('available', 'out_of_stock')
                AND qr_code IS NOT NULL
                AND qr_code != ''
                GROUP BY book_id
                ORDER BY title ASC
                LIMIT 50
            ");
            
            $search_param = '%' . $search_query . '%';
            $search_stmt->bind_param('ss', $search_param, $search_param);
            $search_stmt->execute();
            $search_result = $search_stmt->get_result();
            
            while ($row = $search_result->fetch_assoc()) {
                $results[] = $row;
            }
            $search_stmt->close();
        } else {
            // Return all books if no search query
            $seen = [];
            foreach ($books as $book) {
                // Remove duplicates by book_id
                if (!isset($seen[$book['book_id']])) {
                    $results[] = [
                        'book_id' => $book['book_id'],
                        'title' => $book['title'],
                        'author' => $book['author'],
                        'qr_code' => $book['qr_code'],
                        'book_status' => $book['book_status'],
                        'available_copies' => $book['available_copies']
                    ];
                    $seen[$book['book_id']] = true;
                }
            }
        }
    } catch (Exception $e) {
        logError('Book search error: ' . $e->getMessage());
    }
    
    echo json_encode($results);
    exit();
}

// Handle AJAX to get book details
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['api']) && $_GET['api'] === 'get_book') {
    header('Content-Type: application/json');
    
    $book_id = isset($_GET['book_id']) ? intval($_GET['book_id']) : 0;
    $result = null;
    
    try {
        if ($book_id > 0) {
            $book_stmt = $conn->prepare("
                SELECT 
                    book_id,
                    title,
                    author,
                    qr_code,
                    book_status,
                    available_copies,
                    borrowed_copies,
                    total_copies
                FROM books
                WHERE book_id = ?
            ");
            $book_stmt->bind_param('i', $book_id);
            $book_stmt->execute();
            $book_result = $book_stmt->get_result();
            
            if ($book_result->num_rows > 0) {
                $result = $book_result->fetch_assoc();
            }
            $book_stmt->close();
        }
    } catch (Exception $e) {
        logError('Get book error: ' . $e->getMessage());
    }
    
    echo json_encode($result);
    exit();
}

// Handle transaction creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'process_borrow' && $student) {
        $book_id = isset($_POST['book_id']) ? intval($_POST['book_id']) : 0;
        
        if ($book_id <= 0) {
            $modal_type = 'error';
            $modal_data = [
                'title' => 'Invalid Book',
                'message' => 'Please select a valid book to borrow.',
                'icon' => 'error'
            ];
        } else {
            try {
                // Get book details
                $book_stmt = $conn->prepare("
                    SELECT book_id, title, available_copies, borrowed_copies 
                    FROM books 
                    WHERE book_id = ?
                ");
                $book_stmt->bind_param('i', $book_id);
                $book_stmt->execute();
                $book_result = $book_stmt->get_result();
                
                if ($book_result->num_rows === 0) {
                    $modal_type = 'error';
                    $modal_data = [
                        'title' => 'Book Not Found',
                        'message' => 'The selected book was not found in the system.',
                        'icon' => 'error'
                    ];
                } else {
                    $book = $book_result->fetch_assoc();
                    
                    // Check if available copies > 0
                    if ($book['available_copies'] <= 0) {
                        $modal_type = 'error';
                        $modal_data = [
                            'title' => 'No Copies Available',
                            'message' => 'Unfortunately, all copies of this book are currently borrowed. Please select another book.',
                            'icon' => 'error'
                        ];
                    } else {
                        // Calculate dates
                        $date_borrowed = date('Y-m-d H:i:s');
                        $due_date = date('Y-m-d H:i:s', strtotime('+7 days'));
                        $status = 'borrowed';
                        $book_status = 'available';
                        $student_id = $student['student_id'];
                        
                        // Begin transaction
                        $conn->begin_transaction();
                        
                        try {
                            // Insert transaction
                            $insert_stmt = $conn->prepare("
                                INSERT INTO transactions 
                                (student_id, book_id, date_borrowed, due_date, status) 
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $insert_stmt->bind_param('iisss', $student_id, $book_id, $date_borrowed, $due_date, $status);
                            
                            if (!$insert_stmt->execute()) {
                                throw new Exception('Failed to create transaction: ' . $insert_stmt->error);
                            }
                            
                            $transaction_id = $conn->insert_id;
                            
                            // Update inventory
                            $new_borrowed = $book['borrowed_copies'] + 1;
                            $new_available = $book['available_copies'] - 1;
                            $book_status = ($new_available === 0) ? 'out_of_stock' : 'available';
                            
                            $update_stmt = $conn->prepare("
                                UPDATE books 
                                SET borrowed_copies = ?, available_copies = ?, book_status = ? 
                                WHERE book_id = ?
                            ");
                            $update_stmt->bind_param('iisi', $new_borrowed, $new_available, $book_status, $book_id);
                            
                            if (!$update_stmt->execute()) {
                                throw new Exception('Failed to update inventory: ' . $update_stmt->error);
                            }
                            
                            $conn->commit();
                            
                            $modal_type = 'success';
                            $modal_data = [
                                'title' => 'Book Successfully Borrowed!',
                                'message' => 'Your borrowing has been confirmed.',
                                'icon' => 'success',
                                'transaction_id' => $transaction_id,
                                'student_name' => htmlspecialchars($student['full_name']),
                                'book_title' => htmlspecialchars($book['title']),
                                'due_date' => date('F d, Y', strtotime($due_date)),
                                'date_borrowed' => date('F d, Y', strtotime($date_borrowed))
                            ];
                            
                            $insert_stmt->close();
                            $update_stmt->close();
                        } catch (Exception $e) {
                            $conn->rollback();
                            $modal_type = 'error';
                            $modal_data = [
                                'title' => 'Transaction Error',
                                'message' => 'An error occurred while processing your request. Please try again.',
                                'icon' => 'error'
                            ];
                            logError('Borrow transaction error: ' . $e->getMessage());
                        }
                    }
                }
                $book_stmt->close();
            } catch (Exception $e) {
                $modal_type = 'error';
                $modal_data = [
                    'title' => 'System Error',
                    'message' => 'An unexpected error occurred. Please try again later.',
                    'icon' => 'error'
                ];
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
    <title>Borrow Books - Library Borrowing System</title>
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
        
        /* Student Info Column */
        .student-info-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .student-info-section h2 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #2c3e50;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
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
        
        .student-details h3 {
            font-size: 20px;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .student-details p {
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
        
        .error-box {
            background: #ffebee;
            border: 2px solid #ef5350;
            border-radius: 8px;
            padding: 20px;
            color: #c62828;
            text-align: center;
        }
        
        .error-box .error-icon {
            font-size: 48px;
            margin-bottom: 15px;
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
        
        /* Books Results Column */
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
            max-height: 600px;
            overflow-y: auto;
        }
        
        .book-item {
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
        
        .book-item:hover, .book-item.active {
            background: linear-gradient(135deg, #00336615 0%, #00336215 100%);
            border-color: #667eea;
            transform: translateX(4px);
        }
        
        .book-item.unavailable {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .book-item.unavailable:hover {
            background: #f8f9fa;
            border-color: #e0e0e0;
            transform: none;
        }
        
        .book-icon {
            font-size: 24px;
            flex-shrink: 0;
        }
        
        .book-info {
            flex: 1;
        }
        
        .book-title {
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .book-author {
            font-size: 12px;
            color: #7f8c8d;
        }
        
        .book-availability {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            flex-shrink: 0;
        }
        
        .book-availability.available {
            background: #d4edda;
            color: #155724;
        }
        
        .book-availability.unavailable {
            background: #f8d7da;
            color: #721c24;
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
        
        .book-details {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-top: 30px;
        }
        
        .book-details h3 {
            font-size: 20px;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .book-details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .detail-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid #667eea;
        }
        
        .detail-label {
            font-size: 11px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            font-weight: 600;
        }
        
        .detail-value {
            font-size: 15px;
            color: #2c3e50;
            font-weight: 600;
        }
        
        .book-qr {
            background: linear-gradient(135deg, #f5f7fa 0%, #f0f4f8 100%);
            border: 2px dashed #667eea;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .book-qr h4 {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            font-weight: 600;
        }
        
        .book-qr img {
            max-width: 150px;
            height: auto;
            border-radius: 8px;
            background: white;
            padding: 8px;
        }
        
        .borrow-btn {
            width: 100%;
            padding: 14px 28px;
            background: #003366;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(0, 51, 102, 0.3);
        }
        
        .borrow-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 51, 102, 0.4);
        }
        
        .borrow-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            animation: fadeIn 0.3s ease;
        }
        
        .modal-overlay.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
            overflow: hidden;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .modal-header {
            padding: 40px 30px 30px;
            text-align: center;
        }
        
        .modal-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            animation: scaleIn 0.4s ease;
        }
        
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.5); }
            to { opacity: 1; transform: scale(1); }
        }
        
        .modal-icon.success {
            background: #e8f5e9;
            color: #4CAF50;
        }
        
        .modal-icon.error {
            background: #ffebee;
            color: #f44336;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 10px;
        }
        
        .modal-message {
            font-size: 15px;
            color: #666;
            line-height: 1.6;
            margin-bottom: 0;
        }
        
        .modal-body {
            padding: 0 30px 30px;
        }
        
        .transaction-details {
            background: #f8fafb;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 24px;
            border-left: 4px solid #667eea;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 14px;
        }
        
        .detail-label {
            color: #666;
            font-weight: 600;
        }
        
        .detail-value {
            color: #333;
            font-weight: 700;
        }
        
        .modal-footer {
            padding: 24px 30px;
            border-top: 1px solid #e8e8f0;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .modal-btn {
            padding: 12px 28px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            flex: 1;
            min-width: 120px;
        }
        
        .modal-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .modal-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        .modal-btn-secondary {
            background: #f0f2f8;
            color: #667eea;
            font-weight: 600;
        }
        
        .modal-btn-secondary:hover {
            background: #e8eaf6;
        }
        
        /* Header Styles */
        .page-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 70px;
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            z-index: 998;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 40px;
        }
        
        .header-brand {
            display: flex;
            align-items: center;
            text-decoration: none;
            gap: 12px;
        }
        
        .header-brand img {
            height: 50px;
            width: auto;
            object-fit: contain;
        }
        
        .header-brand-text {
            font-size: 18px;
            font-weight: 700;
            color: #667eea;
        }
        
        .header-brand:hover .header-brand-text {
            color: #764ba2;
        }
        
        .logout-btn {
            padding: 10px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .logout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        /* Adjust body to account for fixed header */
        body {
            padding-top: 70px;
        }
        
        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            
            .info-grid, .book-details-grid {
                grid-template-columns: 1fr;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .results-list {
                max-height: none;
            }
            
            .page-header {
                padding: 0 20px;
                height: 60px;
            }
            
            .header-brand {
                gap: 8px;
            }
            
            .header-brand img {
                height: 40px;
            }
            
            .header-brand-text {
                font-size: 14px;
            }
            
            .logout-btn {
                padding: 8px 16px;
                font-size: 12px;
            }
            
            body {
                padding-top: 60px;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="page-header">
        <a href="/LibraryBorrowingSystem/student/portal.php" class="header-brand">
            <img src="/LibraryBorrowingSystem/Img/Arellano_University_logo.png" alt="Arellano University Logo">
            <span class="header-brand-text">Arellano_University</span>
        </a>
            <!-- Logout Button -->
        <a href="/LibraryBorrowingSystem/student/portal.php" class="logout-btn">
            Logout
        </a>
    </header>
    
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📚 Borrow Books</h1>
            <p>Browse available books and borrow them instantly</p>
        </div>
        
        <!-- Search Section -->
        <div class="search-section">
            <form class="search-form" id="searchForm">
                <input type="text" 
                       id="searchInput"
                       placeholder="Search by book title or author..." 
                       autofocus>
                <button type="submit">Search Books</button>
            </form>
        </div>
        
        <!-- Main Grid: Student Info + Books -->
        <div class="grid">
            <!-- Student Information Column -->
            <div class="student-info-section">
                <?php if ($student_error): ?>
                    <div class="error-box">
                        <div class="error-icon">⚠️</div>
                        <p><?php echo htmlspecialchars($student_error); ?></p>
                    </div>
                <?php elseif ($student): ?>
                    <h2>👤 Your Information</h2>
                    
                    <!-- Student Header -->
                    <div class="student-header">
                        <div class="student-avatar">👨‍🎓</div>
                        <div class="student-details">
                            <h3><?php echo htmlspecialchars($student['full_name']); ?></h3>
                            <p>Member Since: <?php echo date('M d, Y', strtotime($student['created_at'])); ?></p>
                        </div>
                    </div>
                    
                    <!-- Info Grid -->
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Student ID</div>
                            <div class="info-value">STU-<?php echo str_pad($student['student_id'], 4, '0', STR_PAD_LEFT); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Contact</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?></div>
                        </div>
                    </div>
                    
                    <!-- QR Code -->
                    <div class="qr-section">
                        <h4>QR Code</h4>
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($student['qr_code']); ?>" 
                             alt="Student QR Code">
                        <div class="qr-text"><?php echo htmlspecialchars($student['qr_code']); ?></div>
                    </div>
                <?php else: ?>
                    <div class="no-selection">
                        <div class="no-selection-icon">👤</div>
                        <p>Unable to load student information</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Books Results Column -->
            <div class="results-section">
                <h2>📖 Available Books (<span id="bookCount"><?php echo count($books); ?></span>)</h2>
                
                <div class="results-list" id="booksList">
                    <?php if (empty($books) && $student): ?>
                        <div class="empty-results">
                            <div class="empty-results-icon">📚</div>
                            <p>No books available at the moment</p>
                        </div>
                    <?php elseif (!$student): ?>
                        <div class="empty-results">
                            <div class="empty-results-icon">🔒</div>
                            <p>Unable to load books. Please verify your QR code.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($books as $book): ?>
                            <div class="book-item <?php echo $book['available_copies'] <= 0 ? 'unavailable' : ''; ?>" 
                                 onclick="selectBook(<?php echo $book['book_id']; ?>, '<?php echo htmlspecialchars(addslashes($book['title'])); ?>', <?php echo $book['available_copies']; ?>)">
                                <div class="book-icon">📕</div>
                                <div class="book-info">
                                    <div class="book-title"><?php echo htmlspecialchars($book['title']); ?></div>
                                    <div class="book-author"><?php echo htmlspecialchars($book['author']); ?></div>
                                </div>
                                <div class="book-availability <?php echo $book['available_copies'] > 0 ? 'available' : 'unavailable'; ?>">
                                    <?php echo $book['available_copies'] > 0 ? 'Available' : 'Out of Stock'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Book Details Section (shown when a book is selected) -->
        <div class="book-details" id="bookDetailsSection" style="display: none;">
            <h3 id="selectedBookTitle">Book Details</h3>
            
            <div class="book-details-grid">
                <div class="detail-item">
                    <div class="detail-label">Author</div>
                    <div class="detail-value" id="selectedBookAuthor">—</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Available Copies</div>
                    <div class="detail-value" id="selectedBookAvailable">—</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Total Copies</div>
                    <div class="detail-value" id="selectedBookTotal">—</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value" id="selectedBookStatus">—</div>
                </div>
            </div>
            
            <!-- Book QR Code -->
            <div class="book-qr">
                <h4>Book QR Code</h4>
                <img id="selectedBookQR" src="" alt="Book QR Code">
                <div class="qr-text" id="selectedBookQRText"></div>
            </div>
            
            <!-- Borrow Button -->
            <form method="POST" id="borrowForm" style="margin-top: 20px;">
                <input type="hidden" name="action" value="process_borrow">
                <input type="hidden" name="book_id" id="borrowBookId" value="">
            </form>
        </div>
    </div>
    
    <!-- Success/Error Modal -->
    <div class="modal-overlay" id="transactionModal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-icon" id="modalIcon"></div>
                <div class="modal-title" id="modalTitle"></div>
                <div class="modal-message" id="modalMessage"></div>
            </div>
            <div class="modal-body" id="modalBodyContent"></div>
            <div class="modal-footer" id="modalFooter"></div>
        </div>
    </div>

<script>
let selectedBookId = null;
let allBooks = <?php echo json_encode($books); ?>;
let formSubmitting = false;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set up search form
    document.getElementById('searchForm').addEventListener('submit', function(e) {
        e.preventDefault();
        performSearch();
    });
    
    document.getElementById('searchInput').addEventListener('input', function() {
        performSearch();
    });
    
    // Set up borrow form
    document.getElementById('borrowForm').addEventListener('submit', function(e) {
        e.preventDefault();
        if (selectedBookId) {
            submitBorrow();
        }
    });
});

function performSearch() {
    const query = document.getElementById('searchInput').value.toLowerCase();
    const booksList = document.getElementById('booksList');
    
    if (!query) {
        // Show all books if search is empty
        displayBooks(allBooks);
    } else {
        // Filter books
        const filtered = allBooks.filter(book => 
            book.title.toLowerCase().includes(query) || 
            book.author.toLowerCase().includes(query)
        );
        displayBooks(filtered);
    }
}

function displayBooks(books) {
    const booksList = document.getElementById('booksList');
    const bookCount = document.getElementById('bookCount');
    
    // Remove duplicates by book_id
    const seen = new Set();
    const uniqueBooks = books.filter(book => {
        if (seen.has(book.book_id)) {
            return false;
        }
        seen.add(book.book_id);
        return true;
    });
    
    // Filter to only show books with QR codes
    const booksWithQR = uniqueBooks.filter(book => book.qr_code);
    
    if (booksWithQR.length === 0) {
        booksList.innerHTML = `
            <div class="empty-results">
                <div class="empty-results-icon">🔎</div>
                <p>No books found matching your search</p>
            </div>
        `;
    } else {
        let html = '';
        booksWithQR.forEach(book => {
            const isUnavailable = book.available_copies <= 0;
            html += `
                <div class="book-item ${isUnavailable ? 'unavailable' : ''}" 
                     onclick="selectBook(${book.book_id}, '${book.title.replace(/'/g, "\\'")}', ${book.available_copies})">
                    <div class="book-icon">📕</div>
                    <div class="book-info">
                        <div class="book-title">${escapeHtml(book.title)}</div>
                        <div class="book-author">${escapeHtml(book.author)}</div>
                    </div>
                    <div class="book-availability ${isUnavailable ? 'unavailable' : 'available'}">
                        ${isUnavailable ? 'Out of Stock' : 'Available'}
                    </div>
                </div>
            `;
        });
        booksList.innerHTML = html;
    }
    
    bookCount.textContent = booksWithQR.length;
}

function selectBook(bookId, title, availableCopies) {
    if (availableCopies <= 0) return; // Don't allow selecting unavailable books
    
    selectedBookId = bookId;
    
    // Find book in allBooks
    const book = allBooks.find(b => b.book_id === bookId);
    if (!book) return;
    
    // Update book details section
    document.getElementById('selectedBookTitle').textContent = escapeHtml(book.title);
    document.getElementById('selectedBookAuthor').textContent = escapeHtml(book.author);
    document.getElementById('selectedBookAvailable').textContent = book.available_copies;
    document.getElementById('selectedBookTotal').textContent = book.total_copies;
    document.getElementById('selectedBookStatus').textContent = book.available_copies > 0 ? '✓ Available' : 'Out of Stock';
    
    // Set QR code
    const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(book.qr_code)}`;
    document.getElementById('selectedBookQR').src = qrUrl;
    document.getElementById('selectedBookQRText').textContent = book.qr_code;
    
    // Set hidden form field
    document.getElementById('borrowBookId').value = bookId;
    
    // Show book details section
    document.getElementById('bookDetailsSection').style.display = 'block';
    
    // Scroll to book details
    document.getElementById('bookDetailsSection').scrollIntoView({ behavior: 'smooth', block: 'start' });
    
    // Highlight selected book in list
    document.querySelectorAll('.book-item').forEach(item => {
        item.classList.remove('active');
    });
    event.currentTarget.classList.add('active');
}

function submitBorrow() {
    if (formSubmitting || !selectedBookId) return;
    formSubmitting = true;
    
    const formData = new FormData(document.getElementById('borrowForm'));
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(html => {
        // Extract modal data from response
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const modalDataScript = doc.querySelector('script[type="application/json"][id="modal-data"]');
        
        if (modalDataScript) {
            const modalData = JSON.parse(modalDataScript.textContent);
            showModal(modalData.type, modalData.data);
        }
        
        formSubmitting = false;
    })
    .catch(error => {
        console.error('Error:', error);
        formSubmitting = false;
    });
}

function showModal(type, data) {
    const modal = document.getElementById('transactionModal');
    const icon = document.getElementById('modalIcon');
    const title = document.getElementById('modalTitle');
    const message = document.getElementById('modalMessage');
    const bodyContent = document.getElementById('modalBodyContent');
    const footer = document.getElementById('modalFooter');
    
    if (type === 'success') {
        icon.className = 'modal-icon success';
        icon.innerHTML = '✓';
    } else {
        icon.className = 'modal-icon error';
        icon.innerHTML = '✕';
    }
    
    title.textContent = data.title;
    message.textContent = data.message;
    
    if (type === 'success' && data.transaction_id) {
        bodyContent.innerHTML = `
            <div class="transaction-details">
                <div class="detail-row">
                    <span class="detail-label">Transaction ID</span>
                    <span class="detail-value">#${data.transaction_id}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Book Title</span>
                    <span class="detail-value">${escapeHtml(data.book_title)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Borrowed Date</span>
                    <span class="detail-value">${data.date_borrowed}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Due Date</span>
                    <span class="detail-value">${data.due_date}</span>
                </div>
            </div>
        `;
    } else {
        bodyContent.innerHTML = '';
    }
    
    if (type === 'success') {
        footer.innerHTML = `
            <button class="modal-btn modal-btn-primary" onclick="closeModalAndContinue()">✓ Continue Borrowing</button>
            <button class="modal-btn modal-btn-secondary" onclick="closeModal()">Back to Books</button>
        `;
    } else {
        footer.innerHTML = `
            <button class="modal-btn modal-btn-primary" onclick="closeModal()">Try Again</button>
        `;
    }
    
    modal.classList.add('show');
}

function closeModal() {
    const modal = document.getElementById('transactionModal');
    modal.classList.remove('show');
}

function closeModalAndContinue() {
    closeModal();
    selectedBookId = null;
    document.getElementById('bookDetailsSection').style.display = 'none';
    document.getElementById('searchInput').value = '';
    performSearch();
}

document.getElementById('transactionModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<!-- Modal Data Script (JSON) -->
<?php if ($modal_data): ?>
<script type="application/json" id="modal-data">
{
    "type": "<?php echo $modal_type; ?>",
    "data": <?php echo json_encode($modal_data); ?>
}
</script>
<script>
    window.addEventListener('load', function() {
        const modalScript = document.getElementById('modal-data');
        if (modalScript) {
            try {
                const modalData = JSON.parse(modalScript.textContent);
                showModal(modalData.type, modalData.data);
            } catch (e) {
                console.error('Failed to parse modal data:', e);
            }
        }
    });
</script>
<?php endif; ?>
</body>
</html>
