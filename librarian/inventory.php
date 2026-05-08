<?php
/**
 * Inventory Management - Librarian Panel
 * Add books, import via CSV/XLSX, and monitor real-time inventory.
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

if (!isLibrarian() && !isSuperAdmin()) {
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
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
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
        $random = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 5));
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
        'callnumberofbook' => 'call_number',
        'callnumber' => 'call_number',
        'callno' => 'call_number',
        'accessionnumberbarcodenumber' => 'accession_barcode_number',
        'accessionbarcode' => 'accession_barcode_number',
        'accessionbarcodenumber' => 'accession_barcode_number',
        'accessionnumber' => 'accession_barcode_number',
        'barcodenumber' => 'accession_barcode_number',
        'barcode' => 'accession_barcode_number',
        'typeofmaterial' => 'type_of_material',
        'materialtype' => 'type_of_material',
        'material' => 'type_of_material',
        'volumecopy' => 'total_copies',
        'volume' => 'total_copies',
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

    if (!in_array('title', $mapped_headers, true) || !in_array('author', $mapped_headers, true) || !in_array('accession_barcode_number', $mapped_headers, true)) {
        throw new Exception('Import file must include headers for Title, Author, and Accession Number Bar Code Number.');
    }

    $books = [];
    foreach ($rows as $row) {
        $data = [
            'title' => '',
            'author' => '',
            'co_authors' => '',
            'place_of_publication' => '',
            'publication_date' => '',
            'call_number' => '',
            'accession_barcode_number' => '',
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
    $call_number = trim($data['call_number'] ?? '');
    $accession_barcode_number = trim($data['accession_barcode_number'] ?? '');
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
    if ($call_number === '') {
        $error_message = 'Call number of book is required.';
        return false;
    }
    if ($accession_barcode_number === '') {
        $error_message = 'Accession number / bar code number is required.';
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

    $check = $conn->prepare('SELECT book_id FROM books WHERE accession_barcode_number = ? LIMIT 1');
    $check->bind_param('s', $accession_barcode_number);
    $check->execute();
    $duplicate = $check->get_result()->num_rows > 0;
    $check->close();

    if ($duplicate) {
        $error_message = 'Accession number / bar code number already exists: ' . $accession_barcode_number;
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
            (title, author, co_authors, place_of_publication, publication_date, call_number, accession_barcode_number, type_of_material, location_collection, qr_code, book_status, total_copies, available_copies, borrowed_copies, lost_copies)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->bind_param(
        'sssssssssssiiii',
        $title,
        $author,
        $co_authors,
        $place_of_publication,
        $publication_date,
        $call_number,
        $accession_barcode_number,
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
        $error_message = 'Database error: ' . $stmt->error;
        $stmt->close();
        return false;
    }

    $stmt->close();
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $error = '';
        $data = [
            'title' => $_POST['title'] ?? '',
            'author' => $_POST['author'] ?? '',
            'co_authors' => $_POST['co_authors'] ?? [],
            'place_of_publication' => $_POST['place_of_publication'] ?? '',
            'publication_date' => $_POST['publication_date'] ?? '',
            'call_number' => $_POST['call_number'] ?? '',
            'accession_barcode_number' => $_POST['accession_barcode_number'] ?? '',
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
        if (!isset($_FILES['books_file']) || $_FILES['books_file']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Please choose a CSV or XLSX file to import.';
            $message_type = 'error';
        } else {
            $file = $_FILES['books_file'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            try {
                if ($extension === 'xlsx') {
                    $rows = parseXlsxFile($file['tmp_name']);
                } elseif ($extension === 'csv') {
                    $rows = parseCsvFile($file['tmp_name']);
                } else {
                    throw new Exception('Only CSV and XLSX files are supported.');
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
                $message = 'Import failed: ' . $e->getMessage();
                $message_type = 'error';
                logError('Book import error: ' . $e->getMessage());
            }
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
    $low_stock_result = $conn->query('SELECT book_id, title, author, accession_barcode_number, available_copies, total_copies FROM books WHERE available_copies <= 2 ORDER BY available_copies ASC, title ASC LIMIT 10');
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
    $books_result = $conn->query('SELECT book_id, title, author, co_authors, place_of_publication, publication_date, call_number, accession_barcode_number, type_of_material, location_collection, qr_code, total_copies, available_copies, borrowed_copies, lost_copies, book_status, created_at FROM books ORDER BY title ASC');
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

        .page-header h1 { font-size: 28px; color: #2c3e50; }
        .page-header p { color: #7f8c8d; font-size: 14px; margin-top: 4px; }

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
        .stat-card.blue { border-left-color: #003366; }
        .stat-card.green { border-left-color: #27ae60; }
        .stat-card.orange { border-left-color: #f39c12; }
        .stat-card.red { border-left-color: #e74c3c; }
        .stat-card .label { font-size: 12px; color: #7f8c8d; text-transform: uppercase; letter-spacing: .5px; font-weight: 600; margin-bottom: 8px; }
        .stat-card .value { font-size: 32px; font-weight: 700; color: #2c3e50; }
        .stat-card.green .value { color: #27ae60; }
        .stat-card.orange .value { color: #f39c12; }
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
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
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
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px 20px;
        }
        .form-row.three { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .form-group { margin-bottom: 16px; }

        label { display: block; margin-bottom: 8px; color: #2c3e50; font-weight: 500; font-size: 14px; }

        input[type="text"],
        input[type="file"],
        input[type="number"],
        input[type="date"],
        select {
            width: 100%;
            padding: 11px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color .3s, box-shadow .3s;
        }

        input:focus, select:focus {
            outline: none;
            border-color: #003366;
            box-shadow: 0 0 8px rgba(0,51,102,.2);
        }

        .qr-info, .template-note {
            background: #eef4ff;
            padding: 14px;
            border-radius: 8px;
            margin-top: 14px;
            border-left: 4px solid #003366;
            font-size: 13px;
            color: #2c3e50;
            line-height: 1.6;
        }

        .co-author-list { display: flex; flex-direction: column; gap: 8px; }
        .co-author-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: center; }
        .btn-mini { padding: 9px 12px; font-size: 12px; border-radius: 8px; }
        .help-text { color: #7f8c8d; font-size: 12px; margin-top: 6px; }
        .template-note { background: #f8f9fa; margin-bottom: 16px; }
        .template-note code { background: white; padding: 2px 6px; border-radius: 4px; color: #003366; font-size: 12px; }

        .button-group { display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap; }

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
        .btn:hover, button[type="submit"]:hover { background: #002244; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,51,102,.3); }
        button[type="reset"], .btn-secondary { padding: 11px 22px; background: #e0e0e0; color: #555; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background .2s; }
        button[type="reset"]:hover, .btn-secondary:hover { background: #ccc; }

        .alert-panel {
            background: white;
            border-radius: 12px;
            padding: 22px 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            margin-bottom: 28px;
            border-left: 5px solid #f39c12;
        }
        .alert-panel h3 { font-size: 16px; color: #2c3e50; margin-bottom: 16px; }
        .no-low-stock { color: #27ae60; font-weight: 600; font-size: 14px; }
        .low-stock-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f0f0f0; gap: 12px; }
        .low-stock-item:last-child { border-bottom: none; }
        .low-stock-item-info h4 { font-size: 14px; color: #2c3e50; margin-bottom: 2px; }
        .low-stock-item-info p { font-size: 12px; color: #7f8c8d; }
        .low-stock-badge { font-size: 11px; font-weight: 600; background: #fff3cd; color: #856404; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }

        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 1220px; }
        thead { background: #f8f9fa; border-bottom: 2px solid #e0e0e0; }
        th { padding: 12px 14px; text-align: left; font-weight: 600; color: #555; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; }
        td { padding: 12px 14px; border-bottom: 1px solid #f0f0f0; font-size: 13px; vertical-align: top; }
        tbody tr:hover { background: #f8f9fa; }
        .book-title { font-weight: 600; color: #2c3e50; }
        .muted { color: #7f8c8d; font-size: 12px; }

        .badge { display: inline-block; padding: 5px 11px; border-radius: 12px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }

        .qr-code-image { width: 50px; height: 50px; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; transition: transform .2s; display: block; background: white; }
        .qr-code-image:hover { transform: scale(1.12); }
        .empty-message { text-align: center; color: #999; padding: 40px; font-size: 15px; }

        .modal, .qr-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,.55); }
        .modal.active, .qr-modal.show { display: flex; justify-content: center; align-items: center; }
        .modal-content, .qr-modal-content { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 6px 24px rgba(0,0,0,.25); max-width: 420px; width: 90%; }
        .qr-modal-content { text-align: center; max-width: 380px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h2 { font-size: 18px; color: #2c3e50; }
        .close { background: none; border: none; font-size: 24px; cursor: pointer; color: #999; line-height: 1; padding: 0; }
        .close:hover { color: #333; }
        .modal-buttons { display: flex; gap: 10px; margin-top: 20px; }
        .section-title-row { display: flex; align-items: center; justify-content: space-between; gap: 15px; margin-bottom: 20px; }
        .section-title-row h2 { margin: 0; }
        .book-modal-content { max-width: 980px; max-height: 90vh; overflow-y: auto; }
        .book-modal-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
        .book-modal-actions .btn-primary, .book-modal-actions .btn-secondary { padding: 10px 18px; }
        .book-modal-panel { display: none; }
        .book-modal-panel.active { display: block; }
        .modal-note { background: #f8f9fa; border-left: 4px solid #003366; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; color: #555; font-size: 13px; line-height: 1.5; }
        .btn-primary { padding: 11px 22px; background: #003366; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .btn-primary:hover { background: #002244; }
        .qr-modal-image { max-width: 260px; margin: 18px auto; border: 2px solid #ddd; border-radius: 8px; padding: 8px; background: white; display: block; }

        @media (max-width: 900px) {
            .section-title-row { flex-direction: column; align-items: stretch; }
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
            <div class="alert alert-<?php echo h($message_type); ?>">
                <span><?php echo $message_type === 'success' ? '✓' : '✕'; ?></span>
                <span><?php echo h($message); ?></span>
            </div>
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
                                <p><?php echo h($book['author']); ?><?php echo !empty($book['accession_barcode_number']) ? ' | ' . h($book['accession_barcode_number']) : ''; ?></p>
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
            <div class="section-title-row">
                <h2>All Books Inventory</h2>
                <button type="button" class="btn-primary" onclick="openBookModal()">Add Book</button>
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
                                <th>Publication</th>
                                <th>Call No.</th>
                                <th>Accession / Barcode</th>
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
                                    <td>
                                        <div><?php echo h($book['place_of_publication']); ?></div>
                                        <div class="muted"><?php echo h($book['publication_date']); ?></div>
                                    </td>
                                    <td><?php echo h($book['call_number']); ?></td>
                                    <td><?php echo h($book['accession_barcode_number']); ?></td>
                                    <td><?php echo h($book['type_of_material']); ?></td>
                                    <td><?php echo h($book['location_collection']); ?></td>
                                    <td><?php echo (int)$book['total_copies']; ?></td>
                                    <td><strong><?php echo (int)$book['available_copies']; ?></strong></td>
                                    <td><?php echo (int)$book['borrowed_copies']; ?></td>
                                    <td><?php echo (int)$book['lost_copies']; ?></td>
                                    <td><?php echo getStatusBadge($book['available_copies'], $book['total_copies']); ?></td>
                                    <td>
                                        <button class="btn" style="padding:7px 14px;font-size:12px;"
                                            onclick="openAddCopiesModal(<?php echo (int)$book['book_id']; ?>,'<?php echo h($book['title']); ?>')">
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

    <div id="addBookModal" class="modal">
        <div class="modal-content book-modal-content">
            <div class="modal-header">
                <h2>Add Book</h2>
                <button class="close" type="button" onclick="closeBookModal()">&times;</button>
            </div>

            <div class="book-modal-actions">
                <button type="button" id="soloBookBtn" class="btn-primary" onclick="showSoloBookPanel()">Solo Add Book</button>
                <button type="button" id="bulkBookBtn" class="btn-secondary" onclick="showBulkBookPanel()">Bulk Add Books</button>
            </div>

            <div id="soloBookPanel" class="book-modal-panel active">
                <div class="modal-note">Fill out the complete book details below. A unique QR code will be generated automatically after saving.</div>
                <form method="POST">
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
                            <label for="call_number">Call Number of Book *</label>
                            <input type="text" id="call_number" name="call_number" required placeholder="e.g. FIL 899.211 RIZ 1887">
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
                            <label for="accession_barcode_number">Accession Number *</label>
                            <input type="text" id="accession_barcode_number" name="accession_barcode_number" required placeholder="e.g. ACC-0001">
                        </div>
                        <div class="form-group">
                            <label for="type_of_material">Type of Material *</label>
                            <input type="text" id="type_of_material" name="type_of_material" required placeholder="e.g. Book, Thesis, Magazine">
                        </div>
                        <div class="form-group">
                            <label for="location_collection">Location - Collection *</label>
                            <input type="text" id="location_collection" name="location_collection" required placeholder="e.g. Filipiniana Section / Shelf A1">
                        </div>
                    </div>

                    <div class="qr-info">
                        <strong>QR Code &amp; Stock:</strong> A unique QR code will be automatically generated. The selected number of copies will be added as available stock.
                    </div>

                    <div class="button-group">
                        <button type="submit">Add Book</button>
                        <button type="reset">Clear</button>
                    </div>
                </form>
            </div>

            <div id="bulkBookPanel" class="book-modal-panel">
                <div class="template-note">
                    Upload a CSV or XLSX file with these headers:<br>
                    <code>Title, Author, Co-Authors, Place of Publication, Date, Call Number of Book, Accession Number Bar Code Number, Type of Material, Location Collection, Total Copies</code><br>
                    Co-authors can be separated with semicolons or placed on separate lines. <code>Volume/Copy</code>, <code>Copies</code>, or <code>Total Copies</code> will be treated as the number of book copies.
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_books">
                    <div class="form-group">
                        <label for="books_file">Select CSV/XLSX File *</label>
                        <input type="file" id="books_file" name="books_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    </div>
                    <div class="button-group">
                        <button type="submit">Import Books</button>
                        <button type="reset">Clear</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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

        function openBookModal() {
            document.getElementById('addBookModal').classList.add('active');
            showSoloBookPanel();
            const titleInput = document.getElementById('title');
            if (titleInput) setTimeout(() => titleInput.focus(), 50);
        }

        function closeBookModal() {
            document.getElementById('addBookModal').classList.remove('active');
        }

        function showSoloBookPanel() {
            document.getElementById('soloBookPanel').classList.add('active');
            document.getElementById('bulkBookPanel').classList.remove('active');
            document.getElementById('soloBookBtn').className = 'btn-primary';
            document.getElementById('bulkBookBtn').className = 'btn-secondary';
        }

        function showBulkBookPanel() {
            document.getElementById('bulkBookPanel').classList.add('active');
            document.getElementById('soloBookPanel').classList.remove('active');
            document.getElementById('bulkBookBtn').className = 'btn-primary';
            document.getElementById('soloBookBtn').className = 'btn-secondary';
        }

        document.getElementById('addBookModal').addEventListener('click', function(e) {
            if (e.target === this) closeBookModal();
        });

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

        function openQRModal(qrCode, bookTitle) {
            const filePath = '/LibraryBorrowingSystem/qr_codes/' + qrCode + '.png';
            const safeTitle = (bookTitle || 'book').replace(/[^a-z0-9-_]+/gi, '_');
            document.getElementById('qrBookInfo').textContent = 'ID: ' + qrCode + ' | ' + bookTitle;
            document.getElementById('qrImage').src = filePath;
            const downloadBtn = document.getElementById('downloadBookQrBtn');
            downloadBtn.href = filePath;
            downloadBtn.download = safeTitle + '_' + qrCode + '_qr.png';
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
