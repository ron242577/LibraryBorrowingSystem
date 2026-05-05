<?php
/**
 * Student Records - Library Borrowing System
 * Updated student profile fields, always-visible add form, and bulk CSV/XLSX import.
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

if (!isSuperAdmin() && !isLibrarian()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$qr_dir = __DIR__ . '/../qr_codes';
if (!is_dir($qr_dir)) {
    mkdir($qr_dir, 0755, true);
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function generateQRCodeImage($student_qr_code) {
    global $qr_dir;
    $filename = $student_qr_code . '.png';
    $filepath = $qr_dir . '/' . $filename;

    if (file_exists($filepath)) {
        return 'qr_codes/' . $filename;
    }

    $qr_image_url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($student_qr_code);
    try {
        $image_content = @file_get_contents($qr_image_url, false, stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]));
        if ($image_content !== false) {
            @file_put_contents($filepath, $image_content);
        }
    } catch (Exception $e) {
        logError('QR Code generation warning: ' . $e->getMessage());
    }

    return 'qr_codes/' . $filename;
}

function generateUniqueStudentQRCode($conn) {
    do {
        $code = 'STU-' . date('Ymd') . '-' . strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 5));
        $stmt = $conn->prepare('SELECT student_id FROM students WHERE qr_code = ? LIMIT 1');
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } while ($exists);

    return $code;
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

function normalizeHeaderKey($header) {
    return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string)$header)));
}

function mapImportHeader($header) {
    $key = normalizeHeaderKey($header);
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
        'yrlv' => 'year_level',
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

function rowsToStudentData($rows) {
    if (empty($rows)) {
        return [];
    }

    $headers = array_shift($rows);
    $mapped_headers = [];
    foreach ($headers as $index => $header) {
        $field = mapImportHeader($header);
        if ($field) {
            $mapped_headers[$index] = $field;
        }
    }

    if (!in_array('full_name', $mapped_headers, true) || !in_array('student_no', $mapped_headers, true)) {
        throw new Exception('Import file must include headers for Name and Student No.');
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

        foreach ($mapped_headers as $index => $field) {
            $data[$field] = trim((string)($row[$index] ?? ''));
        }

        if (implode('', $data) !== '') {
            $students[] = $data;
        }
    }

    return $students;
}

function addStudentRecord($conn, $data, &$error_message) {
    $student_no = trim($data['student_no'] ?? '');
    $full_name = trim($data['full_name'] ?? '');
    $student_group = trim($data['student_group'] ?? '');
    $department = trim($data['department'] ?? '');
    $year_level = trim($data['year_level'] ?? '');
    $contact_number = trim($data['contact_number'] ?? '');
    $email = trim($data['email'] ?? '');
    $card_valid_until = normalizeDateValue($data['card_valid_until'] ?? '');

    if ($student_no === '') {
        $error_message = 'Student no is required.';
        return false;
    }
    if ($full_name === '' || strlen($full_name) < 3) {
        $error_message = 'Name must be at least 3 characters long.';
        return false;
    }
    if ($contact_number !== '' && !preg_match('/^[0-9\s\-\+\(\)]*$/', $contact_number)) {
        $error_message = 'Invalid contact number format.';
        return false;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Invalid email address.';
        return false;
    }
    if ($card_valid_until === false) {
        $error_message = 'Invalid library access card validity date.';
        return false;
    }

    $check = $conn->prepare('SELECT student_id FROM students WHERE student_no = ? LIMIT 1');
    $check->bind_param('s', $student_no);
    $check->execute();
    $duplicate = $check->get_result()->num_rows > 0;
    $check->close();

    if ($duplicate) {
        $error_message = 'Student no already exists: ' . $student_no;
        return false;
    }

    $student_qr_code = generateUniqueStudentQRCode($conn);
    generateQRCodeImage($student_qr_code);

    $stmt = $conn->prepare('
        INSERT INTO students
            (student_no, full_name, student_group, department, year_level, contact_number, card_valid_until, email, qr_code, status)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, "active")
    ');
    $stmt->bind_param(
        'sssssssss',
        $student_no,
        $full_name,
        $student_group,
        $department,
        $year_level,
        $contact_number,
        $card_valid_until,
        $email,
        $student_qr_code
    );

    if (!$stmt->execute()) {
        $error_message = 'Database error: ' . $stmt->error;
        $stmt->close();
        return false;
    }

    $stmt->close();
    return true;
}

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $error = '';
        $data = [
            'student_no' => $_POST['student_no'] ?? '',
            'full_name' => $_POST['full_name'] ?? '',
            'student_group' => $_POST['student_group'] ?? '',
            'department' => $_POST['department'] ?? '',
            'year_level' => $_POST['year_level'] ?? '',
            'contact_number' => $_POST['contact_number'] ?? '',
            'card_valid_until' => $_POST['card_valid_until'] ?? '',
            'email' => $_POST['email'] ?? ''
        ];

        if (addStudentRecord($conn, $data, $error)) {
            $message = 'Student added successfully.';
            $message_type = 'success';
        } else {
            $message = $error;
            $message_type = 'error';
        }
    }

    if ($action === 'import') {
        if (!isset($_FILES['students_file']) || $_FILES['students_file']['error'] !== UPLOAD_ERR_OK) {
            $message = 'Please choose a CSV or XLSX file to import.';
            $message_type = 'error';
        } else {
            $file = $_FILES['students_file'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            try {
                if ($extension === 'xlsx') {
                    $rows = parseXlsxFile($file['tmp_name']);
                } elseif ($extension === 'csv') {
                    $rows = parseCsvFile($file['tmp_name']);
                } else {
                    throw new Exception('Only CSV and XLSX files are supported.');
                }

                $students = rowsToStudentData($rows);
                $added = 0;
                $skipped = 0;
                $errors = [];

                foreach ($students as $row_index => $student_data) {
                    $error = '';
                    if (addStudentRecord($conn, $student_data, $error)) {
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
            }
        }
    }
}

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$search_rec = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'full_name';
$sort_order = $_GET['order'] ?? 'ASC';

$allowed_sorts = ['student_no', 'full_name', 'student_group', 'department', 'year_level', 'contact_number', 'email', 'card_valid_until', 'created_at'];
$allowed_orders = ['ASC', 'DESC'];
if (!in_array($sort_by, $allowed_sorts, true)) {
    $sort_by = 'full_name';
}
if (!in_array($sort_order, $allowed_orders, true)) {
    $sort_order = 'ASC';
}

$where = 'WHERE 1=1';
if ($search_rec !== '') {
    $sp = '%' . $conn->real_escape_string($search_rec) . '%';
    $where .= " AND (s.student_no LIKE '$sp' OR s.full_name LIKE '$sp' OR s.student_group LIKE '$sp' OR s.department LIKE '$sp' OR s.year_level LIKE '$sp' OR s.contact_number LIKE '$sp' OR s.email LIKE '$sp' OR s.qr_code LIKE '$sp')";
}
if ($status_filter !== '') {
    $sf = $conn->real_escape_string($status_filter);
    $where .= " AND s.status = '$sf'";
}

$count_result = $conn->query("SELECT COUNT(*) AS total FROM students s $where");
$total_students = $count_result ? (int)$count_result->fetch_assoc()['total'] : 0;
$total_pages = max(1, (int)ceil($total_students / $per_page));

$query = "
    SELECT
        s.student_id,
        s.student_no,
        s.full_name,
        s.student_group,
        s.department,
        s.year_level,
        s.contact_number,
        s.card_valid_until,
        s.email,
        s.qr_code,
        s.status,
        s.created_at,
        s.updated_at,
        COUNT(t.transaction_id) AS total_borrows,
        SUM(CASE WHEN t.status = 'borrowed' THEN 1 ELSE 0 END) AS currently_borrowed
    FROM students s
    LEFT JOIN transactions t ON s.student_id = t.student_id
    $where
    GROUP BY s.student_id, s.student_no, s.full_name, s.student_group, s.department, s.year_level,
             s.contact_number, s.card_valid_until, s.email, s.qr_code, s.status, s.created_at, s.updated_at
    ORDER BY s.$sort_by $sort_order
    LIMIT $offset, $per_page
";

$students_paginated = [];
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $students_paginated[] = $row;
    }
}

if (isset($_GET['ajax_student']) && is_numeric($_GET['ajax_student'])) {
    $sid = intval($_GET['ajax_student']);
    $out = ['student' => null, 'transactions' => []];

    $stmt = $conn->prepare('
        SELECT student_id, student_no, full_name, student_group, department, year_level,
               contact_number, card_valid_until, email, qr_code, status, created_at
        FROM students
        WHERE student_id = ?
    ');
    $stmt->bind_param('i', $sid);
    $stmt->execute();
    $student_result = $stmt->get_result();

    if ($student_result->num_rows > 0) {
        $out['student'] = $student_result->fetch_assoc();

        $history = $conn->prepare('
            SELECT t.transaction_id, t.date_borrowed, t.due_date, t.return_date,
                   t.penalty_amount, t.status, b.title, b.author
            FROM transactions t
            JOIN books b ON t.book_id = b.book_id
            WHERE t.student_id = ?
            ORDER BY t.date_borrowed DESC
        ');
        $history->bind_param('i', $sid);
        $history->execute();
        $history_result = $history->get_result();
        while ($row = $history_result->fetch_assoc()) {
            $out['transactions'][] = $row;
        }
        $history->close();
    }
    $stmt->close();

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
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f7fa; color: #333; padding-bottom: 50px; margin-left: 260px; }
        @media (max-width: 992px) { body { margin-left: 0; } }
        .container { max-width: 1500px; margin: 30px auto; padding: 0 20px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-header h1 { font-size: 28px; color: #333; }
        .alert { padding: 14px 18px; border-radius: 6px; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 10px; font-size: 14px; line-height: 1.5; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 18px 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid #8B0000; }
        .stat-card h4 { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
        .stat-card .value { font-size: 26px; font-weight: 700; color: #333; }
        .section { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.07); }
        .section h3 { font-size: 16px; color: #333; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 2px solid #8B0000; }
        .filter-grid, .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; align-items: end; }
        .form-group, .filter-group { display: flex; flex-direction: column; }
        .form-group label, .filter-group label { font-size: 12px; font-weight: 600; margin-bottom: 5px; color: #555; }
        input, select { padding: 9px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
        input:focus, select:focus { outline: none; border-color: #8B0000; box-shadow: 0 0 3px rgba(139,0,0,0.2); }
        .form-actions, .filter-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .import-help { background: #f8f0f0; border-left: 4px solid #8B0000; padding: 12px 15px; border-radius: 5px; margin: 14px 0; font-size: 12px; color: #555; line-height: 1.5; }
        .btn { padding: 10px 22px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s; }
        .btn-primary { background: #8B0000; color: white; }
        .btn-primary:hover { background: #6b0000; }
        .btn-secondary { background: #e0e0e0; color: #333; }
        .btn-secondary:hover { background: #ccc; }
        .btn-reset { background: #6c757d; color: white; }
        .btn-reset:hover { background: #5a6268; }
        .btn-view { padding: 6px 14px; background: #8B0000; color: white; border: none; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }
        .btn-view:hover { background: #6b0000; }
        .table-section { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.07); overflow: hidden; margin-bottom: 20px; }
        .table-header { padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; flex-wrap: wrap; gap: 10px; }
        .table-header h3 { font-size: 16px; color: #333; }
        .table-header span { font-size: 13px; color: #888; }
        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead th { background: #8B0000; color: white; padding: 12px 14px; text-align: left; font-weight: 600; white-space: nowrap; }
        tbody td { padding: 11px 14px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; white-space: nowrap; }
        tbody tr:hover { background: #fafafa; }
        .badge { padding: 4px 11px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-inactive { background: #f8d7da; color: #721c24; }
        .pagination { display: flex; gap: 6px; padding: 16px 20px; flex-wrap: wrap; align-items: center; }
        .pagination a, .pagination span { padding: 7px 13px; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; font-size: 13px; color: #8B0000; }
        .pagination a:hover, .pagination .active { background: #8B0000; color: white; border-color: #8B0000; }
        .no-data { text-align: center; padding: 40px; color: #aaa; }
        .modal-overlay { display: none; position: fixed; inset: 0; z-index: 1100; background: rgba(0,0,0,0.55); justify-content: center; align-items: center; padding: 20px; }
        .modal-overlay.show { display: flex; }
        .modal-box { background: white; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); width: 100%; max-width: 850px; max-height: 90vh; overflow-y: auto; position: relative; }
        .modal-top-bar { display: flex; justify-content: space-between; align-items: center; padding: 18px 24px; border-bottom: 2px solid #8B0000; position: sticky; top: 0; background: white; z-index: 10; }
        .modal-top-bar h2 { font-size: 20px; color: #333; }
        .modal-close { background: none; border: none; font-size: 22px; cursor: pointer; color: #888; line-height: 1; }
        .modal-close:hover { color: #8B0000; }
        .modal-loading { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 20px; color: #888; gap: 14px; }
        .spinner { width: 40px; height: 40px; border: 4px solid #eee; border-top-color: #8B0000; border-radius: 50%; animation: spin 0.75s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .stu-header { padding: 20px 24px 16px; border-bottom: 1px solid #eee; }
        .stu-header h3 { font-size: 22px; color: #222; }
        .stu-header p { font-size: 13px; color: #888; margin-top: 4px; }
        .stu-info-grid { display: grid; grid-template-columns: repeat(2, 1fr); border-bottom: 1px solid #eee; }
        .stu-info-item { padding: 14px 24px; border-right: 1px solid #f0f0f0; border-bottom: 1px solid #f0f0f0; }
        .stu-info-label { font-size: 11px; text-transform: uppercase; color: #999; letter-spacing: .5px; margin-bottom: 4px; }
        .stu-info-value { font-size: 15px; font-weight: 600; color: #333; }
        .stu-qr { padding: 20px 24px; border-bottom: 1px solid #eee; display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .stu-qr img { border: 1px solid #ddd; border-radius: 6px; padding: 6px; width: 110px; height: 110px; }
        .stu-qr-info h4 { font-size: 13px; text-transform: uppercase; color: #999; letter-spacing: .5px; margin-bottom: 6px; }
        .stu-qr-code { font-family: monospace; font-size: 13px; color: #555; }
        .stu-tx { padding: 20px 24px; }
        .stu-tx h3 { font-size: 16px; color: #333; margin-bottom: 14px; border-bottom: 2px solid #8B0000; padding-bottom: 8px; }
        .stu-tx-table { overflow-x: auto; }
        .status-badge { padding: 3px 9px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .empty-tx { text-align: center; padding: 30px; color: #bbb; font-size: 14px; }
        .qr-modal-overlay { display: none; position: fixed; inset: 0; z-index: 1200; background: rgba(0,0,0,0.6); justify-content: center; align-items: center; }
        .qr-modal-overlay.show { display: flex; }
        .qr-modal-content { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 6px 24px rgba(0,0,0,0.25); text-align: center; min-width: 260px; }
        .qr-modal-img { width: 200px; height: 200px; margin: 16px auto; display: block; border: 1px solid #eee; border-radius: 6px; }
        @media (max-width: 768px) { .stu-info-grid { grid-template-columns: 1fr; } body { margin-left: 0; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1>Student Records</h1>
        </div>

        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo h($message_type); ?>">
                <span><?php echo $message_type === 'success' ? 'OK' : 'ERROR'; ?></span>
                <span><?php echo h($message); ?></span>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <h4>Total Students</h4>
                <div class="value"><?php echo number_format($total_students); ?></div>
            </div>
            <div class="stat-card">
                <h4>Active Students</h4>
                <div class="value">
                    <?php $ar = $conn->query("SELECT COUNT(*) AS c FROM students WHERE status='active'"); echo number_format($ar ? $ar->fetch_assoc()['c'] : 0); ?>
                </div>
            </div>
            <div class="stat-card">
                <h4>Inactive Students</h4>
                <div class="value">
                    <?php $ir = $conn->query("SELECT COUNT(*) AS c FROM students WHERE status='inactive'"); echo number_format($ir ? $ir->fetch_assoc()['c'] : 0); ?>
                </div>
            </div>
        </div>

        <div class="section">
            <h3>Add New Student</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name">Name *</label>
                        <input type="text" id="full_name" name="full_name" required placeholder="Full name">
                    </div>
                    <div class="form-group">
                        <label for="student_no">Student No *</label>
                        <input type="text" id="student_no" name="student_no" required placeholder="Student number">
                    </div>
                    <div class="form-group">
                        <label for="student_group">Group</label>
                        <input type="text" id="student_group" name="student_group" placeholder="Group or section">
                    </div>
                    <div class="form-group">
                        <label for="department">Department</label>
                        <input type="text" id="department" name="department" placeholder="Department">
                    </div>
                    <div class="form-group">
                        <label for="year_level">Year Level</label>
                        <input type="text" id="year_level" name="year_level" placeholder="e.g. 1st Year">
                    </div>
                    <div class="form-group">
                        <label for="contact_number">Contact Number</label>
                        <input type="tel" id="contact_number" name="contact_number" placeholder="e.g. 09123456789">
                    </div>
                    <div class="form-group">
                        <label for="card_valid_until">Validity of Library Access Card</label>
                        <input type="date" id="card_valid_until" name="card_valid_until">
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="student@email.com">
                    </div>
                </div>
                <div class="import-help">
                    A unique QR code is generated automatically after saving the student.
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Add Student</button>
                    <button type="reset" class="btn btn-secondary">Clear</button>
                </div>
            </form>
        </div>

        <div class="section">
            <h3>Bulk Add Students Through Excel</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import">
                <div class="form-row">
                    <div class="form-group">
                        <label for="students_file">Upload CSV or XLSX File</label>
                        <input type="file" id="students_file" name="students_file" accept=".csv,.xlsx" required>
                    </div>
                </div>
                <div class="import-help">
                    Required headers: Name, Student No. Optional headers: Group, Department, Yr Lvl, Contact Number, Validity of the Library Access Card, Email.
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Import Students</button>
                </div>
            </form>
        </div>

        <div class="section">
            <h3>Search and Filter</h3>
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Name, student no, group, department, contact, email, or QR code" value="<?php echo h($search_rec); ?>">
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Sort By</label>
                        <select name="sort">
                            <option value="full_name" <?php echo $sort_by === 'full_name' ? 'selected' : ''; ?>>Name</option>
                            <option value="student_no" <?php echo $sort_by === 'student_no' ? 'selected' : ''; ?>>Student No</option>
                            <option value="student_group" <?php echo $sort_by === 'student_group' ? 'selected' : ''; ?>>Group</option>
                            <option value="department" <?php echo $sort_by === 'department' ? 'selected' : ''; ?>>Department</option>
                            <option value="year_level" <?php echo $sort_by === 'year_level' ? 'selected' : ''; ?>>Year Level</option>
                            <option value="contact_number" <?php echo $sort_by === 'contact_number' ? 'selected' : ''; ?>>Contact</option>
                            <option value="email" <?php echo $sort_by === 'email' ? 'selected' : ''; ?>>Email</option>
                            <option value="card_valid_until" <?php echo $sort_by === 'card_valid_until' ? 'selected' : ''; ?>>Card Validity</option>
                            <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Date Added</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Order</label>
                        <select name="order">
                            <option value="ASC" <?php echo $sort_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                            <option value="DESC" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <a href="?" class="btn btn-reset">Reset</a>
                </div>
            </form>
        </div>

        <div class="table-section">
            <div class="table-header">
                <h3>Student Information</h3>
                <span><?php echo number_format($total_students); ?> student<?php echo $total_students !== 1 ? 's' : ''; ?> found</span>
            </div>

            <?php if (empty($students_paginated)): ?>
                <div class="no-data">No student records found.</div>
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
                                <th>Contact</th>
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
                                    <td><?php echo h($student['student_no']); ?></td>
                                    <td><strong><?php echo h($student['full_name']); ?></strong></td>
                                    <td><?php echo h($student['student_group'] ?: 'N/A'); ?></td>
                                    <td><?php echo h($student['department'] ?: 'N/A'); ?></td>
                                    <td><?php echo h($student['year_level'] ?: 'N/A'); ?></td>
                                    <td><?php echo h($student['contact_number'] ?: 'N/A'); ?></td>
                                    <td><?php echo $student['card_valid_until'] ? h(date('M d, Y', strtotime($student['card_valid_until']))) : 'N/A'; ?></td>
                                    <td><?php echo h($student['email'] ?: 'N/A'); ?></td>
                                    <td><code style="background:#f0f0f0;padding:3px 7px;border-radius:3px;"><?php echo h($student['qr_code']); ?></code></td>
                                    <td><span class="badge badge-<?php echo h($student['status']); ?>"><?php echo h($student['status']); ?></span></td>
                                    <td><?php echo (int)($student['total_borrows'] ?? 0); ?></td>
                                    <td><strong style="color:<?php echo ((int)($student['currently_borrowed'] ?? 0)) > 0 ? '#c0392b' : '#27ae60'; ?>"><?php echo (int)($student['currently_borrowed'] ?? 0); ?></strong></td>
                                    <td><?php echo h(date('M d, Y', strtotime($student['created_at']))); ?></td>
                                    <td><button class="btn-view" onclick="openStudentModal(<?php echo (int)$student['student_id']; ?>)">View</button></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">First</a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Prev</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <?php if ($i === $page): ?>
                                <span class="active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">Last</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div id="studentModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-box">
            <div class="modal-top-bar">
                <h2 id="modalTitle">Student Details</h2>
                <button class="modal-close" onclick="closeStudentModal()" title="Close">&times;</button>
            </div>
            <div id="modalBody">
                <div class="modal-loading"><div class="spinner"></div><span>Loading student details...</span></div>
            </div>
        </div>
    </div>

    <div id="qrModal" class="qr-modal-overlay">
        <div class="qr-modal-content">
            <h3>QR Code Preview</h3>
            <p id="qrCodeLabel" style="color:#888;font-size:13px;margin-top:4px;"></p>
            <img id="qrImage" src="" alt="QR Code" class="qr-modal-img">
            <div style="margin-top:14px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                <a id="downloadQrBtn" href="#" download class="btn btn-primary">Download QR</a>
                <button class="btn btn-reset" onclick="closeQRModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
    function openStudentModal(studentId) {
        document.getElementById('modalTitle').textContent = 'Student Details';
        document.getElementById('modalBody').innerHTML = '<div class="modal-loading"><div class="spinner"></div><span>Loading student details...</span></div>';
        document.getElementById('studentModal').classList.add('show');
        document.body.style.overflow = 'hidden';

        fetch('?ajax_student=' + studentId)
            .then(r => r.json())
            .then(data => renderModal(data))
            .catch(() => {
                document.getElementById('modalBody').innerHTML = '<div class="modal-loading" style="color:#c0392b;">Failed to load student details.</div>';
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
        if (e.key === 'Escape') closeStudentModal();
    });

    function renderModal(data) {
        if (!data.student) {
            document.getElementById('modalBody').innerHTML = '<div class="modal-loading" style="color:#c0392b;">Student not found.</div>';
            return;
        }

        const s = data.student;
        const tx = data.transactions || [];
        document.getElementById('modalTitle').textContent = s.full_name;

        const fmtDate = (str) => {
            if (!str) return 'N/A';
            const d = new Date(str);
            return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: '2-digit' });
        };

        const txBadge = (status) => {
            const map = { borrowed: '#fff3cd|#856404', returned: '#d4edda|#155724', overdue: '#f8d7da|#721c24' };
            const parts = (map[status] || '#eee|#555').split('|');
            return `<span class="status-badge" style="background:${parts[0]};color:${parts[1]};">${escHtml(status)}</span>`;
        };

        let txHTML = '';
        if (tx.length === 0) {
            txHTML = '<div class="empty-tx">No borrowing history yet.</div>';
        } else {
            const rows = tx.map(t => `
                <tr>
                    <td><strong>${escHtml(t.title.length > 28 ? t.title.substring(0,28) + '...' : t.title)}</strong><br><small style="color:#999;">${escHtml(t.author)}</small></td>
                    <td>${fmtDate(t.date_borrowed)}</td>
                    <td>${fmtDate(t.due_date)}</td>
                    <td>${fmtDate(t.return_date)}</td>
                    <td>${txBadge(t.status)}</td>
                    <td>${parseFloat(t.penalty_amount) > 0 ? `<strong style="color:#c0392b;">PHP ${parseFloat(t.penalty_amount).toFixed(2)}</strong>` : '<span style="color:#bbb;">N/A</span>'}</td>
                </tr>`).join('');

            txHTML = `<div class="stu-tx-table"><table><thead><tr><th>Book</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th><th>Penalty</th></tr></thead><tbody>${rows}</tbody></table></div>`;
        }

        document.getElementById('modalBody').innerHTML = `
            <div class="stu-header">
                <h3>${escHtml(s.full_name)}</h3>
                <p>Student Since: ${fmtDate(s.created_at)}</p>
            </div>
            <div class="stu-info-grid">
                ${infoItem('Student No', s.student_no)}
                ${infoItem('Name', s.full_name)}
                ${infoItem('Group', s.student_group)}
                ${infoItem('Department', s.department)}
                ${infoItem('Year Level', s.year_level)}
                ${infoItem('Contact Number', s.contact_number)}
                ${infoItem('Card Validity', fmtDate(s.card_valid_until))}
                ${infoItem('Email', s.email)}
                ${infoItem('Status', s.status === 'active' ? 'Active' : 'Inactive')}
                ${infoItem('Total Transactions', tx.length)}
            </div>
            <div class="stu-qr">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=110x110&data=${encodeURIComponent(s.qr_code)}" alt="QR Code" style="cursor:pointer;" onclick="openQRModal('${escAttr(s.qr_code)}', '${escAttr(s.full_name)}')">
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

    function infoItem(label, value) {
        return `<div class="stu-info-item"><div class="stu-info-label">${escHtml(label)}</div><div class="stu-info-value">${escHtml(value || 'N/A')}</div></div>`;
    }

    function escHtml(str) {
        if (str == null) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function escAttr(str) {
        return escHtml(str).replace(/`/g, '&#96;');
    }

    function openQRModal(qrCode, studentName = '') {
        const filePath = '/LibraryBorrowingSystem/qr_codes/' + qrCode + '.png';
        const safeName = (studentName || 'student').replace(/[^a-z0-9-_]+/gi, '_');
        document.getElementById('qrCodeLabel').textContent = 'ID: ' + qrCode;
        document.getElementById('qrImage').src = filePath;
        const downloadBtn = document.getElementById('downloadQrBtn');
        downloadBtn.href = filePath;
        downloadBtn.download = safeName + '_' + qrCode + '_qr.png';
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
