<?php
/**
 * Generic QR Code Download Handler
 * Supports book and student QR codes.
 */

require_once __DIR__ . '/session_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/qr_security.php';
require_once __DIR__ . '/includes/library_access_card.php';

$code = trim((string)($_GET['code'] ?? ''));
$type = strtolower(trim((string)($_GET['type'] ?? 'auto')));

if ($code === '' || strlen($code) > 120 || !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
    http_response_code(400);
    exit('Invalid QR code.');
}

if (!in_array($type, ['auto', 'book', 'student', 'teacher'], true)) {
    $type = 'auto';
}

$record = null;
$resolvedType = null;

if ($type === 'student' || $type === 'auto') {
    $stmt = $conn->prepare("
        SELECT student_id, qr_code, status, COALESCE(is_archived,0) AS is_archived
        FROM students
        WHERE qr_code = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($student && (int)$student['is_archived'] === 0 && $student['status'] === 'active') {
        $record = $student;
        $resolvedType = 'student';
    }
}

if (!$record && ($type === 'book' || $type === 'auto')) {
    $stmt = $conn->prepare("
        SELECT book_id, qr_code, is_archived
        FROM books
        WHERE qr_code = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $book = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($book && (int)$book['is_archived'] === 0) {
        $record = $book;
        $resolvedType = 'book';
    }
}

if (!$record && ($type === 'book' || $type === 'auto')) {
    $chk = $conn->query("SHOW TABLES LIKE 'book_copies'");
    if ($chk && $chk->num_rows > 0) {
        $stmt = $conn->prepare("
            SELECT c.copy_id AS book_id, c.qr_code, b.is_archived
            FROM book_copies c JOIN books b ON b.book_id = c.book_id
            WHERE c.qr_code = ? AND c.copy_status <> 'removed'
            LIMIT 1
        ");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $copyRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($copyRow && (int)$copyRow['is_archived'] === 0) {
            $record = $copyRow;
            $resolvedType = 'book';
        }
    }
}

if (!$record && ($type === 'teacher' || $type === 'auto')) {
    $stmt = $conn->prepare("SELECT teacher_id, qr_code, COALESCE(is_archived,0) AS is_archived, status FROM teachers WHERE qr_code = ? LIMIT 1");
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($teacher && (int)$teacher['is_archived'] === 0 && $teacher['status'] === 'active') {
        $record = $teacher;
        $resolvedType = 'teacher';
    }
}

$actorType = function_exists('isAdmin') && isAdmin()
    ? 'admin'
    : (isset($_SESSION['student_id']) ? 'student' : (isset($_SESSION['teacher_id']) ? 'teacher' : 'guest'));

if (!$record) {
    qrSecurityLog(
        $conn,
        'qr_download',
        $code,
        'rejected',
        (int)($_SESSION['user_id'] ?? 0),
        $actorType,
        null,
        'QR download rejected because the QR code was not found or is inactive.'
    );
    http_response_code(404);
    exit('QR code not found or no longer active.');
}

$targetId = $resolvedType === 'student'
    ? (int)$record['student_id']
    : ($resolvedType === 'teacher' ? (int)$record['teacher_id'] : (int)$record['book_id']);

qrSecurityLog(
    $conn,
    'qr_download',
    $code,
    'success',
    (int)($_SESSION['user_id'] ?? 0),
    $actorType,
    $targetId,
    'QR download requested for ' . $resolvedType . '.'
);

// Personal student/teacher QR downloads are delivered as the complete
// Library Access Card JPEG. Book QR downloads remain QR-only.
if ($resolvedType === 'student' || $resolvedType === 'teacher') {
    $table = $resolvedType === 'student' ? 'students' : 'teachers';
    $idColumn = $resolvedType === 'student' ? 'student_id' : 'teacher_id';
    $numberColumn = $resolvedType === 'student' ? 'student_no' : 'teacher_no';

    $stmt = $conn->prepare("SELECT full_name, {$numberColumn} AS id_number, qr_code FROM {$table} WHERE {$idColumn} = ? LIMIT 1");
    $stmt->bind_param('i', $targetId);
    $stmt->execute();
    $personal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$personal) {
        http_response_code(404);
        exit('Personal Library Access Card data was not found.');
    }

    try {
        $qrBytes = fetchLibraryQrPngBytes((string)$personal['qr_code']);
        if ($qrBytes === null) throw new RuntimeException('Unable to prepare the QR image right now.');
        $cardJpeg = buildLibraryAccessCardJpeg((string)$personal['full_name'], (string)$personal['id_number'], (string)$personal['qr_code'], $qrBytes);
    } catch (Throwable $e) {
        http_response_code(503);
        exit($e->getMessage());
    }

    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$personal['full_name']);
    $downloadName = ($safeName ?: ucfirst($resolvedType)) . '_Library_Access_Card.jpg';
    header('Content-Type: image/jpeg');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . strlen($cardJpeg));
    header('Cache-Control: private, no-store, max-age=0, must-revalidate');
    header('Pragma: no-cache');
    echo $cardJpeg;
    exit;
}

$qrDir = __DIR__ . '/qr_codes';
if (!is_dir($qrDir)) {
    @mkdir($qrDir, 0755, true);
}

$filename = $record['qr_code'] . '.png';
$filepath = $qrDir . '/' . $filename;

if (!is_file($filepath) || filesize($filepath) === 0) {
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($record['qr_code']);
    $context = stream_context_create([
        'http' => ['timeout' => 15],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);

    $image = @file_get_contents($qrUrl, false, $context);

    if ($image === false || strlen($image) === 0) {
        http_response_code(503);
        exit('Unable to generate the QR code right now. Please try again.');
    }

    if (@file_put_contents($filepath, $image) === false) {
        http_response_code(500);
        exit('Unable to save the QR code image.');
    }
}

header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="' . $record['qr_code'] . '_qr.png"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');

readfile($filepath);
exit;
