<?php
/**
 * Student Records - Library Borrowing System
 * Includes inline search/filter, Add Student form, and
 * a "View" button per row that opens a centred popup modal with full
 * student details + borrowing history.
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

// ── Access control ──────────────────────────────────────────────────────────
if (!isSuperAdmin() && !isLibrarian()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

// ── QR Code directory ────────────────────────────────────────────────────────
$qr_dir = __DIR__ . '/../qr_codes';
if (!is_dir($qr_dir)) {
    mkdir($qr_dir, 0755, true);
}

/**
 * Generate a unique student QR code and save image
 */
function generateQRCode($student_id) {
    global $qr_dir;
    $filename  = $student_id . '.png';
    $filepath  = $qr_dir . '/' . $filename;
    $qr_image_url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($student_id);
    try {
        $image_content = @file_get_contents($qr_image_url, false, stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]));
        if ($image_content === false) throw new Exception('Failed to download QR code from API');
        if (file_put_contents($filepath, $image_content) === false) throw new Exception('Failed to save QR code image');
        return 'qr_codes/' . $filename;
    } catch (Exception $e) {
        logError('QR Code generation error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Generate unique student QR code identifier
 * Format: STU-YYYYMMDD-XXXXX
 */
function generateUniqueStudentId() {
    $date   = date('Ymd');
    $random = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 5));
    return 'STU-' . $date . '-' . $random;
}


/**
 * Ensure the student detail columns exist so the Student Information table can
 * display the complete record even on older databases.
 */
function ensureStudentDetailColumns($conn) {
    $columns = [
        'student_no'       => "ALTER TABLE students ADD COLUMN student_no VARCHAR(100) NULL AFTER student_id",
        'student_group'    => "ALTER TABLE students ADD COLUMN student_group VARCHAR(100) NULL AFTER full_name",
        'department'       => "ALTER TABLE students ADD COLUMN department VARCHAR(150) NULL AFTER student_group",
        'year_level'       => "ALTER TABLE students ADD COLUMN year_level VARCHAR(50) NULL AFTER department",
        'card_valid_until' => "ALTER TABLE students ADD COLUMN card_valid_until DATE NULL AFTER contact_number",
        'email'            => "ALTER TABLE students ADD COLUMN email VARCHAR(150) NULL AFTER card_valid_until"
    ];

    foreach ($columns as $column => $sql) {
        $safe_column = $conn->real_escape_string($column);
        $check = $conn->query("SHOW COLUMNS FROM students LIKE '$safe_column'");
        if ($check && $check->num_rows === 0) {
            @$conn->query($sql);
        }
    }
}
ensureStudentDetailColumns($conn);

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
        return null;
    }
    return date('Y-m-d', $timestamp);
}

// ── Student add/import helpers ─────────────────────────────────────────────────
$message      = '';
$message_type = '';

function createStudentRecord($conn, $student_no, $full_name, $student_group, $department, $year_level, $contact_number, $card_valid_until, $email, &$generated_qr = '', &$error = '') {
    $student_no = trim((string)$student_no);
    $full_name = trim((string)$full_name);
    $student_group = trim((string)$student_group);
    $department = trim((string)$department);
    $year_level = trim((string)$year_level);
    $contact_number = trim((string)$contact_number);
    $card_valid_until = normalizeDateValue($card_valid_until);
    $email = trim((string)$email);

    if ($full_name === '') {
        $error = 'Full name is required.';
        return false;
    }
    if (strlen($full_name) < 3) {
        $error = 'Full name must be at least 3 characters long.';
        return false;
    }
    if ($contact_number !== '' && !preg_match('/^[0-9\s\-\+\(\)]*$/', $contact_number)) {
        $error = 'Invalid contact number format.';
        return false;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
        return false;
    }

    try {
        do {
            $student_qr_code = generateUniqueStudentId();
            $check_stmt = $conn->prepare("SELECT student_id FROM students WHERE qr_code = ? LIMIT 1");
            $check_stmt->bind_param('s', $student_qr_code);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->num_rows > 0;
            $check_stmt->close();
        } while ($exists);

        generateQRCode($student_qr_code);
        $insert_stmt = $conn->prepare("INSERT INTO students (student_no, full_name, student_group, department, year_level, contact_number, card_valid_until, email, qr_code, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
        $insert_stmt->bind_param('sssssssss', $student_no, $full_name, $student_group, $department, $year_level, $contact_number, $card_valid_until, $email, $student_qr_code);
        $ok = $insert_stmt->execute();
        if (!$ok) {
            $error = 'Error adding student: ' . $conn->error;
            $insert_stmt->close();
            return false;
        }
        $insert_stmt->close();
        $generated_qr = $student_qr_code;
        return true;
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
        return false;
    }
}

function normalizeImportHeader($header) {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$header)));
}

function parseStudentCsv($path) {
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

function columnIndexFromCellRef($cell_ref) {
    $letters = preg_replace('/[^A-Z]/', '', strtoupper((string)$cell_ref));
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function parseStudentXlsx($path) {
    if (!class_exists('ZipArchive')) {
        throw new Exception('XLSX import requires the PHP ZipArchive extension. You can upload CSV instead.');
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
            $index = columnIndexFromCellRef((string)$cell['r']);
            $type = (string)$cell['t'];
            if ($type === 's') {
                $value = $shared_strings[(int)$cell->v] ?? '';
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

function mapStudentImportHeader($header) {
    $key = normalizeImportHeader($header);
    $map = [
        'name' => 'full_name',
        'fullname' => 'full_name',
        'studentname' => 'full_name',
        'studentno' => 'student_no',
        'studentnumber' => 'student_no',
        'studentid' => 'student_no',
        'schoolid' => 'student_no',
        'group' => 'student_group',
        'studentgroup' => 'student_group',
        'section' => 'student_group',
        'department' => 'department',
        'dept' => 'department',
        'yrlvl' => 'year_level',
        'yearlevel' => 'year_level',
        'yearlvl' => 'year_level',
        'year' => 'year_level',
        'gradelevel' => 'year_level',
        'contactnumber' => 'contact_number',
        'contactno' => 'contact_number',
        'contact' => 'contact_number',
        'phone' => 'contact_number',
        'mobile' => 'contact_number',
        'validityofthelibraryaccesscard' => 'card_valid_until',
        'libraryaccesscardvalidity' => 'card_valid_until',
        'cardvaliduntil' => 'card_valid_until',
        'validuntil' => 'card_valid_until',
        'validity' => 'card_valid_until',
        'email' => 'email',
        'emailaddress' => 'email'
    ];
    return $map[$key] ?? null;
}

function rowsToStudentImportData($rows) {
    if (empty($rows)) {
        return [];
    }

    $headers = array_shift($rows);
    $mapped_headers = [];
    foreach ($headers as $index => $header) {
        $field = mapStudentImportHeader($header);
        if ($field) {
            $mapped_headers[$index] = $field;
        }
    }

    if (!in_array('full_name', $mapped_headers, true)) {
        array_unshift($rows, $headers);
        $mapped_headers = [0 => 'full_name', 1 => 'student_no', 2 => 'student_group', 3 => 'department', 4 => 'year_level', 5 => 'contact_number', 6 => 'card_valid_until', 7 => 'email'];
    }

    $students = [];
    foreach ($rows as $row) {
        $data = [
            'student_no' => '',
            'full_name' => '',
            'student_group' => '',
            'department' => '',
            'year_level' => '',
            'contact_number' => '',
            'card_valid_until' => '',
            'email' => ''
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
            $students[] = $data;
        }
    }
    return $students;
}

// ── Handle Add Student form ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $generated_qr = '';
    $error = '';
    if (createStudentRecord(
        $conn,
        $_POST['student_no'] ?? '',
        $_POST['full_name'] ?? '',
        $_POST['student_group'] ?? '',
        $_POST['department'] ?? '',
        $_POST['year_level'] ?? '',
        $_POST['contact_number'] ?? '',
        $_POST['card_valid_until'] ?? '',
        $_POST['email'] ?? '',
        $generated_qr,
        $error
    )) {
        $message = 'Student added successfully! QR Code: ' . htmlspecialchars($generated_qr);
        $message_type = 'success';
        header("Refresh: 2; url=/LibraryBorrowingSystem/superadmin/student_records.php");
    } else {
        $message = htmlspecialchars($error);
        $message_type = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    if (empty($_FILES['students_file']['tmp_name'])) {
        $message = 'Please choose a CSV or XLSX file to import.';
        $message_type = 'error';
    } else {
        try {
            $name = $_FILES['students_file']['name'] ?? '';
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($ext === 'xlsx') {
                $rows = parseStudentXlsx($_FILES['students_file']['tmp_name']);
            } elseif ($ext === 'csv') {
                $rows = parseStudentCsv($_FILES['students_file']['tmp_name']);
            } else {
                throw new Exception('Only CSV and XLSX files are supported.');
            }

            $students_to_import = rowsToStudentImportData($rows);
            if (empty($students_to_import)) {
                throw new Exception('No student records found in the uploaded file.');
            }

            $success_count = 0;
            $errors = [];
            foreach ($students_to_import as $row_num => $student_data) {
                $generated_qr = '';
                $error = '';
                if (createStudentRecord(
                    $conn,
                    $student_data['student_no'] ?? '',
                    $student_data['full_name'] ?? '',
                    $student_data['student_group'] ?? '',
                    $student_data['department'] ?? '',
                    $student_data['year_level'] ?? '',
                    $student_data['contact_number'] ?? '',
                    $student_data['card_valid_until'] ?? '',
                    $student_data['email'] ?? '',
                    $generated_qr,
                    $error
                )) {
                    $success_count++;
                } else {
                    $errors[] = 'Row ' . ($row_num + 2) . ': ' . $error;
                }
            }

            $message = $success_count . ' student' . ($success_count === 1 ? '' : 's') . ' imported successfully.';
            if (!empty($errors)) {
                $message .= ' Some rows were skipped: ' . implode(' | ', array_slice($errors, 0, 5));
            }
            $message_type = $success_count > 0 ? 'success' : 'error';
            if ($success_count > 0) {
                header("Refresh: 2; url=/LibraryBorrowingSystem/superadmin/student_records.php");
            }
        } catch (Exception $e) {
            $message = htmlspecialchars($e->getMessage());
            $message_type = 'error';
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   RECORDS logic
═══════════════════════════════════════════════════════════════════════════ */
$page          = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page      = 20;
$offset        = ($page - 1) * $per_page;

$search_rec    = $_GET['search']  ?? '';
$status_filter = $_GET['status']  ?? '';
$sort_by       = $_GET['sort']    ?? 'full_name';
$sort_order    = $_GET['order']   ?? 'ASC';

$allowed_sorts  = ['full_name', 'student_id', 'student_no', 'student_group', 'department', 'year_level', 'created_at', 'contact_number', 'email', 'card_valid_until'];
$allowed_orders = ['ASC', 'DESC'];
if (!in_array($sort_by,    $allowed_sorts))  $sort_by    = 'full_name';
if (!in_array($sort_order, $allowed_orders)) $sort_order = 'ASC';

$where = "WHERE 1=1";
if (!empty($search_rec)) {
    $sp = '%' . $conn->real_escape_string($search_rec) . '%';
    $where .= " AND (s.full_name LIKE '$sp' OR s.student_no LIKE '$sp' OR s.student_group LIKE '$sp' OR s.department LIKE '$sp' OR s.year_level LIKE '$sp' OR s.contact_number LIKE '$sp' OR s.email LIKE '$sp' OR s.qr_code LIKE '$sp')";
}
if (!empty($status_filter)) {
    $sf = $conn->real_escape_string($status_filter);
    $where .= " AND s.status = '$sf'";
}

$cr             = $conn->query("SELECT COUNT(*) as total FROM students s $where");
$total_students = $cr ? $cr->fetch_assoc()['total'] : 0;
$total_pages    = max(1, ceil($total_students / $per_page));

$qry = "SELECT
            s.student_id, s.student_no, s.full_name, s.student_group, s.department,
            s.year_level, s.contact_number, s.card_valid_until, s.email, s.qr_code,
            s.status, s.created_at, s.updated_at,
            COUNT(t.transaction_id)                                    AS total_borrows,
            SUM(CASE WHEN t.status = 'borrowed' THEN 1 ELSE 0 END)    AS currently_borrowed
        FROM students s
        LEFT JOIN transactions t ON s.student_id = t.student_id
        $where
        GROUP BY s.student_id, s.student_no, s.full_name, s.student_group, s.department,
                 s.year_level, s.contact_number, s.card_valid_until, s.email, s.qr_code,
                 s.status, s.created_at, s.updated_at
        ORDER BY $sort_by $sort_order
        LIMIT $offset, $per_page";

$students_paginated = [];
$res = $conn->query($qry);
if ($res) {
    while ($row = $res->fetch_assoc()) $students_paginated[] = $row;
}

/* ═══════════════════════════════════════════════════════════════════════════
   AJAX – fetch single student detail for modal (JSON)
═══════════════════════════════════════════════════════════════════════════ */
if (isset($_GET['ajax_student']) && is_numeric($_GET['ajax_student'])) {
    $sid = intval($_GET['ajax_student']);
    $out = ['student' => null, 'transactions' => []];

    $ss = $conn->prepare("SELECT student_id, student_no, full_name, student_group, department, year_level, contact_number, card_valid_until, email, qr_code, status, created_at
                           FROM students WHERE student_id = ?");
    $ss->bind_param('i', $sid);
    $ss->execute();
    $sr = $ss->get_result();
    if ($sr->num_rows > 0) {
        $out['student'] = $sr->fetch_assoc();

        $hs = $conn->prepare("
            SELECT t.transaction_id, t.date_borrowed, t.due_date, t.return_date,
                   t.penalty_amount, t.status, b.title, b.author
            FROM transactions t
            JOIN books b ON t.book_id = b.book_id
            WHERE t.student_id = ?
            ORDER BY t.date_borrowed DESC
        ");
        $hs->bind_param('i', $sid);
        $hs->execute();
        $hr = $hs->get_result();
        while ($row = $hr->fetch_assoc()) $out['transactions'][] = $row;
        $hs->close();
    }
    $ss->close();

    header('Content-Type: application/json');
    echo json_encode($out);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Records - Library Borrowing System</title>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #F3F7FC;
            color: #202A44;
            padding-bottom: 50px;
            margin-left: 260px;
        }
        @media (max-width: 992px) { body { margin-left: 0; } }

        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }

        /* ── Page Header ── */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .page-header h1 { font-size: 28px; color: #202A44; }

        /* ── Alert ── */
        .alert {
            padding: 14px 18px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        .alert-success { background: #EDF5DD; color: #344E15; border: 1px solid #B5D27A; }
        .alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* ── Stats grid ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 18px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border-left: 4px solid #141F52;
        }
        .stat-card h4 { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
        .stat-card .value { font-size: 26px; font-weight: 700; color: #202A44; }

        /* ── Filter section ── */
        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.07);
        }
        .filter-section h3 { font-size: 15px; color: #202A44; margin-bottom: 14px; }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
            align-items: end;
        }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 12px; font-weight: 600; margin-bottom: 5px; color: #52618D; }
        .filter-group input,
        .filter-group select {
            padding: 9px 10px;
            border: 1px solid #D2E2F6;
            border-radius: 4px;
            font-size: 13px;
        }
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #141F52;
            box-shadow: 0 0 0 3px rgba(244,249,22,.35);
        }
        .filter-actions { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .filter-action-left { display: flex; gap: 10px; flex-wrap: wrap; }
        .filter-action-right { margin-left: auto; }
        @media (max-width: 600px) { .filter-actions { align-items: stretch; } .filter-action-left, .filter-action-right, .filter-action-right .btn { width: 100%; justify-content: center; } }

        /* ── Add Student section ── */
        .add-student-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.07);
        }
        .add-student-section h3 {
            font-size: 15px;
            color: #202A44;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 2px solid #141F52;
        }
        .add-student-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
        }
        .add-student-toggle h3 { margin-bottom: 0; border-bottom: none; padding-bottom: 0; }
        .toggle-icon {
            font-size: 18px;
            color: #141F52;
            transition: transform 0.2s;
        }
        .toggle-icon.open { transform: rotate(45deg); }
        .add-student-body { margin-top: 16px; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
        .form-group { display: flex; flex-direction: column; }
        .form-group label { font-size: 12px; font-weight: 600; margin-bottom: 5px; color: #52618D; }
        .form-group input {
            padding: 9px 10px;
            border: 1px solid #D2E2F6;
            border-radius: 4px;
            font-size: 13px;
        }
        .form-group input:focus {
            outline: none;
            border-color: #141F52;
            box-shadow: 0 0 0 3px rgba(244,249,22,.35);
        }
        .qr-info {
            background: #EDF3FA;
            padding: 12px 15px;
            border-radius: 5px;
            margin-top: 14px;
            border-left: 4px solid #141F52;
            font-size: 12px;
            color: #52618D;
        }
        .form-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .add-modal-body { padding: 22px 24px 24px; }
        .modal-form-box { max-width: 760px; }
        .modal-mode-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
        .modal-mode-header h3 { font-size: 16px; color: #202A44; }
        .modal-panel { display: block; }
        .modal-panel.hidden { display: none; }
        .bulk-note { background: #EDF3FA; border-left: 4px solid #141F52; padding: 12px 15px; border-radius: 5px; margin: 14px 0; font-size: 12px; color: #52618D; line-height: 1.5; }


        /* ── Buttons ── */
        .btn {
            padding: 10px 22px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }
        .btn-primary   { background: #141F52; color: white; }
        .btn-primary:hover { background: #52618D; }
        .btn-secondary { background: #D2E2F6; color: #202A44; }
        .btn-secondary:hover { background: #ccc; }
        .btn-reset     { background: #6c757d; color: white; }
        .btn-reset:hover { background: #5a6268; }
        .btn-view {
            padding: 6px 14px;
            background: #141F52;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            white-space: nowrap;
        }
        .btn-view:hover { background: #52618D; }

        /* ── Table ── */
        .table-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .table-header {
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            flex-wrap: wrap;
            gap: 10px;
        }
        .table-header h3 { font-size: 16px; color: #202A44; }
        .table-header span { font-size: 13px; color: #888; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead th {
            background: #141F52;
            color: white;
            padding: 12px 14px;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
        }
        tbody td { padding: 11px 14px; border-bottom: 1px solid #E7EEF7; vertical-align: middle; }
        tbody tr:hover { background: #F7F9FC; }

        /* ── Badges ── */
        .badge {
            padding: 4px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
        }
        .badge-active   { background: #EDF5DD; color: #344E15; }
        .badge-inactive { background: #f8d7da; color: #721c24; }

        /* ── Pagination ── */
        .pagination {
            display: flex;
            gap: 6px;
            padding: 16px 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        .pagination a,
        .pagination span {
            padding: 7px 13px;
            border: 1px solid #D2E2F6;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
            color: #141F52;
        }
        .pagination a:hover { background: #141F52; color: white; border-color: #141F52; }
        .pagination .active { background: #141F52; color: white; border-color: #F4F916; }

        .no-data { text-align: center; padding: 40px; color: #aaa; }

        /* ══════════════════════════════════════════
           STUDENT DETAIL MODAL
        ══════════════════════════════════════════ */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1100;
            background: rgba(0,0,0,0.55);
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.show { display: flex; }

        .modal-box {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 720px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            animation: modalIn 0.22s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(-18px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0)     scale(1);    }
        }

        .modal-top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 24px;
            border-bottom: 2px solid #141F52;
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
        }
        .modal-top-bar h2 { font-size: 20px; color: #202A44; }
        .modal-close {
            background: none;
            border: none;
            font-size: 22px;
            cursor: pointer;
            color: #888;
            line-height: 1;
            transition: color 0.2s;
        }
        .modal-close:hover { color: #141F52; }

        /* Loading spinner */
        .modal-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            color: #888;
            gap: 14px;
        }
        .spinner {
            width: 40px; height: 40px;
            border: 4px solid #eee;
            border-top-color: #141F52;
            border-radius: 50%;
            animation: spin 0.75s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Student header inside modal */
        .stu-header { padding: 20px 24px 16px; border-bottom: 1px solid #eee; }
        .stu-header h3 { font-size: 22px; color: #222; }
        .stu-header p  { font-size: 13px; color: #888; margin-top: 4px; }

        /* Info grid */
        .stu-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-bottom: 1px solid #eee;
        }
        .stu-info-item {
            padding: 14px 24px;
            border-right: 1px solid #E7EEF7;
            border-bottom: 1px solid #E7EEF7;
        }
        .stu-info-label { font-size: 11px; text-transform: uppercase; color: #999; letter-spacing: .5px; margin-bottom: 4px; }
        .stu-info-value { font-size: 15px; font-weight: 600; color: #202A44; }

        /* QR section */
        .stu-qr {
            padding: 20px 24px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .stu-qr img { border: 1px solid #D2E2F6; border-radius: 6px; padding: 6px; width: 110px; height: 110px; }
        .stu-qr-info h4 { font-size: 13px; text-transform: uppercase; color: #999; letter-spacing: .5px; margin-bottom: 6px; }
        .stu-qr-code { font-family: monospace; font-size: 13px; color: #52618D; }

        /* Transactions table inside modal */
        .stu-tx { padding: 20px 24px; }
        .stu-tx h3 { font-size: 16px; color: #202A44; margin-bottom: 14px; border-bottom: 2px solid #141F52; padding-bottom: 8px; }
        .stu-tx-table { overflow-x: auto; }
        .stu-tx-table table { font-size: 12px; }
        .stu-tx-table thead th { padding: 10px 12px; }
        .stu-tx-table tbody td { padding: 9px 12px; }

        .status-badge {
            padding: 3px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
        }
        .status-borrowed { background: #FBFDCB; color: #5C5F05; }
        .status-returned { background: #EDF5DD; color: #344E15; }
        .status-overdue  { background: #f8d7da; color: #721c24; }

        .empty-tx { text-align: center; padding: 30px; color: #bbb; font-size: 14px; }

        /* QR modal (for QR image click) */
        .qr-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 1200;
            background: rgba(0,0,0,0.6);
            justify-content: center;
            align-items: center;
        }
        .qr-modal-overlay.show { display: flex; }
        .qr-modal-content {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.25);
            text-align: center;
            min-width: 260px;
        }
        .qr-modal-content h3 { font-size: 18px; color: #202A44; margin-bottom: 4px; }
        .qr-modal-img { width: 200px; height: 200px; margin: 16px auto; display: block; border: 1px solid #eee; border-radius: 6px; }
        .qr-modal-close-btn {
            margin-top: 12px;
            padding: 10px 28px;
            background: #52618D;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }
        .qr-modal-close-btn:hover { background: #202A44; }

        @media (max-width: 768px) {
            .stu-info-grid { grid-template-columns: 1fr; }
            body { margin-left: 0; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>

    <div class="container">

        <div class="page-header">
            <h1>Student Records</h1>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <span><?php echo $message_type === 'success' ? '✓' : '✕'; ?></span>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <h4>Total Students</h4>
                <div class="value"><?php echo number_format($total_students); ?></div>
            </div>
            <div class="stat-card">
                <h4>Active Students</h4>
                <div class="value">
                    <?php
                    $ar = $conn->query("SELECT COUNT(*) AS c FROM students WHERE status='active'");
                    echo number_format($ar ? $ar->fetch_assoc()['c'] : 0);
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <h4>Inactive Students</h4>
                <div class="value">
                    <?php
                    $ir = $conn->query("SELECT COUNT(*) AS c FROM students WHERE status='inactive'");
                    echo number_format($ir ? $ir->fetch_assoc()['c'] : 0);
                    ?>
                </div>
            </div>
        </div>

        <!-- Filters / Search -->
        <div class="filter-section">
            <h3>Search &amp; Filter</h3>
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Search by student information</label>
                        <input type="text" name="search"
                               placeholder="Enter name, student no, group, department, contact, email, or QR code…"
                               value="<?php echo htmlspecialchars($search_rec); ?>">
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="active"   <?php echo $status_filter === 'active'   ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Sort By</label>
                        <select name="sort">
                            <option value="full_name"      <?php echo $sort_by === 'full_name'      ? 'selected' : ''; ?>>Name</option>
                            <option value="student_no"     <?php echo $sort_by === 'student_no'     ? 'selected' : ''; ?>>Student No</option>
                            <option value="student_group"  <?php echo $sort_by === 'student_group'  ? 'selected' : ''; ?>>Group</option>
                            <option value="department"     <?php echo $sort_by === 'department'     ? 'selected' : ''; ?>>Department</option>
                            <option value="year_level"     <?php echo $sort_by === 'year_level'     ? 'selected' : ''; ?>>Yr Lvl</option>
                            <option value="created_at"     <?php echo $sort_by === 'created_at'     ? 'selected' : ''; ?>>Date Added</option>
                            <option value="contact_number" <?php echo $sort_by === 'contact_number' ? 'selected' : ''; ?>>Contact</option>
                            <option value="email"          <?php echo $sort_by === 'email'          ? 'selected' : ''; ?>>Email</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Order</label>
                        <select name="order">
                            <option value="ASC"  <?php echo $sort_order === 'ASC'  ? 'selected' : ''; ?>>Ascending</option>
                            <option value="DESC" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <div class="filter-action-left">
                        <button type="submit" class="btn btn-primary">Search</button>
                        <a href="?" class="btn btn-reset">↺ Reset</a>
                    </div>
                    <div class="filter-action-right">
                        <button type="button" class="btn btn-primary" onclick="scrollToAddForm()">Add Student</button>
                        <button type="button" class="btn btn-secondary" onclick="openBulkModal()">Bulk Add</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- ── Add Student Form (inline page section) ── -->
        <div id="addStudentSection" class="add-student-section" style="display:none;">
            <div class="add-student-toggle" onclick="toggleAddForm()">
                <h3>Add New Student</h3>
                <span class="toggle-icon open" id="addFormToggleIcon">×</span>
            </div>
            <div class="add-student-body" id="addStudentBody">
                <form method="POST" id="addStudentForm">
                    <input type="hidden" name="action" value="add">
                    <div class="form-row" style="margin-top:6px;">
                        <div class="form-group">
                            <label for="inline_full_name">Name *</label>
                            <input type="text" id="inline_full_name" name="full_name" required placeholder="e.g. John Doe">
                        </div>
                        <div class="form-group">
                            <label for="inline_student_no">Student No *</label>
                            <input type="text" id="inline_student_no" name="student_no" required placeholder="e.g. 23-01234">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="inline_student_group">Group *</label>
                            <input type="text" id="inline_student_group" name="student_group" required placeholder="e.g. BSIT">
                        </div>
                        <div class="form-group">
                            <label for="inline_department">Department *</label>
                            <input type="text" id="inline_department" name="department" required placeholder="e.g. College of Computer Studies">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="inline_year_level">Year Level *</label>
                            <input type="text" id="inline_year_level" name="year_level" required placeholder="e.g. 1st Year">
                        </div>
                        <div class="form-group">
                            <label for="inline_contact_number">Contact Number</label>
                            <input type="tel" id="inline_contact_number" name="contact_number" placeholder="e.g. +63-912-345-6789">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="inline_card_valid_until">Validity of Library Access Card *</label>
                            <input type="date" id="inline_card_valid_until" name="card_valid_until" required>
                        </div>
                        <div class="form-group">
                            <label for="inline_email">Email</label>
                            <input type="email" id="inline_email" name="email" placeholder="e.g. student@gmail.com">
                        </div>
                    </div>
                    <div class="qr-info">
                        A unique QR code will be automatically generated when you add the student.
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Save Student</button>
                        <button type="reset" class="btn btn-secondary">Clear</button>
                        <button type="button" class="btn btn-reset" onclick="closeAddForm()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Records Table -->
        <div class="table-section">
            <div class="table-header">
                <h3>Student Information</h3>
                <span><?php echo number_format($total_students); ?> student<?php echo $total_students !== 1 ? 's' : ''; ?> found</span>
            </div>

            <?php if (empty($students_paginated)): ?>
                <div class="no-data">
                    No student records found.
                    <?php echo !empty($search_rec) ? 'Try adjusting your search.' : 'No students registered yet.'; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Student No</th>
                                <th>Name</th>
                                <th>Group</th>
                                <th>Department</th>
                                <th>Yr Lvl</th>
                                <th>Contact Number</th>
                                <th>Card Validity</th>
                                <th>Email</th>
                                <th>QR Code</th>
                                <th>Status</th>
                                <th>Total Borrows</th>
                                <th>Currently Borrowed</th>
                                <th>Date Added</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students_paginated as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['student_no'] ?: 'N/A'); ?></td>
                                    <td><strong><?php echo htmlspecialchars($student['full_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($student['student_group'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['department'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['year_level'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['contact_number'] ?: 'N/A'); ?></td>
                                    <td><?php echo !empty($student['card_valid_until']) ? date('M d, Y', strtotime($student['card_valid_until'])) : 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($student['email'] ?: 'N/A'); ?></td>
                                    <td>
                                        <code style="background:#E7EEF7;padding:3px 7px;border-radius:3px;">
                                            <?php echo htmlspecialchars($student['qr_code']); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $student['status']; ?>">
                                            <?php echo htmlspecialchars($student['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $student['total_borrows'] ?? 0; ?></td>
                                    <td>
                                        <strong style="color:<?php echo ($student['currently_borrowed'] ?? 0) > 0 ? '#c0392b' : '#567D1F'; ?>">
                                            <?php echo $student['currently_borrowed'] ?? 0; ?>
                                        </strong>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($student['created_at'])); ?></td>
                                    <td>
                                        <button class="btn-view"
                                                onclick="openStudentModal(<?php echo (int)$student['student_id']; ?>)">
                                            View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">« First</a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">‹ Prev</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <?php if ($i === $page): ?>
                                <span class="active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next ›</a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">Last »</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        


    </div><!-- /container -->



    <!-- Bulk Add Students Modal -->
    <div id="bulkAddModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="bulkAddModalTitle">
        <div class="modal-box modal-form-box">
            <div class="modal-top-bar">
                <h2 id="bulkAddModalTitle">Bulk Add Students</h2>
                <button class="modal-close" onclick="closeBulkModal()" title="Close">&times;</button>
            </div>
            <div class="add-modal-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import">
                    <div class="form-row">
                        <div class="form-group" style="grid-column:1/-1;">
                            <label for="students_file">Upload CSV or XLSX File</label>
                            <input type="file" id="students_file" name="students_file" accept=".csv,.xlsx" required
                                   style="padding:8px;border:2px dashed #D2E2F6;border-radius:6px;background:#F7F9FC;cursor:pointer;">
                        </div>
                    </div>
                    <div class="bulk-note" style="margin-top:14px;">
                        <strong>Accepted column headers:</strong> Name, Student No, Group, Department, Yr Lvl, Contact Number, Validity of the Library Access Card, Email.<br>
                        Supported file formats: <strong>.CSV</strong> and <strong>.XLSX</strong>
                    </div>
                    <div class="form-actions" style="margin-top:18px;">
                        <button type="submit" class="btn btn-primary">Import Students</button>
                        <button type="button" class="btn btn-secondary" onclick="closeBulkModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         STUDENT DETAIL MODAL (centred popup)
    ══════════════════════════════════════════ -->
    <div id="studentModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-box" id="modalBox">
            <div class="modal-top-bar">
                <h2 id="modalTitle">Student Details</h2>
                <button class="modal-close" onclick="closeStudentModal()" title="Close">&times;</button>
            </div>
            <div id="modalBody">
                <div class="modal-loading">
                    <div class="spinner"></div>
                    <span>Loading student details…</span>
                </div>
            </div>
        </div>
    </div>

    <!-- QR Enlarge Modal -->
    <div id="qrModal" class="qr-modal-overlay">
        <div class="qr-modal-content">
            <h3>QR Code Preview</h3>
            <p id="qrCodeLabel" style="color:#888;font-size:13px;margin-top:4px;"></p>
            <img id="qrImage" src="" alt="QR Code" class="qr-modal-img">
            <div style="margin-top:14px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                <a id="downloadQrBtn" href="#" download class="btn btn-primary">Download QR</a>
                <button class="qr-modal-close-btn" onclick="closeQRModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
    /* ── Add Student Inline Form ── */
    function scrollToAddForm() {
        const section = document.getElementById('addStudentSection');
        section.style.display = 'block';
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(function() {
            const input = document.getElementById('inline_full_name');
            if (input) input.focus();
        }, 400);
    }

    function closeAddForm() {
        const section = document.getElementById('addStudentSection');
        section.style.display = 'none';
    }

    function toggleAddForm() {
        const section = document.getElementById('addStudentSection');
        const body = document.getElementById('addStudentBody');
        const icon = document.getElementById('addFormToggleIcon');
        const isVisible = body.style.display !== 'none';
        body.style.display = isVisible ? 'none' : 'block';
        icon.classList.toggle('open', !isVisible);
    }

    /* ── Bulk Add Modal ── */
    function openBulkModal() {
        document.getElementById('bulkAddModal').classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeBulkModal() {
        document.getElementById('bulkAddModal').classList.remove('show');
        document.body.style.overflow = '';
    }

    document.getElementById('bulkAddModal').addEventListener('click', function(e) {
        if (e.target === this) closeBulkModal();
    });

    <?php if ($message_type === 'error' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (($_POST['action'] ?? '') === 'import'): ?>
        openBulkModal();
        <?php else: ?>
        scrollToAddForm();
        <?php endif; ?>
    });
    <?php endif; ?>
    <?php if ($message_type === 'success' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const section = document.getElementById('addStudentSection');
        if (section) section.style.display = 'none';
    });
    <?php endif; ?>

    /* ── Student Detail Modal ── */
    function openStudentModal(studentId) {
        document.getElementById('modalTitle').textContent = 'Student Details';
        document.getElementById('modalBody').innerHTML =
            '<div class="modal-loading"><div class="spinner"></div><span>Loading student details…</span></div>';
        document.getElementById('studentModal').classList.add('show');
        document.body.style.overflow = 'hidden';

        fetch('?ajax_student=' + studentId)
            .then(r => r.json())
            .then(data => renderModal(data))
            .catch(() => {
                document.getElementById('modalBody').innerHTML =
                    '<div class="modal-loading" style="color:#c0392b;">Failed to load student details.</div>';
            });
    }

    function closeStudentModal() {
        document.getElementById('studentModal').classList.remove('show');
        document.body.style.overflow = '';
    }

    document.getElementById('studentModal').addEventListener('click', function(e) {
        if (e.target === this) closeStudentModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeStudentModal();
            closeBulkModal();
            closeQRModal();
        }
    });

    function renderModal(data) {
        if (!data.student) {
            document.getElementById('modalBody').innerHTML =
                '<div class="modal-loading" style="color:#c0392b;">Student not found.</div>';
            return;
        }

        const s  = data.student;
        const tx = data.transactions;

        document.getElementById('modalTitle').textContent = s.full_name;

        const fmtDate = (str) => {
            if (!str) return '—';
            const d = new Date(str);
            return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: '2-digit' });
        };

        const txBadge = (status) => {
            const map = { borrowed: '#FBFDCB|#5C5F05', returned: '#EDF5DD|#344E15', overdue: '#f8d7da|#721c24' };
            const [bg, color] = (map[status] || '#eee|#52618D').split('|');
            return `<span class="status-badge" style="background:${bg};color:${color};">${status.charAt(0).toUpperCase()+status.slice(1)}</span>`;
        };

        let txHTML = '';
        if (tx.length === 0) {
            txHTML = '<div class="empty-tx">📚 No borrowing history yet.</div>';
        } else {
            const rows = tx.map(t => `
                <tr>
                    <td>
                        <strong>${escHtml(t.title.length > 28 ? t.title.substring(0,28)+'…' : t.title)}</strong><br>
                        <small style="color:#999;">${escHtml(t.author)}</small>
                    </td>
                    <td>${fmtDate(t.date_borrowed)}</td>
                    <td>${fmtDate(t.due_date)}</td>
                    <td>${fmtDate(t.return_date)}</td>
                    <td>${txBadge(t.status)}</td>
                    <td>${parseFloat(t.penalty_amount) > 0
                        ? `<strong style="color:#c0392b;">₱${parseFloat(t.penalty_amount).toFixed(2)}</strong>`
                        : '<span style="color:#bbb;">—</span>'}</td>
                </tr>`).join('');

            txHTML = `
                <div class="stu-tx-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Book</th>
                                <th>Borrowed</th>
                                <th>Due</th>
                                <th>Returned</th>
                                <th>Status</th>
                                <th>Penalty</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>`;
        }

        document.getElementById('modalBody').innerHTML = `
            <div class="stu-header">
                <h3>${escHtml(s.full_name)}</h3>
                <p>Student Since: ${fmtDate(s.created_at)}</p>
            </div>
            <div class="stu-info-grid">
                <div class="stu-info-item">
                    <div class="stu-info-label">Student No</div>
                    <div class="stu-info-value">${escHtml(s.student_no || 'N/A')}</div>
                </div>
                <div class="stu-info-item">
                    <div class="stu-info-label">Name</div>
                    <div class="stu-info-value">${escHtml(s.full_name || 'N/A')}</div>
                </div>
                <div class="stu-info-item">
                    <div class="stu-info-label">Group</div>
                    <div class="stu-info-value">${escHtml(s.student_group || 'N/A')}</div>
                </div>
                <div class="stu-info-item">
                    <div class="stu-info-label">Department</div>
                    <div class="stu-info-value">${escHtml(s.department || 'N/A')}</div>
                </div>
                <div class="stu-info-item">
                    <div class="stu-info-label">Yr Lvl</div>
                    <div class="stu-info-value">${escHtml(s.year_level || 'N/A')}</div>
                </div>
                <div class="stu-info-item">
                    <div class="stu-info-label">Contact Number</div>
                    <div class="stu-info-value">${escHtml(s.contact_number || 'N/A')}</div>
                </div>
                <div class="stu-info-item">
                    <div class="stu-info-label">Card Validity</div>
                    <div class="stu-info-value">${fmtDate(s.card_valid_until)}</div>
                </div>
                <div class="stu-info-item">
                    <div class="stu-info-label">Email</div>
                    <div class="stu-info-value">${escHtml(s.email || 'N/A')}</div>
                </div>
                <div class="stu-info-item">
                    <div class="stu-info-label">Status</div>
                    <div class="stu-info-value" style="color:${s.status==='active'?'#567D1F':'#c0392b'};">
                        ${s.status === 'active' ? '✓ Active' : '✕ Inactive'}
                    </div>
                </div>
                <div class="stu-info-item">
                    <div class="stu-info-label">Total Transactions</div>
                    <div class="stu-info-value">${tx.length}</div>
                </div>
            </div>
            <div class="stu-qr">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=110x110&data=${encodeURIComponent(s.qr_code)}"
                     alt="QR Code"
                     style="cursor:pointer;"
                     onclick="openQRModal('${escHtml(s.qr_code)}')">
                <div class="stu-qr-info">
                    <h4>QR Code ID</h4>
                    <div class="stu-qr-code">${escHtml(s.qr_code)}</div>
                    <small style="color:#aaa;font-size:11px;margin-top:4px;display:block;">Click image to enlarge</small>
                </div>
            </div>
            <div class="stu-tx">
                <h3>Borrowing History (${tx.length})</h3>
                ${txHTML}
            </div>
        `;
    }

    function escHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#39;');
    }

    /* ── QR Enlarge Modal ── */
    function openQRModal(qrCode) {
        const qrPath = '/LibraryBorrowingSystem/qr_codes/' + qrCode + '.png';
        document.getElementById('qrCodeLabel').textContent = 'ID: ' + qrCode;
        document.getElementById('qrImage').src = qrPath;
        const downloadBtn = document.getElementById('downloadQrBtn');
        if (downloadBtn) {
            downloadBtn.href = qrPath;
            downloadBtn.download = qrCode + '_qr.png';
        }
        document.getElementById('qrModal').classList.add('show');
    }
    function closeQRModal() {
        document.getElementById('qrModal').classList.remove('show');
    }
    window.addEventListener('click', function(e) {
        const qm = document.getElementById('qrModal');
        if (e.target === qm) qm.classList.remove('show');
    });
    </script>
</body>
</html>
