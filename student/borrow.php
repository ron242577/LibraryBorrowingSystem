<?php
/**
 * Student Book Borrowing Page - Jose Abad Santos High School
 * Requires a verified student portal session.
 */

require_once __DIR__ . '/../includes/student_session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/library_rules.php';
require_once __DIR__ . '/../includes/notification_helper.php';

$student = null;
$student_error = null;
$books = [];
$modal_data = null;
$modal_type = '';
$borrowed_books = [];
$reserved_book_ids = [];
$student_id = (int)$_SESSION['student_id'];
$student_qr = $_SESSION['student_qr'] ?? '';

$max_active_books = getLibraryRule($conn, 'max_active_books_per_student', 3);
expireStaleReservations($conn);

try {
    $student_stmt = $conn->prepare("\n        SELECT student_id, student_no, full_name, contact_number, qr_code, status, created_at\n        FROM students\n        WHERE student_id = ? AND status = 'active' AND COALESCE(is_archived,0) = 0\n        LIMIT 1\n    ");
    $student_stmt->bind_param('i', $student_id);
    $student_stmt->execute();
    $student = $student_stmt->get_result()->fetch_assoc();
    $student_stmt->close();

    if (!$student) {
        unset($_SESSION['student_id'], $_SESSION['student_no'], $_SESSION['student_name'], $_SESSION['student_qr']);
        header('Location: /LibraryBorrowingSystem/student/portal.php');
        exit();
    }

    $borrowed_stmt = $conn->prepare("SELECT book_id FROM transactions WHERE student_id = ? AND status = 'borrowed'");
    $borrowed_stmt->bind_param('i', $student_id);
    $borrowed_stmt->execute();
    $borrowed_result = $borrowed_stmt->get_result();
    while ($row = $borrowed_result->fetch_assoc()) {
        $borrowed_books[] = (int)$row['book_id'];
    }
    $borrowed_stmt->close();

    try {
        $reservation_stmt = $conn->prepare("
            SELECT book_id
            FROM book_reservations
            WHERE student_id = ? AND status IN ('pending','ready')
        ");
        $reservation_stmt->bind_param('i', $student_id);
        $reservation_stmt->execute();
        $reservation_result = $reservation_stmt->get_result();
        while ($row = $reservation_result->fetch_assoc()) {
            $reserved_book_ids[] = (int)$row['book_id'];
        }
        $reservation_stmt->close();
    } catch (Throwable $reservation_error) {
        $reserved_book_ids = [];
        logError('Reservation lookup error: ' . $reservation_error->getMessage());
    }
} catch (Exception $e) {
    $student_error = 'Unable to load your student account right now.';
    logError('Student borrow page error: ' . $e->getMessage());
}

// Fetch all available books with QR codes, removing duplicates
if ($student) {
    try {
        $books_stmt = $conn->prepare("
            SELECT 
                book_id,
                title,
                author,
                book_number,
                book_pages,
                publisher,
                edition,
                volumes,
                class,
                location_collection,
                library_building,
                shelf_number,
                library_section,
                book_condition,
                qr_code,
                book_status,
                total_copies,
                available_copies,
                borrowed_copies
            FROM books
            WHERE COALESCE(is_archived,0) = 0 AND COALESCE(is_archived,0) = 0
                AND book_status IN ('available', 'out_of_stock')
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
                    book_number,
                    book_pages,
                    publisher,
                    edition,
                    volumes,
                    class,
                    location_collection,
                    library_building,
                    shelf_number,
                    library_section,
                    qr_code,
                    book_status,
                    total_copies,
                    available_copies,
                    borrowed_copies
                FROM books
                WHERE (title LIKE ? OR author LIKE ?) 
                AND COALESCE(is_archived,0) = 0
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
                        'book_number' => $book['book_number'],
                        'book_pages' => $book['book_pages'],
                        'publisher' => $book['publisher'],
                        'edition' => $book['edition'],
                        'volumes' => $book['volumes'],
                        'class' => $book['class'],
                        'location_collection' => $book['location_collection'],
                        'library_building' => $book['library_building'],
                        'shelf_number' => $book['shelf_number'],
                        'library_section' => $book['library_section'],
                        'qr_code' => $book['qr_code'],
                        'book_status' => $book['book_status'],
                        'total_copies' => $book['total_copies'],
                        'available_copies' => $book['available_copies'],
                        'borrowed_copies' => $book['borrowed_copies']
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
                    book_number,
                    book_pages,
                    publisher,
                    edition,
                    volumes,
                    class,
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
    try { requireValidCsrf($_POST['csrf_token'] ?? ''); } catch (Throwable $e) { $modal_type = 'error'; $modal_data = ['title' => 'Security Check Failed', 'message' => $e->getMessage(), 'icon' => 'error']; }
    $action = $modal_type === 'error' ? '' : $_POST['action'];
    
    if ($action === 'create_reservation' && $student) {
        $book_id = (int)($_POST['book_id'] ?? 0);

        try {
            if ($book_id <= 0) throw new Exception('Please select a valid book.');

            $book_stmt = $conn->prepare("
                SELECT book_id, title, available_copies, is_archived
                FROM books
                WHERE book_id = ? LIMIT 1
            ");
            $book_stmt->bind_param('i', $book_id);
            $book_stmt->execute();
            $book = $book_stmt->get_result()->fetch_assoc();
            $book_stmt->close();

            if (!$book || (int)$book['is_archived'] === 1) {
                throw new Exception('This book is not available for reservation.');
            }
            if ((int)$book['available_copies'] > 0) {
                throw new Exception('A copy is available. You can borrow this book instead.');
            }

            $borrowedCheck = $conn->prepare("
                SELECT transaction_id FROM transactions
                WHERE student_id=? AND book_id=? AND status='borrowed'
                LIMIT 1
            ");
            $borrowedCheck->bind_param('ii', $student_id, $book_id);
            $borrowedCheck->execute();
            $alreadyBorrowed = $borrowedCheck->get_result()->num_rows > 0;
            $borrowedCheck->close();

            if ($alreadyBorrowed) {
                throw new Exception('You already borrowed this book. You cannot reserve a book you currently have.');
            }

            $check = $conn->prepare("
                SELECT reservation_id
                FROM book_reservations
                WHERE student_id = ? AND book_id = ? AND status IN ('pending','ready')
                LIMIT 1
            ");
            $check->bind_param('ii', $student_id, $book_id);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if ($exists) throw new Exception('You already have an active reservation for this book.');

            $insert = $conn->prepare("
                INSERT INTO book_reservations (student_id, book_id, status, reserved_at)
                VALUES (?, ?, 'pending', NOW())
            ");
            $insert->bind_param('ii', $student_id, $book_id);
            if (!$insert->execute()) throw new Exception('Unable to create the reservation.');
            $reservation_id = $conn->insert_id;
            $insert->close();

            if (function_exists('auditRecordChange')) {
                auditRecordChange(
                    $conn,
                    'book_reserved',
                    'student/borrow',
                    'Student created a book reservation.',
                    'success',
                    'reservation',
                    $reservation_id,
                    null,
                    ['student_id'=>$student_id,'book_id'=>$book_id,'book_title'=>$book['title']]
                );
            }

            $modal_type='success';
            $modal_data=[
                'title'=>'Reservation Created',
                'message'=>'Your reservation has been recorded. You will be notified when a copy becomes available.',
                'icon'=>'success'
            ];
        } catch (Throwable $e) {
            $modal_type='error';
            $modal_data=[
                'title'=>'Reservation Failed',
                'message'=>$e->getMessage(),
                'icon'=>'error'
            ];
        }
    }

    if ($action === 'process_borrow' && $student) {
        $book_id = (int)($_POST['book_id'] ?? 0);

        if ($book_id <= 0) {
            $modal_type = 'error';
            $modal_data = ['title'=>'Invalid Book','message'=>'Please select a valid book to borrow.','icon'=>'error'];
        } else {
            try {
                $book_stmt = $conn->prepare("
                    SELECT book_id, title, available_copies, borrowed_copies,
                           book_status, book_condition, is_archived
                    FROM books
                    WHERE book_id = ?
                    LIMIT 1
                ");
                $book_stmt->bind_param('i', $book_id);
                $book_stmt->execute();
                $book = $book_stmt->get_result()->fetch_assoc();
                $book_stmt->close();

                if (!$book) {
                    throw new Exception('The selected book was not found.');
                }
                if ((int)$book['is_archived'] === 1) {
                    throw new Exception('This book is archived and cannot be borrowed.');
                }
                if ((int)$book['available_copies'] <= 0) {
                    throw new Exception('There are no available copies of this book.');
                }
                if (in_array($book['book_condition'] ?? '', ['Damaged','Lost'], true)) {
                    throw new Exception('This book is marked as ' . $book['book_condition'] . ' and cannot be borrowed.');
                }

                $activeCount = countActiveBorrowings($conn, $student_id);
                if ($activeCount >= $max_active_books) {
                    throw new Exception(
                        'You have reached the maximum of ' . $max_active_books .
                        ' active borrowed book(s). Return a book before borrowing another.'
                    );
                }

                $duplicate_stmt = $conn->prepare("
                    SELECT transaction_id
                    FROM transactions
                    WHERE student_id=? AND book_id=? AND status='borrowed'
                    LIMIT 1
                ");
                $duplicate_stmt->bind_param('ii', $student_id, $book_id);
                $duplicate_stmt->execute();
                $alreadyBorrowed = $duplicate_stmt->get_result()->num_rows > 0;
                $duplicate_stmt->close();

                if ($alreadyBorrowed) {
                    throw new Exception('You already have an active borrowing record for this book.');
                }

                $readyReservation = getReadyReservationForBook($conn, $book_id);
                if ($readyReservation && (int)$readyReservation['student_id'] !== $student_id) {
                    throw new Exception('This copy is reserved for another student.');
                }

                $date_borrowed = date('Y-m-d H:i:s');
                $due_date = date('Y-m-d') . ' 23:59:59';
                $status = 'borrowed';

                $conn->begin_transaction();
                try {
                    $insert_stmt = $conn->prepare("
                        INSERT INTO transactions
                        (student_id, book_id, date_borrowed, due_date, status, borrow_condition)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $borrow_condition = $book['book_condition'] ?? 'Good';
                    $insert_stmt->bind_param(
                        'iissss',
                        $student_id,
                        $book_id,
                        $date_borrowed,
                        $due_date,
                        $status,
                        $borrow_condition
                    );
                    if (!$insert_stmt->execute()) {
                        throw new Exception('Failed to create the borrowing record.');
                    }

                    $transaction_id = $conn->insert_id;
                    $new_borrowed = (int)$book['borrowed_copies'] + 1;
                    $new_available = (int)$book['available_copies'] - 1;
                    $book_status = $new_available === 0 ? 'out_of_stock' : 'available';

                    $update_stmt = $conn->prepare("
                        UPDATE books
                        SET borrowed_copies=?, available_copies=?, book_status=?
                        WHERE book_id=?
                    ");
                    $update_stmt->bind_param('iisi', $new_borrowed, $new_available, $book_status, $book_id);

                    if (!$update_stmt->execute()) {
                        throw new Exception('Failed to update the book inventory.');
                    }

                    if ($readyReservation && (int)$readyReservation['student_id'] === $student_id) {
                        $fulfill_stmt = $conn->prepare("
                            UPDATE book_reservations
                            SET status='fulfilled', fulfilled_at=NOW()
                            WHERE reservation_id=? AND status='ready'
                        ");
                        $fulfill_stmt->bind_param('i', $readyReservation['reservation_id']);
                        $fulfill_stmt->execute();
                        $fulfill_stmt->close();
                    }

                    $conn->commit();

                    if (function_exists('createNotification')) {
                        createNotification(
                            $conn,
                            'student',
                            $student_id,
                            'Book Borrowing Confirmed',
                            'Your borrowing of "' . $book['title'] . '" was recorded successfully. Return it by ' .
                            date('F d, Y', strtotime($due_date)) . ' (same day).'
                        );
                    }

                    $modal_type = 'success';
                    $modal_data = [
                        'title'=>'Book Successfully Borrowed!',
                        'message'=>'Your borrowing has been confirmed.',
                        'icon'=>'success',
                        'transaction_id'=>$transaction_id,
                        'student_name'=>htmlspecialchars($student['full_name']),
                        'book_title'=>htmlspecialchars($book['title']),
                        'due_date'=>date('F d, Y', strtotime($due_date)),
                        'date_borrowed'=>date('F d, Y', strtotime($date_borrowed))
                    ];

                    $insert_stmt->close();
                    $update_stmt->close();
                } catch (Throwable $e) {
                    $conn->rollback();
                    throw $e;
                }
            } catch (Throwable $e) {
                $modal_type = 'error';
                $modal_data = [
                    'title'=>'Borrowing Failed',
                    'message'=>$e->getMessage(),
                    'icon'=>'error'
                ];
                logError('Student borrowing rule error: ' . $e->getMessage());
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
            background: #F3F7FC;
            color: #202A44;
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
            color: #202A44;
            margin-bottom: 8px;
        }
        
        .header p {
            color: #52618D;
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
            border-color: #141F52;
            box-shadow: 0 0 0 3px rgba(244, 249, 22, 0.35);
            background: #F7FAFE;
        }
        
        .search-form button {
            padding: 12px 28px;
            background: #141F52;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(20, 31, 82, 0.3);
        }
        
        .search-form button:hover {
            background: #52618D;
            transform: translateY(-2px);
            box-shadow: 0 5px 16px rgba(20, 31, 82, 0.4);
        }
        
        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
            align-items: start;
        }
        
        /* Student Info Column */
        .student-info-section {
            display: none;
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .student-info-section h2 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #202A44;
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
            background: #141F52;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .student-details h3 {
            font-size: 20px;
            color: #202A44;
            margin-bottom: 5px;
        }
        
        .student-details p {
            color: #52618D;
            font-size: 13px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .info-item {
            background: #F7F9FC;
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid #141F52;
        }
        
        .info-label {
            font-size: 11px;
            color: #52618D;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            font-weight: 600;
        }
        
        .info-value {
            font-size: 15px;
            color: #202A44;
            font-weight: 600;
        }
        
        .qr-section {
            background: #F3F7FC;
            border: 2px dashed #141F52;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 25px;
        }
        
        .qr-section h4 {
            font-size: 12px;
            color: #52618D;
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
            color: #141F52;
            font-family: 'Courier New', monospace;
            margin-top: 10px;
            font-weight: 600;
        }

        .qr-download-btn {
            display: inline-block;
            margin-top: 12px;
            padding: 9px 16px;
            background: #141F52;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
        }

        .qr-download-btn:hover { background: #52618D; }
        
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
            color: #202A44;
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
            background: #F7F9FC;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .book-item:hover, .book-item.active {
            background: rgba(20, 31, 82, 0.08);
            border-color: #52618D;
            transform: translateX(4px);
        }
        
        .book-item.unavailable {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .book-item.unavailable:hover {
            background: #F7F9FC;
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
            color: #202A44;
            font-size: 14px;
        }
        
        .book-author {
            font-size: 12px;
            color: #52618D;
        }
        
        .book-availability {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            flex-shrink: 0;
        }
        
        .book-availability.available {
            background: #EDF5DD;
            color: #344E15;
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
            color: #202A44;
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
            background: #F7F9FC;
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid #52618D;
        }
        
        .detail-label {
            font-size: 11px;
            color: #52618D;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            font-weight: 600;
        }
        
        .detail-value {
            font-size: 15px;
            color: #202A44;
            font-weight: 600;
        }
        
        .book-qr {
            background: #F3F7FC;
            border: 2px dashed #52618D;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .book-qr h4 {
            font-size: 12px;
            color: #52618D;
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
            background: #141F52;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(20, 31, 82, 0.3);
        }
        
        .reserve-button{display:block;width:100%;background:#52618D!important}
        .reserve-button:hover:not(:disabled){background:#202A44!important}
        .borrow-btn:hover:not(:disabled) {
            background: #52618D;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(20, 31, 82, 0.4);
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
            background: #F7F9FC;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 24px;
            border-left: 4px solid #52618D;
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
            background: #141F52;
            color: white;
            box-shadow: 0 4px 15px rgba(20, 31, 82, 0.3);
        }
        
        .modal-btn-primary:hover {
            background: #52618D;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(20, 31, 82, 0.4);
        }
        
        .modal-btn-secondary {
            background: #EDF3FA;
            color: #52618D;
            font-weight: 600;
        }
        
        .modal-btn-secondary:hover {
            background: #D2E2F6;
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
            border-bottom: 3px solid #F4F916;
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
            color: #141F52;
        }
        
        .header-brand:hover .header-brand-text {
            color: #52618D;
        }
        
        .student-menu {
            position: relative;
            display: flex;
            align-items: center;
        }

        .student-menu-toggle {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: #141F52;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            box-shadow: none;
        }

        .student-menu-toggle:hover {
            background: #0D153B;
            transform: none;
            box-shadow: none;
        }

        .student-menu-name {
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .student-menu-caret {
            font-size: 11px;
            line-height: 1;
        }

        .student-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            min-width: 190px;
            background: #ffffff;
            border: 1px solid #e2e6ea;
            border-radius: 8px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.14);
            padding: 8px 0;
            display: none;
            z-index: 1200;
        }

        .student-dropdown.show {
            display: block;
        }

        .student-dropdown a {
            display: block;
            padding: 12px 18px;
            color: #202A44;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
        }

        .student-dropdown a:hover,
        .student-dropdown a.active {
            background: #EDF3FA;
            color: #141F52;
            border-left: 3px solid #F4F916;
        }

        .student-dropdown .dropdown-divider {
            height: 1px;
            background: #D2E2F6;
            margin: 6px 0;
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
            
            .student-menu-toggle {
                padding: 8px 10px;
                font-size: 12px;
            }

            .student-menu-name {
                max-width: 130px;
            }
            
            body {
                padding-top: 60px;
            }
        }
    <style id="student-book-filter-css">
        .search-form select {
            min-width: 190px;
            padding: 12px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            color: #202A44;
        }

        .search-form select:focus {
            outline: none;
            border-color: #141F52;
            box-shadow: 0 0 0 3px rgba(244, 249, 22, 0.35);
        }

        @media (max-width: 700px) {
            .search-form select,
            .search-form input,
            .search-form button {
                width: 100%;
                min-width: 0;
            }
        }
.auto-search-submit { display:none !important; }
    
        .student-notification-wrap{position:relative;display:flex;align-items:center;margin-right:8px}
        .student-notification-bell{position:relative;width:40px;height:40px;border:1px solid #D2E2F6;border-radius:9px;background:#fff;color:#141F52;cursor:pointer}
        .student-notification-count{position:absolute;top:-4px;right:-4px;min-width:17px;height:17px;padding:0 4px;border-radius:999px;background:#F4F916;color:#141F52;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center}
        .student-notification-panel{position:absolute;right:0;top:48px;width:330px;max-width:calc(100vw - 30px);background:#fff;border:1px solid #D2E2F6;border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.18);display:none;z-index:1300;overflow:hidden;color:#202A44}
        .student-notification-panel.show{display:block}
        .student-notification-header{display:flex;justify-content:space-between;align-items:center;padding:12px 13px;border-bottom:1px solid #E7EEF7}
        .student-notification-header button{border:0;background:none;color:#52618D;font-size:11px;font-weight:700;cursor:pointer}
        .student-notification-item{padding:12px 13px;border-bottom:1px solid #EEF2F7}
        .student-notification-item.unread{background:#F3F7FC}
        .student-notification-title{font-size:12px;font-weight:800}
        .student-notification-message{font-size:12px;color:#52618D;line-height:1.4;margin-top:3px}
        .student-notification-time{font-size:10px;color:#8793A7;margin-top:5px}
        .student-notification-empty{padding:22px;text-align:center;color:#8793A7;font-size:12px}
        @media(max-width:700px){.student-notification-wrap{margin-right:4px}.student-notification-panel{right:-60px}}


        .header-brand-text{display:flex;flex-direction:column;line-height:1.1;}
        .header-brand-subtitle{display:block;margin-top:4px;font-size:11px;font-weight:600;color:#52618D;}
        .page-header{gap:10px;}
        @media(max-width:700px){.header-brand-text{font-size:16px;}.header-brand-subtitle{font-size:10px;}}
        
        .student-header-actions{display:flex;align-items:center;gap:4px;flex-shrink:0}
        .student-notification-wrap{margin:0!important}
        @media(max-width:700px){.student-header-actions{gap:4px}.student-notification-bell{width:38px;height:38px}.student-menu-name{max-width:120px}}


        .page-header{height:78px;padding:0 40px;box-sizing:border-box;position:sticky;top:0;z-index:900;background:#fff;}
        .student-header-actions{display:flex;align-items:center;gap:6px;flex-shrink:0;}
        .student-notification-wrap{margin:0!important;}
        @media(max-width:700px){.page-header{height:60px;padding:0 16px;}.student-header-actions{gap:4px;}}
        </style>
    <?php require_once __DIR__ . '/../includes/responsive.php'; ?>
</head>
<body class="student-app student-borrow-page">
    <?php require_once __DIR__ . '/../includes/ui_feedback.php'; ?>
    <!-- Header -->
    <header class="page-header">
        <a href="/LibraryBorrowingSystem/student/borrow.php" class="header-brand">
            <img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo">
            <span class="header-brand-text">Jose Abad Santos High School<span class="header-brand-subtitle">Library Management System</span></span>
        </a>
        <div class="student-header-actions">
        <div class="student-notification-wrap">
    <button type="button" class="student-notification-bell" id="studentNotificationBell" aria-label="Notifications">
        <span>🔔</span><span class="student-notification-count" id="studentNotificationCount" style="display:none;">0</span>
    </button>
    <div class="student-notification-panel" id="studentNotificationPanel">
        <div class="student-notification-header"><strong>Notifications</strong><button type="button" id="studentMarkAllNotifications">Mark all read</button></div>
        <div id="studentNotificationList"><div class="student-notification-empty">Loading notifications...</div></div>
    </div>
</div>

<div class="student-menu">
            <button type="button" class="student-menu-toggle" id="studentMenuToggle" aria-haspopup="true" aria-expanded="false">
                <span class="student-menu-name"><?php echo $student ? htmlspecialchars($student['full_name']) : 'Student'; ?></span>
                <span class="student-menu-caret">▼</span>
            </button>
            <div class="student-dropdown" id="studentDropdown">
                <?php if ($student_qr): ?>
                    <a href="/LibraryBorrowingSystem/student/profile.php">Profile</a>
                <?php endif; ?>
                <a href="/LibraryBorrowingSystem/student/borrow.php" class="active">Search Books</a>
                <div class="dropdown-divider"></div>
                <a href="/LibraryBorrowingSystem/student/portal.php?logout=1">Logout</a>
            </div>
        </div>
    </div>
    </header>
    
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>Search Books</h1>
            <p>Search by title, author, or book number and use the filters to narrow results.</p>
        </div>
        
        <!-- Search Section -->
        <div class="search-section">
            <form class="search-form" id="searchForm">
                <input type="text"
                       id="searchInput"
                       placeholder="Search title, author, or book number..."
                       autofocus>
                <select id="classFilter" aria-label="Filter by class">
                    <option value="">All Classes</option>
                </select>
                <select id="sectionFilter" aria-label="Filter by library section">
                    <option value="">All Sections</option>
                </select>
                <select id="availabilityFilter" aria-label="Filter by availability">
                    <option value="">All Availability</option>
                    <option value="available">Available</option>
                    <option value="out_of_stock">Out of Stock</option>
                </select>
                <button type="submit" class="auto-search-submit">Search Books</button>
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
                        <div class="info-item">
                            <div class="info-label">Active Books</div>
                            <div class="info-value"><?php echo countActiveBorrowings($conn, $student_id); ?> / <?php echo (int)$max_active_books; ?></div>
                        </div>
                    </div>
                    
                    <!-- QR Code -->
                    <div class="qr-section">
                        <h4>QR Code</h4>
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($student['qr_code']); ?>" 
                             alt="Student QR Code">
                        <div class="qr-text"><?php echo htmlspecialchars($student['qr_code']); ?></div>
                        <a class="qr-download-btn" href="/LibraryBorrowingSystem/student/download_qr.php">Download Student QR</a>
                    </div>
                <?php else: ?>
                    <div class="no-selection">
                        <p>Unable to load student information</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Books Results Column -->
            <div class="results-section">
                <h2>Available Books (<span id="bookCount"><?php echo count($books); ?></span>)</h2>
                <div style="margin:-10px 0 14px;color:#52618D;font-size:12px;">
                    Maximum active books: <strong><?php echo (int)$max_active_books; ?></strong>. Reservations do not count toward this limit.
                </div>
                
                <div class="results-list" id="booksList">
                    <?php if (empty($books) && $student): ?>
                        <div class="empty-results">
                            <p>No books available at the moment</p>
                        </div>
                    <?php elseif (!$student): ?>
                        <div class="empty-results">
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
                    <div class="detail-label">Book Number</div>
                    <div class="detail-value" id="selectedBookNumber">—</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Book Pages</div>
                    <div class="detail-value" id="selectedBookPages">—</div>
                </div>
                <div class="detail-item" id="locationDetail">
                    <div class="detail-label">Library Location</div>
                    <div class="detail-value" id="selectedBookLocation">—</div>
                </div>
                <div class="detail-item" id="buildingDetail" style="display:none;">
                    <div class="detail-label">Building / Room</div>
                    <div class="detail-value" id="selectedBookBuilding">—</div>
                </div>
                <div class="detail-item" id="shelfDetail" style="display:none;">
                    <div class="detail-label">Shelf Number</div>
                    <div class="detail-value" id="selectedBookShelf">—</div>
                </div>
                <div class="detail-item" id="librarySectionDetail" style="display:none;">
                    <div class="detail-label">Library Section</div>
                    <div class="detail-value" id="selectedBookLibrarySection">—</div>
                </div>
                <div class="detail-item" id="publisherDetail" style="display:none;">
                    <div class="detail-label">Publisher</div>
                    <div class="detail-value" id="selectedBookPublisher">—</div>
                </div>
                <div class="detail-item" id="editionDetail" style="display:none;">
                    <div class="detail-label">Edition</div>
                    <div class="detail-value" id="selectedBookEdition">—</div>
                </div>
                <div class="detail-item" id="volumesDetail" style="display:none;">
                    <div class="detail-label">Volumes</div>
                    <div class="detail-value" id="selectedBookVolumes">—</div>
                </div>
                <div class="detail-item" id="classDetail" style="display:none;">
                    <div class="detail-label">Class</div>
                    <div class="detail-value" id="selectedBookClass">—</div>
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
                <a id="selectedBookQRDownload" class="qr-download-btn" href="#">Download Book QR</a>
            </div>
            
            <!-- Reservation Button -->
            <div id="reservationActions" style="display:none;margin-top:20px;">
                <div class="modal-note" style="margin-bottom:12px;">
                    This book is currently unavailable. Reserve it to be notified when a copy is returned.
                </div>
                <form method="POST" id="reservationForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="create_reservation">
                    <input type="hidden" name="book_id" id="reserveBookId" value="">
                    <button type="submit" class="borrow-btn reserve-button" id="reserveBookButton">Reserve This Book</button>
                </form>
            </div>

            <!-- Borrow Button -->
            <form method="POST" id="borrowForm" style="margin-top: 20px;">
                <?php echo csrfField(); ?>
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
let reservedBookIds = <?php echo json_encode($reserved_book_ids); ?>;
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

    populateCatalogFilters();
    
    // Set up borrow form
    document.getElementById('borrowForm').addEventListener('submit', function(e) {
        e.preventDefault();
        if (selectedBookId) {
            submitBorrow();
        }
    });
});

function performSearch() {
    const query = document.getElementById('searchInput').value.trim().toLowerCase();
    const classValue = document.getElementById('classFilter')?.value || '';
    const sectionValue = document.getElementById('sectionFilter')?.value || '';
    const availabilityValue = document.getElementById('availabilityFilter')?.value || '';
    const conditionValue = document.getElementById('conditionFilter')?.value || '';

    const filtered = allBooks.filter(book => {
        const searchable = [
            book.title,
            book.author,
            book.book_number
        ].map(value => String(value || '').toLowerCase()).join(' ');

        const bookClass = String(book.class || '');
        const section = String(book.library_section || book.location_collection || '');
        const condition = String(book.book_condition || '');
        const availability = Number(book.available_copies || 0) > 0 ? 'available' : 'out_of_stock';

        return (!query || searchable.includes(query))
            && (!classValue || bookClass === classValue)
            && (!sectionValue || section === sectionValue)
            && (!availabilityValue || availability === availabilityValue)
            && (!conditionValue || condition === conditionValue);
    });

    displayBooks(filtered);
}

function populateCatalogFilters() {
    const classFilter = document.getElementById('classFilter');
    const sectionFilter = document.getElementById('sectionFilter');
    const conditionFilter = document.getElementById('conditionFilter');

    const uniqueValues = (key, fallbackKey = '') => {
        const set = new Set();
        allBooks.forEach(book => {
            const value = String(book[key] || book[fallbackKey] || '').trim();
            if (value) set.add(value);
        });
        return Array.from(set).sort((a,b) => a.localeCompare(b));
    };

    if (classFilter) {
        uniqueValues('class').forEach(value => {
            classFilter.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`);
        });
        classFilter.addEventListener('change', performSearch);
    }

    if (sectionFilter) {
        uniqueValues('library_section', 'location_collection').forEach(value => {
            sectionFilter.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`);
        });
        sectionFilter.addEventListener('change', performSearch);
    }

    if (conditionFilter) {
        uniqueValues('book_condition').forEach(value => {
            conditionFilter.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`);
        });
        conditionFilter.addEventListener('change', performSearch);
    }

    const availabilityFilter = document.getElementById('availabilityFilter');
    if (availabilityFilter) {
        availabilityFilter.addEventListener('change', performSearch);
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

function setOptionalBookDetail(containerId, valueId, value) {
    const container = document.getElementById(containerId);
    const valueElement = document.getElementById(valueId);
    const hasValue = value !== null && value !== undefined && String(value).trim() !== '';
    container.style.display = hasValue ? '' : 'none';
    valueElement.textContent = hasValue ? String(value) : '—';
}

function selectBook(bookId, title, availableCopies) {
    
    selectedBookId = bookId;
    
    // Find book in allBooks
    const book = allBooks.find(b => b.book_id === bookId);
    if (!book) return;
    
    // Update book details section
    document.getElementById('selectedBookTitle').textContent = book.title;
    document.getElementById('selectedBookAuthor').textContent = book.author;
    document.getElementById('selectedBookNumber').textContent = book.book_number || '—';
    document.getElementById('selectedBookPages').textContent = book.book_pages || '—';

    setOptionalBookDetail('publisherDetail', 'selectedBookPublisher', book.publisher);
    setOptionalBookDetail('editionDetail', 'selectedBookEdition', book.edition);
    setOptionalBookDetail('volumesDetail', 'selectedBookVolumes', book.volumes);
    setOptionalBookDetail('classDetail', 'selectedBookClass', book.class);

    document.getElementById('selectedBookAvailable').textContent = book.available_copies;
    document.getElementById('selectedBookTotal').textContent = book.total_copies;

    const unavailable = Number(book.available_copies || 0) <= 0;
    document.getElementById('selectedBookStatus').textContent = unavailable ? 'Out of Stock' : '✓ Available';

    const reservationActions = document.getElementById('reservationActions');
    const reserveBookId = document.getElementById('reserveBookId');
    const reserveButton = document.getElementById('reserveBookButton');
    const borrowForm = document.getElementById('borrowForm');

    if (reservationActions && reserveBookId && reserveButton && borrowForm) {
        reserveBookId.value = book.book_id;
        const alreadyReserved = reservedBookIds.map(Number).includes(Number(book.book_id));

        if (unavailable) {
            reservationActions.style.display = 'block';
            borrowForm.style.display = 'none';
            reserveButton.disabled = alreadyReserved;
            reserveButton.textContent = alreadyReserved ? 'Already Reserved' : 'Reserve This Book';
        } else {
            reservationActions.style.display = 'none';
            borrowForm.style.display = 'block';
        }
    }
    
    // Set QR code
    const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(book.qr_code)}`;
    document.getElementById('selectedBookQR').src = qrUrl;
    document.getElementById('selectedBookQRText').textContent = book.qr_code;
    document.getElementById('selectedBookQRDownload').href = '/LibraryBorrowingSystem/student/download_book_qr.php?book_id=' + encodeURIComponent(book.book_id);
    
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
    const toastType = type === 'success' ? 'success' : 'error';
    const title = data && data.title ? data.title : (type === 'success' ? 'Success' : 'Error');
    let message = data && data.message ? data.message : '';

    if (type === 'success' && data && data.book_title) {
        message += (message ? ' ' : '') + 'Book: ' + data.book_title + '.';
        if (data.due_date) {
            message += ' Return by: ' + data.due_date + '.';
        }
    }

    showToast(message || title, toastType, 4400, title);

    if (type === 'success') {
        selectedBookId = null;
        const detailsSection = document.getElementById('bookDetailsSection');
        const searchInput = document.getElementById('searchInput');
        if (detailsSection) detailsSection.style.display = 'none';
        if (searchInput) searchInput.value = '';
        performSearch();
    }
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

    <script>
        const studentMenuToggle = document.getElementById('studentMenuToggle');
        const studentDropdown = document.getElementById('studentDropdown');

        if (studentMenuToggle && studentDropdown) {
            studentMenuToggle.addEventListener('click', function (event) {
                event.stopPropagation();
                const isOpen = studentDropdown.classList.toggle('show');
                studentMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            document.addEventListener('click', function () {
                studentDropdown.classList.remove('show');
                studentMenuToggle.setAttribute('aria-expanded', 'false');
            });
        }
    </script>

<script>
(function(){
  const bell=document.getElementById('studentNotificationBell');
  const panel=document.getElementById('studentNotificationPanel');
  const count=document.getElementById('studentNotificationCount');
  const list=document.getElementById('studentNotificationList');
  const markAll=document.getElementById('studentMarkAllNotifications');
  if(!bell||!panel||!list)return;
  function esc(v){const d=document.createElement('div');d.textContent=v??'';return d.innerHTML;}
  function relativeTime(v){const t=new Date(v.replace(' ','T')).getTime(),m=Math.floor(Math.max(0,Date.now()-t)/60000);if(m<1)return'Just now';if(m<60)return m+' min ago';const h=Math.floor(m/60);if(h<24)return h+' hr ago';return Math.floor(h/24)+' day(s) ago';}
  function load(){fetch('/LibraryBorrowingSystem/notifications.php?action=list',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{if(!d.ok)return;const u=Number(d.unread||0);count.textContent=u>99?'99+':u;count.style.display=u?'flex':'none';if(!d.notifications.length){list.innerHTML='<div class="student-notification-empty">No notifications yet.</div>';return;}list.innerHTML=d.notifications.map(n=>`<div class="student-notification-item ${Number(n.is_read)===0?'unread':''}" data-id="${Number(n.notification_id)}"><div class="student-notification-title">${esc(n.title)}</div><div class="student-notification-message">${esc(n.message)}</div><div class="student-notification-time">${relativeTime(n.created_at)}</div></div>`).join('');list.querySelectorAll('.student-notification-item').forEach(el=>el.onclick=function(){fetch('/LibraryBorrowingSystem/notifications.php?action=read',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'notification_id='+encodeURIComponent(this.dataset.id)}).then(load);});}).catch(()=>{});}
  bell.addEventListener('click',e=>{e.stopPropagation();panel.classList.toggle('show');load();});
  panel.addEventListener('click',e=>e.stopPropagation());document.addEventListener('click',()=>panel.classList.remove('show'));
  markAll.addEventListener('click',()=>fetch('/LibraryBorrowingSystem/notifications.php?action=read_all',{method:'POST',credentials:'same-origin'}).then(load));
  load();setInterval(load,30000);
})();
</script>

</body>
</html>
