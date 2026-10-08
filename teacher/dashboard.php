<?php
/**
 * Teacher Dashboard - Jose Abad Santos High School
 * Landing page after login: library stats + book search. Book details open in a modal.
 * Requires a verified teacher portal session.
 */

require_once __DIR__ . '/../includes/teacher_session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/book_copies.php'; bcEnsureSchema($conn);
require_once __DIR__ . '/../includes/library_rules.php';
require_once __DIR__ . '/../includes/notification_helper.php';

$teacher = null;
$teacher_error = null;
$books = [];
$modal_data = null;
$modal_type = '';
$borrowed_books = [];
$reserved_book_ids = [];
$teacher_id = (int)$_SESSION['teacher_id'];
$teacher_qr = $_SESSION['teacher_qr'] ?? '';

$max_active_books = getLibraryRule($conn, 'max_active_books_per_teacher', 3);
expireStaleReservations($conn);

try {
    $teacher_stmt = $conn->prepare("\n        SELECT teacher_id, teacher_no, full_name, contact_number, qr_code, status, created_at\n        FROM teachers\n        WHERE teacher_id = ? AND status = 'active' AND COALESCE(is_archived,0) = 0\n        LIMIT 1\n    ");
    $teacher_stmt->bind_param('i', $teacher_id);
    $teacher_stmt->execute();
    $teacher = $teacher_stmt->get_result()->fetch_assoc();
    $teacher_stmt->close();

    if (!$teacher) {
        unset($_SESSION['teacher_id'], $_SESSION['teacher_no'], $_SESSION['teacher_name'], $_SESSION['teacher_qr']);
        header('Location: /LibraryBorrowingSystem/teacher/portal.php');
        exit();
    }

    $borrowed_stmt = $conn->prepare("SELECT book_id FROM transactions WHERE teacher_id = ? AND status = 'borrowed'");
    $borrowed_stmt->bind_param('i', $teacher_id);
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
            WHERE teacher_id = ? AND status IN ('pending','ready')
        ");
        $reservation_stmt->bind_param('i', $teacher_id);
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
    $teacher_error = 'Unable to load your teacher account right now.';
    logError('Teacher borrow page error: ' . $e->getMessage());
}

// Fetch all available books with QR codes, removing duplicates
if ($teacher) {
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
            WHERE COALESCE(is_archived,0) = 0
            AND qr_code IS NOT NULL
            AND qr_code != ''
            GROUP BY book_id
            ORDER BY title ASC
        ");
        $books_stmt->execute();
        $books_result = $books_stmt->get_result();
        $copyMap = bcListCopiesByBooks($conn);
        while ($row = $books_result->fetch_assoc()) {
            $row['copies'] = $copyMap[(int)$row['book_id']] ?? [];
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
                    book_condition,
                    qr_code,
                    book_status,
                    total_copies,
                    available_copies,
                    borrowed_copies
                FROM books
                WHERE (title LIKE ? OR author LIKE ?) 
                AND COALESCE(is_archived,0) = 0
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
            $copyMap = bcListCopiesByBooks($conn);
            while ($row = $search_result->fetch_assoc()) {
                $row['copies'] = $copyMap[(int)$row['book_id']] ?? [];
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
                        'book_condition' => $book['book_condition'],
                        'qr_code' => $book['qr_code'],
                        'book_status' => $book['book_status'],
                        'total_copies' => $book['total_copies'],
                        'available_copies' => $book['available_copies'],
                        'borrowed_copies' => $book['borrowed_copies'],
                        'copies' => $book['copies'] ?? []
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
                $result['copies'] = bcListCopies($conn, $book_id);
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
    
    if ($action === 'create_reservation' && $teacher) {
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
                WHERE teacher_id=? AND book_id=? AND status='borrowed'
                LIMIT 1
            ");
            $borrowedCheck->bind_param('ii', $teacher_id, $book_id);
            $borrowedCheck->execute();
            $alreadyBorrowed = $borrowedCheck->get_result()->num_rows > 0;
            $borrowedCheck->close();

            if ($alreadyBorrowed) {
                throw new Exception('You already borrowed this book. You cannot reserve a book you currently have.');
            }

            $check = $conn->prepare("
                SELECT reservation_id
                FROM book_reservations
                WHERE teacher_id = ? AND book_id = ? AND status IN ('pending','ready')
                LIMIT 1
            ");
            $check->bind_param('ii', $teacher_id, $book_id);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if ($exists) throw new Exception('You already have an active reservation for this book.');

            $insert = $conn->prepare("
                INSERT INTO book_reservations (teacher_id, book_id, status, reserved_at)
                VALUES (?, ?, 'pending', NOW())
            ");
            $insert->bind_param('ii', $teacher_id, $book_id);
            if (!$insert->execute()) throw new Exception('Unable to create the reservation.');
            $reservation_id = $conn->insert_id;
            $insert->close();

            if (function_exists('auditRecordChange')) {
                auditRecordChange(
                    $conn,
                    'book_reserved',
                    'teacher/dashboard',
                    'Teacher created a book reservation.',
                    'success',
                    'reservation',
                    $reservation_id,
                    null,
                    ['teacher_id'=>$teacher_id,'book_id'=>$book_id,'book_title'=>$book['title']]
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

    if ($action === 'process_borrow' && $teacher) {
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

                $activeCount = countActiveBorrowings($conn, $teacher_id);
                if ($activeCount >= $max_active_books) {
                    throw new Exception(
                        'You have reached the maximum of ' . $max_active_books .
                        ' active borrowed book(s). Return a book before borrowing another.'
                    );
                }

                $duplicate_stmt = $conn->prepare("
                    SELECT transaction_id
                    FROM transactions
                    WHERE teacher_id=? AND book_id=? AND status='borrowed'
                    LIMIT 1
                ");
                $duplicate_stmt->bind_param('ii', $teacher_id, $book_id);
                $duplicate_stmt->execute();
                $alreadyBorrowed = $duplicate_stmt->get_result()->num_rows > 0;
                $duplicate_stmt->close();

                if ($alreadyBorrowed) {
                    throw new Exception('You already have an active borrowing record for this book.');
                }

                $readyReservation = getReadyReservationForBook($conn, $book_id);
                if ($readyReservation && (int)$readyReservation['teacher_id'] !== $teacher_id) {
                    throw new Exception('This copy is reserved for another teacher.');
                }

                $date_borrowed = date('Y-m-d H:i:s');
                $due_date = date('Y-m-d') . ' 23:59:59';
                $status = 'borrowed';

                $conn->begin_transaction();
                try {
                    $insert_stmt = $conn->prepare("
                        INSERT INTO transactions
                        (teacher_id, book_id, date_borrowed, due_date, status, borrow_condition)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $borrow_condition = match ($book['book_condition'] ?? '') {
                        'New' => 'Excellent',
                        'Old' => 'Good',
                        'Excellent', 'Good', 'Fair', 'Damaged', 'Lost' => $book['book_condition'],
                        default => 'Good'
                    };
                    $insert_stmt->bind_param(
                        'iissss',
                        $teacher_id,
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
                    $assignedCopy = bcClaimAvailableCopy($conn, $book_id, $transaction_id);
                    if (!$assignedCopy) {
                        throw new Exception('No copy of this book is available right now.');
                    }

                    if ($readyReservation && (int)$readyReservation['teacher_id'] === $teacher_id) {
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
                            'teacher',
                            $teacher_id,
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
                        'teacher_name'=>htmlspecialchars($teacher['full_name']),
                        'book_title'=>htmlspecialchars($book['title']),
                        'due_date'=>date('F d, Y', strtotime($due_date)),
                        'date_borrowed'=>date('F d, Y', strtotime($date_borrowed)),
                        'copy_book_number'=>$assignedCopy['book_number'] ?? '',
                        'copy_qr_code'=>$assignedCopy['qr_code'] ?? ''
                    ];

                    $insert_stmt->close();
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
                logError('Teacher borrowing rule error: ' . $e->getMessage());
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
    <title>Dashboard - Library Borrowing System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter','Segoe UI',system-ui,-apple-system,Roboto,sans-serif;
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
        
        /* Teacher Info Column */
        .teacher-info-section {
            display: none;
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .teacher-info-section h2 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #202A44;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .teacher-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .teacher-avatar {
            font-size: 48px;
            width: 70px;
            height: 70px;
            background: #141F52;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .teacher-details h3 {
            font-size: 20px;
            color: #202A44;
            margin-bottom: 5px;
        }
        
        .teacher-details p {
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
        
        .teacher-menu {
            position: relative;
            display: flex;
            align-items: center;
        }

        .teacher-menu-toggle {
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

        .teacher-menu-toggle:hover {
            background: #0D153B;
            transform: none;
            box-shadow: none;
        }

        .teacher-menu-name {
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .teacher-menu-caret {
            font-size: 11px;
            line-height: 1;
        }

        .teacher-dropdown {
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

        .teacher-dropdown.show {
            display: block;
        }

        .teacher-dropdown a {
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

        .teacher-dropdown a:hover,
        .teacher-dropdown a.active {
            background: #EDF3FA;
            color: #141F52;
            border-left: 3px solid #F4F916;
        }

        .teacher-dropdown .dropdown-divider {
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
            
            .teacher-menu-toggle {
                padding: 8px 10px;
                font-size: 12px;
            }

            .teacher-menu-name {
                max-width: 130px;
            }
            
            body {
                padding-top: 60px;
            }
        }
    <style id="teacher-book-filter-css">
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
    
        .notification-icon{display:inline-flex;align-items:center;justify-content:center}
        .book-icon-svg{display:inline-flex;align-items:center;justify-content:center;color:currentColor}
        .search-icon-svg{display:inline-flex;align-items:center;justify-content:center}
        .teacher-notification-wrap{position:relative;display:flex;align-items:center;margin-right:8px}
        .teacher-notification-bell{position:relative;width:40px;height:40px;border:1px solid #D2E2F6;border-radius:9px;background:#fff;color:#141F52;cursor:pointer}
        .teacher-notification-count{position:absolute;top:-4px;right:-4px;min-width:17px;height:17px;padding:0 4px;border-radius:999px;background:#F4F916;color:#141F52;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center}
        .teacher-notification-panel{position:absolute;right:0;top:48px;width:330px;max-width:calc(100vw - 30px);background:#fff;border:1px solid #D2E2F6;border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.18);display:none;z-index:1300;overflow:hidden;color:#202A44}
        .teacher-notification-panel.show{display:block}
        .teacher-notification-header{display:flex;justify-content:space-between;align-items:center;padding:12px 13px;border-bottom:1px solid #E7EEF7}
        .teacher-notification-header button{border:0;background:none;color:#52618D;font-size:11px;font-weight:700;cursor:pointer}
        .teacher-notification-item{padding:12px 13px;border-bottom:1px solid #EEF2F7}
        .teacher-notification-item.unread{background:#F3F7FC}
        .teacher-notification-title{font-size:12px;font-weight:800}
        .teacher-notification-message{font-size:12px;color:#52618D;line-height:1.4;margin-top:3px}
        .teacher-notification-time{font-size:10px;color:#8793A7;margin-top:5px}
        .teacher-notification-empty{padding:22px;text-align:center;color:#8793A7;font-size:12px}
        @media(max-width:700px){.teacher-notification-wrap{margin-right:4px}.teacher-notification-panel{right:-60px}}


        .header-brand-text{display:flex;flex-direction:column;line-height:1.1;}
        .header-brand-subtitle{display:block;margin-top:4px;font-size:11px;font-weight:600;color:#52618D;}
        .page-header{gap:10px;}
        @media(max-width:700px){.header-brand-text{font-size:16px;}.header-brand-subtitle{font-size:10px;}}
        
        .teacher-header-actions{display:flex;align-items:center;gap:4px;flex-shrink:0}
        .teacher-notification-wrap{margin:0!important}
        @media(max-width:700px){.teacher-header-actions{gap:4px}.teacher-notification-bell{width:38px;height:38px}.teacher-menu-name{max-width:120px}}


        .page-header{height:78px;padding:0 40px;box-sizing:border-box;position:sticky;top:0;z-index:900;background:#fff;}
        .teacher-header-actions{display:flex;align-items:center;gap:6px;flex-shrink:0;}
        .teacher-notification-wrap{margin:0!important;}
        @media(max-width:700px){.page-header{height:60px;padding:0 16px;}.teacher-header-actions{gap:4px;}}
        .theme-switch{display:block;width:calc(100% - 36px);margin:8px 18px;padding:9px 12px;border:1px solid #D2E2F6;border-radius:8px;background:#fff;color:#202A44;cursor:pointer;font-weight:700;text-align:left}
        body.dark{background:#0d132d;color:#f4f7ff}body.dark .page-header,body.dark .search-section,body.dark .teacher-info-section,body.dark .results-section,body.dark .book-details,body.dark .modal-content{background:#18213f;color:#f4f7ff}body.dark .header h1,body.dark .results-section h2,body.dark .teacher-info-section h2,body.dark .book-details h3,body.dark .book-title,body.dark .detail-value{color:#f4f7ff}body.dark .header p,body.dark .book-author,body.dark .info-label,body.dark .detail-label{color:#b7c5e2}body.dark .book-item,body.dark .info-item,body.dark .detail-item{background:#222d4d;color:#f4f7ff;border-color:#3c4b72}
        </style>
    <?php require_once __DIR__ . '/../includes/responsive.php'; ?>
    <?php require_once __DIR__ . '/../includes/portal_ui.php'; ?>

    <style id="mobile-portal-book-fix">
        @media (max-width: 600px){
            body.teacher-dashboard-page{padding-bottom:24px;}
            body.teacher-dashboard-page .page-header{position:sticky;top:0;width:100%;z-index:900;}
            body.teacher-dashboard-page .container{width:100%;max-width:100%;margin:18px auto 28px;padding:0 12px;}
            body.teacher-dashboard-page .search-section{padding:14px;margin-bottom:16px;border-radius:14px;}
            body.teacher-dashboard-page .search-form{display:grid;grid-template-columns:1fr;gap:8px;}
            body.teacher-dashboard-page .search-form input,
            body.teacher-dashboard-page .search-form select{width:100%;min-width:0;padding:11px 12px;font-size:13px;}
            body.teacher-dashboard-page .results-section{padding:14px;border-radius:14px;}
            body.teacher-dashboard-page .results-section h2{font-size:17px;margin-bottom:12px;padding-bottom:10px;}
            body.teacher-dashboard-page .results-section > div[style]{margin:-4px 0 10px!important;font-size:11px!important;line-height:1.4;}
            body.teacher-dashboard-page .results-list{gap:7px;max-height:none;overflow:visible;}
            body.teacher-dashboard-page .book-item{display:grid;grid-template-columns:38px minmax(0,1fr) auto;align-items:center;gap:9px;width:100%;min-width:0;padding:9px 9px;border-width:1px;border-radius:11px;}
            body.teacher-dashboard-page .book-item:hover,
            body.teacher-dashboard-page .book-item.active{transform:none;}
            body.teacher-dashboard-page .book-icon{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;font-size:18px;background:#EEF3FB;color:#141F52;}
            body.teacher-dashboard-page .book-info{min-width:0;}
            body.teacher-dashboard-page .book-title{font-size:13px;line-height:1.25;white-space:normal;overflow-wrap:anywhere;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;}
            body.teacher-dashboard-page .book-author{font-size:11px;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;}
            body.teacher-dashboard-page .book-availability{max-width:78px;font-size:9px;line-height:1.2;padding:4px 6px;border-radius:999px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
            body.teacher-dashboard-page .book-qr img{max-width:100%;}
            body.teacher-dashboard-page .book-details-grid{grid-template-columns:1fr;}
        }
        @media (max-width: 380px){
            body.teacher-dashboard-page .container{padding:0 10px;}
            body.teacher-dashboard-page .book-item{grid-template-columns:34px minmax(0,1fr) auto;gap:7px;padding:8px;}
            body.teacher-dashboard-page .book-icon{width:34px;height:34px;}
            body.teacher-dashboard-page .book-availability{max-width:70px;font-size:8px;}
        }
    </style>
    <style id="dashboard-extra">
        /* ---------- Welcome + stats ---------- */
        .dash-welcome h1{font-size:clamp(22px,3vw,30px);letter-spacing:-.4px;color:var(--pu-ink);margin:0 0 4px}
        .dash-welcome p{color:var(--pu-muted);font-size:14.5px;line-height:1.5}
        .dash-welcome{margin-bottom:18px}
        .dash-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:20px}
        .dash-stat{background:var(--pu-card);border:1px solid var(--pu-line);border-left:4px solid var(--pu-navy);border-radius:14px;padding:14px 16px;box-shadow:var(--pu-shadow);min-width:0}
        .dash-stat-label{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--pu-muted);font-weight:700}
        .dash-stat-value{font-size:26px;font-weight:800;color:var(--pu-ink);margin-top:4px;line-height:1.1}
        .dash-stat-value small{font-size:14px;font-weight:700;color:var(--pu-muted)}
        .dash-stat a{color:inherit;text-decoration:none}
        @media (max-width:600px){
            .dash-stats{gap:8px}
            .dash-stat{padding:10px 12px}
            .dash-stat-value{font-size:20px}
            .dash-stat-label{font-size:10px}
        }

        /* ---------- Book details modal ---------- */
        body.modal-open{overflow:hidden}
        .modal-content.book-sheet{max-width:680px;width:calc(100% - 32px);padding:0;text-align:left}
        .book-sheet-head{position:sticky;top:0;z-index:2;display:flex;align-items:flex-start;gap:12px;
            padding:18px 20px 14px;background:var(--pu-card);border-bottom:1px solid var(--pu-line)}
        .book-sheet-head h3{flex:1;min-width:0;margin:0;font-size:clamp(17px,2.4vw,21px);line-height:1.3;color:var(--pu-ink);overflow-wrap:anywhere}
        .book-sheet-head .book-sheet-status{display:inline-block;margin-top:8px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700}
        .book-sheet-status.available{background:var(--pu-green-bg);color:var(--pu-green)}
        .book-sheet-status.unavailable{background:var(--pu-red-bg);color:var(--pu-red)}
        .book-sheet-close{flex-shrink:0;width:44px;height:44px;margin:-6px -8px 0 0;border:0;border-radius:50%;background:var(--pu-mist);
            color:var(--pu-ink);font-size:26px;line-height:1;cursor:pointer;display:grid;place-items:center}
        .book-sheet-close:hover{background:var(--pu-sky)}
        .book-sheet-body{padding:16px 20px 22px}
        .book-sheet-body .book-details-grid{margin-bottom:18px}
        .book-sheet-body .book-qr{margin:0;text-align:center}
        .book-sheet-body .book-qr{background:var(--pu-mist);border:2px dashed var(--pu-sky);border-radius:16px;padding:18px}
        .book-sheet-body .book-qr h4{color:var(--pu-muted)}
        .book-sheet-body .book-qr .qr-text{color:var(--pu-ink)}
        .book-sheet-body .book-qr img{background:#fff;border-radius:12px;padding:8px}
        .book-sheet-grab{display:none}
        @media (max-width:600px){
            .modal-content.book-sheet{width:100% !important;max-width:none !important}
            .book-sheet-grab{display:block;width:42px;height:5px;border-radius:999px;background:var(--pu-line);margin:8px auto 0}
            .book-sheet-head{padding:10px 16px 12px}
            .book-sheet-body{padding:14px 16px calc(22px + env(safe-area-inset-bottom,0px))}
        }
        .book-item:focus-visible{outline:3px solid #F4F916;outline-offset:2px}
    </style>
    <style id="qr-zoom-extra">
        .book-sheet-body .book-qr img{width:min(62vw,220px);height:auto;aspect-ratio:1/1}
        .book-sheet-body .book-qr .qr-zoomable{margin:4px auto 2px}
        .book-sheet-body .book-qr .qr-tap-note{margin-bottom:6px}
        @media (max-width:600px){
            .modal-content.book-sheet{min-height:88dvh;max-height:96dvh !important}
            .book-sheet-body .book-qr img{width:min(70vw,260px)}
        }
        html{scroll-behavior:smooth;scroll-padding-top:84px}
    </style>

<style id="mobile-teacher-book-visibility-fix">
@media (max-width: 600px) {
    body.teacher-dashboard-page .container,
    body.teacher-dashboard-page .grid,
    body.teacher-dashboard-page .results-section,
    body.teacher-dashboard-page .results-list {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box !important;
    }
    body.teacher-dashboard-page .grid { margin: 0 !important; overflow: hidden !important; }
    body.teacher-dashboard-page .results-section { padding: 14px !important; overflow: hidden !important; }
    body.teacher-dashboard-page .results-list {
        display: flex !important;
        flex-direction: column !important;
        gap: 7px !important;
        padding: 2px 0 4px !important;
        max-height: none !important;
        overflow: visible !important;
    }
    body.teacher-dashboard-page .book-item {
        display: grid !important;
        grid-template-columns: 38px minmax(0, 1fr) auto !important;
        align-items: center !important;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        margin: 0 !important;
        padding: 9px !important;
        gap: 8px !important;
        box-sizing: border-box !important;
        overflow: hidden !important;
        transform: none !important;
    }
    body.teacher-dashboard-page .book-icon { width:38px !important; height:38px !important; min-width:38px !important; }
    body.teacher-dashboard-page .book-info { min-width:0 !important; max-width:100% !important; width:auto !important; }
    body.teacher-dashboard-page .book-title {
        min-width:0 !important; max-width:100% !important;
        font-size:13px !important; line-height:1.25 !important;
        display:-webkit-box !important; -webkit-box-orient:vertical !important; -webkit-line-clamp:2 !important;
        overflow:hidden !important; overflow-wrap:anywhere !important; word-break:break-word !important;
    }
    body.teacher-dashboard-page .book-author {
        min-width:0 !important; max-width:100% !important;
        font-size:11px !important; line-height:1.25 !important;
        white-space:nowrap !important; overflow:hidden !important; text-overflow:ellipsis !important;
    }
    body.teacher-dashboard-page .book-availability {
        justify-self:end !important; align-self:center !important;
        min-width:56px !important; max-width:72px !important;
        padding:4px 6px !important; font-size:9px !important; line-height:1.15 !important;
        white-space:nowrap !important; overflow:hidden !important; text-overflow:ellipsis !important;
        text-align:center !important; box-sizing:border-box !important;
    }
}
@media (max-width: 380px) {
    body.teacher-dashboard-page .book-item { grid-template-columns:34px minmax(0,1fr) 60px !important; gap:7px !important; padding:8px !important; }
    body.teacher-dashboard-page .book-icon { width:34px !important; height:34px !important; min-width:34px !important; }
    body.teacher-dashboard-page .book-availability { min-width:54px !important; max-width:60px !important; font-size:8px !important; }
}
</style>
</head>
<body class="teacher-app teacher-dashboard-page">
    <?php require_once __DIR__ . '/../includes/ui_feedback.php'; ?>
    <!-- Header -->
    <header class="page-header">
        <a href="/LibraryBorrowingSystem/teacher/dashboard.php" class="header-brand">
            <img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo">
            <span class="header-brand-text">Jose Abad Santos High School<span class="header-brand-subtitle">Library Management System</span></span>
        </a>
        <div class="teacher-header-actions">
        <div class="teacher-notification-wrap">
    <button type="button" class="teacher-notification-bell" id="teacherNotificationBell" aria-label="Notifications">
        <span class="notification-icon" aria-hidden="true">🔔</span><span class="teacher-notification-count" id="teacherNotificationCount" style="display:none;">0</span>
    </button>
    <div class="teacher-notification-panel" id="teacherNotificationPanel">
        <div class="teacher-notification-header"><strong>Notifications</strong><button type="button" id="teacherMarkAllNotifications">Mark all read</button></div>
        <div id="teacherNotificationList"><div class="teacher-notification-empty">Loading notifications...</div></div>
    </div>
</div>

<div class="teacher-menu">
            <button type="button" class="teacher-menu-toggle" id="teacherMenuToggle" aria-haspopup="true" aria-expanded="false">
                <span class="teacher-menu-name"><?php echo $teacher ? htmlspecialchars($teacher['full_name']) : 'Teacher'; ?></span>
                <span class="teacher-menu-caret">▼</span>
            </button>
            <div class="teacher-dropdown" id="teacherDropdown">
                <a href="/LibraryBorrowingSystem/teacher/dashboard.php" class="active">Dashboard</a>
                <a href="/LibraryBorrowingSystem/teacher/profile.php">Profile</a>
                <button type="button" class="theme-switch" id="teacherThemeToggle">Dark mode</button>
                <div class="dropdown-divider"></div>
                <a href="/LibraryBorrowingSystem/teacher/portal.php?logout=1">Logout</a>
            </div>
        </div>
    </div>
    </header>
    
    <div class="container">
        <?php require_once __DIR__ . '/../includes/portal_dashboard.php'; renderPortalDashboard($conn, 'teacher', $teacher, (int)$max_active_books); ?>

        <!-- Search Section -->
        <div class="search-section">
            <form class="search-form" id="searchForm">
                <input type="text"
                       id="searchInput"
                       placeholder="Search title, author, or book number...">
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
                <select id="conditionFilter" aria-label="Filter by book condition">
                    <option value="">All Conditions</option>
                    <option value="New">New</option>
                    <option value="Old">Old</option>
                </select>
                <button type="submit" class="auto-search-submit">Search Books</button>
            </form>
        </div>
        
        <!-- Main Grid: Teacher Info + Books -->
        <div class="grid">
            <!-- Books Results Column -->
            <div class="results-section">
                <h2>Available Books (<span id="bookCount"><?php echo count($books); ?></span>)</h2>
                <div style="margin:-10px 0 14px;color:#52618D;font-size:12px;">
                    Maximum active books: <strong><?php echo (int)$max_active_books; ?></strong>. Reservations do not count toward this limit.
                </div>
                
                <div class="results-list" id="booksList">
                    <?php if (empty($books) && $teacher): ?>
                        <div class="empty-results">
                            <p>No books available at the moment</p>
                        </div>
                    <?php elseif (!$teacher): ?>
                        <div class="empty-results">
                            <p>Unable to load books. Please verify your QR code.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($books as $book): ?>
                            <div role="button" tabindex="0" class="book-item <?php echo $book['available_copies'] <= 0 ? 'unavailable' : ''; ?>" 
                                 onclick="selectBook(<?php echo $book['book_id']; ?>, '<?php echo htmlspecialchars(addslashes($book['title'])); ?>', <?php echo $book['available_copies']; ?>, this)">
                                <div class="book-icon"><span class="book-icon-svg" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v17H6.5A2.5 2.5 0 0 1 4 17.5z"/><path d="M4 5.5V18a2 2 0 0 0 2 2h14"/><path d="M8 7h7"/><path d="M8 10h9"/></svg></span></div>
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
        
    </div>

    <!-- Book Details Modal (bottom sheet on phones) -->
    <div class="modal-overlay" id="bookModal">
        <div class="modal-content book-sheet" role="dialog" aria-modal="true" aria-labelledby="selectedBookTitle">
            <div class="book-sheet-grab" aria-hidden="true"></div>
            <div class="book-sheet-head">
                <div style="flex:1;min-width:0;">
                    <h3 id="selectedBookTitle">Book Details</h3>
                    <span class="book-sheet-status" id="selectedBookStatusBadge"></span>
                </div>
                <button type="button" class="book-sheet-close" id="bookModalClose" aria-label="Close book details">&times;</button>
            </div>
            <div class="book-sheet-body">
            
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
                    <div class="detail-label">QR ID</div>
                    <div class="detail-value" id="selectedBookQrId" style="word-break:break-all;">—</div>
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
                <div class="detail-item" style="display:none;">
                    <div class="detail-label">Status</div>
                    <div class="detail-value" id="selectedBookStatus">—</div>
                </div>
            </div>
            
            <!-- Current available physical copy and its unique QR code -->
            <div class="book-qr">
                <h4>Available Copy &amp; QR Code</h4>
                <div id="selectedBookCopies" style="display:grid;grid-template-columns:minmax(170px,260px);justify-content:center;gap:12px;text-align:left;"></div>
                <div class="qr-tap-note" style="margin-top:10px;">Only one currently available physical copy is shown. When it is borrowed, the next available BK copy will be shown automatically.</div>
            </div>
            
            <!-- Borrow Button -->
            <form method="POST" id="borrowForm" style="margin-top: 20px;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="process_borrow">
                <input type="hidden" name="book_id" id="borrowBookId" value="">
            </form>
            </div>
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

let lastFocusedBookItem = null;

function openBookModal() {
    const overlay = document.getElementById('bookModal');
    if (!overlay) return;
    overlay.classList.add('show');
    document.body.classList.add('modal-open');
    const sheet = overlay.querySelector('.book-sheet');
    if (sheet) sheet.scrollTop = 0;
    const closeBtn = overlay.querySelector('.book-sheet-close');
    if (closeBtn) closeBtn.focus({ preventScroll: true });
}

function closeBookModal() {
    const overlay = document.getElementById('bookModal');
    if (!overlay) return;
    overlay.classList.remove('show');
    document.body.classList.remove('modal-open');
    selectedBookId = null;
    document.querySelectorAll('.book-item.active').forEach(item => item.classList.remove('active'));
    if (lastFocusedBookItem && document.body.contains(lastFocusedBookItem)) {
        lastFocusedBookItem.focus({ preventScroll: true });
    }
    lastFocusedBookItem = null;
}

document.addEventListener('DOMContentLoaded', function () {
    const overlay = document.getElementById('bookModal');
    if (overlay) {
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeBookModal();
        });
    }
    const closeBtn = document.getElementById('bookModalClose');
    if (closeBtn) closeBtn.addEventListener('click', closeBookModal);

    // Keyboard: Esc closes the modal; Enter/Space opens a focused book card.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const o = document.getElementById('bookModal');
            if (o && o.classList.contains('show')) closeBookModal();
        }
        if ((e.key === 'Enter' || e.key === ' ') && e.target && e.target.classList &&
            e.target.classList.contains('book-item')) {
            e.preventDefault();
            e.target.click();
        }
    });
});

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
            book.book_number,
            book.publisher,
            book.edition,
            book.class,
            book.library_section,
            book.location_collection
        ].map(value => String(value || '').toLowerCase()).join(' ');

        const bookClass = String(book.class || '');
        const section = String(book.library_section || book.location_collection || '');
        const condition = String(book.book_condition || '').trim().toLowerCase();
        const selectedCondition = String(conditionValue || '').trim().toLowerCase();
        const availability = Number(book.available_copies || 0) > 0 ? 'available' : 'out_of_stock';

        return (!query || searchable.includes(query))
            && (!classValue || bookClass === classValue)
            && (!sectionValue || section === sectionValue)
            && (!availabilityValue || availability === availabilityValue)
            && (!selectedCondition || condition === selectedCondition);
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
        // Keep only the fixed conditions used by the borrowing page.
        // Database values are normalized during filtering.
        conditionFilter.innerHTML = `
            <option value="">All Conditions</option>
            <option value="New">New</option>
            <option value="Old">Old</option>
        `;

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
                <div class="empty-results-icon"><span class="search-icon-svg" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg></span></div>
                <p>No books found matching your search</p>
            </div>
        `;
    } else {
        let html = '';
        booksWithQR.forEach(book => {
            const isUnavailable = book.available_copies <= 0;
            html += `
                <div role="button" tabindex="0" class="book-item ${isUnavailable ? 'unavailable' : ''}" 
                     onclick="selectBook(${book.book_id}, '${book.title.replace(/'/g, "\\'")}', ${book.available_copies}, this)">
                    <div class="book-icon"><span class="book-icon-svg" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v17H6.5A2.5 2.5 0 0 1 4 17.5z"/><path d="M4 5.5V18a2 2 0 0 0 2 2h14"/><path d="M8 7h7"/><path d="M8 10h9"/></svg></span></div>
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

function selectBook(bookId, title, availableCopies, element = null) {
    
    selectedBookId = bookId;
    
    // Find book in allBooks
    const book = allBooks.find(b => b.book_id === bookId);
    if (!book) return;
    
    // Update book details section
    document.getElementById('selectedBookTitle').textContent = book.title;
    document.getElementById('selectedBookAuthor').textContent = book.author;
    document.getElementById('selectedBookNumber').textContent = book.book_number || '—';
    document.getElementById('selectedBookQrId').textContent = book.qr_code || '—';
    document.getElementById('selectedBookPages').textContent = book.book_pages || '—';

    setOptionalBookDetail('publisherDetail', 'selectedBookPublisher', book.publisher);
    setOptionalBookDetail('editionDetail', 'selectedBookEdition', book.edition);
    setOptionalBookDetail('volumesDetail', 'selectedBookVolumes', book.volumes);
    setOptionalBookDetail('classDetail', 'selectedBookClass', book.class);
    setOptionalBookDetail('locationDetail', 'selectedBookLocation', book.location_collection);
    setOptionalBookDetail('buildingDetail', 'selectedBookBuilding', book.library_building);
    setOptionalBookDetail('shelfDetail', 'selectedBookShelf', book.shelf_number);
    setOptionalBookDetail('librarySectionDetail', 'selectedBookLibrarySection', book.library_section);

    document.getElementById('selectedBookAvailable').textContent = book.available_copies;
    document.getElementById('selectedBookTotal').textContent = book.total_copies;

    const unavailable = Number(book.available_copies || 0) <= 0;
    document.getElementById('selectedBookStatus').textContent = unavailable ? 'Out of Stock' : 'Available';

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
    
    // Show only ONE currently available physical copy.
    // Copies are ordered by BK number, so after BK-001 is borrowed, BK-002
    // becomes the next displayed copy automatically.
    const copiesBox = document.getElementById('selectedBookCopies');
    const copies = Array.isArray(book.copies) ? book.copies : [];
    const availableCopy = copies
        .filter(copy => String(copy.copy_status || '').toLowerCase() === 'available')
        .sort((a, b) => {
            const aNum = parseInt(String(a.book_number || '').replace(/^BK-/i, ''), 10) || 0;
            const bNum = parseInt(String(b.book_number || '').replace(/^BK-/i, ''), 10) || 0;
            if (aNum !== bNum) return aNum - bNum;
            return Number(a.copy_id || 0) - Number(b.copy_id || 0);
        })[0] || null;

    if (copiesBox) {
        if (!availableCopy) {
            copiesBox.innerHTML = '<div style="padding:16px;background:#fff;border:1px solid #D8E5F5;border-radius:12px;text-align:center;">No available physical copy at the moment.</div>';
        } else {
            const copy = availableCopy;
            const qrSrc = (typeof qrDataUrl === 'function')
                ? qrDataUrl(copy.qr_code, 220)
                : ('https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=' + encodeURIComponent(copy.qr_code));
            const download = '/LibraryBorrowingSystem/teacher/download_book_qr.php?copy_id=' + encodeURIComponent(copy.copy_id);

            // The physical copy is the authoritative Book Number / QR ID shown to the user.
            document.getElementById('selectedBookNumber').textContent = copy.book_number || '—';
            document.getElementById('selectedBookQrId').textContent = copy.qr_code || '—';

            copiesBox.innerHTML = '<div style="background:#fff;border:1px solid #D8E5F5;border-radius:12px;padding:14px;text-align:center;">' +
                '<button type="button" class="qr-zoomable" data-qr-zoom data-qr-code="' + escapeHtml(copy.qr_code) + '" data-qr-title="' + escapeHtml(book.title + ' · ' + copy.book_number) + '" data-qr-sub="Book QR · ' + escapeHtml(copy.book_number) + '" aria-label="Enlarge ' + escapeHtml(copy.book_number) + ' QR code"><img src="' + qrSrc + '" alt="QR ' + escapeHtml(copy.book_number) + '" style="width:190px;height:190px;object-fit:contain;background:#fff;border-radius:8px;"><span class="qr-zoom-hint" aria-hidden="true">⤢</span></button>' +
                '<div style="font-weight:800;font-size:19px;margin-top:8px;">' + escapeHtml(copy.book_number) + '</div>' +
                '<div style="font-size:12px;word-break:break-all;color:#52618D;margin:5px 0;">QR ID: ' + escapeHtml(copy.qr_code) + '</div>' +
                '<div style="font-size:12px;font-weight:700;margin-bottom:9px;color:#18864B;">AVAILABLE · ' + escapeHtml(copy.copy_condition || '') + '</div>' +
                '<a class="qr-download-btn" href="' + download + '">Download QR</a>' +
                '</div>';
        }
    }
    
    // Set hidden form field
    document.getElementById('borrowBookId').value = bookId;
    
    // Status badge in the modal header
    const badge = document.getElementById('selectedBookStatusBadge');
    if (badge) {
        badge.textContent = unavailable ? 'Out of Stock' : 'Available';
        badge.className = 'book-sheet-status ' + (unavailable ? 'unavailable' : 'available');
    }

    // Highlight selected book in list, then open the modal (no page scrolling)
    document.querySelectorAll('.book-item').forEach(item => {
        item.classList.remove('active');
    });
    if (element) { element.classList.add('active'); lastFocusedBookItem = element; }
    openBookModal();
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
        if (data.copy_book_number) {
            message += ' Assigned physical copy: ' + data.copy_book_number + '.';
        }
        if (data.copy_qr_code) {
            message += ' QR ID: ' + data.copy_qr_code + '.';
        }
        if (data.due_date) {
            message += ' Return by: ' + data.due_date + '.';
        }
    }

    showToast(message || title, toastType, 4400, title);

    if (type === 'success') {
        closeBookModal();
        const searchInput = document.getElementById('searchInput');
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
    closeBookModal();
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
        const teacherThemeToggle = document.getElementById('teacherThemeToggle');
        function applyTeacherTheme() {
            const dark = localStorage.getItem('jas-theme') === 'dark';
            document.body.classList.toggle('dark', dark);
            if (teacherThemeToggle) teacherThemeToggle.textContent = dark ? 'Light mode' : 'Dark mode';
        }
        applyTeacherTheme();
        if (teacherThemeToggle) teacherThemeToggle.addEventListener('click', function () {
            localStorage.setItem('jas-theme', document.body.classList.contains('dark') ? 'light' : 'dark');
            applyTeacherTheme();
        });
        const teacherMenuToggle = document.getElementById('teacherMenuToggle');
        const teacherDropdown = document.getElementById('teacherDropdown');

        if (teacherMenuToggle && teacherDropdown) {
            teacherMenuToggle.addEventListener('click', function (event) {
                event.stopPropagation();
                const isOpen = teacherDropdown.classList.toggle('show');
                teacherMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            document.addEventListener('click', function () {
                teacherDropdown.classList.remove('show');
                teacherMenuToggle.setAttribute('aria-expanded', 'false');
            });
        }
    </script>

<script>
(function(){
  const bell=document.getElementById('teacherNotificationBell');
  const panel=document.getElementById('teacherNotificationPanel');
  const count=document.getElementById('teacherNotificationCount');
  const list=document.getElementById('teacherNotificationList');
  const markAll=document.getElementById('teacherMarkAllNotifications');
  if(!bell||!panel||!list)return;
  function esc(v){const d=document.createElement('div');d.textContent=v??'';return d.innerHTML;}
  function relativeTime(v){const t=new Date(v.replace(' ','T')).getTime(),m=Math.floor(Math.max(0,Date.now()-t)/60000);if(m<1)return'Just now';if(m<60)return m+' min ago';const h=Math.floor(m/60);if(h<24)return h+' hr ago';return Math.floor(h/24)+' day(s) ago';}
  function load(){fetch('/LibraryBorrowingSystem/notifications.php?action=list',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{if(!d.ok)return;const u=Number(d.unread||0);count.textContent=u>99?'99+':u;count.style.display=u?'flex':'none';if(!d.notifications.length){list.innerHTML='<div class="teacher-notification-empty">No notifications yet.</div>';return;}list.innerHTML=d.notifications.map(n=>`<div class="teacher-notification-item ${Number(n.is_read)===0?'unread':''}" data-id="${Number(n.notification_id)}"><div class="teacher-notification-title">${esc(n.title)}</div><div class="teacher-notification-message">${esc(n.message)}</div><div class="teacher-notification-time">${relativeTime(n.created_at)}</div></div>`).join('');list.querySelectorAll('.teacher-notification-item').forEach(el=>el.onclick=function(){fetch('/LibraryBorrowingSystem/notifications.php?action=read',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'notification_id='+encodeURIComponent(this.dataset.id)}).then(load);});}).catch(()=>{});}
  bell.addEventListener('click',e=>{e.stopPropagation();panel.classList.toggle('show');load();});
  panel.addEventListener('click',e=>e.stopPropagation());document.addEventListener('click',()=>panel.classList.remove('show'));
  markAll.addEventListener('click',()=>fetch('/LibraryBorrowingSystem/notifications.php?action=read_all',{method:'POST',credentials:'same-origin'}).then(load));
  load();setInterval(load,30000);
})();
</script>

<script src="/LibraryBorrowingSystem/js/qrcode.js"></script>
<script>
function qrDataUrl(text, size) {
    try {
        const qr = qrcode(0, 'M');
        qr.addData(String(text)); qr.make();
        const n = qr.getModuleCount(), quiet = 4, cell = Math.max(2, Math.ceil((size || 200) / (n + quiet * 2)));
        const px = (n + quiet * 2) * cell, cv = document.createElement('canvas');
        cv.width = cv.height = px;
        const ctx = cv.getContext('2d');
        ctx.fillStyle='#fff'; ctx.fillRect(0,0,px,px); ctx.fillStyle='#000';
        for (let r=0;r<n;r++) for (let c=0;c<n;c++) if (qr.isDark(r,c)) ctx.fillRect((c+quiet)*cell,(r+quiet)*cell,cell,cell);
        return cv.toDataURL('image/png');
    } catch (e) {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=0&data=' + encodeURIComponent(text);
    }
}
</script>

<?php require_once __DIR__ . '/../includes/qr_lightbox.php'; ?>
</body>
</html>