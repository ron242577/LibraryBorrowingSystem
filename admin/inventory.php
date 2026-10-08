<?php
/**
 * Inventory Management - Admin Panel
 * Add books, import via CSV/XLSX, and monitor real-time inventory.
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/book_copies.php';

if (!isAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}


function ensureArchiveColumns($conn) {
    foreach ([
        'books' => [
            'is_archived' => "ALTER TABLE books ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER book_status",
            'archived_at' => "ALTER TABLE books ADD COLUMN archived_at DATETIME NULL AFTER is_archived",
            'archived_by' => "ALTER TABLE books ADD COLUMN archived_by INT NULL AFTER archived_at",
            'library_building' => "ALTER TABLE books ADD COLUMN library_building VARCHAR(150) NULL AFTER location_collection",
            'shelf_number' => "ALTER TABLE books ADD COLUMN shelf_number VARCHAR(100) NULL AFTER library_building",
            'library_section' => "ALTER TABLE books ADD COLUMN library_section VARCHAR(150) NULL AFTER shelf_number"
            ,'damaged_copies' => "ALTER TABLE books ADD COLUMN damaged_copies INT NOT NULL DEFAULT 0 AFTER lost_copies"
        ]
    ] as $table => $columns) {
        foreach ($columns as $column => $sql) {
            $safe = $conn->real_escape_string($column);
            $check = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$safe}'");
            if ($check && $check->num_rows === 0) {
                @$conn->query($sql);
            }
        }
    }
}
ensureArchiveColumns($conn);

$message = $_SESSION['inventory_flash_message'] ?? '';
$message_type = $_SESSION['inventory_flash_type'] ?? '';
unset($_SESSION['inventory_flash_message'], $_SESSION['inventory_flash_type']);

function redirectInventory($message, $type = 'success') {
    $_SESSION['inventory_flash_message'] = $message;
    $_SESSION['inventory_flash_type'] = $type;
    $url = '/LibraryBorrowingSystem/admin/inventory.php';
    $filter = $_GET['archive_filter'] ?? $_POST['archive_filter'] ?? 'active';
    if (in_array($filter, ['active','archived','all'], true)) {
        $url .= '?archive_filter=' . urlencode($filter);
    }
    header('Location: ' . $url);
    exit;
}

try { bcEnsureSchema($conn); } catch (Throwable $e) { logError('Book copies setup: ' . $e->getMessage()); }

// JSON endpoint: list every copy (own number + QR) of a title
if (isset($_GET['api']) && $_GET['api'] === 'copies') {
    header('Content-Type: application/json');
    echo json_encode(['copies' => bcListCopies($conn, (int)($_GET['book_id'] ?? 0))]);
    exit;
}

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

function getStatusBadge($available_copies, $total_copies, $book_status = '') {
    if ($book_status === 'lost') {
        return '<span class="badge badge-danger">Lost</span>';
    }
    if ($book_status === 'damaged') {
        return '<span class="badge badge-warning">Damaged</span>';
    }
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
        'librarybuilding' => 'library_building',
        'building' => 'library_building',
        'shelfnumber' => 'shelf_number',
        'shelf' => 'shelf_number',
        'librarysection' => 'library_section',
        'collectionsection' => 'library_section',
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

    if (!in_array('title', $mapped_headers, true) || !in_array('author', $mapped_headers, true) || !in_array('book_pages', $mapped_headers, true)) {
        throw new Exception('Import file must include headers for Title, Author, and Book Pages (Book Numbers are generated automatically).');
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
    // Type of material is no longer entered in the catalogue form. Keep the
    // database column for backward compatibility with existing records.
    $type_of_material = '';
    $book_condition = trim($data['book_condition'] ?? 'New');
    if (!in_array($book_condition, ['New','Old'], true)) { $book_condition = 'New'; }
    $location_collection = trim($data['location_collection'] ?? '');
    $library_building = trim($data['library_building'] ?? '');
    $shelf_number = trim($data['shelf_number'] ?? '');
    $library_section = trim($data['library_section'] ?? '');
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
    $book_number = '';
    if (false) {
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
    if ($location_collection === '') {
        $error_message = 'Location/collection is required.';
        return false;
    }
    if ($publisher === '') {
        $error_message = 'Publisher is required.';
        return false;
    }
    if ($shelf_number === '') {
        $error_message = 'Shelf number is required.';
        return false;
    }
    if ($library_section === '') {
        $error_message = 'Library section is required.';
        return false;
    }
    if ($total_copies < 1) {
        $total_copies = 1;
    }

    // Same title already in the catalogue -> add the new copies to it (e.g. 10 + 2 = 12 copies)
    $existingId = bcFindTitle($conn, $title);
    if ($existingId !== null) {
        try {
            $conn->begin_transaction();
            $created = bcCreateCopies($conn, $existingId, $total_copies, $book_condition ?: 'New');
            bcSync($conn, $existingId);
            $conn->commit();
            $GLOBALS['bc_last_add'] = ['merged' => true, 'copies' => $total_copies, 'created' => $created];
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            $error_message = 'Unable to add copies to the existing title.';
            logError('Add copies error: ' . $e->getMessage());
            return false;
        }
    }

    // New title: temporary unique placeholders, replaced by the first copy's real number/QR below
    $book_number = 'TMP-' . bin2hex(random_bytes(6));
    $book_qr_code = 'TMP-' . bin2hex(random_bytes(6));
    $conn->begin_transaction();

    $status = 'available';
    $new_copy_count = $total_copies;
    $total_copies = 0;
    $available_copies = 0;
    $borrowed_copies = 0;
    $lost_copies = 0;

    $stmt = $conn->prepare('
        INSERT INTO books
            (title, author, co_authors, place_of_publication, publication_date, book_number, book_pages, source_of_funds, cost_price, publisher, edition, volumes, class, type_of_material, location_collection, library_building, shelf_number, library_section, book_condition, qr_code, book_status, total_copies, available_copies, borrowed_copies, lost_copies, damaged_copies)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $damaged_copies = 0;

    $stmt->bind_param(
        'ssssssisdssssssssssssiiiii',
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
        $library_building,
        $shelf_number,
        $library_section,
        $book_condition,
        $book_qr_code,
        $status,
        $total_copies,
        $available_copies,
        $borrowed_copies,
        $lost_copies,
        $damaged_copies
    );

    if (!$stmt->execute()) {
        $error_message = 'Unable to save the book right now.';
        logError('Book database error: ' . $stmt->error);
        $stmt->close();
        $conn->rollback();
        return false;
    }
    $new_book_id = (int)$conn->insert_id;
    $stmt->close();

    try {
        $created = bcCreateCopies($conn, $new_book_id, $new_copy_count, $book_condition ?: 'New');
        $up = $conn->prepare('UPDATE books SET book_number = ?, qr_code = ? WHERE book_id = ?');
        $up->bind_param('ssi', $created[0]['book_number'], $created[0]['qr_code'], $new_book_id);
        $up->execute();
        $up->close();
        bcSync($conn, $new_book_id);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $error_message = 'Unable to create the book copies.';
        logError('Create copies error: ' . $e->getMessage());
        return false;
    }
    $GLOBALS['bc_last_add'] = ['merged' => false, 'copies' => $new_copy_count, 'created' => $created];
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
            'book_condition' => $_POST['book_condition'] ?? 'New',
            'location_collection' => $_POST['location_collection'] ?? '',
            'library_building' => $_POST['library_building'] ?? '',
            'shelf_number' => $_POST['shelf_number'] ?? '',
            'library_section' => $_POST['library_section'] ?? '',
            'total_copies' => $_POST['total_copies'] ?? 1
        ];

        if (addBookRecord($conn, $data, $error)) {
            auditRecordChange($conn, 'book_added', 'inventory', 'Added a new book to inventory.', 'success', 'book', null, null, [
                'title' => $data['title'] ?? '', 'author' => $data['author'] ?? '', 'book_number' => $data['book_number'] ?? '', 'book_pages' => $data['book_pages'] ?? '', 'total_copies' => $data['total_copies'] ?? 1
            ]);
            $bcAdd = $GLOBALS['bc_last_add'] ?? ['merged' => false, 'copies' => 1];
            $message = !empty($bcAdd['merged'])
                ? 'This title already exists, so ' . (int)$bcAdd['copies'] . ' new copy/copies were added to it. Each copy has its own book number and QR code.'
                : 'Book added successfully with ' . (int)$bcAdd['copies'] . ' copy/copies. Each copy has its own book number and QR code.';
            $message_type = 'success';
        } else {
            $message = $error;
            $message_type = 'error';
        }
    }

    if ($action === 'edit_book') {
        $copy_id = intval($_POST['copy_id'] ?? 0);
        $allowed_statuses = ['available', 'borrowed', 'damaged', 'lost'];
        $new_copy_status = trim((string)($_POST['copy_status'] ?? 'available'));

        if ($copy_id <= 0) {
            $message = 'Invalid book copy.';
            $message_type = 'error';
        } elseif (!in_array($new_copy_status, $allowed_statuses, true)) {
            $message = 'Invalid copy status selected.';
            $message_type = 'error';
        } else {
            try {
                $oldCopy = bcGetCopyById($conn, $copy_id, false);
                if (!$oldCopy) {
                    throw new Exception('Book copy not found.');
                }
                if ((int)$oldCopy['is_archived'] === 1) {
                    throw new Exception('Books under an archived title cannot be edited. Restore the title first.');
                }

                $title = trim((string)($_POST['title'] ?? ''));
                $author = trim((string)($_POST['author'] ?? ''));
                $place = trim((string)($_POST['place_of_publication'] ?? ''));
                $publicationDate = normalizeDateValue($_POST['publication_date'] ?? '');
                $bookNumber = trim((string)$oldCopy['book_number']);
                $bookPages = intval($_POST['book_pages'] ?? 0);
                $sourceFunds = trim((string)($_POST['source_of_funds'] ?? ''));
                $costPriceRaw = trim((string)($_POST['cost_price'] ?? ''));
                $costPrice = $costPriceRaw === '' ? null : (float)$costPriceRaw;
                $publisher = trim((string)($_POST['publisher'] ?? ''));
                $edition = trim((string)($_POST['edition'] ?? ''));
                $volumes = trim((string)($_POST['volumes'] ?? ''));
                $bookClass = trim((string)($_POST['class'] ?? ''));
                $material = trim((string)($oldCopy['type_of_material'] ?? ''));
                $bookCondition = trim((string)($_POST['book_condition'] ?? ($oldCopy['copy_condition'] ?? 'New')));
                if (!in_array($bookCondition, ['New','Old'], true)) { $bookCondition = 'New'; }
                $location = trim((string)($_POST['location_collection'] ?? ''));
                $building = trim((string)($_POST['library_building'] ?? ''));
                $shelf = trim((string)($_POST['shelf_number'] ?? ''));
                $section = trim((string)($_POST['library_section'] ?? ''));
                $coAuthors = normalizeCoAuthors($_POST['co_authors'] ?? '');

                if ($title === '' || $author === '' || $place === '' || !$publicationDate || $bookPages <= 0 || $location === '') {
                    throw new Exception('Please complete all required book fields.');
                }
                if ($publisher === '') {
                    throw new Exception('Publisher is required.');
                }
                if ($shelf === '') {
                    throw new Exception('Shelf number is required.');
                }
                if ($section === '') {
                    throw new Exception('Library section is required.');
                }

                $currentCopyStatus = (string)$oldCopy['copy_status'];
                if ($currentCopyStatus === 'borrowed' && $new_copy_status !== 'borrowed') {
                    throw new Exception('This copy is currently borrowed. Return it before changing its copy status.');
                }
                if ($currentCopyStatus !== 'borrowed' && $new_copy_status === 'borrowed') {
                    throw new Exception('Borrowed status is managed by the borrowing transaction.');
                }

                $bookId = (int)$oldCopy['book_id'];
                $conn->begin_transaction();

                $update = $conn->prepare(
                    'UPDATE books SET
                        title = ?, author = ?, co_authors = ?, place_of_publication = ?,
                        publication_date = ?, book_pages = ?, source_of_funds = ?, cost_price = ?, publisher = ?,
                        edition = ?, volumes = ?, class = ?, type_of_material = ?, location_collection = ?,
                        library_building = ?, shelf_number = ?, library_section = ?
                     WHERE book_id = ?'
                );
                $update->bind_param(
                    'sssssisdsssssssssi',
                    $title,
                    $author,
                    $coAuthors,
                    $place,
                    $publicationDate,
                    $bookPages,
                    $sourceFunds,
                    $costPrice,
                    $publisher,
                    $edition,
                    $volumes,
                    $bookClass,
                    $material,
                    $location,
                    $building,
                    $shelf,
                    $section,
                    $bookId
                );
                if (!$update->execute()) {
                    $update->close();
                    throw new Exception('Unable to update the book details.');
                }
                $update->close();

                $copyUpdate = $conn->prepare("UPDATE book_copies SET copy_status = ?, copy_condition = ? WHERE copy_id = ? AND copy_status <> 'removed'");
                $copyUpdate->bind_param('ssi', $new_copy_status, $bookCondition, $copy_id);
                if (!$copyUpdate->execute()) {
                    $copyUpdate->close();
                    throw new Exception('Unable to update the selected book copy.');
                }
                $copyUpdate->close();

                bcSync($conn, $bookId);
                $conn->commit();

                auditRecordChange(
                    $conn,
                    'book_copy_updated',
                    'inventory',
                    'Updated catalogue details and the selected physical book copy.',
                    'success',
                    'copy',
                    $copy_id,
                    [
                        'book_id' => $bookId,
                        'book_number' => $bookNumber,
                        'copy_status' => $currentCopyStatus,
                        'copy_condition' => $oldCopy['copy_condition']
                    ],
                    [
                        'book_id' => $bookId,
                        'book_number' => $bookNumber,
                        'copy_status' => $new_copy_status,
                        'copy_condition' => $bookCondition,
                        'title' => $title
                    ]
                );

                $message = 'Book copy ' . $bookNumber . ' updated successfully.';
                $message_type = 'success';
            } catch (Exception $e) {
                if ($conn->in_transaction) $conn->rollback();
                $message = $e->getMessage();
                $message_type = 'error';
                logError('Book copy edit error: ' . $e->getMessage());
            }
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
                $book_stmt = $conn->prepare('SELECT title, qr_code, total_copies, available_copies, is_archived FROM books WHERE book_id = ?');
                $book_stmt->bind_param('i', $book_id);
                $book_stmt->execute();
                $book_result = $book_stmt->get_result();

                if ($book_result->num_rows === 0) {
                    $message = 'Book not found.';
                    $message_type = 'error';
                } else {
                    $book = $book_result->fetch_assoc();
                    if ((int)$book['is_archived'] === 1) {
                        $message = 'Archived books cannot receive new copies. Restore the book first.';
                        $message_type = 'error';
                        $book_stmt->close();
                    } else {
                    $conn->begin_transaction();
                    try {
                        bcCreateCopies($conn, $book_id, $copies_to_add, 'New');
                        bcSync($conn, $book_id);
                        $conn->commit();
                        $new_total = (int)$book['total_copies'] + $copies_to_add;
                        $new_available = (int)$book['available_copies'] + $copies_to_add;
                        auditRecordChange($conn, 'book_copies_added', 'inventory', 'Added copies to a book.', 'success', 'book', $book_id, [
                            'title' => $book['title'], 'total_copies' => (int)$book['total_copies'], 'available_copies' => (int)$book['available_copies']
                        ], [
                            'title' => $book['title'], 'total_copies' => $new_total, 'available_copies' => $new_available
                        ], ['copies_added' => $copies_to_add]);
                        $message = 'Added ' . $copies_to_add . ' copy/copies to "' . h($book['title']) . '" with new book numbers and QR codes. New total: ' . $new_total;
                        $message_type = 'success';
                    } catch (Throwable $e) {
                        $conn->rollback();
                        $message = 'Error updating inventory: ' . $e->getMessage();
                        $message_type = 'error';
                    }
                    }
                }
                if (isset($book_stmt) && $book_stmt) $book_stmt->close();
            } catch (Exception $e) {
                $message = 'Error: ' . h($e->getMessage());
                $message_type = 'error';
                logError('Inventory update error: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'remove_book') {
        $copy_id = intval($_POST['copy_id'] ?? 0);
        if ($copy_id <= 0) {
            $message = 'Invalid book copy.';
            $message_type = 'error';
        } else {
            try {
                $copy = bcGetCopyById($conn, $copy_id, false);
                if (!$copy) {
                    throw new Exception('Book copy not found.');
                }
                if ((int)$copy['is_archived'] === 1) {
                    throw new Exception('This copy belongs to an archived title and is not active.');
                }
                if ((string)$copy['copy_status'] === 'borrowed') {
                    throw new Exception('This book copy is currently borrowed. Return it before removing the copy.');
                }

                $admin_id = (int)($_SESSION['user_id'] ?? 0);
                $bookId = (int)$copy['book_id'];
                $conn->begin_transaction();
                $stmt = $conn->prepare("UPDATE book_copies SET copy_status='removed', archived_at=NOW(), archived_by=? WHERE copy_id=? AND copy_status<>'removed'");
                $stmt->bind_param('ii', $admin_id, $copy_id);
                if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new Exception('Unable to move the selected book copy to the archive.');
                }
                $stmt->close();
                bcSync($conn, $bookId);
                $conn->commit();

                auditRecordChange($conn, 'book_copy_archived', 'inventory', 'Moved one physical book copy to the archive.', 'success', 'copy', $copy_id,
                    ['book_number'=>$copy['book_number'], 'title'=>$copy['title'], 'copy_status'=>$copy['copy_status']],
                    ['book_number'=>$copy['book_number'], 'title'=>$copy['title'], 'copy_status'=>'removed']
                );
                $message = 'Book copy ' . $copy['book_number'] . ' was moved to the archive. The other copies of "' . $copy['title'] . '" remain active.';
                $message_type = 'success';
            } catch (Exception $e) {
                if ($conn->in_transaction) $conn->rollback();
                $message = 'Error removing book copy: ' . $e->getMessage();
                $message_type = 'error';
                logError('Remove book copy error: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'restore_book') {
        $book_id = intval($_POST['book_id'] ?? 0);
        if ($book_id <= 0) {
            $message = 'Invalid book ID.';
            $message_type = 'error';
        } else {
            try {
                $book_stmt = $conn->prepare('SELECT title, is_archived, borrowed_copies FROM books WHERE book_id = ? LIMIT 1');
                $book_stmt->bind_param('i', $book_id);
                $book_stmt->execute();
                $book = $book_stmt->get_result()->fetch_assoc();
                $book_stmt->close();
                if (!$book) {
                    $message = 'Book not found.';
                    $message_type = 'error';
                } elseif ((int)$book['is_archived'] === 0) {
                    $message = 'This book is already active.';
                    $message_type = 'error';
                } elseif ((int)$book['borrowed_copies'] > 0) {
                    $message = 'This archived book has an active borrowing record and cannot be restored yet.';
                    $message_type = 'error';
                } else {
                    $stmt = $conn->prepare("UPDATE books SET is_archived = 0, archived_at = NULL, archived_by = NULL, book_status = CASE WHEN available_copies > 0 THEN 'available' ELSE 'out_of_stock' END WHERE book_id = ?");
                    $stmt->bind_param('i', $book_id);
                    if (!$stmt->execute()) throw new Exception('Unable to restore the book.');
                    $stmt->close();
                    auditRecordChange($conn, 'book_restored', 'inventory', 'Restored an archived book to active inventory.', 'success', 'book', $book_id, ['status'=>'archived'], ['status'=>'active','title'=>$book['title']]);
                    $message = 'Book restored successfully: ' . $book['title'];
                    $message_type = 'success';
                }
            } catch (Exception $e) {
                $message = 'Error restoring book: ' . h($e->getMessage());
                $message_type = 'error';
                logError('Restore book error: ' . $e->getMessage());
            }
        }
    }


    if ($action === 'restore_copy') {
        $copy_id = intval($_POST['copy_id'] ?? 0);
        if ($copy_id <= 0) {
            $message = 'Invalid archived book copy.';
            $message_type = 'error';
        } else {
            try {
                $copy = bcGetCopyById($conn, $copy_id, true);
                if (!$copy) throw new Exception('Archived book copy not found.');
                if ((string)$copy['copy_status'] !== 'removed') throw new Exception('This copy is not in the archive.');
                if ((int)$copy['is_archived'] === 1) throw new Exception('Restore the parent title first before restoring this copy.');

                $bookId = (int)$copy['book_id'];
                $conn->begin_transaction();
                $stmt = $conn->prepare("UPDATE book_copies SET copy_status='available', archived_at=NULL, archived_by=NULL WHERE copy_id=? AND copy_status='removed'");
                $stmt->bind_param('i', $copy_id);
                if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new Exception('Unable to restore the archived copy.');
                }
                $stmt->close();
                bcSync($conn, $bookId);
                $conn->commit();

                auditRecordChange($conn, 'book_copy_restored', 'inventory', 'Restored one archived physical book copy.', 'success', 'copy', $copy_id,
                    ['book_number'=>$copy['book_number'], 'copy_status'=>'removed'],
                    ['book_number'=>$copy['book_number'], 'copy_status'=>'available']
                );
                $message = 'Book copy ' . $copy['book_number'] . ' restored successfully.';
                $message_type = 'success';
            } catch (Exception $e) {
                if ($conn->in_transaction) $conn->rollback();
                $message = 'Error restoring book copy: ' . $e->getMessage();
                $message_type = 'error';
                logError('Restore book copy error: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'permanently_remove_copy') {
        $copy_id = intval($_POST['copy_id'] ?? 0);
        if ($copy_id <= 0) {
            $message = 'Invalid archived book copy.';
            $message_type = 'error';
        } else {
            try {
                $copy = bcGetCopyById($conn, $copy_id, true);
                if (!$copy) throw new Exception('Archived book copy not found.');
                if ((string)$copy['copy_status'] !== 'removed') throw new Exception('Only archived copies can be permanently removed.');

                $bookId = (int)$copy['book_id'];
                $conn->begin_transaction();

                // Keep borrowing history while removing the physical-copy row itself.
                $tx = $conn->prepare('UPDATE transactions SET copy_id=NULL WHERE copy_id=?');
                $tx->bind_param('i', $copy_id);
                if (!$tx->execute()) {
                    $tx->close();
                    throw new Exception('Unable to detach the archived copy from its transaction history.');
                }
                $tx->close();

                $stmt = $conn->prepare("DELETE FROM book_copies WHERE copy_id=? AND copy_status='removed'");
                $stmt->bind_param('i', $copy_id);
                if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new Exception('Unable to permanently remove the archived copy.');
                }
                $stmt->close();
                bcSync($conn, $bookId);
                $conn->commit();

                bcDeleteQrImage((string)$copy['qr_code']);
                auditRecordChange($conn, 'book_copy_permanently_removed', 'inventory', 'Permanently removed an archived physical book copy.', 'success', 'copy', $copy_id,
                    ['book_number'=>$copy['book_number'], 'title'=>$copy['title'], 'copy_status'=>'removed'],
                    ['book_number'=>$copy['book_number'], 'title'=>$copy['title'], 'deleted'=>true]
                );
                $message = 'Book copy ' . $copy['book_number'] . ' was permanently removed from the archive.';
                $message_type = 'success';
            } catch (Exception $e) {
                if ($conn->in_transaction) $conn->rollback();
                $message = 'Error permanently removing book copy: ' . $e->getMessage();
                $message_type = 'error';
                logError('Permanent book copy removal error: ' . $e->getMessage());
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $message !== '') {
    redirectInventory($message, $message_type ?: 'info');
}

$stats = ['total_titles' => 0, 'total_copies' => 0, 'available_copies' => 0, 'borrowed_copies' => 0, 'lost_copies' => 0];
try {
    $stats_result = $conn->query("SELECT COUNT(*) AS total_titles, COALESCE(SUM(total_copies),0) AS total_copies, COALESCE(SUM(available_copies),0) AS available_copies, COALESCE(SUM(borrowed_copies),0) AS borrowed_copies, COALESCE(SUM(CASE WHEN book_status = 'lost' THEN CASE WHEN lost_copies > 0 THEN lost_copies ELSE total_copies END WHEN book_status = 'damaged' THEN CASE WHEN damaged_copies > 0 THEN damaged_copies ELSE total_copies END ELSE lost_copies + damaged_copies END),0) AS lost_copies FROM books WHERE is_archived = 0");
    if ($stats_result) {
        $stats = $stats_result->fetch_assoc();
    }
} catch (Exception $e) {
    logError('Error fetching inventory stats: ' . $e->getMessage());
}

$low_stock_books = [];
try {
    $low_stock_result = $conn->query('SELECT book_id, title, author, book_number, available_copies, total_copies FROM books WHERE is_archived = 0 AND available_copies <= 2 ORDER BY available_copies ASC, title ASC LIMIT 10');
    if ($low_stock_result) {
        while ($row = $low_stock_result->fetch_assoc()) {
            $low_stock_books[] = $row;
        }
    }
} catch (Exception $e) {
    logError('Error fetching low stock books: ' . $e->getMessage());
}

$archive_filter = $_GET['archive_filter'] ?? 'active';
if (!in_array($archive_filter, ['active','archived','all'], true)) $archive_filter = 'active';
$archive_where = $archive_filter === 'archived' ? 'WHERE is_archived = 1' : ($archive_filter === 'active' ? 'WHERE is_archived = 0' : '');

$all_books = [];
try {
    $books_result = $conn->query("SELECT book_id, title, author, co_authors, place_of_publication, publication_date, book_number, book_pages, source_of_funds, cost_price, publisher, edition, volumes, class, type_of_material, location_collection, library_building, shelf_number, library_section, book_condition, qr_code, total_copies, available_copies, borrowed_copies, lost_copies, damaged_copies, book_status, is_archived, archived_at, created_at FROM books {$archive_where} ORDER BY title ASC");
    if ($books_result) {
        while ($row = $books_result->fetch_assoc()) {
            $all_books[] = $row;
        }
    }
} catch (Exception $e) {
    logError('Error fetching books inventory: ' . $e->getMessage());
}

// Keep the Remove/Edit selectors populated with every active physical copy even when the
// inventory table is currently filtered to archived books.
$book_selection_list = [];
try {
    $selection_result = $conn->query("SELECT
        c.copy_id, c.book_id, c.book_number, c.qr_code, c.copy_status, c.copy_condition, c.archived_at, c.archived_by,
        b.title, b.author, b.co_authors, b.place_of_publication, b.publication_date, b.book_pages,
        b.source_of_funds, b.cost_price, b.publisher, b.edition, b.volumes, b.class, b.type_of_material,
        b.location_collection, b.library_building, b.shelf_number, b.library_section, b.book_condition,
        b.total_copies, b.available_copies, b.borrowed_copies, b.lost_copies, b.damaged_copies,
        b.book_status, b.is_archived, b.created_at
        FROM book_copies c
        INNER JOIN books b ON b.book_id = c.book_id
        WHERE c.copy_status <> 'removed' AND b.is_archived = 0
        ORDER BY b.title ASC, CAST(SUBSTRING(c.book_number,4) AS UNSIGNED), c.copy_id ASC");
    if ($selection_result) {
        while ($row = $selection_result->fetch_assoc()) {
            $book_selection_list[] = $row;
        }
    }
} catch (Exception $e) {
    logError('Error fetching active book copy selection list: ' . $e->getMessage());
}

$inventory_copy_meta_by_book = [];
foreach ($book_selection_list as $copy) {
    $copyBookId = (int)($copy['book_id'] ?? 0);
    if ($copyBookId <= 0) continue;
    if (!isset($inventory_copy_meta_by_book[$copyBookId])) {
        $inventory_copy_meta_by_book[$copyBookId] = ['statuses' => [], 'conditions' => [], 'numbers' => [], 'qr_codes' => []];
    }
    $copyStatus = strtolower(trim((string)($copy['copy_status'] ?? '')));
    if ($copyStatus !== '') $inventory_copy_meta_by_book[$copyBookId]['statuses'][$copyStatus] = true;
    $copyCondition = trim((string)($copy['copy_condition'] ?? ''));
    if ($copyCondition !== '') $inventory_copy_meta_by_book[$copyBookId]['conditions'][strtolower($copyCondition)] = $copyCondition;
    $copyNumber = trim((string)($copy['book_number'] ?? ''));
    if ($copyNumber !== '') $inventory_copy_meta_by_book[$copyBookId]['numbers'][] = $copyNumber;
    $copyQr = trim((string)($copy['qr_code'] ?? ''));
    if ($copyQr !== '') $inventory_copy_meta_by_book[$copyBookId]['qr_codes'][] = $copyQr;
}

$archived_copies = [];
try {
    $archiveCopiesResult = $conn->query("SELECT
        c.copy_id, c.book_id, c.book_number, c.qr_code, c.copy_status, c.copy_condition, c.archived_at, c.archived_by,
        b.title, b.author, b.publisher, b.shelf_number, b.library_section, b.is_archived AS parent_is_archived
        FROM book_copies c
        INNER JOIN books b ON b.book_id = c.book_id
        WHERE c.copy_status = 'removed'
        ORDER BY COALESCE(c.archived_at, c.created_at) DESC, b.title ASC, CAST(SUBSTRING(c.book_number,4) AS UNSIGNED) ASC");
    if ($archiveCopiesResult) {
        while ($row = $archiveCopiesResult->fetch_assoc()) {
            $archived_copies[] = $row;
        }
    }
} catch (Exception $e) {
    logError('Error fetching archived book copies: ' . $e->getMessage());
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
            overflow-x: hidden;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
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
            transition: transform .3s, box-shadow .3s;
        }

        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 14px rgba(0,0,0,.12); }
        .stat-card .label { font-size: 12px; color: #52618D; text-transform: uppercase; letter-spacing: .5px; font-weight: 600; margin-bottom: 8px; }
        .stat-card .value { font-size: 32px; font-weight: 700; color: #202A44; }

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
        }
        .alert-panel h3 { font-size: 16px; color: #202A44; margin-bottom: 16px; }
        .no-low-stock { color: #567D1F; font-weight: 600; font-size: 14px; }
        .low-stock-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #E7EEF7; gap: 12px; }
        .low-stock-item:last-child { border-bottom: none; }
        .low-stock-item-info h4 { font-size: 14px; color: #202A44; margin-bottom: 2px; }
        .low-stock-item-info p { font-size: 12px; color: #52618D; }
        .low-stock-badge { font-size: 11px; font-weight: 600; background: #FBFDCB; color: #5C5F05; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }

        .table-wrapper { overflow-x: hidden; width:100%; }
        table { width: 100%; border-collapse: collapse; min-width: 1000px; }
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
            .details-modal-header{align-items:flex-start;}
        .details-heading{display:flex;align-items:center;gap:10px;min-width:0;}
        .details-heading h2{margin:0;}
        .details-edit-btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 14px;border:0;border-radius:8px;background:#52618D;color:#fff;font-size:12px;font-weight:700;cursor:pointer;}
        .details-edit-btn:hover{background:#141F52;}
        .edit-modal-header{align-items:flex-start;}
        .modal-subtitle{margin-top:5px;color:#52618D;font-size:12px;line-height:1.45;}
        .edit-section-title{margin:20px 0 12px;padding:10px 13px;border:1px solid #E7EEF7;border-left:4px solid #141F52;border-radius:9px;background:#F7F9FC;color:#141F52;font-size:13px;font-weight:800;}
        .book-edit-modal-content{max-width:950px;width:94%;max-height:92vh;overflow-y:auto;border:1px solid #D2E2F6;}
        .book-edit-modal-content .form-row{gap:22px 24px;}
        .book-edit-modal-content .form-group{margin-bottom:16px;}
        .book-edit-modal-content input,.book-edit-modal-content select{padding:12px 14px;border-radius:8px;}
        .inventory-action-btn{cursor:pointer;}
        .inventory-action-btn:focus-visible,.details-edit-btn:focus-visible{outline:3px solid rgba(244,249,22,.55);outline-offset:2px;}

    
.inventory-compact-table th:nth-child(1),
.inventory-compact-table td:nth-child(1),
.inventory-compact-table th:nth-child(4),
.inventory-compact-table td:nth-child(4),
.inventory-compact-table th:nth-child(5),
.inventory-compact-table td:nth-child(5),
.inventory-compact-table th:nth-child(6),
.inventory-compact-table td:nth-child(6),
.inventory-compact-table th:nth-child(7),
.inventory-compact-table td:nth-child(7),
.inventory-compact-table th:nth-child(9),
.inventory-compact-table td:nth-child(9),
.inventory-compact-table th:nth-child(12),
.inventory-compact-table td:nth-child(12),
.inventory-compact-table th:nth-child(13),
.inventory-compact-table td:nth-child(13) { display:none; }
</style>

<style id="responsive-inventory-priority-fix">
@media (max-width: 768px) {

    /* Active catalogue: show only Title and all Action buttons. */
    .inventory-active-table th,
    .inventory-active-table td,
    .inventory-archive-table th,
    .inventory-archive-table td {
        display: none;
    }

    .inventory-active-table th:nth-child(2),
    .inventory-active-table td:nth-child(2),
    .inventory-active-table th:nth-child(14),
    .inventory-active-table td:nth-child(14),
    .inventory-archive-table th:nth-child(2),
    .inventory-archive-table td:nth-child(2),
    .inventory-archive-table th:nth-child(8),
    .inventory-archive-table td:nth-child(8) {
        display: table-cell;
    }

    .inventory-active-table,
    .inventory-archive-table {
        width: 100%;
        table-layout: fixed;
    }

    .inventory-active-table td,
    .inventory-archive-table td {
        white-space: normal;
        overflow-wrap: break-word;
    }
}
</style>

<style id="mobile-no-horizontal-scroll-fix">
@media (max-width: 768px) {
    html, body { overflow-x: hidden !important; }
    .container, .table-section, .table-wrapper, .table-responsive {
        max-width: 100% !important;
        width: 100% !important;
        overflow-x: hidden !important;
    }
    table, .student-compact-table, .teacher-compact-table, .inventory-compact-table {
        width: 100% !important;
        min-width: 0 !important;
        table-layout: fixed !important;
    }
    th, td {
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 1px;
    }
}
</style>

<style id="final-catalogue-toolbar-fix">
/* Keep the catalogue action row inside the content area. */
.inventory-toolbar {
    width: 100% !important;
    max-width: 100% !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 10px !important;
    margin-bottom: 16px !important;
}
.inventory-center {
    min-width: 0 !important;
    flex: 1 1 auto !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 7px !important;
}
.inventory-right {
    min-width: 0 !important;
    flex: 0 1 auto !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 7px !important;
}
.inventory-center .search-input {
    flex: 1 1 165px !important;
    width: 165px !important;
    min-width: 120px !important;
    height: 38px !important;
    padding: 8px 10px !important;
}
.inventory-center .filter-select {
    flex: 0 1 auto !important;
    width: 125px !important;
    min-width: 100px !important;
    height: 38px !important;
    padding: 8px 9px !important;
}
.inventory-center #sectionFilter { width: 145px !important; }
.inventory-center #archiveFilter { width: 130px !important; }
.inventory-right .btn-primary,
.inventory-right .btn-secondary {
    height: 38px !important;
    min-height: 38px !important;
    padding: 8px 12px !important;
    font-size: 12px !important;
    white-space: nowrap !important;
    flex: 0 1 auto !important;
}
@media (max-width: 1250px) {
    .inventory-toolbar { flex-wrap: wrap !important; }
    .inventory-center { width: 100% !important; flex-wrap: nowrap !important; }
    .inventory-right { width: 100% !important; flex-wrap: wrap !important; }
}
@media (max-width: 900px) {
    .inventory-center { flex-wrap: wrap !important; }
    .inventory-center .search-input,
    .inventory-center .filter-select,
    .inventory-center #sectionFilter,
    .inventory-center #archiveFilter {
        flex: 1 1 150px !important;
        width: auto !important;
    }
    .inventory-right { justify-content: stretch !important; }
    .inventory-right .btn-primary,
    .inventory-right .btn-secondary { flex: 1 1 150px !important; }
}
</style>

</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>

    <div class="container">
        <div class="page-header">
            <div>
                <h1>Cataloging</h1>
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
            <div class="inventory-left">
                    <h2>All Books Inventory</h2>
                </div>
            <div class="inventory-toolbar">
                
                <div class="inventory-center">
                    <input 
                        type="text"
                        id="searchInput"
                        placeholder="Search title, author, book number..."
                        oninput="filterTable()"
                        class="search-input"
                    >
                    <select id="statusConditionFilter" class="filter-select" onchange="filterTable()">
                        <option value="">All Status</option>
                        <optgroup label="Status">
                            <option value="status:available">Available</option>
                            <option value="status:out_of_stock">Out of Stock</option>
                            <option value="status:damaged">Damaged</option>
                            <option value="status:lost">Lost</option>
                        </optgroup>
                        <optgroup label="Condition">
                            <option value="condition:New">New</option>
                            <option value="condition:Old">Old</option>
                        </optgroup>
                    </select>
                    <select id="classFilter" class="filter-select" onchange="filterTable()">
                        <option value="">All Classes</option>
                    </select>
                    <select id="sectionFilter" class="filter-select" onchange="filterTable()">
                        <option value="">All Library Sections</option>
                    </select>
                    <select id="archiveFilter" class="filter-select" onchange="applyInventoryFilters()">
                        <option value="active" <?php echo $archive_filter === 'active' ? 'selected' : ''; ?>>Active Books</option>
                        <option value="archived" <?php echo $archive_filter === 'archived' ? 'selected' : ''; ?>>Archive</option>
                        <option value="all" <?php echo $archive_filter === 'all' ? 'selected' : ''; ?>>All Books</option>
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

                    <button
                        type="button"
                        class="btn-secondary"
                        onclick="window.open('/LibraryBorrowingSystem/admin/qr_print.php', '_blank', 'noopener')"
                    >
                        Print QR Codes
                    </button>
                </div>
            </div>

            <?php if (empty($all_books)): ?>
                <div class="empty-message">No books in inventory yet.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="inventory-compact-table inventory-active-table" data-inventory-pagination="1">
                        <thead>
                            <tr>
                                <th>QR</th>
                                <th>Title / Author</th>
                                <th>Book No.</th>
                                <th>Pages</th>
                                <th>Publication</th>
                                <th>Edition / Volume / Class</th>
                                <th>Source / Cost</th>
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
                                <?php
                                    $inventoryAvailability = (int)$book['is_archived'] === 1
                                        ? 'archived'
                                        : ((int)$book['available_copies'] > 0
                                            ? ((int)$book['available_copies'] < (int)$book['total_copies'] ? 'limited' : 'available')
                                            : 'not_available');
                                ?>
                                <?php
                                    $copyMeta = $inventory_copy_meta_by_book[(int)$book['book_id']] ?? ['statuses' => [], 'conditions' => [], 'numbers' => [], 'qr_codes' => []];
                                    $filterStatuses = array_keys($copyMeta['statuses']);
                                    $availableCount = (int)$book['available_copies'];
                                    if ($availableCount > 0) $filterStatuses[] = 'available';
                                    if ($availableCount <= 0) $filterStatuses[] = 'out_of_stock';
                                    $filterStatuses = array_values(array_unique(array_map('strtolower', $filterStatuses)));
                                    $filterConditions = array_values($copyMeta['conditions']);
                                    $parentCondition = trim((string)($book['book_condition'] ?? ''));
                                    if ($parentCondition === '') $parentCondition = 'New';
                                    if (!in_array($parentCondition, $filterConditions, true)) $filterConditions[] = $parentCondition;
                                    $searchParts = [
                                        $book['title'], $book['author'], $book['book_number'], $book['qr_code'],
                                        implode(' ', $copyMeta['numbers']), implode(' ', $copyMeta['qr_codes'])
                                    ];
                                ?>
                                <tr data-search="<?php echo h(implode(' ', $searchParts)); ?>"
                                    data-statuses="<?php echo h(implode(' ', $filterStatuses)); ?>"
                                    data-conditions="<?php echo h(implode('||', array_map('strtolower', $filterConditions))); ?>"
                                    data-class="<?php echo h($book['class'] ?? ''); ?>"
                                    data-section="<?php echo h($book['library_section'] ?? ($book['location_collection'] ?? '')); ?>">
                                    <td>
                                        <button type="button" class="btn btn-secondary" onclick="toggleCopies(this, <?php echo (int)$book['book_id']; ?>, <?php echo h(json_encode($book['title'])); ?>)">&#9656; <?php echo (int)$book['total_copies']; ?> Cop<?php echo (int)$book['total_copies'] === 1 ? 'y' : 'ies'; ?></button>
                                    </td>
                                    <td>
                                        <div class="book-title"><?php echo h($book['title']); ?></div>
                                        <div class="muted">Author: <?php echo h($book['author']); ?></div>
                                        <?php if (!empty($book['co_authors'])): ?>
                                            <div class="muted">Co-Author(s): <?php echo h(formatCoAuthorsForDisplay($book['co_authors'])); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo h($book['book_number']); ?><?php echo (int)$book['total_copies'] > 1 ? '<div class="muted">+ ' . ((int)$book['total_copies'] - 1) . ' more</div>' : ''; ?></td>
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
                                    <td><?php echo h($book['location_collection']); ?></td>
                                    <td><?php echo (int)$book['total_copies']; ?></td>
                                    <td><strong><?php echo (int)$book['available_copies']; ?></strong></td>
                                    <td><?php echo (int)$book['borrowed_copies']; ?></td>
                                    <td><?php echo (int)$book['lost_copies']; ?></td>
                                    <td><?php echo (int)$book['is_archived'] === 1 ? '<span class="badge badge-danger">Archived</span>' : getStatusBadge($book['available_copies'], $book['total_copies'], $book['book_status']); ?></td>
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
                                                'book_condition' => (($book['book_condition'] ?? 'New') === 'Old' ? 'Old' : ($book['book_condition'] ?? 'New')),
                                                'location_collection' => $book['location_collection'],
                                                'library_building' => $book['library_building'],
                                                'shelf_number' => $book['shelf_number'],
                                                'library_section' => $book['library_section'],
                                                'qr_code' => $book['qr_code'],
                                                'total_copies' => (int)$book['total_copies'],
                                                'available_copies' => (int)$book['available_copies'],
                                                'borrowed_copies' => (int)$book['borrowed_copies'],
                                                'lost_copies' => (int)$book['lost_copies'],
                                                'damaged_copies' => (int)$book['damaged_copies'],
                                                'book_status' => $book['book_status'],
                                                'is_archived' => (int)$book['is_archived'],
                                                'archived_at' => $book['archived_at'],
                                                'created_at' => $book['created_at']
                                            ];
                                            $book_modal_json = json_encode($book_modal_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                        ?>
                                        <div class="inventory-action-group">
                                            <?php if ((int)$book['is_archived'] === 1): ?>
                                                <form method="POST" style="display:inline;" data-confirm-title="Restore Book" data-confirm-message="Restore this archived book to the active inventory? Its borrowing history will remain intact." data-confirm-text="Restore Book" data-confirm-danger="0">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="restore_book">
                                                    <input type="hidden" name="book_id" value="<?php echo (int)$book['book_id']; ?>">
                                                    <button type="submit" class="btn-primary" style="padding:7px 14px;font-size:12px;">Restore</button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn" type="button" style="padding:7px 14px;font-size:12px;" onclick="openAddCopiesModal(<?php echo (int)$book['book_id']; ?>,'<?php echo h($book['title']); ?>')">+ Add</button>
                                                <button type="button" class="btn inventory-action-btn" style="padding:7px 14px;font-size:12px;" data-action="edit" data-book-b64="<?php echo base64_encode($book_modal_json); ?>">Edit</button>
                                                <button type="button" class="btn-danger inventory-action-btn" data-action="remove" data-book-b64="<?php echo base64_encode($book_modal_json); ?>">Remove</button>
                                            <?php endif; ?>
                                            <button type="button" class="btn-details inventory-action-btn" data-action="details" data-book-b64="<?php echo base64_encode($book_modal_json); ?>">Details</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($archive_filter !== 'active'): ?>
        <div class="table-section">
            <div class="inventory-left">
                <h2>Archived Physical Book Copies</h2>
                <p class="muted" style="margin-top:4px;">Removed copies are kept here until they are restored or permanently removed.</p>
            </div>
            <?php if (empty($archived_copies)): ?>
                <div class="empty-message">No physical book copies are currently in the archive.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="inventory-compact-table inventory-archive-table" data-inventory-pagination="1">
                        <thead>
                            <tr>
                                <th>Book Number</th>
                                <th>Title / Author</th>
                                <th>Publisher</th>
                                <th>Shelf Number</th>
                                <th>Library Section</th>
                                <th>Condition</th>
                                <th>Archived At</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($archived_copies as $copy): ?>
                                <tr>
                                    <td><strong><?php echo h($copy['book_number']); ?></strong></td>
                                    <td>
                                        <div class="book-title"><?php echo h($copy['title']); ?></div>
                                        <div class="muted">Author: <?php echo h($copy['author']); ?></div>
                                    </td>
                                    <td><?php echo h($copy['publisher']); ?></td>
                                    <td><?php echo h($copy['shelf_number']); ?></td>
                                    <td><?php echo h($copy['library_section']); ?></td>
                                    <td><?php echo h($copy['copy_condition']); ?></td>
                                    <td><?php echo h($copy['archived_at'] ?: '—'); ?></td>
                                    <td>
                                        <div class="inventory-action-group">
                                            <?php if ((int)$copy['parent_is_archived'] === 0): ?>
                                            <form method="POST" style="display:inline;" data-confirm-title="Restore Book Copy" data-confirm-message="Restore <?php echo h($copy['book_number']); ?> back to the active catalogue?" data-confirm-text="Restore Copy" data-confirm-danger="0">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="restore_copy">
                                                <input type="hidden" name="copy_id" value="<?php echo (int)$copy['copy_id']; ?>">
                                                <button type="submit" class="btn-primary" style="padding:7px 12px;font-size:12px;">Restore</button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="POST" style="display:inline;" data-confirm-title="Remove Permanently" data-confirm-message="Permanently remove <?php echo h($copy['book_number']); ?> — <?php echo h($copy['title']); ?> from the archive? This cannot be undone. Transaction history will remain, but this physical copy record will be deleted." data-confirm-text="Remove Permanently" data-confirm-danger="1">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="action" value="permanently_remove_copy">
                                                <input type="hidden" name="copy_id" value="<?php echo (int)$copy['copy_id']; ?>">
                                                <button type="submit" class="btn-danger" style="padding:7px 12px;font-size:12px;">Remove Permanently</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div id="addBookSection" class="add-book-section" style="display:none;">
            <div class="add-book-toggle" onclick="toggleAddBookForm()">
                <h2>Add New Book</h2>
                <span class="toggle-icon open" id="addBookToggleIcon">×</span>
            </div>
            <div id="addBookFormWrapper">
                <div class="modal-note" style="margin-bottom:16px;">Fill out the complete book details below. Every physical copy receives its own automatic <strong>BK-###</strong> book number, unique QR ID, and QR image.</div>
                <form method="POST" id="addBookForm">
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
                            <input type="text" id="book_number" value="Auto-generated for every copy" readonly disabled>
                        </div>
                        <div class="form-group">
                            <label for="book_pages">Book Pages *</label>
                            <input type="number" id="book_pages" name="book_pages" min="1" required placeholder="e.g. 320">
                        </div>
                    </div>

                    <div class="form-row three">
                        <div class="form-group">
                            <label for="book_condition">Book Condition *</label>
                            <select id="book_condition" name="book_condition" required>
                                <option value="New">New</option>
                                <option value="Old">Old</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="location_collection">Location - Collection *</label>
                            <input type="text" id="location_collection" name="location_collection" required placeholder="e.g. Filipiniana Section / Shelf A1">
                        </div>
                        <div class="form-group">
                            <label for="library_building">Building / Room</label>
                            <input type="text" id="library_building" name="library_building" placeholder="e.g. Main Library">
                        </div>
                        <div class="form-group">
                            <label for="shelf_number">Shelf Number *</label>
                            <input type="text" id="shelf_number" name="shelf_number" required placeholder="e.g. A-04">
                        </div>
                        <div class="form-group">
                            <label for="library_section">Library Section *</label>
                            <input type="text" id="library_section" name="library_section" required placeholder="e.g. Science Section">
                        </div>
                        <div class="form-group">
                            <label for="total_copies">Number of Copies *</label>
                            <input type="number" id="total_copies" name="total_copies" min="1" max="100" step="1" value="1" required placeholder="e.g. 10">
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
                            <label for="publisher">Publisher *</label>
                            <input type="text" id="publisher" name="publisher" required placeholder="e.g. Rex Book Store">
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
                <code>Title, Author, Co-Authors, Place of Publication, Date, Book Pages, Source of Funds, Cost Price, Publisher, Edition, Volumes, Class, Location Collection, Building, Shelf Number, Library Section, Total Copies</code><br>
                Book Number is generated automatically for every physical copy as BK-001, BK-002, BK-003, and so on. Book Pages, Publisher, Shelf Number, and Library Section are required. Source of Funds, Cost Price, Edition, Volumes, Class, Building, and Co-authors are optional. Co-authors can be separated with semicolons or placed on separate lines.
            </div>
            <form method="POST" enctype="multipart/form-data" id="bulkAddBooksForm">
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
        <div class="modal-content" style="max-width:700px;">
            <div class="modal-header">
                <h2>Remove Book</h2>
                <button class="close" type="button" onclick="closeRemoveBookModal()">&times;</button>
            </div>

            <div class="form-group">
                <label for="removeBookSelector">Choose Which Book Copy to Remove *</label>
                <select id="removeBookSelector" onchange="handleRemoveBookSelection()">
                    <option value="">Select a book copy / book number</option>
                </select>
                <div class="help-text">Each physical copy has its own book number. Select the exact copy you want to remove.</div>
            </div>

            <div id="removeBookDetailsPanel" class="modal-note" style="display:none;line-height:1.7;">
                <strong>Selected Physical Copy</strong>
                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 18px;margin-top:10px;">
                    <div><strong>Book Number:</strong> <span id="removeDetailBookNumber">&mdash;</span></div>
                    <div><strong>Title:</strong> <span id="removeBookTitle">&mdash;</span></div>
                    <div><strong>Author:</strong> <span id="removeDetailAuthor">&mdash;</span></div>
                    <div><strong>Publisher:</strong> <span id="removeDetailPublisher">&mdash;</span></div>
                    <div><strong>Shelf Number:</strong> <span id="removeDetailShelf">&mdash;</span></div>
                    <div><strong>Library Section:</strong> <span id="removeDetailSection">&mdash;</span></div>
                    <div><strong>Copy Condition:</strong> <span id="removeDetailCondition">&mdash;</span></div>
                    <div><strong>Copy Status:</strong> <span id="removeDetailStatus">&mdash;</span></div>
                    <div><strong>Other Active Copies:</strong> <span id="removeDetailOtherCopies">&mdash;</span></div>
                </div>
            </div>

            <div id="removeBookActions" style="display:none;margin-top:18px;">
                <div id="removeBookAvailabilityNote" class="remove-book-note" style="margin-bottom:12px;"></div>
                <form method="POST" id="removeBookForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="remove_book">
                    <input type="hidden" name="copy_id" id="removeBookCopyId">
                    <button type="button" class="btn-danger" style="width:100%;padding:11px 14px;font-size:13px;" onclick="confirmRemoveBook()">Remove Book</button>
                </form>
            </div>

            <div class="modal-buttons" style="justify-content:flex-end;">
                <button type="button" class="btn-secondary" onclick="closeRemoveBookModal()">Cancel</button>
            </div>
        </div>
    </div>

    <div id="bookDetailsModal" class="modal">
        <div class="modal-content book-details-modal-content">
            <div class="modal-header details-modal-header">
                <div class="details-heading">
                    <h2 id="bookDetailsHeading">Book Details</h2>
                </div>
                <button class="close" type="button" onclick="closeBookDetailsModal()">&times;</button>
            </div>
            <div class="form-group" style="margin:4px 0 18px;">
                <label for="detailBookSelector">Select Book Number *</label>
                <select id="detailBookSelector" onchange="handleDetailBookSelection()">
                    <option value="">Select a physical book copy</option>
                </select>
                <div class="help-text">Select a specific physical copy. The details below will update to that copy.</div>
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
                    <div class="book-detail-label">Book Condition</div>
                    <div class="book-detail-value" id="detailCondition">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Location / Collection</div>
                    <div class="book-detail-value" id="detailLocation">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Building / Room</div>
                    <div class="book-detail-value" id="detailBuilding">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Shelf Number</div>
                    <div class="book-detail-value" id="detailShelf">—</div>
                </div>
                <div class="book-detail-item">
                    <div class="book-detail-label">Library Section</div>
                    <div class="book-detail-value" id="detailLibrarySection">—</div>
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
                    <div class="book-detail-label">Damaged Copies</div>
                    <div class="book-detail-value" id="detailDamagedCopies">—</div>
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
                <img id="detailQrImage" src="" alt="Primary Physical Copy QR Code">
                <div>
                    <div class="book-detail-label">Primary Physical Copy</div>
                    <div class="book-detail-value" id="detailBookNumberQr">—</div>
                    <div class="book-detail-label" style="margin-top:8px;">QR ID</div>
                    <div class="book-detail-value" id="detailQrCode">—</div>
                    <a id="detailQrDownload" class="btn-primary" href="#" style="text-decoration:none;display:inline-block;margin-top:10px;padding:9px 14px;">Download QR</a>
                </div>
            </div>
            <div style="margin-top:18px;">
                <div class="book-detail-label" style="margin-bottom:8px;">Individual Physical Copies</div>
                <div id="detailCopiesGrid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;">Loading copies...</div>
            </div>
            <div class="modal-buttons" style="justify-content:flex-end;">
                <button type="button" class="btn-secondary" onclick="closeBookDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <div id="editBookModal" class="modal">
        <div class="modal-content book-edit-modal-content">
            <div class="modal-header edit-modal-header">
                <div>
                    <h2>Edit Book Details</h2>
                    <p class="modal-subtitle">Update the catalog information and the selected physical copy.</p>
                </div>
                <button class="close" type="button" onclick="closeEditBookModal()">&times;</button>
            </div>

            <div class="form-group" style="margin-top:4px;">
                <label for="editBookSelector">Choose Which Book Copy to Edit *</label>
                <select id="editBookSelector" onchange="handleEditBookSelection()">
                    <option value="">Select a book copy / book number</option>
                </select>
                <div class="help-text">Choose the exact physical copy you want to edit. Its book number identifies that copy. Copy status and condition apply only to this copy; catalogue details such as title, publisher, shelf, and section are shared by the title.</div>
            </div>

            <div id="editBookSelectionDetails" class="modal-note" style="display:none;line-height:1.7;margin-bottom:18px;">
                <strong>Selected Physical Copy</strong>
                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 18px;margin-top:10px;">
                    <div><strong>Book Number:</strong> <span id="editSelectedBookNumber">—</span></div>
                    <div><strong>Title:</strong> <span id="editSelectedBookTitle">—</span></div>
                    <div><strong>Author:</strong> <span id="editSelectedBookAuthor">—</span></div>
                    <div><strong>Publisher:</strong> <span id="editSelectedBookPublisher">—</span></div>
                    <div><strong>Shelf Number:</strong> <span id="editSelectedBookShelf">—</span></div>
                    <div><strong>Library Section:</strong> <span id="editSelectedBookSection">—</span></div>
                </div>
            </div>

            <form method="POST" id="editBookForm" style="display:none;">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="edit_book">
                <input type="hidden" name="book_id" id="editBookId">
                <input type="hidden" name="copy_id" id="editCopyId">

                <div class="edit-section-title">Basic Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="editTitle">Title *</label>
                        <input type="text" id="editTitle" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="editAuthor">Author *</label>
                        <input type="text" id="editAuthor" name="author" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="editCoAuthors">Co-Author(s)</label>
                        <input type="text" id="editCoAuthors" name="co_authors" placeholder="Separate names with semicolons">
                    </div>
                    <div class="form-group">
                        <label for="editPlace">Place of Publication *</label>
                        <input type="text" id="editPlace" name="place_of_publication" required>
                    </div>
                </div>

                <div class="edit-section-title">Publication Details</div>
                <div class="form-row three">
                    <div class="form-group">
                        <label for="editPublicationDate">Date Published *</label>
                        <input type="date" id="editPublicationDate" name="publication_date" required>
                    </div>
                    <div class="form-group">
                        <label for="editBookNumber">Book Number *</label>
                        <input type="text" id="editBookNumber" name="book_number" required readonly title="Each copy's number is generated automatically">
                    </div>
                    <div class="form-group">
                        <label for="editBookPages">Book Pages *</label>
                        <input type="number" id="editBookPages" name="book_pages" min="1" required>
                    </div>
                </div>

                <div class="form-row three">
                    <div class="form-group">
                        <label for="editSourceFunds">Source of Funds</label>
                        <input type="text" id="editSourceFunds" name="source_of_funds">
                    </div>
                    <div class="form-group">
                        <label for="editCostPrice">Cost Price</label>
                        <input type="number" id="editCostPrice" name="cost_price" min="0" step="0.01">
                    </div>
                    <div class="form-group">
                        <label for="editPublisher">Publisher *</label>
                        <input type="text" id="editPublisher" name="publisher" required>
                    </div>
                </div>

                <div class="form-row three">
                    <div class="form-group">
                        <label for="editEdition">Edition</label>
                        <input type="text" id="editEdition" name="edition">
                    </div>
                    <div class="form-group">
                        <label for="editVolumes">Volumes</label>
                        <input type="text" id="editVolumes" name="volumes">
                    </div>
                    <div class="form-group">
                        <label for="editClass">Class</label>
                        <input type="text" id="editClass" name="class">
                    </div>
                </div>

                <div class="edit-section-title">Classification &amp; Collection</div>
                <div class="form-row three">
                    <div class="form-group">
                        <label for="editLocation">Location / Collection *</label>
                        <input type="text" id="editLocation" name="location_collection" required>
                    </div>
                    <div class="form-group">
                        <label for="editBookCondition">Book Condition *</label>
                        <select id="editBookCondition" name="book_condition" required>
                            <option value="New">New</option>
                            <option value="Old">Old</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editStatus">Copy Status *</label>
                        <select id="editStatus" name="copy_status" required>
                            <option value="available">Available</option>
                            <option value="borrowed">Borrowed</option>
                            <option value="damaged">Damaged</option>
                            <option value="lost">Lost</option>
                        </select>
                    </div>
                </div>

                <div class="edit-section-title">Physical Location</div>
                <div class="form-row three">
                    <div class="form-group">
                        <label for="editBuilding">Building / Room</label>
                        <input type="text" id="editBuilding" name="library_building">
                    </div>
                    <div class="form-group">
                        <label for="editShelf">Shelf Number *</label>
                        <input type="text" id="editShelf" name="shelf_number" required>
                    </div>
                    <div class="form-group">
                        <label for="editLibrarySection">Library Section *</label>
                        <input type="text" id="editLibrarySection" name="library_section" required>
                    </div>
                </div>

                <div class="edit-section-title">Status</div>
                <div class="modal-note">
                    The selected book number identifies one physical copy. Changing the copy status or condition affects only this copy; shared catalogue details apply to the title.
                </div>

                <div class="modal-buttons">
                    <button type="submit" class="btn-primary">Save Changes</button>
                    <button type="button" class="btn-secondary" onclick="closeEditBookModal()">Cancel</button>
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

        document.addEventListener('DOMContentLoaded', function() {
            fillBookSelector('removeBookSelector');
            fillBookSelector('editBookSelector');
        });

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
            const search = (document.getElementById('searchInput')?.value || '').trim().toLowerCase();
            const statusCondition = document.getElementById('statusConditionFilter')?.value || '';
            const classValue = (document.getElementById('classFilter')?.value || '').trim();
            const sectionValue = (document.getElementById('sectionFilter')?.value || '').trim();

            const parts = statusCondition.split(':');
            const filterType = parts[0] || '';
            const filterValue = (parts.slice(1).join(':') || '').toLowerCase();
            const rows = document.querySelectorAll('.inventory-active-table tbody tr[data-search]');

            rows.forEach(row => {
                const rowSearch = (row.dataset.search || '').toLowerCase();
                const statusSet = new Set((row.dataset.statuses || row.dataset.status || '').toLowerCase().split(/\s+/).filter(Boolean));
                const conditionSet = new Set((row.dataset.conditions || row.dataset.condition || '').toLowerCase().split('||').filter(Boolean));
                const matchSearch = !search || rowSearch.includes(search);
                const matchStatus = filterType !== 'status' || statusSet.has(filterValue);
                const matchCondition = filterType !== 'condition' || conditionSet.has(filterValue);
                const matchClass = !classValue || row.dataset.class === classValue;
                const matchSection = !sectionValue || row.dataset.section === sectionValue;
                const matches = matchSearch && matchStatus && matchCondition && matchClass && matchSection;

                row.dataset.filterHidden = matches ? '0' : '1';
                const subRow = row.nextElementSibling;
                if (subRow && subRow.classList.contains('copies-row')) {
                    subRow.style.display = (!matches || subRow.dataset.open !== '1') ? 'none' : '';
                }
            });

            const table = document.querySelector('.inventory-active-table');
            if (window.TablePagination && table) window.TablePagination.resetTable(table);
            updateInventoryVisibleCount();
        }

        function populateInventoryFilters() {
            const rows = document.querySelectorAll('tbody tr[data-search]');

            function fill(selectId, dataKey) {
                const select = document.getElementById(selectId);
                if (!select) return;

                const values = new Set();
                rows.forEach(row => {
                    const value = (row.dataset[dataKey] || '').trim();
                    if (value) values.add(value);
                });

                Array.from(values).sort((a, b) => a.localeCompare(b)).forEach(value => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = value;
                    select.appendChild(option);
                });
            }

            fill('classFilter', 'class');
            fill('sectionFilter', 'section');
        }

        function updateInventoryVisibleCount() {
            const rows = document.querySelectorAll('.inventory-active-table tbody tr[data-search]');
            let visible = 0;

            rows.forEach(row => {
                if (row.dataset.filterHidden !== '1') visible++;
            });

            const counter = document.getElementById('inventoryVisibleCount');
            if (counter) counter.textContent = visible;
        }


        function applyInventoryFilters() {
            const value = document.getElementById('archiveFilter')?.value || 'active';
            const url = new URL(window.location.href);
            url.searchParams.set('archive_filter', value);
            window.location.href = url.toString();
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

        /* ── Book Selection Data ── */
        const inventoryCopies = <?php echo json_encode($book_selection_list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function detailText(value) {
            return value !== null && value !== undefined && String(value).trim() !== '' ? String(value) : '—';
        }

        function parseBookButtonData(button) {
            try {
                if (button?.dataset?.bookB64) {
                    const binary = atob(button.dataset.bookB64);
                    const bytes = Uint8Array.from(binary, c => c.charCodeAt(0));
                    return JSON.parse(new TextDecoder('utf-8').decode(bytes));
                }
                return JSON.parse(button?.dataset?.book || '{}');
            } catch (error) {
                console.error('Unable to read book data:', error);
                return null;
            }
        }

        function activeInventoryCopies() {
            return inventoryCopies.slice();
        }

        function fillBookSelector(selectId, includePlaceholder = true, bookId = null) {
            const select = document.getElementById(selectId);
            if (!select) return;
            select.innerHTML = includePlaceholder ? '<option value="">Select a book copy / book number</option>' : '';

            const copies = activeInventoryCopies().filter(copy => bookId === null || String(copy.book_id) === String(bookId));
            copies.forEach(copy => {
                const option = document.createElement('option');
                option.value = String(copy.copy_id);
                option.textContent = (copy.book_number || ('Copy #' + copy.copy_id)) + ' — ' + (copy.title || 'Untitled');
                select.appendChild(option);
            });
        }

        function findActiveCopyById(copyId) {
            return activeInventoryCopies().find(copy => String(copy.copy_id) === String(copyId)) || null;
        }

        function setRemoveBookSelection(copy) {
            const selector = document.getElementById('removeBookSelector');
            const panel = document.getElementById('removeBookDetailsPanel');
            const actions = document.getElementById('removeBookActions');
            if (!selector || !panel || !actions) return;

            if (!copy || Number(copy.is_archived || 0) === 1 || String(copy.copy_status) === 'removed') {
                selector.value = '';
                panel.style.display = 'none';
                actions.style.display = 'none';
                return;
            }

            selector.value = String(copy.copy_id);
            document.getElementById('removeDetailBookNumber').textContent = detailText(copy.book_number);
            document.getElementById('removeBookTitle').textContent = detailText(copy.title);
            document.getElementById('removeDetailAuthor').textContent = detailText(copy.author);
            document.getElementById('removeDetailPublisher').textContent = detailText(copy.publisher);
            document.getElementById('removeDetailShelf').textContent = detailText(copy.shelf_number);
            document.getElementById('removeDetailSection').textContent = detailText(copy.library_section);
            document.getElementById('removeDetailCondition').textContent = detailText(copy.copy_condition || copy.book_condition || 'New');
            document.getElementById('removeDetailStatus').textContent = detailText(copy.copy_status);

            const total = Number(copy.total_copies || 0);
            const borrowed = Number(copy.borrowed_copies || 0);
            const otherActive = Math.max(0, total - (String(copy.copy_status) === 'removed' ? 0 : 1));
            document.getElementById('removeDetailOtherCopies').textContent = otherActive + (otherActive === 1 ? ' other active copy' : ' other active copies');
            document.getElementById('removeBookCopyId').value = copy.copy_id;

            const unavailable = String(copy.copy_status) === 'borrowed';
            const note = unavailable
                ? 'This copy is currently borrowed and cannot be removed until it is returned.'
                : 'This action will move only ' + copy.book_number + ' to the archive. Other copies of “' + copy.title + '” remain active.';
            document.getElementById('removeBookAvailabilityNote').textContent = note;
            const removeButton = document.querySelector('#removeBookForm button[type="button"]');
            if (removeButton) removeButton.disabled = unavailable;

            panel.style.display = 'block';
            actions.style.display = 'block';
        }

        function handleRemoveBookSelection() {
            const selector = document.getElementById('removeBookSelector');
            setRemoveBookSelection(findActiveCopyById(selector?.value || ''));
        }

        function openRemoveBookModal(button = null) {
            const baseBook = button ? parseBookButtonData(button) : null;
            const filteredBookId = baseBook && baseBook.book_id ? baseBook.book_id : null;
            fillBookSelector('removeBookSelector', true, filteredBookId);
            setRemoveBookSelection(null);
            const selector = document.getElementById('removeBookSelector');
            const availableCopies = activeInventoryCopies().filter(copy => filteredBookId === null || String(copy.book_id) === String(filteredBookId));
            selector.disabled = !availableCopies.length;
            if (!availableCopies.length) {
                selector.innerHTML = '<option value="">No active book copies available for this title</option>';
            }
            document.getElementById('removeBookModal').classList.add('active');
            setTimeout(() => selector?.focus(), 50);
        }

        function closeRemoveBookModal() {
            document.getElementById('removeBookModal').classList.remove('active');
        }

        function confirmRemoveBook() {
            const selector = document.getElementById('removeBookSelector');
            const selectedCopy = findActiveCopyById(selector?.value || '');
            if (!selectedCopy) {
                showToast('Choose a book copy / book number first.', 'error');
                return;
            }
            if (String(selectedCopy.copy_status) === 'borrowed') {
                showToast('This copy is currently borrowed. Return it first.', 'error');
                return;
            }

            showConfirmModal({
                title: 'Remove Book',
                message: 'Move book copy ' + selectedCopy.book_number + ' — "' + selectedCopy.title + '" to the archive? Only this physical copy will be removed from the active catalogue.',
                confirmText: 'Remove Book',
                cancelText: 'Cancel',
                danger: true
            }).then(function(confirmed) {
                if (!confirmed) return;
                closeRemoveBookModal();
                HTMLFormElement.prototype.submit.call(document.getElementById('removeBookForm'));
            });
        }

        document.getElementById('removeBookModal').addEventListener('click', function(e) {
            if (e.target === this) closeRemoveBookModal();
        });

        /* ── Book Details Modal ── */
        let selectedDetailsBook = null;
        let selectedDetailsBaseBook = null;
        let selectedDetailsCopies = [];

        function renderBookDetails(copy) {
            if (!copy) return;
            selectedDetailsBook = copy;

            document.getElementById('detailTitle').textContent = detailText(copy.title);
            document.getElementById('detailAuthor').textContent = detailText(copy.author);
            document.getElementById('detailCoAuthors').textContent = detailText(copy.co_authors);
            document.getElementById('detailBookNumber').textContent = detailText(copy.book_number);
            document.getElementById('detailBookPages').textContent = detailText(copy.book_pages);
            document.getElementById('detailPublicationPlace').textContent = detailText(copy.place_of_publication);
            document.getElementById('detailPublicationDate').textContent = detailText(copy.publication_date);
            document.getElementById('detailPublisher').textContent = detailText(copy.publisher);
            document.getElementById('detailEdition').textContent = detailText(copy.edition);
            document.getElementById('detailVolumes').textContent = detailText(copy.volumes);
            document.getElementById('detailClass').textContent = detailText(copy.class);
            document.getElementById('detailSourceFunds').textContent = detailText(copy.source_of_funds);
            document.getElementById('detailCostPrice').textContent = copy.cost_price !== null && copy.cost_price !== '' ? '₱' + Number(copy.cost_price).toFixed(2) : '—';
            if (document.getElementById('detailCondition')) document.getElementById('detailCondition').textContent = detailText(copy.copy_condition || copy.book_condition || 'New');
            document.getElementById('detailLocation').textContent = detailText(copy.location_collection);
            document.getElementById('detailBuilding').textContent = detailText(copy.library_building);
            document.getElementById('detailShelf').textContent = detailText(copy.shelf_number);
            document.getElementById('detailLibrarySection').textContent = detailText(copy.library_section);
            document.getElementById('detailTotalCopies').textContent = detailText(copy.total_copies);
            document.getElementById('detailAvailableCopies').textContent = detailText(copy.available_copies);
            document.getElementById('detailBorrowedCopies').textContent = detailText(copy.borrowed_copies);
            document.getElementById('detailLostCopies').textContent = detailText(copy.lost_copies);
            document.getElementById('detailDamagedCopies').textContent = detailText(copy.damaged_copies);

            const statusLabels = {
                available: 'Available',
                borrowed: 'Borrowed',
                damaged: 'Damaged',
                lost: 'Lost',
                removed: 'Archived'
            };
            document.getElementById('detailStatus').textContent = statusLabels[copy.copy_status] || statusLabels[copy.book_status] || 'Not Available';
            document.getElementById('detailCreatedAt').textContent = detailText(copy.created_at);
            document.getElementById('detailQrCode').textContent = detailText(copy.qr_code);
            document.getElementById('detailBookNumberQr').textContent = detailText(copy.book_number);
            document.getElementById('detailQrImage').src = qrDataUrl(copy.qr_code, 300);
            document.getElementById('detailQrDownload').href = qrDataUrl(copy.qr_code, 400);
            document.getElementById('detailQrDownload').setAttribute('download', (copy.book_number || 'book') + '_QR.png');
        }

        function populateDetailBookSelector(baseBook) {
            const selector = document.getElementById('detailBookSelector');
            if (!selector) return;

            selectedDetailsCopies = inventoryCopies.filter(copy => String(copy.book_id) === String(baseBook.book_id));
            selector.innerHTML = '<option value="">Select a physical book copy</option>';

            selectedDetailsCopies.forEach(copy => {
                const option = document.createElement('option');
                option.value = String(copy.copy_id);
                option.textContent = (copy.book_number || ('Copy #' + copy.copy_id)) + ' — ' + (copy.title || 'Untitled');
                selector.appendChild(option);
            });

            if (selectedDetailsCopies.length) {
                selector.value = String(selectedDetailsCopies[0].copy_id);
                renderBookDetails(selectedDetailsCopies[0]);
            } else {
                renderBookDetails(baseBook);
            }
        }

        function handleDetailBookSelection() {
            const selector = document.getElementById('detailBookSelector');
            const copy = selectedDetailsCopies.find(item => String(item.copy_id) === String(selector?.value || ''));
            if (copy) renderBookDetails(copy);
        }

        function openBookDetailsModal(button) {
            const book = parseBookButtonData(button);
            if (!book) {
                showToast('Unable to load the selected book details.', 'error');
                return;
            }

            selectedDetailsBaseBook = book;
            populateDetailBookSelector(book);

            const copiesGrid = document.getElementById('detailCopiesGrid');
            if (copiesGrid) {
                copiesGrid.textContent = 'Loading copies...';
                fetch('/LibraryBorrowingSystem/admin/inventory.php?api=copies&book_id=' + encodeURIComponent(book.book_id))
                    .then(r => r.json())
                    .then(data => {
                        const copies = Array.isArray(data.copies) ? data.copies : [];
                        if (!copies.length) { copiesGrid.textContent = 'No individual physical copies found.'; return; }
                        copiesGrid.innerHTML = copies.map(c => {
                            const status = String(c.copy_status || '').toUpperCase();
                            return '<div style="background:#F7F9FC;border:1px solid #D2E2F6;border-radius:10px;padding:12px;text-align:center;">' +
                                '<img src="' + qrDataUrl(c.qr_code, 180) + '" alt="QR ' + escHtml(c.book_number) + '" style="width:140px;height:140px;object-fit:contain;background:#fff;border-radius:8px;">' +
                                '<div style="font-weight:800;margin-top:7px;">' + escHtml(c.book_number) + '</div>' +
                                '<div style="font-size:11px;color:#52618D;margin:4px 0;">Copy ID: ' + escHtml(c.copy_id) + '</div>' +
                                '<div style="font-size:11px;word-break:break-all;color:#52618D;margin:4px 0;">QR ID: ' + escHtml(c.qr_code) + '</div>' +
                                '<div style="font-size:12px;font-weight:700;">' + escHtml(status + ' · ' + (c.copy_condition || '')) + '</div>' +
                                '<a class="btn-secondary" style="display:inline-block;margin-top:8px;padding:7px 10px;text-decoration:none;border-radius:7px;" href="/LibraryBorrowingSystem/download_qr.php?type=book&code=' + encodeURIComponent(c.qr_code) + '">Download QR</a>' +
                                '</div>';
                        }).join('');
                    })
                    .catch(() => { copiesGrid.textContent = 'Unable to load individual copies.'; });
            }

            document.getElementById('bookDetailsModal').classList.add('active');
        }

        function closeBookDetailsModal() {
            document.getElementById('bookDetailsModal').classList.remove('active');
        }

        document.getElementById('bookDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) closeBookDetailsModal();
        });

        /* ── Edit Book Modal ── */
        function setEditValue(id, value) {
            const el = document.getElementById(id);
            if (el) el.value = value === null || value === undefined ? '' : String(value);
        }

        function renderEditBookSelection(copy) {
            const details = document.getElementById('editBookSelectionDetails');
            const form = document.getElementById('editBookForm');
            const selector = document.getElementById('editBookSelector');

            if (!copy) {
                if (selector) selector.value = '';
                if (details) details.style.display = 'none';
                if (form) form.style.display = 'none';
                return;
            }

            if (Number(copy.is_archived || 0) === 1 || String(copy.copy_status) === 'removed') {
                showToast('Archived copies cannot be edited. Restore the copy first.', 'error');
                renderEditBookSelection(null);
                return;
            }

            selector.value = String(copy.copy_id);
            document.getElementById('editSelectedBookNumber').textContent = detailText(copy.book_number);
            document.getElementById('editSelectedBookTitle').textContent = detailText(copy.title);
            document.getElementById('editSelectedBookAuthor').textContent = detailText(copy.author);
            document.getElementById('editSelectedBookPublisher').textContent = detailText(copy.publisher);
            document.getElementById('editSelectedBookShelf').textContent = detailText(copy.shelf_number);
            document.getElementById('editSelectedBookSection').textContent = detailText(copy.library_section);

            setEditValue('editBookId', copy.book_id);
            setEditValue('editCopyId', copy.copy_id);
            setEditValue('editTitle', copy.title);
            setEditValue('editAuthor', copy.author);
            setEditValue('editCoAuthors', copy.co_authors);
            setEditValue('editPlace', copy.place_of_publication);
            setEditValue('editPublicationDate', copy.publication_date);
            setEditValue('editBookNumber', copy.book_number);
            setEditValue('editBookPages', copy.book_pages);
            setEditValue('editSourceFunds', copy.source_of_funds);
            setEditValue('editCostPrice', copy.cost_price);
            setEditValue('editPublisher', copy.publisher);
            setEditValue('editEdition', copy.edition);
            setEditValue('editVolumes', copy.volumes);
            setEditValue('editClass', copy.class);
            setEditValue('editBookCondition', copy.copy_condition || copy.book_condition || 'New');
            setEditValue('editLocation', copy.location_collection);
            setEditValue('editBuilding', copy.library_building);
            setEditValue('editShelf', copy.shelf_number);
            setEditValue('editLibrarySection', copy.library_section);

            const status = document.getElementById('editStatus');
            if (status) {
                status.value = copy.copy_status || 'available';
                const borrowed = String(copy.copy_status) === 'borrowed';
                status.disabled = false;
                status.title = borrowed ? 'Return the copy before changing its status.' : '';
            }

            if (details) details.style.display = 'block';
            if (form) form.style.display = 'block';
        }

        function handleEditBookSelection() {
            const selector = document.getElementById('editBookSelector');
            renderEditBookSelection(findActiveCopyById(selector?.value || ''));
        }

        function openEditBookModal(button = null) {
            const baseBook = button ? parseBookButtonData(button) : null;
            const filteredBookId = baseBook && baseBook.book_id ? baseBook.book_id : null;
            fillBookSelector('editBookSelector', true, filteredBookId);
            const selector = document.getElementById('editBookSelector');
            const availableCopies = activeInventoryCopies().filter(copy => filteredBookId === null || String(copy.book_id) === String(filteredBookId));
            selector.disabled = !availableCopies.length;
            if (!availableCopies.length) selector.innerHTML = '<option value="">No active book copies available for this title</option>';

            renderEditBookSelection(null);
            document.getElementById('editBookModal').classList.add('active');
            setTimeout(() => selector?.focus(), 50);
        }

        function openEditBookModalFromData() {
            openEditBookModal();
        }

        function closeEditBookModal() {
            document.getElementById('editBookModal').classList.remove('active');
        }

        document.getElementById('editBookModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditBookModal();
        });

        document.addEventListener('click', function(event) {
            const button = event.target.closest('.inventory-action-btn');
            if (!button) return;

            const action = button.dataset.action;
            if (action === 'details') openBookDetailsModal(button);
            if (action === 'remove') openRemoveBookModal(button);
            if (action === 'edit') openEditBookModal(button);
        });

        /* ── QR Modal ── */
        function openQRModal(qrCode, bookTitle) {
            document.getElementById('qrBookInfo').textContent = 'QR ID: ' + qrCode + ' | ' + bookTitle;
            const qrImage = document.getElementById('qrImage');
            try {
                qrImage.src = qrDataUrl(qrCode, 420);
            } catch (e) {
                qrImage.src = '/LibraryBorrowingSystem/qr_codes/' + encodeURIComponent(qrCode) + '.png';
            }
            const downloadBtn = document.getElementById('downloadBookQrBtn');
            downloadBtn.href = '/LibraryBorrowingSystem/download_qr.php?code=' + encodeURIComponent(qrCode) + '&type=book';
            downloadBtn.removeAttribute('download');
            document.getElementById('qrModal').classList.add('show');
        }

        function closeQRModal() {
            document.getElementById('qrModal').classList.remove('show');
        }

        document.getElementById('qrModal').addEventListener('click', function(e) {
            if (e.target === this) closeQRModal();
        });
    
        let inventorySubmitLocked = false;

        document.addEventListener('DOMContentLoaded', function () {
            populateInventoryFilters();
            filterTable();

            ['addBookForm', 'bulkAddBooksForm'].forEach(function(formId) {
                const form = document.getElementById(formId);
                if (!form) return;
                form.addEventListener('submit', function() {
                    if (inventorySubmitLocked) return;
                    inventorySubmitLocked = true;
                    const submit = form.querySelector('button[type="submit"]');
                    if (submit) submit.disabled = true;
                });
            });
        });
</script>
<?php require_once __DIR__ . '/../includes/ui_feedback.php'; ?>

<div id="copiesModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;max-width:900px;width:100%;max-height:90vh;overflow:auto;padding:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <h3 id="copiesTitle" style="margin:0;">Copies</h3>
      <button type="button" class="btn btn-secondary" onclick="document.getElementById('copiesModal').style.display='none'">Close</button>
    </div>
    <div id="copiesBody" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;">Loading...</div>
  </div>
</div>
<script>
function openCopiesModal(bookId, title) {
    const modal = document.getElementById('copiesModal'), body = document.getElementById('copiesBody');
    document.getElementById('copiesTitle').textContent = title + ' - individual copies';
    body.textContent = 'Loading...';
    modal.style.display = 'flex';
    fetch('/LibraryBorrowingSystem/admin/inventory.php?api=copies&book_id=' + bookId)
        .then(r => r.json()).then(d => {
            body.innerHTML = '';
            (d.copies || []).forEach(c => {
                const card = document.createElement('div');
                card.style.cssText = 'border:1px solid #ddd;border-radius:10px;padding:10px;text-align:center;';
                const img = document.createElement('img');
                img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&margin=0&data=' + encodeURIComponent(c.qr_code);
                img.width = 140; img.height = 140; img.alt = 'QR ' + c.book_number;
                const num = document.createElement('div'); num.style.fontWeight = '700'; num.textContent = c.book_number;
                const id = document.createElement('div'); id.style.cssText = 'font-size:11px;color:#52618D;margin-top:4px;'; id.textContent = 'Copy ID: ' + c.copy_id;
                const qr = document.createElement('div'); qr.style.cssText = 'font-size:11px;color:#52618D;word-break:break-all;margin-top:3px;'; qr.textContent = 'QR ID: ' + c.qr_code;
                const st = document.createElement('div'); st.className = 'muted'; st.textContent = c.copy_status + ' | ' + c.copy_condition;
                const dl = document.createElement('a'); dl.className = 'btn btn-secondary'; dl.textContent = 'Download QR';
                dl.href = '/LibraryBorrowingSystem/download_qr.php?type=book&code=' + encodeURIComponent(c.qr_code);
                card.append(img, num, id, qr, st, dl); body.appendChild(card);
            });
            if (!(d.copies || []).length) body.textContent = 'No copies found.';
        }).catch(() => { body.textContent = 'Unable to load copies.'; });
}
</script>

<?php
$bcLastAdd = $GLOBALS['bc_last_add'] ?? null;
$bcCreatedCopies = $bcLastAdd['created'] ?? [];
if (!empty($bcCreatedCopies) && $message_type === 'success'):
?>
<div id="newCopiesModal" style="display:flex;position:fixed;inset:0;background:rgba(15,23,42,.58);z-index:10000;align-items:center;justify-content:center;padding:18px;">
  <div style="background:#fff;border-radius:16px;max-width:980px;width:100%;max-height:90vh;overflow:auto;padding:22px;box-shadow:0 24px 70px rgba(15,23,42,.28);">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;">
      <div>
        <h3 style="margin:0;color:#14213D;">Physical Copy QR Codes Created</h3>
        <div style="font-size:13px;color:#64748B;margin-top:4px;">Each copy below has its own Book Number, Copy ID, QR ID, and QR image.</div>
      </div>
      <button type="button" class="btn btn-secondary" onclick="document.getElementById('newCopiesModal').remove()">Close</button>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;">
      <?php foreach ($bcCreatedCopies as $copy): ?>
        <div style="border:1px solid #D9E5F4;border-radius:12px;padding:14px;text-align:center;background:#F8FBFF;">
          <img class="new-copy-qr" data-qr="<?php echo h($copy['qr_code']); ?>" src="" alt="QR <?php echo h($copy['book_number']); ?>" style="width:180px;height:180px;object-fit:contain;background:#fff;border-radius:8px;">
          <div style="font-size:18px;font-weight:800;color:#14213D;margin-top:8px;"><?php echo h($copy['book_number']); ?></div>
          <div style="font-size:12px;color:#64748B;margin-top:4px;">Copy ID: <?php echo h($copy['copy_id']); ?></div>
          <div style="font-size:12px;color:#52618D;word-break:break-all;margin-top:4px;">QR ID: <?php echo h($copy['qr_code']); ?></div>
          <a class="btn btn-secondary" style="display:inline-block;margin-top:10px;text-decoration:none;" href="/LibraryBorrowingSystem/download_qr.php?type=book&code=<?php echo urlencode($copy['qr_code']); ?>" download="<?php echo h($copy['book_number']); ?>_QR.png">Download QR Image</a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="/LibraryBorrowingSystem/js/qrcode.js"></script>
<script>
/* Local QR generator (works offline, no image files or internet needed) */
function qrDataUrl(text, size) {
    const qr = qrcode(0, 'M'); qr.addData(String(text)); qr.make();
    const n = qr.getModuleCount(), quiet = 4, cell = Math.max(2, Math.ceil((size || 200) / (n + quiet * 2)));
    const px = (n + quiet * 2) * cell, cv = document.createElement('canvas');
    cv.width = cv.height = px;
    const ctx = cv.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, px, px); ctx.fillStyle = '#000';
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (qr.isDark(r, c)) ctx.fillRect((c + quiet) * cell, (r + quiet) * cell, cell, cell);
    return cv.toDataURL('image/png');
}
function escHtml(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

document.querySelectorAll('.new-copy-qr').forEach(img => { try { img.src = qrDataUrl(img.dataset.qr, 240); } catch (e) {} });

function toggleCopies(btn, bookId, title) {
    const row = btn.closest('tr');
    let sub = row.nextElementSibling;
    if (sub && sub.classList.contains('copies-row')) {
        const open = sub.dataset.open !== '1';
        sub.dataset.open = open ? '1' : '0';
        sub.style.display = open ? '' : 'none';
        btn.innerHTML = btn.innerHTML.replace(open ? '\u25B8' : '\u25BE', open ? '\u25BE' : '\u25B8');
        return;
    }
    sub = document.createElement('tr');
    sub.className = 'copies-row'; sub.dataset.open = '1';
    const td = document.createElement('td');
    td.colSpan = row.children.length;
    td.style.cssText = 'background:#F5F9FF;padding:14px;';
    td.textContent = 'Loading copies...';
    sub.appendChild(td);
    row.after(sub);
    btn.innerHTML = btn.innerHTML.replace('\u25B8', '\u25BE');
    fetch('/LibraryBorrowingSystem/admin/inventory.php?api=copies&book_id=' + bookId)
        .then(r => r.json()).then(d => {
            const copies = d.copies || [];
            if (!copies.length) { td.textContent = 'No copies found.'; return; }
            let html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;"><strong>' + escHtml(title) + ' &mdash; ' + copies.length + ' separate cop' + (copies.length === 1 ? 'y' : 'ies') + '</strong>' +
                '<button type="button" class="btn btn-secondary" id="printLabels' + bookId + '">Print all QR labels</button></div>' +
                '<table style="width:100%;border-collapse:collapse;background:#fff;"><thead><tr style="text-align:left;border-bottom:2px solid #D2E2F6;"><th style="padding:8px;">QR</th><th>Copy ID</th><th>Book No.</th><th>QR ID</th><th>Status</th><th>Condition</th><th>Download</th></tr></thead><tbody>';
            copies.forEach(c => {
                const url = qrDataUrl(c.qr_code, 200);
                html += '<tr style="border-bottom:1px solid #E5EEF9;"><td style="padding:8px;"><img src="' + url + '" width="72" height="72" alt="QR"></td>' +
                    '<td>' + escHtml(c.copy_id) + '</td><td><strong>' + escHtml(c.book_number) + '</strong></td><td style="font-size:12px;word-break:break-all;">' + escHtml(c.qr_code) + '</td>' +
                    '<td>' + escHtml(c.copy_status) + '</td><td>' + escHtml(c.copy_condition) + '</td>' +
                    '<td><a class="btn btn-secondary" download="' + escHtml(c.book_number) + '_QR.png" href="' + url + '">Download QR</a></td></tr>';
            });
            td.innerHTML = html + '</tbody></table>';
            document.getElementById('printLabels' + bookId).onclick = () => {
                window.open('/LibraryBorrowingSystem/admin/qr_print.php?book_id=' + encodeURIComponent(bookId), '_blank', 'noopener');
            };
        }).catch(() => { td.textContent = 'Unable to load copies.'; });
}
</script>
</body>
</html>
