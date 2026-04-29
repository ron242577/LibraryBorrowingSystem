<?php
/**
 * Inventory Management - Librarian Panel
 * Add books, import via CSV, and monitor real-time inventory
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
$books = [];

// QR codes directory
$qr_dir = __DIR__ . '/../qr_codes';
if (!is_dir($qr_dir)) {
    mkdir($qr_dir, 0755, true);
}

// ─── Helper functions ────────────────────────────────────────────────────────

function generateBookQRCode($book_qr_id) {
    global $qr_dir;
    $filename  = $book_qr_id . '.png';
    $filepath  = $qr_dir . '/' . $filename;
    $qr_image_url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($book_qr_id);
    try {
        $image_content = @file_get_contents($qr_image_url, false, stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]));
        if ($image_content === false) throw new Exception('Failed to download QR code from API');
        if (file_put_contents($filepath, $image_content) === false) throw new Exception('Failed to save QR code image');
        return 'qr_codes/' . $filename;
    } catch (Exception $e) {
        logError('Book QR Code generation error: ' . $e->getMessage());
        throw $e;
    }
}

function generateUniqueBookQRId() {
    $date   = date('Ymd');
    $random = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 5));
    return 'BOOK-' . $date . '-' . $random;
}

function getStatusBadge($available_copies, $total_copies) {
    if ($available_copies <= 0) {
        return '<span class="badge badge-danger">Out of Stock</span>';
    } elseif ($available_copies <= 2) {
        return '<span class="badge badge-warning">Low Stock</span>';
    } else {
        return '<span class="badge badge-success">Available</span>';
    }
}

// ─── POST handlers ───────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ── Add single book ──────────────────────────────────────────────────────
    if ($action === 'add') {
        $title  = trim($_POST['title']  ?? '');
        $author = trim($_POST['author'] ?? '');

        if (empty($title)) {
            $message = 'Book title is required.'; $message_type = 'error';
        } elseif (strlen($title) < 3) {
            $message = 'Book title must be at least 3 characters long.'; $message_type = 'error';
        } elseif (empty($author)) {
            $message = 'Author name is required.'; $message_type = 'error';
        } elseif (strlen($author) < 2) {
            $message = 'Author name must be at least 2 characters long.'; $message_type = 'error';
        } else {
            try {
                $book_qr_code = generateUniqueBookQRId();
                $check_stmt   = $conn->prepare("SELECT book_id FROM books WHERE qr_code = ?");
                $check_stmt->bind_param('s', $book_qr_code);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    $message = 'Error generating unique QR code. Please try again.'; $message_type = 'error';
                } else {
                    generateBookQRCode($book_qr_code);
                    $status           = 'available';
                    $total_copies     = 1;
                    $available_copies = 1;
                    $borrowed_copies  = 0;
                    $lost_copies      = 0;

                    $insert_stmt = $conn->prepare("INSERT INTO books (title, author, qr_code, book_status, total_copies, available_copies, borrowed_copies, lost_copies) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $insert_stmt->bind_param('ssssiiii', $title, $author, $book_qr_code, $status, $total_copies, $available_copies, $borrowed_copies, $lost_copies);

                    if ($insert_stmt->execute()) {
                        $message = 'Book added successfully! QR Code: ' . htmlspecialchars($book_qr_code);
                        $message_type = 'success';
                        header("Refresh: 2; url=/LibraryBorrowingSystem/librarian/inventory.php");
                    } else {
                        $message = 'Error adding book: ' . htmlspecialchars($conn->error); $message_type = 'error';
                    }
                    $insert_stmt->close();
                }
                $check_stmt->close();
            } catch (Exception $e) {
                $message = 'Error: ' . htmlspecialchars($e->getMessage()); $message_type = 'error';
            }
        }
    }

    // ── Import CSV ───────────────────────────────────────────────────────────
    elseif ($action === 'import_books') {
        $imported_count = 0;
        $skipped_count  = 0;
        $import_errors  = [];

        if (!isset($_FILES['books_file']) || $_FILES['books_file']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Please choose a valid CSV file to import.'; $message_type = 'error';
        } else {
            $file_tmp  = $_FILES['books_file']['tmp_name'];
            $file_name = $_FILES['books_file']['name'];
            $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if ($file_ext !== 'csv') {
                $message = 'Invalid file type. Please upload a CSV file only.'; $message_type = 'error';
            } else {
                try {
                    $handle = fopen($file_tmp, 'r');
                    if ($handle === false) throw new Exception('Unable to open uploaded CSV file.');

                    $row_number = 0;
                    $conn->begin_transaction();

                    while (($row = fgetcsv($handle, 10000, ',')) !== false) {
                        $row_number++;
                        if (count(array_filter($row, function($v){ return trim((string)$v) !== ''; })) === 0) continue;

                        $title        = trim($row[0] ?? '');
                        $author       = trim($row[1] ?? '');
                        $total_copies = isset($row[2]) && trim($row[2]) !== '' ? (int)trim($row[2]) : 1;

                        if ($row_number === 1 && strtolower($title) === 'title' && strtolower($author) === 'author') continue;
                        if ($title === '' || $author === '') {
                            $skipped_count++;
                            $import_errors[] = 'Row ' . $row_number . ': Missing title or author.';
                            continue;
                        }
                        if ($total_copies < 1) $total_copies = 1;

                        do {
                            $book_qr_code = generateUniqueBookQRId();
                            $check_stmt   = $conn->prepare("SELECT book_id FROM books WHERE qr_code = ?");
                            $check_stmt->bind_param('s', $book_qr_code);
                            $check_stmt->execute();
                            $exists = $check_stmt->get_result()->num_rows > 0;
                            $check_stmt->close();
                        } while ($exists);

                        generateBookQRCode($book_qr_code);
                        $status           = 'available';
                        $available_copies = $total_copies;
                        $borrowed_copies  = 0;
                        $lost_copies      = 0;

                        $insert_stmt = $conn->prepare("INSERT INTO books (title, author, qr_code, book_status, total_copies, available_copies, borrowed_copies, lost_copies) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $insert_stmt->bind_param('ssssiiii', $title, $author, $book_qr_code, $status, $total_copies, $available_copies, $borrowed_copies, $lost_copies);

                        if (!$insert_stmt->execute()) {
                            $skipped_count++;
                            $import_errors[] = 'Row ' . $row_number . ': ' . $insert_stmt->error;
                            $insert_stmt->close();
                            continue;
                        }
                        $insert_stmt->close();
                        $imported_count++;
                    }

                    fclose($handle);
                    $conn->commit();

                    if ($imported_count > 0) {
                        $message = 'Import completed. Added ' . $imported_count . ' book(s).';
                        if ($skipped_count > 0) $message .= ' Skipped ' . $skipped_count . ' row(s).';
                        if (!empty($import_errors)) $message .= ' First issue: ' . $import_errors[0];
                        $message_type = 'success';
                        header("Refresh: 3; url=/LibraryBorrowingSystem/librarian/inventory.php#import-books");
                    } else {
                        $message = 'No books were imported. Please check your CSV format: title, author, total_copies.';
                        if (!empty($import_errors)) $message .= ' First issue: ' . $import_errors[0];
                        $message_type = 'error';
                    }
                } catch (Exception $e) {
                    @$conn->rollback();
                    $message = 'Import failed: ' . htmlspecialchars($e->getMessage()); $message_type = 'error';
                    logError('Book import error: ' . $e->getMessage());
                }
            }
        }
    }

    // ── Add copies ───────────────────────────────────────────────────────────
    elseif ($action === 'add_copies') {
        $book_id      = intval($_POST['book_id']      ?? 0);
        $copies_to_add = intval($_POST['copies_to_add'] ?? 0);

        if ($book_id <= 0) {
            $message = 'Invalid book ID.'; $message_type = 'error';
        } elseif ($copies_to_add <= 0) {
            $message = 'Number of copies must be greater than 0.'; $message_type = 'error';
        } else {
            try {
                $book_stmt = $conn->prepare("SELECT title, qr_code, total_copies, available_copies FROM books WHERE book_id = ?");
                $book_stmt->bind_param('i', $book_id);
                $book_stmt->execute();
                $book_result = $book_stmt->get_result();

                if ($book_result->num_rows === 0) {
                    $message = 'Book not found.'; $message_type = 'error';
                } else {
                    $book = $book_result->fetch_assoc();
                    if (!file_exists($qr_dir . '/' . $book['qr_code'] . '.png')) {
                        $message = 'Book does not have a valid QR code.'; $message_type = 'error';
                    } else {
                        $new_total     = $book['total_copies'] + $copies_to_add;
                        $new_available = $book['available_copies'] + $copies_to_add;
                        $update_stmt   = $conn->prepare("UPDATE books SET total_copies = ?, available_copies = ? WHERE book_id = ?");
                        $update_stmt->bind_param('iii', $new_total, $new_available, $book_id);
                        if ($update_stmt->execute()) {
                            $message = 'Added ' . $copies_to_add . ' copies to "' . htmlspecialchars($book['title']) . '". New total: ' . $new_total;
                            $message_type = 'success';
                        } else {
                            $message = 'Error updating inventory: ' . $conn->error; $message_type = 'error';
                        }
                        $update_stmt->close();
                    }
                }
                $book_stmt->close();
            } catch (Exception $e) {
                $message = 'Error: ' . htmlspecialchars($e->getMessage()); $message_type = 'error';
                logError('Inventory update error: ' . $e->getMessage());
            }
        }
    }
}

// ─── Data fetching ────────────────────────────────────────────────────────────

// Inventory statistics
$stats = ['total_titles' => 0, 'total_copies' => 0, 'available_copies' => 0, 'borrowed_copies' => 0, 'lost_copies' => 0];
try {
    $stats_with_qr = $stats;
    $qr_books = $conn->query("SELECT * FROM books");
    if ($qr_books) {
        while ($book = $qr_books->fetch_assoc()) {
            $stats_with_qr['total_titles']++;
            $stats_with_qr['total_copies']     += $book['total_copies'];
            $stats_with_qr['available_copies'] += $book['available_copies'];
            $stats_with_qr['borrowed_copies']  += $book['borrowed_copies'];
            $stats_with_qr['lost_copies']      += $book['lost_copies'];
        }
    }
    $stats = $stats_with_qr;
} catch (Exception $e) {
    logError('Error fetching inventory stats: ' . $e->getMessage());
}

// Low stock books
$low_stock_books = [];
try {
    $low_stock_result = $conn->query("SELECT book_id, title, author, qr_code, available_copies, total_copies FROM books WHERE available_copies <= 2 ORDER BY available_copies ASC");
    if ($low_stock_result) {
        while ($row = $low_stock_result->fetch_assoc()) {
            $low_stock_books[] = $row;
        }
    }
} catch (Exception $e) {
    logError('Error fetching low stock books: ' . $e->getMessage());
}

// All books
$all_books = [];
try {
    $books_result = $conn->query("SELECT book_id, title, author, qr_code, total_copies, available_copies, borrowed_copies, lost_copies, book_status, created_at FROM books ORDER BY title ASC");
    if ($books_result) {
        while ($row = $books_result->fetch_assoc()) {
            $all_books[] = $row;
        }
    }
} catch (Exception $e) {
    logError('Error fetching books inventory: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - Library Borrowing System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

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

        /* ── Page header ── */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .page-header h1 {
            font-size: 28px;
            color: #2c3e50;
        }

        .page-header p {
            color: #7f8c8d;
            font-size: 14px;
            margin-top: 4px;
        }

        /* ── Stats grid ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 22px 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            border-left: 5px solid #003366;
            transition: transform .3s, box-shadow .3s;
        }

        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 14px rgba(0,0,0,.12); }
        .stat-card.blue  { border-left-color: #003366; }
        .stat-card.green { border-left-color: #27ae60; }
        .stat-card.orange{ border-left-color: #f39c12; }
        .stat-card.red   { border-left-color: #e74c3c; }

        .stat-card .label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: .5px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-card.green  .value { color: #27ae60; }
        .stat-card.orange .value { color: #f39c12; }
        .stat-card.red    .value { color: #e74c3c; }

        /* ── Alerts ── */
        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }

        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* ── Section card ── */
        .section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .section h3 {
            font-size: 18px;
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }

        /* ── Forms ── */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group { margin-bottom: 16px; }

        label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 500;
            font-size: 14px;
        }

        input[type="text"],
        input[type="file"],
        input[type="number"] {
            width: 100%;
            padding: 11px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color .3s, box-shadow .3s;
        }

        input[type="text"]:focus,
        input[type="file"]:focus,
        input[type="number"]:focus {
            outline: none;
            border-color: #003366;
            box-shadow: 0 0 8px rgba(0,51,102,.2);
        }

        .qr-info {
            background: #eef4ff;
            padding: 14px;
            border-radius: 8px;
            margin-top: 14px;
            border-left: 4px solid #003366;
            font-size: 13px;
            color: #2c3e50;
        }

        .template-note {
            background: #f8f9fa;
            padding: 14px;
            border-radius: 8px;
            border-left: 4px solid #003366;
            font-size: 13px;
            color: #2c3e50;
            line-height: 1.6;
            margin-bottom: 16px;
        }

        .template-note code {
            background: white;
            padding: 2px 6px;
            border-radius: 4px;
            color: #003366;
            font-size: 12px;
        }

        .button-group { display: flex; gap: 10px; margin-top: 18px; }

        .btn, button[type="submit"] {
            padding: 11px 22px;
            background: #003366;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s, transform .2s, box-shadow .2s;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover, button[type="submit"]:hover {
            background: #002244;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,51,102,.3);
        }

        button[type="reset"] {
            padding: 11px 22px;
            background: #e0e0e0;
            color: #555;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }

        button[type="reset"]:hover { background: #ccc; }

        /* ── Low stock panel ── */
        .alert-panel {
            background: white;
            border-radius: 12px;
            padding: 22px 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            margin-bottom: 28px;
            border-left: 5px solid #f39c12;
        }

        .alert-panel h3 {
            font-size: 16px;
            color: #2c3e50;
            margin-bottom: 16px;
        }

        .no-low-stock { color: #27ae60; font-weight: 600; font-size: 14px; }

        .low-stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .low-stock-item:last-child { border-bottom: none; }
        .low-stock-item-info h4 { font-size: 14px; color: #2c3e50; margin-bottom: 2px; }
        .low-stock-item-info p { font-size: 12px; color: #7f8c8d; }

        .low-stock-badge {
            font-size: 11px;
            font-weight: 600;
            background: #fff3cd;
            color: #856404;
            padding: 4px 10px;
            border-radius: 20px;
        }

        /* ── Table ── */
        .table-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .table-section h2 {
            font-size: 18px;
            margin-bottom: 20px;
            color: #2c3e50;
            padding-bottom: 14px;
            border-bottom: 2px solid #f0f0f0;
        }

        .table-wrapper { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; }
        thead { background: #f8f9fa; border-bottom: 2px solid #e0e0e0; }

        th {
            padding: 12px 14px;
            text-align: left;
            font-weight: 600;
            color: #555;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        td {
            padding: 12px 14px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }

        tbody tr:hover { background: #f8f9fa; }

        .book-title { font-weight: 600; color: #2c3e50; }

        /* Progress bar */
        .inventory-bar { display: flex; align-items: center; gap: 8px; }

        .progress-bar {
            flex: 1;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
            min-width: 60px;
        }

        .progress-fill { height: 100%; background: #27ae60; border-radius: 4px; transition: width .4s; }
        .progress-fill.warning { background: #f39c12; }
        .progress-fill.danger  { background: #e74c3c; }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger  { background: #f8d7da; color: #721c24; }

        /* Status badges (book status column) */
        .status-badge { display: inline-block; padding: 5px 11px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status-available { background: #d4edda; color: #155724; }
        .status-borrowed  { background: #fff3cd; color: #856404; }
        .status-damaged   { background: #f8d7da; color: #721c24; }
        .status-lost      { background: #e2e3e5; color: #383d41; }

        /* QR thumbnail */
        .qr-code-image {
            width: 50px;
            height: 50px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            transition: transform .2s;
            display: block;
        }

        .qr-code-image:hover { transform: scale(1.12); }

        .empty-message { text-align: center; color: #999; padding: 40px; font-size: 15px; }

        /* ── Add copies modal ── */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,.55);
        }

        .modal.active { display: flex; justify-content: center; align-items: center; }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 6px 24px rgba(0,0,0,.25);
            max-width: 420px;
            width: 90%;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h2 { font-size: 18px; color: #2c3e50; }

        .close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
            line-height: 1;
            padding: 0;
        }

        .close:hover { color: #333; }

        .modal-buttons { display: flex; gap: 10px; margin-top: 20px; }

        .btn-primary  { padding: 11px 22px; background: #003366; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-primary:hover  { background: #002244; }
        .btn-secondary{ padding: 11px 22px; background: #e0e0e0; color: #555; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-secondary:hover{ background: #ccc; }

        /* ── QR preview modal ── */
        .qr-modal {
            display: none;
            position: fixed;
            z-index: 1100;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,.6);
        }

        .qr-modal.show { display: flex; justify-content: center; align-items: center; }

        .qr-modal-content {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.3);
            text-align: center;
            max-width: 380px;
            width: 90%;
        }

        .qr-modal-content h3 { margin-bottom: 8px; color: #2c3e50; }

        .qr-modal-image {
            max-width: 260px;
            margin: 18px auto;
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 8px;
            background: white;
            display: block;
        }

        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            th, td { padding: 10px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>

    <div class="container">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Inventory Management</h1>
                <p>Add books, import via CSV, and monitor real-time library inventory</p>
            </div>
        </div>

        <!-- Alert -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <span><?php echo $message_type === 'success' ? '✓' : '✕'; ?></span>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="label">Total Titles</div>
                <div class="value"><?php echo $stats['total_titles']; ?></div>
            </div>
            <div class="stat-card blue">
                <div class="label">Total Copies</div>
                <div class="value"><?php echo $stats['total_copies']; ?></div>
            </div>
            <div class="stat-card green">
                <div class="label">Available</div>
                <div class="value"><?php echo $stats['available_copies']; ?></div>
            </div>
            <div class="stat-card orange">
                <div class="label">Borrowed</div>
                <div class="value"><?php echo $stats['borrowed_copies']; ?></div>
            </div>
            <div class="stat-card red">
                <div class="label">Lost/Damaged</div>
                <div class="value"><?php echo $stats['lost_copies']; ?></div>
            </div>
        </div>

        <!-- Add Book Form -->
        <div class="section">
            <h3>Add New Book</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-row">
                    <div class="form-group">
                        <label for="title">Book Title *</label>
                        <input type="text" id="title" name="title" required placeholder="e.g. The Great Gatsby">
                    </div>
                    <div class="form-group">
                        <label for="author">Author *</label>
                        <input type="text" id="author" name="author" required placeholder="e.g. F. Scott Fitzgerald">
                    </div>
                </div>
                <div class="qr-info">
                    <strong>QR Code &amp; Status:</strong> A unique QR code will be automatically generated for each book. The status will default to <em>Available</em>.
                </div>
                <div class="button-group">
                    <button type="submit">Add Book</button>
                    <button type="reset">Clear</button>
                </div>
            </form>
        </div>

        <!-- Import Books CSV -->
        <div class="section" id="import-books">
            <h3>Import Books via CSV</h3>
            <div class="template-note">
                Upload a CSV file with this format: <code>title, author, total_copies</code><br>
                Example: <code>Noli Me Tangere, Jose Rizal, 3</code><br>
                The <code>total_copies</code> column is optional — defaults to 1 if omitted.
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_books">
                <div class="form-group">
                    <label for="books_file">Select CSV File *</label>
                    <input type="file" id="books_file" name="books_file" accept=".csv,text/csv" required>
                </div>
                <div class="button-group">
                    <button type="submit">Import Books</button>
                    <button type="reset">Clear</button>
                </div>
            </form>
        </div>

        <!-- Low Stock Alert -->
        <div class="alert-panel">
            <h3>⚠ Low Stock Alert</h3>
            <div class="alert-panel-content">
                <?php if (empty($low_stock_books)): ?>
                    <p class="no-low-stock">✓ All books have sufficient stock</p>
                <?php else: ?>
                    <?php foreach ($low_stock_books as $book): ?>
                        <div class="low-stock-item">
                            <div class="low-stock-item-info">
                                <h4><?php echo htmlspecialchars($book['title']); ?></h4>
                                <p><?php echo htmlspecialchars($book['author']); ?></p>
                                <p style="color:#e74c3c;font-weight:600;margin-top:2px;">
                                    Available: <?php echo $book['available_copies']; ?>/<?php echo $book['total_copies']; ?>
                                </p>
                            </div>
                            <span class="low-stock-badge">Consider Restocking</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- All Books Inventory Table -->
        <div class="table-section">
            <h2>All Books Inventory</h2>

            <?php if (empty($all_books)): ?>
                <div class="empty-message">No books in inventory yet.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>QR</th>
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
                            <?php foreach ($all_books as $book):
                                $pct       = $book['total_copies'] > 0 ? round(($book['available_copies'] / $book['total_copies']) * 100) : 0;
                                $bar_class = $book['available_copies'] <= 0 ? 'danger' : ($book['available_copies'] <= 2 ? 'warning' : '');
                            ?>
                                <tr>
                                    <td>
                                        <img src="/LibraryBorrowingSystem/qr_codes/<?php echo htmlspecialchars($book['qr_code']); ?>.png"
                                             alt="QR"
                                             class="qr-code-image"
                                             onclick="openQRModal('<?php echo htmlspecialchars($book['qr_code']); ?>','<?php echo htmlspecialchars($book['title'], ENT_QUOTES); ?>')">
                                    </td>
                                    <td class="book-title"><?php echo htmlspecialchars($book['title']); ?></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><?php echo $book['total_copies']; ?></td>
                                    <td><strong><?php echo $book['available_copies']; ?></strong></td>
                                    <td><?php echo $book['borrowed_copies']; ?></td>
                                    <td><?php echo $book['lost_copies']; ?></td>
                                    <td>
                                        <div class="inventory-bar">
                                            <div class="progress-bar">
                                                <div class="progress-fill <?php echo $bar_class; ?>" style="width:<?php echo $pct; ?>%"></div>
                                            </div>
                                            <span style="font-size:12px;color:#666;min-width:35px;"><?php echo $pct; ?>%</span>
                                        </div>
                                    </td>
                                    <td><?php echo getStatusBadge($book['available_copies'], $book['total_copies']); ?></td>
                                    <td>
                                        <button class="btn" style="padding:7px 14px;font-size:12px;"
                                            onclick="openAddCopiesModal(<?php echo $book['book_id']; ?>,'<?php echo htmlspecialchars($book['title'], ENT_QUOTES); ?>')">
                                            + Add
                                        </button>
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
                    <input type="text" id="bookTitle" readonly style="background:#f5f5f5;cursor:not-allowed;">
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

    <!-- QR Preview Modal -->
    <div id="qrModal" class="qr-modal">
        <div class="qr-modal-content">
            <h3>QR Code Preview</h3>
            <p id="qrBookInfo" style="color:#666;font-size:13px;"></p>
            <img id="qrImage" src="" alt="QR Code" class="qr-modal-image">
            <button class="btn-secondary" style="margin-top:14px;" onclick="closeQRModal()">Close</button>
        </div>
    </div>

    <script>
        // Add Copies Modal
        function openAddCopiesModal(bookId, bookTitle) {
            document.getElementById('bookId').value      = bookId;
            document.getElementById('bookTitle').value   = bookTitle;
            document.getElementById('copiesToAdd').value = '';
            document.getElementById('addCopiesModal').classList.add('active');
            document.getElementById('copiesToAdd').focus();
        }

        function closeAddCopiesModal() {
            document.getElementById('addCopiesModal').classList.remove('active');
        }

        document.getElementById('addCopiesModal').addEventListener('click', function(e) {
            if (e.target === this) closeAddCopiesModal();
        });

        document.getElementById('copiesToAdd').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); this.closest('form').submit(); }
        });

        // QR Preview Modal
        function openQRModal(qrCode, bookTitle) {
            document.getElementById('qrBookInfo').textContent = 'ID: ' + qrCode + ' | ' + bookTitle;
            document.getElementById('qrImage').src = '/LibraryBorrowingSystem/qr_codes/' + qrCode + '.png';
            document.getElementById('qrModal').classList.add('show');
        }

        function closeQRModal() {
            document.getElementById('qrModal').classList.remove('show');
        }

        document.getElementById('qrModal').addEventListener('click', function(e) {
            if (e.target === this) closeQRModal();
        });
    </script>
</body>
</html>