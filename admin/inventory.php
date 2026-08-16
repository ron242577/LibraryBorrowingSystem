<?php
/**
 * Inventory Management - Admin Panel
 * Add books, import via CSV/XLSX, and monitor real-time inventory.
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

if (!isAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$message = '';
$message_type = '';

$qr_dir = __DIR__ . '/../qr_codes';
if (!is_dir($qr_dir)) {
    mkdir($qr_dir, 0755, true);
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function generateBookQRCode($book_qr_id) {
    global $qr_dir;
    $filename = $book_qr_id . '.png';
    $filepath = $qr_dir . '/' . $filename;

    if (file_exists($filepath)) {
        return 'qr_codes/' . $filename;
    }

    $qr_image_url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($book_qr_id);
    try {
        $image_content = @file_get_contents($qr_image_url, false, stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]));
        if ($image_content !== false) {
            @file_put_contents($filepath, $image_content);
        }
    } catch (Exception $e) {
        logError('Book QR Code generation warning: ' . $e->getMessage());
    }

    return 'qr_codes/' . $filename;
}

function generateUniqueBookQRId($conn) {
    do {
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $code = 'BOOK-' . $date . '-' . $random;

        $stmt = $conn->prepare('SELECT book_id FROM books WHERE qr_code = ? LIMIT 1');
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } while ($exists);

    return $code;
}

function getStatusBadge($available_copies, $total_copies) {
    if ((int)$available_copies <= 0) {
        return '<span class="badge badge-danger">Not Available</span>';
    }
    if ((int)$available_copies <= 2) {
        return '<span class="badge badge-warning">Limited Copy</span>';
    }
    return '<span class="badge badge-success">Available</span>';
}

function normalizeDateValue($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (is_numeric($value) && (float)$value > 25000 && (float)$value < 80000) {
        $timestamp = ((float)$value - 25569) * 86400;
        return gmdate('Y-m-d', (int)$timestamp);
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return false;
    }

    return date('Y-m-d', $timestamp);
}


function normalizeCoAuthors($value) {
    if (is_array($value)) {
        $parts = $value;
    } else {
        $parts = preg_split('/[\r\n;]+/', (string)$value);
    }

    $clean = [];
    foreach ($parts as $part) {
        $name = trim((string)$part);
        if ($name !== '') {
            $clean[] = $name;
        }
    }

    return implode("\n", array_unique($clean));
}

function formatCoAuthorsForDisplay($value) {
    $parts = preg_split('/[\r\n;]+/', (string)$value);
    $clean = [];
    foreach ($parts as $part) {
        $name = trim((string)$part);
        if ($name !== '') {
            $clean[] = $name;
        }
    }
    return implode(', ', $clean);
}

function normalizeHeaderKey($header) {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$header)));
}

function mapBookImportHeader($header) {
    $key = normalizeHeaderKey($header);
    $map = [
        'title' => 'title',
        'booktitle' => 'title',
        'author' => 'author',
        'mainauthor' => 'author',
        'coauthor' => 'co_authors',
        'coauthors' => 'co_authors',
        'coauthoroptional' => 'co_authors',
        'placeofpublication' => 'place_of_publication',
        'publicationplace' => 'place_of_publication',
        'placepublished' => 'place_of_publication',
        'date' => 'publication_date',
        'publicationdate' => 'publication_date',
        'datepublished' => 'publication_date',
        'booknumber' => 'book_number',
        'bookno' => 'book_number',
        'bookpages' => 'book_pages',
        'numberofpages' => 'book_pages',
        'pages' => 'book_pages',
        'sourceoffunds' => 'source_of_funds',
        'sourceoffund' => 'source_of_funds',
        'costprice' => 'cost_price',
        'cost' => 'cost_price',
        'publisher' => 'publisher',
        'edition' => 'edition',
        'volumes' => 'volumes',
        'volume' => 'volumes',
        'class' => 'class',
        'typeofmaterial' => 'type_of_material',
        'materialtype' => 'type_of_material',
        'material' => 'type_of_material',
        'copy' => 'total_copies',
        'numberofcopies' => 'total_copies',
        'locationcollection' => 'location_collection',
        'locationcollectionunderwherethatbook' => 'location_collection',
        'location' => 'location_collection',
        'collection' => 'location_collection',
        'totalcopies' => 'total_copies',
        'stock' => 'total_copies',
        'copies' => 'total_copies'
    ];

    return $map[$key] ?? null;
}

function columnIndexFromCellReference($cell_ref) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper((string)$cell_ref));
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function parseXlsxFile($path) {
    if (!class_exists('ZipArchive')) {
        throw new Exception('XLSX import requires the PHP ZipArchive extension. Upload CSV instead.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception('Unable to open XLSX file.');
    }

    $shared_strings = [];
    $shared_xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($shared_xml !== false) {
        $xml = simplexml_load_string($shared_xml);
        if ($xml) {
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $run) {
                        $text .= (string)$run->t;
                    }
                }
                $shared_strings[] = $text;
            }
        }
    }

    $sheet_xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet_xml === false) {
        $zip->close();
        throw new Exception('The XLSX file must contain a first worksheet.');
    }

    $xml = simplexml_load_string($sheet_xml);
    if (!$xml) {
        $zip->close();
        throw new Exception('Unable to read the first worksheet.');
    }

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $row_values = [];
        foreach ($row->c as $cell) {
            $cell_ref = (string)$cell['r'];
            $index = columnIndexFromCellReference($cell_ref);
            $type = (string)$cell['t'];
            $value = '';

            if ($type === 's') {
                $shared_index = (int)$cell->v;
                $value = $shared_strings[$shared_index] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)$cell->is->t;
            } else {
                $value = (string)$cell->v;
            }

            $row_values[$index] = trim($value);
        }

        if (!empty($row_values)) {
            ksort($row_values);
            $max = max(array_keys($row_values));
            $normalized = [];
            for ($i = 0; $i <= $max; $i++) {
                $normalized[] = $row_values[$i] ?? '';
            }
            $rows[] = $normalized;
        }
    }

    $zip->close();
    return $rows;
}

function parseCsvFile($path) {
    $rows = [];
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw new Exception('Unable to open CSV file.');
    }

    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = array_map('trim', $row);
    }
    fclose($handle);

    return $rows;
}

function rowsToBookData($rows) {
    if (empty($rows)) {
        return [];
    }

    $headers = array_shift($rows);
    $mapped_headers = [];
    foreach ($headers as $index => $header) {
        $field = mapBookImportHeader($header);
        if ($field) {
            $mapped_headers[$index] = $field;
        }
    }

    if (!in_array('title', $mapped_headers, true) || !in_array('author', $mapped_headers, true) || !in_array('book_number', $mapped_headers, true) || !in_array('book_pages', $mapped_headers, true)) {
        throw new Exception('Import file must include headers for Title, Author, Book Number, and Book Pages.');
    }

    $books = [];
    foreach ($rows as $row) {
        $data = [
            'title' => '',
            'author' => '',
            'co_authors' => '',
            'place_of_publication' => '',
            'publication_date' => '',
            'book_number' => '',
            'book_pages' => '',
            'source_of_funds' => '',
            'cost_price' => '',
            'publisher' => '',
            'edition' => '',
            'volumes' => '',
            'class' => '',
            'type_of_material' => '',
            'location_collection' => '',
            'total_copies' => 1
        ];

        $has_content = false;
        foreach ($mapped_headers as $index => $field) {
            $value = trim((string)($row[$index] ?? ''));
            if ($value !== '') {
                $has_content = true;
            }
            $data[$field] = $value;
        }

        if ($has_content) {
            $books[] = $data;
        }
    }

    return $books;
}

function addBookRecord($conn, $data, &$error_message) {
    $title = trim($data['title'] ?? '');
    $author = trim($data['author'] ?? '');
    $co_authors = normalizeCoAuthors($data['co_authors'] ?? '');
    $place_of_publication = trim($data['place_of_publication'] ?? '');
    $publication_date = normalizeDateValue($data['publication_date'] ?? '');
    $book_number = trim($data['book_number'] ?? '');
    $book_pages = (int)($data['book_pages'] ?? 0);
    $source_of_funds = trim($data['source_of_funds'] ?? '');
    $cost_price_raw = trim((string)($data['cost_price'] ?? ''));
    $cost_price = $cost_price_raw === '' ? null : (float)$cost_price_raw;
    $publisher = trim($data['publisher'] ?? '');
    $edition = trim($data['edition'] ?? '');
    $volumes = trim($data['volumes'] ?? '');
    $class = trim($data['class'] ?? '');
    $type_of_material = trim($data['type_of_material'] ?? '');
    $location_collection = trim($data['location_collection'] ?? '');
    $total_copies = isset($data['total_copies']) && trim((string)$data['total_copies']) !== '' ? (int)$data['total_copies'] : 1;

    if ($title === '' || strlen($title) < 3) {
        $error_message = 'Title must be at least 3 characters long.';
        return false;
    }
    if ($author === '' || strlen($author) < 2) {
        $error_message = 'Author is required.';
        return false;
    }
    if ($place_of_publication === '') {
        $error_message = 'Place of publication is required.';
        return false;
    }
    if ($publication_date === null || $publication_date === false) {
        $error_message = 'Valid publication date is required.';
        return false;
    }
    if ($book_number === '') {
        $error_message = 'Book number is required.';
        return false;
    }
    if ($book_pages < 1) {
        $error_message = 'Book pages must be at least 1.';
        return false;
    }
    if ($cost_price_raw !== '' && (!is_numeric($cost_price_raw) || $cost_price < 0)) {
        $error_message = 'Cost price must be a valid non-negative amount.';
        return false;
    }
    if ($type_of_material === '') {
        $error_message = 'Type of material is required.';
        return false;
    }
    if ($location_collection === '') {
        $error_message = 'Location/collection is required.';
        return false;
    }
    if ($total_copies < 1) {
        $total_copies = 1;
    }

    $check = $conn->prepare('SELECT book_id FROM books WHERE book_number = ? LIMIT 1');
    $check->bind_param('s', $book_number);
    $check->execute();
    $duplicate = $check->get_result()->num_rows > 0;
    $check->close();

    if ($duplicate) {
        $error_message = 'Book number already exists: ' . $book_number;
        return false;
    }

    $book_qr_code = generateUniqueBookQRId($conn);
    generateBookQRCode($book_qr_code);

    $status = 'available';
    $available_copies = $total_copies;
    $borrowed_copies = 0;
    $lost_copies = 0;

    $stmt = $conn->prepare('
        INSERT INTO books
            (title, author, co_authors, place_of_publication, publication_date, book_number, book_pages, source_of_funds, cost_price, publisher, edition, volumes, class, type_of_material, location_collection, qr_code, book_status, total_copies, available_copies, borrowed_copies, lost_copies)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->bind_param(
        'ssssssisdssssssssiiii',
        $title,
        $author,
        $co_authors,
        $place_of_publication,
        $publication_date,
        $book_number,
        $book_pages,
        $source_of_funds,
        $cost_price,
        $publisher,
        $edition,
        $volumes,
        $class,
        $type_of_material,
        $location_collection,
        $book_qr_code,
        $status,
        $total_copies,
        $available_copies,
        $borrowed_copies,
        $lost_copies
    );

    if (!$stmt->execute()) {
        $error_message = 'Unable to save the book right now.';
        logError('Book database error: ' . $stmt->error);
        $stmt->close();
        return false;
    }

    $stmt->close();
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try { requireValidCsrf($_POST['csrf_token'] ?? ''); } catch (Throwable $e) { $message = $e->getMessage(); $message_type = 'error'; }
    $action = $message_type === 'error' ? '' : $_POST['action'];

    if ($action === 'add') {
        $error = '';
        $data = [
            'title' => $_POST['title'] ?? '',
            'author' => $_POST['author'] ?? '',
            'co_authors' => $_POST['co_authors'] ?? [],
            'place_of_publication' => $_POST['place_of_publication'] ?? '',
            'publication_date' => $_POST['publication_date'] ?? '',
            'book_number' => $_POST['book_number'] ?? '',
            'book_pages' => $_POST['book_pages'] ?? '',
            'source_of_funds' => $_POST['source_of_funds'] ?? '',
            'cost_price' => $_POST['cost_price'] ?? '',
            'publisher' => $_POST['publisher'] ?? '',
            'edition' => $_POST['edition'] ?? '',
            'volumes' => $_POST['volumes'] ?? '',
            'class' => $_POST['class'] ?? '',
            'type_of_material' => $_POST['type_of_material'] ?? '',
            'location_collection' => $_POST['location_collection'] ?? '',
            'total_copies' => $_POST['total_copies'] ?? 1
        ];

        if (addBookRecord($conn, $data, $error)) {
            $message = 'Book added successfully.';
            $message_type = 'success';
        } else {
            $message = $error;
            $message_type = 'error';
        }
    }

    if ($action === 'import_books') {
        try {
            $validatedUpload = validateSpreadsheetUpload($_FILES['books_file'] ?? []);
            $extension = $validatedUpload['extension'];
            $uploadPath = $validatedUpload['tmp_name'];

            if ($extension === 'xlsx') {
                $rows = parseXlsxFile($uploadPath);
            } else {
                $rows = parseCsvFile($uploadPath);
            }

                $books_to_import = rowsToBookData($rows);
                $added = 0;
                $skipped = 0;
                $errors = [];

                foreach ($books_to_import as $row_index => $book_data) {
                    $error = '';
                    if (addBookRecord($conn, $book_data, $error)) {
                        $added++;
                    } else {
                        $skipped++;
                        if (count($errors) < 8) {
                            $errors[] = 'Row ' . ($row_index + 2) . ': ' . $error;
                        }
                    }
                }

                $message = 'Import complete. Added: ' . $added . '. Skipped: ' . $skipped . '.';
                if (!empty($errors)) {
                    $message .= ' ' . implode(' ', $errors);
                }
                $message_type = $added > 0 ? 'success' : 'error';
        } catch (Exception $e) {
            $safeImportErrors = ['XLSX import requires the PHP ZipArchive extension. You can upload CSV instead.', 'No book records found in the uploaded file.'];
            $message = in_array($e->getMessage(), $safeImportErrors, true) ? $e->getMessage() : 'Import failed. Check that the file matches the current template and try again.';
            $message_type = 'error';
            logError('Book import error: ' . $e->getMessage());
        }
    }

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
                $book_stmt = $conn->prepare('SELECT title, qr_code, total_copies, available_copies FROM books WHERE book_id = ?');
                $book_stmt->bind_param('i', $book_id);
                $book_stmt->execute();
                $book_result = $book_stmt->get_result();

                if ($book_result->num_rows === 0) {
                    $message = 'Book not found.';
                    $message_type = 'error';
                } else {
                    $book = $book_result->fetch_assoc();
                    $new_total = (int)$book['total_copies'] + $copies_to_add;
                    $new_available = (int)$book['available_copies'] + $copies_to_add;
                    $book_status = $new_available > 0 ? 'available' : 'out_of_stock';

                    $update_stmt = $conn->prepare('UPDATE books SET total_copies = ?, available_copies = ?, book_status = ? WHERE book_id = ?');
                    $update_stmt->bind_param('iisi', $new_total, $new_available, $book_status, $book_id);
                    if ($update_stmt->execute()) {
                        $message = 'Added ' . $copies_to_add . ' copy/copies to "' . h($book['title']) . '". New total: ' . $new_total;
                        $message_type = 'success';
                    } else {
                        $message = 'Error updating inventory: ' . $conn->error;
                        $message_type = 'error';
                    }
                    $update_stmt->close();
                }
                $book_stmt->close();
            } catch (Exception $e) {
                $message = 'Error: ' . h($e->getMessage());
                $message_type = 'error';
                logError('Inventory update error: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'remove_copy') {
        $book_id = intval($_POST['book_id'] ?? 0);
        $copies_to_remove = intval($_POST['copies_to_remove'] ?? 0);

        if ($book_id <= 0) {
            $message = 'Invalid book ID.';
            $message_type = 'error';
        } elseif ($copies_to_remove <= 0) {
            $message = 'Enter a valid number of copies to remove.';
            $message_type = 'error';
        } else {
            try {
                $book_stmt = $conn->prepare('SELECT title, total_copies, available_copies, borrowed_copies, lost_copies FROM books WHERE book_id = ? LIMIT 1');
                $book_stmt->bind_param('i', $book_id);
                $book_stmt->execute();
                $book = $book_stmt->get_result()->fetch_assoc();
                $book_stmt->close();

                if (!$book) {
                    $message = 'Book not found.';
                    $message_type = 'error';
                } elseif ((int)$book['total_copies'] <= 1) {
                    $message = 'Only one copy remains. Use Remove Book if you want to remove this title completely.';
                    $message_type = 'error';
                } elseif ((int)$book['available_copies'] <= 0) {
                    $message = 'No available copies can be removed right now. Return a borrowed copy first.';
                    $message_type = 'error';
                } else {
                    $max_removable = min((int)$book['available_copies'], (int)$book['total_copies'] - 1);

                    if ($copies_to_remove > $max_removable) {
                        $message = 'You can remove a maximum of ' . $max_removable . ' cop' . ($max_removable === 1 ? 'y' : 'ies') . ' while keeping this book record.';
                        $message_type = 'error';
                    } else {
                        $new_total = (int)$book['total_copies'] - $copies_to_remove;
                        $new_available = (int)$book['available_copies'] - $copies_to_remove;
                        $book_status = $new_available > 0 ? 'available' : 'out_of_stock';

                        $update_stmt = $conn->prepare('UPDATE books SET total_copies = ?, available_copies = ?, book_status = ? WHERE book_id = ?');
                        $update_stmt->bind_param('iisi', $new_total, $new_available, $book_status, $book_id);

                        if ($update_stmt->execute()) {
                            $message = $copies_to_remove . ' cop' . ($copies_to_remove === 1 ? 'y' : 'ies') . ' removed from "' . $book['title'] . '". Remaining copies: ' . $new_total . '.';
                            $message_type = 'success';
                        } else {
                            $message = 'Unable to remove copies from the book.';
                            $message_type = 'error';
                        }
                        $update_stmt->close();
                    }
                }
            } catch (Exception $e) {
                $message = 'Error removing book copies: ' . $e->getMessage();
                $message_type = 'error';
                logError('Remove book copies error: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'remove_book') {
        $book_id = intval($_POST['book_id'] ?? 0);

        if ($book_id <= 0) {
            $message = 'Invalid book ID.';
            $message_type = 'error';
        } else {
            try {
                $book_stmt = $conn->prepare('SELECT title, qr_code, borrowed_copies FROM books WHERE book_id = ? LIMIT 1');
                $book_stmt->bind_param('i', $book_id);
                $book_stmt->execute();
                $book = $book_stmt->get_result()->fetch_assoc();
                $book_stmt->close();

                if (!$book) {
                    $message = 'Book not found.';
                    $message_type = 'error';
                } elseif ((int)$book['borrowed_copies'] > 0) {
                    $message = 'This book cannot be removed while it is currently borrowed. Return it first.';
                    $message_type = 'error';
                } else {
                    $conn->begin_transaction();
                    try {
                        // Remove old transaction history for this book first so the foreign key does not block deletion.
                        $delete_tx = $conn->prepare('DELETE FROM transactions WHERE book_id = ?');
                        $delete_tx->bind_param('i', $book_id);
                        if (!$delete_tx->execute()) {
                            throw new Exception('Unable to remove related transaction records.');
                        }
                        $delete_tx->close();

                        $delete_book = $conn->prepare('DELETE FROM books WHERE book_id = ?');
                        $delete_book->bind_param('i', $book_id);
                        if (!$delete_book->execute() || $delete_book->affected_rows < 1) {
                            throw new Exception('Unable to remove the book.');
                        }
                        $delete_book->close();
                        $conn->commit();

                        $qr_file = $qr_dir . '/' . basename((string)$book['qr_code']) . '.png';
                        if (is_file($qr_file)) {
                            @unlink($qr_file);
                        }

                        $message = 'Book removed successfully: ' . $book['title'];
                        $message_type = 'success';
                    } catch (Exception $e) {
                        $conn->rollback();
                        throw $e;
                    }
                }
            } catch (Exception $e) {
                $message = 'Error removing book: ' . $e->getMessage();
                $message_type = 'error';
                logError('Remove book error: ' . $e->getMessage());
            }
        }
    }
}

$stats = ['total_titles' => 0, 'total_copies' => 0, 'available_copies' => 0, 'borrowed_copies' => 0, 'lost_copies' => 0];
try {
    $stats_result = $conn->query('SELECT COUNT(*) AS total_titles, COALESCE(SUM(total_copies),0) AS total_copies, COALESCE(SUM(available_copies),0) AS available_copies, COALESCE(SUM(borrowed_copies),0) AS borrowed_copies, COALESCE(SUM(lost_copies),0) AS lost_copies FROM books');
    if ($stats_result) {
        $stats = $stats_result->fetch_assoc();
    }
} catch (Exception $e) {
    logError('Error fetching inventory stats: ' . $e->getMessage());
}

$low_stock_books = [];
try {
    $low_stock_result = $conn->query('SELECT book_id, title, author, book_number, available_copies, total_copies FROM books WHERE available_copies <= 2 ORDER BY available_copies ASC, title ASC LIMIT 10');
    if ($low_stock_result) {
        while ($row = $low_stock_result->fetch_assoc()) {
            $low_stock_books[] = $row;
        }
    }
} catch (Exception $e) {
    logError('Error fetching low stock books: ' . $e->getMessage());
}

$all_books = [];
try {
    $books_result = $conn->query('SELECT book_id, title, author, co_authors, place_of_publication, publication_date, book_number, book_pages, source_of_funds, cost_price, publisher, edition, volumes, class, type_of_material, location_collection, qr_code, total_copies, available_copies, borrowed_copies, lost_copies, book_status, created_at FROM books ORDER BY title ASC');
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
            background: #F3F7FC;
            color: #202A44;
            padding-bottom: 40px;
        }

        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
            margin-top: 100px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .page-header h1 { font-size: 28px; color: #202A44; }
        .page-header p { color: #52618D; font-size: 14px; margin-top: 4px; }

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
            border-left: 5px solid #141F52;
            transition: transform .3s, box-shadow .3s;
        }

        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 14px rgba(0,0,0,.12); }
        .stat-card.blue { border-left-color: #141F52; }
        .stat-card.green { border-left-color: #567D1F; }
        .stat-card.orange { border-left-color: #BB5716; }
        .stat-card.red { border-left-color: #e74c3c; }
        .stat-card .label { font-size: 12px; color: #52618D; text-transform: uppercase; letter-spacing: .5px; font-weight: 600; margin-bottom: 8px; }
        .stat-card .value { font-size: 32px; font-weight: 700; color: #202A44; }
        .stat-card.green .value { color: #567D1F; }
        .stat-card.orange .value { color: #BB5716; }
        .stat-card.red .value { color: #e74c3c; }

        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        .alert-success { background: #EDF5DD; color: #344E15; border: 1px solid #B5D27A; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .section, .table-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .section h3, .table-section h2 {
            font-size: 18px;
            color: #202A44;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #E7EEF7;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 20px;
        }
        .form-row.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .form-group { margin-bottom: 16px; }

        label { display: block; margin-bottom: 8px; color: #202A44; font-weight: 500; font-size: 14px; }

        input[type="text"],
        input[type="file"],
        input[type="number"],
        input[type="date"],
        select {
            width: 100%;
            padding: 11px 14px;
            border: 2px solid #D2E2F6;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color .3s, box-shadow .3s;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #141F52;
            box-shadow: 0 0 0 3px rgba(244,249,22,.35);
        }

        .qr-info, .template-note {
            background: #EDF3FA;
            padding: 14px;
            border-radius: 8px;
            margin-top: 14px;
            border-left: 4px solid #141F52;
            font-size: 13px;
            color: #202A44;
            line-height: 1.6;
        }

        .co-author-list { display: flex; flex-direction: column; gap: 8px; }
        .co-author-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: center; }
        .btn-mini { padding: 9px 12px; font-size: 12px; border-radius: 8px; }
        .help-text { color: #52618D; font-size: 12px; margin-top: 6px; }
        .template-note { background: #F7F9FC; margin-bottom: 16px; }
        .template-note code { background: white; padding: 2px 6px; border-radius: 4px; color: #141F52; font-size: 12px; }

        .button-group { display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap; }

        .btn, button[type="submit"] {
            padding: 11px 22px;
            background: #141F52;
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
        .btn:hover, button[type="submit"]:hover { background: #52618D; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(20, 31, 82,.3); }
        button[type="reset"], .btn-secondary { padding: 11px 22px; background: #D2E2F6; color: #52618D; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background .2s; }
        button[type="reset"]:hover, .btn-secondary:hover { background: #ccc; }

        .alert-panel {
            background: white;
            border-radius: 12px;
            padding: 22px 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            margin-bottom: 28px;
            border-left: 5px solid #BB5716;
        }
        .alert-panel h3 { font-size: 16px; color: #202A44; margin-bottom: 16px; }
        .no-low-stock { color: #567D1F; font-weight: 600; font-size: 14px; }
        .low-stock-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #E7EEF7; gap: 12px; }
        .low-stock-item:last-child { border-bottom: none; }
        .low-stock-item-info h4 { font-size: 14px; color: #202A44; margin-bottom: 2px; }
        .low-stock-item-info p { font-size: 12px; color: #52618D; }
        .low-stock-badge { font-size: 11px; font-weight: 600; background: #FBFDCB; color: #5C5F05; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }

        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1650px; }
        thead { background: #F7F9FC; border-bottom: 2px solid #D2E2F6; }
        th { padding: 12px 14px; text-align: left; font-weight: 600; color: #52618D; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        td { padding: 12px 14px; border-bottom: 1px solid #E7EEF7; font-size: 13px; vertical-align: top; }
        tbody tr:hover { background: #F7F9FC; }
        .book-title { font-weight: 600; color: #202A44; }
        .muted { color: #52618D; font-size: 12px; }

        .badge { display: inline-block; padding: 5px 11px; border-radius: 12px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .badge-success { background: #EDF5DD; color: #344E15; }
        .badge-warning { background: #FBFDCB; color: #5C5F05; }
        .badge-danger { background: #f8d7da; color: #721c24; }

        .qr-code-image { width: 50px; height: 50px; border: 1px solid #D2E2F6; border-radius: 4px; cursor: pointer; transition: transform .2s; display: block; background: white; }
        .qr-code-image:hover { transform: scale(1.12); }
        .empty-message { text-align: center; color: #999; padding: 40px; font-size: 15px; }

        .modal, .qr-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,.55); }
        .modal.active, .qr-modal.show { display: flex; justify-content: center; align-items: center; }
        .modal-content, .qr-modal-content { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 6px 24px rgba(0,0,0,.25); max-width: 420px; width: 90%; }
        .qr-modal-content { text-align: center; max-width: 380px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h2 { font-size: 18px; color: #202A44; }
        .close { background: none; border: none; font-size: 24px; cursor: pointer; color: #999; line-height: 1; padding: 0; }
        .close:hover { color: #202A44; }
        .modal-buttons { display: flex; gap: 10px; margin-top: 20px; }
        .section-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .section-title-row h2 { margin: 0; }
        .table-controls-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .book-modal-content { max-width: 980px; max-height: 90vh; overflow-y: auto; }
        .book-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
        .book-modal-actions .btn-primary, .book-modal-actions .btn-secondary { padding: 10px 18px; }
        .book-modal-panel { display: none; }
        .book-modal-panel.active { display: block; }
        .modal-note { background: #F7F9FC; border-left: 4px solid #141F52; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; color: #52618D; font-size: 13px; line-height: 1.5; }
        .btn-primary { padding: 11px 22px; background: #141F52; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-primary:hover { background: #52618D; }
        .btn-danger { padding: 7px 14px; background: #c0392b; color: white; border: none; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .btn-danger:hover { background: #a93226; }
        .inventory-action-group { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn-details { padding: 7px 14px; background: #52618D; color: white; border: none; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; }
        .btn-details:hover { background: #141F52; }
        .book-details-modal-content { max-width: 760px; max-height: 88vh; overflow-y: auto; }
        .book-details-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .book-detail-item { background: #F7F9FC; border: 1px solid #E7EEF7; border-radius: 9px; padding: 12px 14px; }
        .book-detail-label { color: #52618D; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .45px; margin-bottom: 5px; }
        .book-detail-value { color: #202A44; font-size: 14px; font-weight: 600; line-height: 1.45; overflow-wrap: anywhere; }
        .book-detail-full { grid-column: 1 / -1; }
        .details-qr-row { display: flex; align-items: center; gap: 18px; margin-top: 16px; padding: 14px; background: #F7F9FC; border-radius: 10px; }
        .details-qr-row img { width: 110px; height: 110px; background: #fff; padding: 6px; border: 1px solid #D2E2F6; border-radius: 8px; }
        .remove-choice-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .remove-choice { border: 1px solid #D2E2F6; border-radius: 10px; padding: 16px; background: #F7F9FC; }
        .remove-choice h3 { color: #202A44; font-size: 15px; margin-bottom: 6px; }
        .remove-choice p { color: #52618D; font-size: 12px; line-height: 1.5; min-height: 55px; margin-bottom: 12px; }
        .remove-choice button { width: 100%; }
        .remove-copy-btn { padding: 10px 14px; background: #C17A12; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer; }
        .remove-copy-btn:hover { background: #9D620B; }
        .remove-copy-btn:disabled { background: #BFC6D4; color: #6B7280; cursor: not-allowed; transform: none; box-shadow: none; }
        .remove-book-note { margin-top: 12px; padding: 10px 12px; border-radius: 8px; background: #EDF3FA; color: #52618D; font-size: 12px; line-height: 1.45; }
        .qr-modal-image { max-width: 260px; margin: 18px auto; border: 2px solid #D2E2F6; border-radius: 8px; padding: 8px; background: white; display: block; }
        @media (max-width: 650px) {
            .book-details-grid, .remove-choice-grid { grid-template-columns: 1fr; }
            .book-detail-full { grid-column: auto; }
            .details-qr-row { align-items: flex-start; flex-direction: column; }
        }

        /* ── Inline Add Book Section ── */
        .add-book-section {
            background: #F7F9FC;
            border: 1px solid #D2E2F6;
            border-radius: 12px;
            padding: 20px 25px;
            margin-bottom: 25px;
            margin-top: 10px;
        }
        .add-book-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
        }
        .add-book-toggle h2 {
            font-size: 17px;
            color: #141F52;
            margin: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        .toggle-icon {
            font-size: 20px;
            color: #141F52;
            font-weight: 700;
            transition: transform 0.2s;
            line-height: 1;
        }
        .toggle-icon.open { transform: rotate(45deg); }
        #addBookFormWrapper { margin-top: 18px; }

        /* ── Table Actions Row (search + buttons) ── */
        .table-actions-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .search-filter-group {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .search-input {
            padding: 9px 14px;
            border: 2px solid #D2E2F6;
            border-radius: 8px;
            font-size: 13px;
            width: 230px;
            transition: border-color .2s;
        }
        .search-input:focus { outline: none; border-color: #141F52; box-shadow: 0 0 0 3px rgba(244,249,22,.35); }
        .filter-select {
            padding: 9px 12px;
            border: 2px solid #D2E2F6;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            width: auto;
        }
/* ── Inventory Toolbar ── */

.inventory-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: nowrap;
    margin-bottom: 20px;
}

.inventory-left {
    flex-shrink: 0;
}

.inventory-left h2 {
    margin: 0;
    white-space: nowrap;
}

.inventory-center {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    justify-content: center;
}

.inventory-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}

.search-input {
    width: 260px;
    height: 42px;
}

.filter-select {
    width: 160px;
    height: 42px;
}

.inventory-right .btn-primary,
.inventory-right .btn-secondary {
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* ── Responsive ── */

@media (max-width: 1100px) {

    .inventory-toolbar {
        flex-wrap: wrap;
        align-items: stretch;
    }

    .inventory-center {
        width: 100%;
        justify-content: flex-start;
        flex-wrap: wrap;
    }

    .inventory-right {
        width: 100%;
        flex-wrap: wrap;
    }

    .search-input {
        flex: 1;
        min-width: 220px;
    }
}
        @media (max-width: 900px) {
            .section-title-row, .table-controls-row { flex-direction: column; align-items: stretch; }
            .form-row, .form-row.three { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            th, td { padding: 10px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>

    <div class="container">
        <div class="page-header">
            <div>
                <h1>Inventory Management</h1>
                <p>Add complete book details, import via CSV/XLSX, and monitor real-time library inventory</p>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    showToast(<?php echo json_encode($message); ?>, <?php echo json_encode($message_type ?: 'info'); ?>, 4200);
                });
            </script>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card blue"><div class="label">Total Titles</div><div class="value"><?php echo (int)$stats['total_titles']; ?></div></div>
            <div class="stat-card green"><div class="label">Total Copies</div><div class="value"><?php echo (int)$stats['total_copies']; ?></div></div>
            <div class="stat-card green"><div class="label">Available</div><div class="value"><?php echo (int)$stats['available_copies']; ?></div></div>
            <div class="stat-card orange"><div class="label">Borrowed</div><div class="value"><?php echo (int)$stats['borrowed_copies']; ?></div></div>
            <div class="stat-card red"><div class="label">Lost/Damaged</div><div class="value"><?php echo (int)$stats['lost_copies']; ?></div></div>
        </div>

        <div class="alert-panel">
            <h3>Limited Copies Alert</h3>
            <div class="alert-panel-content">
                <?php if (empty($low_stock_books)): ?>
                    <p class="no-low-stock">All books have sufficient stock</p>
                <?php else: ?>
                    <?php foreach ($low_stock_books as $book): ?>
                        <div class="low-stock-item">
                            <div class="low-stock-item-info">
                                <h4><?php echo h($book['title']); ?></h4>
                                <p><?php echo h($book['author']); ?><?php echo !empty($book['book_number']) ? ' | ' . h($book['book_number']) : ''; ?></p>
                                <p style="color:#e74c3c;font-weight:600;margin-top:2px;">
                                    Available: <?php echo (int)$book['available_copies']; ?>/<?php echo (int)$book['total_copies']; ?>
                                </p>
                            </div>
                            <span class="low-stock-badge">Consider Restocking</span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        

        <div class="table-section">
            <div class="inventory-toolbar">
                <div class="inventory-left">
                    <h2>All Books Inventory</h2>
                </div>
                <div class="inventory-center">
                    <input 
                        type="text"
                        id="searchInput"
                        placeholder="Search title, author, book number..."
                        oninput="filterTable()"
                        class="search-input"
                    >
                    <select 
                        id="statusFilter"
                        onchange="filterTable()"
                        class="filter-select"
                    >
                        <option value="">All Status</option>
                        <option value="Available">Available</option>
                        <option value="Limited Copy">Limited Copy</option>
                        <option value="Not Available">Not Available</option>
                    </select>
                </div>
                <div class="inventory-right">
                    <button 
                        type="button"
                        class="btn-primary"
                        onclick="openAddBookForm()"
                    >
                        Add Book
                    </button>
                    <button 
                        type="button"
                        class="btn-secondary"
                        onclick="openBulkModal()"
                    >
                        Bulk Add Books
                    </button>
                </div>
            </div>

            <?php if (empty($all_books)): ?>
                <div class="empty-message">No books in inventory yet.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>QR</th>
                                <th>Title / Author</th>
                                <th>Book No.</th>
                                <th>Pages</th>
                                <th>Publication</th>
                                <th>Edition / Volume / Class</th>
                                <th>Source / Cost</th>
                                <th>Material</th>
                                <th>Location / Collection</th>
                                <th>Total</th>
                                <th>Available</th>
                                <th>Borrowed</th>
                                <th>Lost</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_books as $book): ?>
                                <tr>
                                    <td>
                                        <img src="/LibraryBorrowingSystem/qr_codes/<?php echo h($book['qr_code']); ?>.png"
                                             alt="QR"
                                             class="qr-code-image"
                                             onclick="openQRModal('<?php echo h($book['qr_code']); ?>','<?php echo h($book['title']); ?>')">
                                    </td>
                                    <td>
                                        <div class="book-title"><?php echo h($book['title']); ?></div>
                                        <div class="muted">Author: <?php echo h($book['author']); ?></div>
                                        <?php if (!empty($book['co_authors'])): ?>
                                            <div class="muted">Co-Author(s): <?php echo h(formatCoAuthorsForDisplay($book['co_authors'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h($book['book_number']); ?></td>
                                    <td><?php echo (int)$book['book_pages']; ?></td>
                                    <td>
                                        <div><?php echo h($book['place_of_publication']); ?></div>
                                        <div class="muted"><?php echo h($book['publication_date']); ?></div>
                                        <?php if (!empty($book['publisher'])): ?><div class="muted">Publisher: <?php echo h($book['publisher']); ?></div><?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo !empty($book['edition']) ? h($book['edition']) : '—'; ?></div>
                                        <div class="muted">Volume: <?php echo !empty($book['volumes']) ? h($book['volumes']) : '—'; ?></div>
                                        <div class="muted">Class: <?php echo !empty($book['class']) ? h($book['class']) : '—'; ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo !empty($book['source_of_funds']) ? h($book['source_of_funds']) : '—'; ?></div>
                                        <div class="muted">Cost: <?php echo $book['cost_price'] !== null ? '₱' . number_format((float)$book['cost_price'], 2) : '—'; ?></div>
                                    </td>
                                    <td><?php echo h($book['type_of_material']); ?></td>
                                    <td><?php echo h($book['location_collection']); ?></td>
                                    <td><?php echo (int)$book['total_copies']; ?></td>
                                    <td><strong><?php echo (int)$book['available_copies']; ?></strong></td>
                                    <td><?php echo (int)$book['borrowed_copies']; ?></td>
                                    <td><?php echo (int)$book['lost_copies']; ?></td>
                                    <td><?php echo getStatusBadge($book['available_copies'], $book['total_copies']); ?></td>
                                    <td>
                                        <?php
                                            $book_modal_data = [
                                                'book_id' => (int)$book['book_id'],
                                                'title' => $book['title'],
                                                'author' => $book['author'],
                                                'co_authors' => formatCoAuthorsForDisplay($book['co_authors']),
                                                'place_of_publication' => $book['place_of_publication'],
                                                'publication_date' => $book['publication_date'],
                                                'book_number' => $book['book_number'],
                                                'book_pages' => (int)$book['book_pages'],
                                                'source_of_funds' => $book['source_of_funds'],
                                                'cost_price' => $book['cost_price'],
                                                'publisher' => $book['publisher'],
                                                'edition' => $book['edition'],
                                                'volumes' => $book['volumes'],
                                                'class' => $book['class'],
                                                'type_of_material' => $book['type_of_material'],
                                                'location_collection' => $book['location_collection'],
                                                'qr_code' => $book['qr_code'],
                                                'total_copies' => (int)$book['total_copies'],
                                                'available_copies' => (int)$book['available_copies'],
                                                'borrowed_copies' => (int)$book['borrowed_copies'],
                                                'lost_copies' => (int)$book['lost_copies'],
                                                'book_status' => $book['book_status'],
                                                'created_at' => $book['created_at']
                                            ];
                                            $book_modal_json = json_encode($book_modal_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                        ?>
                                        <div class="inventory-action-group">
                                            <button class="btn" type="button" style="padding:7px 14px;font-size:12px;"
                                                onclick="openAddCopiesModal(<?php echo (int)$book['book_id']; ?>,'<?php echo h($book['title']); ?>')">
                                                + Add
                                            </button>
                                            <button type="button" class="btn-danger"
                                                data-book='<?php echo h($book_modal_json); ?>'
                                                onclick="openRemoveBookModal(this)">
                                                Remove
                                            </button>
                                            <button type="button" class="btn-details"
                                                data-book='<?php echo h($book_modal_json); ?>'
                                                onclick="openBookDetailsModal(this)">
                                                Details
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div id="addBookSection" class="add-book-section" style="display:none;">
            <div class="add-book-toggle" onclick="toggleAddBookForm()">
                <h2>Add New Book</h2>
                <span class="toggle-icon open" id="addBookToggleIcon">×</span>
            </div>
            <div id="addBookFormWrapper">
                <div class="modal-note" style="margin-bottom:16px;">Fill out the complete book details below. A unique QR code will be generated automatically after saving.</div>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="add">

                    <div class="form-row">
                        <div class="form-group">
                            <label for="title">Title *</label>
                            <input type="text" id="title" name="title" required placeholder="e.g. Noli Me Tangere">
                        </div>
                        <div class="form-group">
                            <label for="author">Author *</label>
                            <input type="text" id="author" name="author" required placeholder="e.g. Jose Rizal">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Co-Author(s) Optional</label>
                            <div id="coAuthorList" class="co-author-list">
                                <div class="co-author-row">
                                    <input type="text" name="co_authors[]" placeholder="Enter co-author name">
                                    <button type="button" class="btn-secondary btn-mini" onclick="removeCoAuthorField(this)">Remove</button>
                                </div>
                            </div>
                            <button type="button" class="btn btn-mini" style="margin-top:8px;" onclick="addCoAuthorField()">+ Add Co-Author</button>
                            <div class="help-text">Add as many co-authors as needed.</div>
                        </div>
                        <div class="form-group">
                            <label for="place_of_publication">Place of Publication *</label>
                            <input type="text" id="place_of_publication" name="place_of_publication" required placeholder="e.g. Manila">
                        </div>
                    </div>

                    <div class="form-row three">
                        <div class="form-group">
                            <label for="publication_date">Date Published *</label>
                            <input type="date" id="publication_date" name="publication_date" required>
                        </div>
                        <div class="form-group">
                            <label for="book_number">Book Number *</label>
                            <input type="text" id="book_number" name="book_number" required placeholder="e.g. BOOK-0001">
                        </div>
                        <div class="form-group">
                            <label for="book_pages">Book Pages *</label>
                            <input type="number" id="book_pages" name="book_pages" min="1" required placeholder="e.g. 320">
                        </div>
                    </div>

                    <div class="form-row three">
                        <div class="form-group">
                            <label for="type_of_material">Type of Material *</label>
                            <input type="text" id="type_of_material" name="type_of_material" required placeholder="e.g. Book, Thesis, Magazine">
                        </div>
                        <div class="form-group">
                            <label for="location_collection">Location - Collection *</label>
                            <input type="text" id="location_collection" name="location_collection" required placeholder="e.g. Filipiniana Section / Shelf A1">
                        </div>
                        <div class="form-group">
                            <label for="total_copies">Number of Copies *</label>
                            <select id="total_copies" name="total_copies" required>
                                <?php for ($i = 1; $i <= 100; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row three">
                        <div class="form-group">
                            <label for="source_of_funds">Source of Funds</label>
                            <input type="text" id="source_of_funds" name="source_of_funds" placeholder="Optional">
                        </div>
                        <div class="form-group">
                            <label for="cost_price">Cost Price</label>
                            <input type="number" id="cost_price" name="cost_price" min="0" step="0.01" placeholder="Optional">
                        </div>
                        <div class="form-group">
                            <label for="publisher">Publisher</label>
                            <input type="text" id="publisher" name="publisher" placeholder="Optional">
                        </div>
                    </div>

                    <div class="form-row three">
                        <div class="form-group">
                            <label for="edition">Edition</label>
                            <input type="text" id="edition" name="edition" placeholder="Optional">
                        </div>
                        <div class="form-group">
                            <label for="volumes">Volumes</label>
                            <input type="text" id="volumes" name="volumes" placeholder="Optional">
                        </div>
                        <div class="form-group">
                            <label for="class">Class</label>
                            <input type="text" id="class" name="class" placeholder="Optional">
                        </div>
                    </div>

                    <div class="qr-info">
                        <strong>QR Code &amp; Stock:</strong> A unique QR code will be automatically generated. The selected number of copies will be added as available stock.
                    </div>

                    <div class="button-group">
                        <button type="submit">Add Book</button>
                        <button type="reset">Clear</button>
                        <button type="button" class="btn-secondary" onclick="closeAddBookForm()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        
    </div>

    <!-- Bulk Add Books Modal -->
    <div id="bulkBooksModal" class="modal">
        <div class="modal-content" style="max-width:520px;width:92%;">
            <div class="modal-header">
                <h2>Bulk Add Books</h2>
                <button class="close" type="button" onclick="closeBulkModal()">&times;</button>
            </div>
            <div class="template-note" style="margin-bottom:16px;">
                Upload a CSV or XLSX file with these headers:<br>
                <code>Title, Author, Co-Authors, Place of Publication, Date, Book Number, Book Pages, Source of Funds, Cost Price, Publisher, Edition, Volumes, Class, Type of Material, Location Collection, Total Copies</code><br>
                Book Number and Book Pages are required. Source of Funds, Cost Price, Publisher, Edition, Volumes, and Class are optional. Co-authors can be separated with semicolons or placed on separate lines.
            </div>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="import_books">
                <div class="form-group">
                    <label for="books_file">Select CSV/XLSX File *</label>
                    <input type="file" id="books_file" name="books_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                </div>
                <div class="modal-buttons">
                    <button type="submit" class="btn-primary">Import Books</button>
                    <button type="button" class="btn-secondary" onclick="closeBulkModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div id="addCopiesModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add Book Copies</h2>
                <button class="close" onclick="closeAddCopiesModal()">&times;</button>
            </div>
            <form method="POST">
                    <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add_copies">
                <input type="hidden" id="bookId" name="book_id">
                <div class="form-group">
                    <label>Book Title</label>
                    <input type="text" id="bookTitle" readonly style="background:#F3F7FC;cursor:not-allowed;">
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

    <div id="removeBookModal" class="modal">
        <div class="modal-content" style="max-width:620px;">
            <div class="modal-header">
                <h2>Remove Book</h2>
                <button class="close" type="button" onclick="closeRemoveBookModal()">&times;</button>
            </div>
            <div class="modal-note">
                <strong id="removeBookTitle">Selected Book</strong><br>
                Choose how many available copies to remove, or permanently remove the entire book title.
            </div>
            <div class="remove-choice-grid">
                <div class="remove-choice">
                    <h3>Remove Copies</h3>
                    <p>Choose the number of available physical copies to remove while keeping the book record, QR code, and at least one copy in the system.</p>
                    <form method="POST" id="removeCopyForm">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="remove_copy">
                        <input type="hidden" name="book_id" id="removeCopyBookId">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label for="copiesToRemove">Number of Copies to Remove</label>
                            <input type="number" name="copies_to_remove" id="copiesToRemove" min="1" value="1" required>
                            <small id="removeCopyLimitText" style="display:block;margin-top:6px;color:#52618D;"></small>
                        </div>
                        <button type="button" id="removeCopyButton" class="remove-copy-btn" onclick="confirmRemoveCopy()">Remove Copies</button>
                    </form>
                </div>
                <div class="remove-choice">
                    <h3>Remove Book</h3>
                    <p>Permanently removes the whole book title, its inventory record, QR code, and related old transaction history.</p>
                    <form method="POST" id="removeEntireBookForm">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="remove_book">
                        <input type="hidden" name="book_id" id="removeEntireBookId">
                        <button type="button" class="btn-danger" style="width:100%;padding:10px 14px;font-size:13px;" onclick="confirmRemoveEntireBook()">Remove Book</button>
                    </form>
                </div>
            </div>
            <div id="removeBookAvailabilityNote" class="remove-book-note"></div>
            <div class="modal-buttons" style="justify-content:flex-end;">
                <button type="button" class="btn-secondary" onclick="closeRemoveBookModal()">Cancel</button>
            </div>
        </div>
    </div>

    <div id="bookDetailsModal" class="modal">
        <div class="modal-content book-details-modal-content">
            <div class="modal-header">
                <h2>Book Details</h2>
                <button class="close" type="button" onclick="closeBookDetailsModal()">&times;</button>
            </div>
            <div class="book-details-grid">
                <div class="book-detail-item book-detail-full">
                    <div class="book-detail-label">Title</div>
                    <div class="book-detail-value" id="detailTitle">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Author</div>
                    <div class="book-detail-value" id="detailAuthor">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Co-Authors</div>
                    <div class="book-detail-value" id="detailCoAuthors">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Book Number</div>
                    <div class="book-detail-value" id="detailBookNumber">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Book Pages</div>
                    <div class="book-detail-value" id="detailBookPages">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Place of Publication</div>
                    <div class="book-detail-value" id="detailPublicationPlace">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Publication Date</div>
                    <div class="book-detail-value" id="detailPublicationDate">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Publisher</div>
                    <div class="book-detail-value" id="detailPublisher">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Edition</div>
                    <div class="book-detail-value" id="detailEdition">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Volumes</div>
                    <div class="book-detail-value" id="detailVolumes">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Class</div>
                    <div class="book-detail-value" id="detailClass">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Source of Funds</div>
                    <div class="book-detail-value" id="detailSourceFunds">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Cost Price</div>
                    <div class="book-detail-value" id="detailCostPrice">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Type of Material</div>
                    <div class="book-detail-value" id="detailMaterial">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Location / Collection</div>
                    <div class="book-detail-value" id="detailLocation">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Total Copies</div>
                    <div class="book-detail-value" id="detailTotalCopies">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Available Copies</div>
                    <div class="book-detail-value" id="detailAvailableCopies">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Borrowed Copies</div>
                    <div class="book-detail-value" id="detailBorrowedCopies">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Lost Copies</div>
                    <div class="book-detail-value" id="detailLostCopies">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Status</div>
                    <div class="book-detail-value" id="detailStatus">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Date Added</div>
                    <div class="book-detail-value" id="detailCreatedAt">—</div>
                </div>
            </div>
            <div class="details-qr-row">
                <img id="detailQrImage" src="" alt="Book QR Code">
                <div>
                    <div class="book-detail-label">QR Code</div>
                    <div class="book-detail-value" id="detailQrCode">—</div>
                    <a id="detailQrDownload" class="btn-primary" href="#" style="text-decoration:none;display:inline-block;margin-top:10px;padding:9px 14px;">Download QR</a>
                </div>
            </div>
            <div class="modal-buttons" style="justify-content:flex-end;">
                <button type="button" class="btn-secondary" onclick="closeBookDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <div id="qrModal" class="qr-modal">
        <div class="qr-modal-content">
            <h3>QR Code Preview</h3>
            <p id="qrBookInfo" style="color:#666;font-size:13px;"></p>
            <img id="qrImage" src="" alt="QR Code" class="qr-modal-image">
            <div style="margin-top:14px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                <a id="downloadBookQrBtn" href="#" download class="btn-primary" style="text-decoration:none;display:inline-block;">Download QR</a>
                <button class="btn-secondary" onclick="closeQRModal()">Close</button>
            </div>
        </div>
    </div>

    <script>

        /* ── Inline Add Book Form ── */
        function openAddBookForm() {
            const section = document.getElementById('addBookSection');
            section.style.display = 'block';
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            setTimeout(function() {
                const input = document.getElementById('title');
                if (input) input.focus();
            }, 400);
        }

        function closeAddBookForm() {
            document.getElementById('addBookSection').style.display = 'none';
        }

        function toggleAddBookForm() {
            const body = document.getElementById('addBookFormWrapper');
            const icon = document.getElementById('addBookToggleIcon');
            const isVisible = body.style.display !== 'none';
            body.style.display = isVisible ? 'none' : 'block';
            icon.classList.toggle('open', !isVisible);
        }

        <?php if ($message_type === 'error' && isset($_POST['action']) && $_POST['action'] === 'add'): ?>
        document.addEventListener('DOMContentLoaded', function() { openAddBookForm(); });
        <?php endif; ?>
        <?php if ($message_type === 'success' && isset($_POST['action']) && $_POST['action'] === 'add'): ?>
        document.addEventListener('DOMContentLoaded', function() { closeAddBookForm(); });
        <?php endif; ?>

        /* ── Bulk Add Modal ── */
        function openBulkModal() {
            document.getElementById('bulkBooksModal').classList.add('active');
        }

        function closeBulkModal() {
            document.getElementById('bulkBooksModal').classList.remove('active');
        }

        document.getElementById('bulkBooksModal').addEventListener('click', function(e) {
            if (e.target === this) closeBulkModal();
        });

        /* ── Co-Author Fields ── */
        function addCoAuthorField() {
            const list = document.getElementById('coAuthorList');
            const row = document.createElement('div');
            row.className = 'co-author-row';
            row.innerHTML = '<input type="text" name="co_authors[]" placeholder="Enter co-author name"><button type="button" class="btn-secondary btn-mini" onclick="removeCoAuthorField(this)">Remove</button>';
            list.appendChild(row);
            row.querySelector('input').focus();
        }

        function removeCoAuthorField(button) {
            const list = document.getElementById('coAuthorList');
            const rows = list.querySelectorAll('.co-author-row');
            if (rows.length === 1) {
                rows[0].querySelector('input').value = '';
                return;
            }
            button.closest('.co-author-row').remove();
        }

        /* ── Search & Filter ── */
        function filterTable() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            const status = document.getElementById('statusFilter').value.toLowerCase();
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const badge = row.querySelector('.badge');
                const badgeText = badge ? badge.textContent.trim().toLowerCase() : '';
                const matchSearch = !search || text.includes(search);
                const matchStatus = !status || badgeText.includes(status.toLowerCase());
                row.style.display = matchSearch && matchStatus ? '' : 'none';
            });
        }

        /* ── Add Copies Modal ── */
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

        document.getElementById('addCopiesModal').addEventListener('click', function(e) {
            if (e.target === this) closeAddCopiesModal();
        });

        document.getElementById('copiesToAdd').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.closest('form').submit();
            }
        });

        /* ── Remove Book Modal ── */
        let selectedRemovalBook = null;

        function parseBookButtonData(button) {
            try {
                return JSON.parse(button.dataset.book || '{}');
            } catch (error) {
                console.error('Unable to read book data:', error);
                return null;
            }
        }

        function openRemoveBookModal(button) {
            const book = parseBookButtonData(button);
            if (!book) {
                showToast('Unable to load the selected book information.', 'error');
                return;
            }

            selectedRemovalBook = book;
            document.getElementById('removeBookTitle').textContent = book.title || 'Selected Book';
            document.getElementById('removeCopyBookId').value = book.book_id;
            document.getElementById('removeEntireBookId').value = book.book_id;

            const removeCopyButton = document.getElementById('removeCopyButton');
            const copiesToRemoveInput = document.getElementById('copiesToRemove');
            const removeCopyLimitText = document.getElementById('removeCopyLimitText');
            const total = Number(book.total_copies || 0);
            const available = Number(book.available_copies || 0);
            const borrowed = Number(book.borrowed_copies || 0);
            const maxRemovable = Math.max(0, Math.min(available, total - 1));

            let note = 'Total: ' + total + ' | Available: ' + available + ' | Borrowed: ' + borrowed;
            let canRemoveCopy = maxRemovable > 0;

            if (total <= 1) {
                note += '. Only one copy remains, so use Remove Book to remove the title completely.';
            } else if (available <= 0) {
                note += '. No available copy can be removed until a borrowed copy is returned.';
            } else {
                note += '. You can remove up to ' + maxRemovable + ' available cop' + (maxRemovable === 1 ? 'y' : 'ies') + ' while keeping this title.';
            }

            copiesToRemoveInput.min = 1;
            copiesToRemoveInput.max = Math.max(1, maxRemovable);
            copiesToRemoveInput.value = canRemoveCopy ? 1 : '';
            copiesToRemoveInput.disabled = !canRemoveCopy;
            removeCopyLimitText.textContent = canRemoveCopy ? ('Maximum removable: ' + maxRemovable) : 'No copies can be removed right now.';
            removeCopyButton.disabled = !canRemoveCopy;
            document.getElementById('removeBookAvailabilityNote').textContent = note;
            document.getElementById('removeBookModal').classList.add('active');
        }

        function closeRemoveBookModal() {
            document.getElementById('removeBookModal').classList.remove('active');
            selectedRemovalBook = null;
        }

        function confirmRemoveCopy() {
            if (!selectedRemovalBook) return;

            const copiesInput = document.getElementById('copiesToRemove');
            const copiesToRemove = Number(copiesInput.value || 0);
            const total = Number(selectedRemovalBook.total_copies || 0);
            const available = Number(selectedRemovalBook.available_copies || 0);
            const maxRemovable = Math.max(0, Math.min(available, total - 1));

            if (!Number.isInteger(copiesToRemove) || copiesToRemove < 1 || copiesToRemove > maxRemovable) {
                showToast('Enter a number from 1 to ' + maxRemovable + ' copies.', 'error');
                copiesInput.focus();
                return;
            }

            showConfirmModal({
                title: 'Remove Copies',
                message: 'Remove ' + copiesToRemove + ' available cop' + (copiesToRemove === 1 ? 'y' : 'ies') + ' of "' + selectedRemovalBook.title + '"?',
                confirmText: 'Remove ' + copiesToRemove,
                cancelText: 'Cancel',
                danger: true
            }).then(function(confirmed) {
                if (!confirmed) return;
                closeRemoveBookModal();
                HTMLFormElement.prototype.submit.call(document.getElementById('removeCopyForm'));
            });
        }

        function confirmRemoveEntireBook() {
            if (!selectedRemovalBook) return;
            if (Number(selectedRemovalBook.borrowed_copies || 0) > 0) {
                showToast('This book is currently borrowed. Return all borrowed copies before removing the entire book.', 'error');
                return;
            }
            showConfirmModal({
                title: 'Remove Entire Book',
                message: 'Permanently remove "' + selectedRemovalBook.title + '" and its related old transaction history?',
                confirmText: 'Remove Book',
                cancelText: 'Cancel',
                danger: true
            }).then(function(confirmed) {
                if (!confirmed) return;
                closeRemoveBookModal();
                HTMLFormElement.prototype.submit.call(document.getElementById('removeEntireBookForm'));
            });
        }

        document.getElementById('removeBookModal').addEventListener('click', function(e) {
            if (e.target === this) closeRemoveBookModal();
        });

        /* ── Book Details Modal ── */
        function detailText(value) {
            return value !== null && value !== undefined && String(value).trim() !== '' ? String(value) : '—';
        }

        function openBookDetailsModal(button) {
            const book = parseBookButtonData(button);
            if (!book) {
                showToast('Unable to load the selected book details.', 'error');
                return;
            }

            document.getElementById('detailTitle').textContent = detailText(book.title);
            document.getElementById('detailAuthor').textContent = detailText(book.author);
            document.getElementById('detailCoAuthors').textContent = detailText(book.co_authors);
            document.getElementById('detailBookNumber').textContent = detailText(book.book_number);
            document.getElementById('detailBookPages').textContent = detailText(book.book_pages);
            document.getElementById('detailPublicationPlace').textContent = detailText(book.place_of_publication);
            document.getElementById('detailPublicationDate').textContent = detailText(book.publication_date);
            document.getElementById('detailPublisher').textContent = detailText(book.publisher);
            document.getElementById('detailEdition').textContent = detailText(book.edition);
            document.getElementById('detailVolumes').textContent = detailText(book.volumes);
            document.getElementById('detailClass').textContent = detailText(book.class);
            document.getElementById('detailSourceFunds').textContent = detailText(book.source_of_funds);
            document.getElementById('detailCostPrice').textContent = book.cost_price !== null && book.cost_price !== '' ? '₱' + Number(book.cost_price).toFixed(2) : '—';
            document.getElementById('detailMaterial').textContent = detailText(book.type_of_material);
            document.getElementById('detailLocation').textContent = detailText(book.location_collection);
            document.getElementById('detailTotalCopies').textContent = detailText(book.total_copies);
            document.getElementById('detailAvailableCopies').textContent = detailText(book.available_copies);
            document.getElementById('detailBorrowedCopies').textContent = detailText(book.borrowed_copies);
            document.getElementById('detailLostCopies').textContent = detailText(book.lost_copies);
            document.getElementById('detailStatus').textContent = Number(book.available_copies || 0) > 0 ? 'Available' : 'Not Available';
            document.getElementById('detailCreatedAt').textContent = detailText(book.created_at);
            document.getElementById('detailQrCode').textContent = detailText(book.qr_code);
            document.getElementById('detailQrImage').src = '/LibraryBorrowingSystem/qr_codes/' + encodeURIComponent(book.qr_code) + '.png';
            document.getElementById('detailQrDownload').href = '/LibraryBorrowingSystem/download_qr.php?code=' + encodeURIComponent(book.qr_code);

            document.getElementById('bookDetailsModal').classList.add('active');
        }

        function closeBookDetailsModal() {
            document.getElementById('bookDetailsModal').classList.remove('active');
        }

        document.getElementById('bookDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) closeBookDetailsModal();
        });

        /* ── QR Modal ── */
        function openQRModal(qrCode, bookTitle) {
            const filePath = '/LibraryBorrowingSystem/qr_codes/' + qrCode + '.png';
            const safeTitle = (bookTitle || 'book').replace(/[^a-z0-9-_]+/gi, '_');
            document.getElementById('qrBookInfo').textContent = 'ID: ' + qrCode + ' | ' + bookTitle;
            document.getElementById('qrImage').src = filePath;
            const downloadBtn = document.getElementById('downloadBookQrBtn');
            downloadBtn.href = '/LibraryBorrowingSystem/download_qr.php?code=' + encodeURIComponent(qrCode);
            downloadBtn.removeAttribute('download');
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
