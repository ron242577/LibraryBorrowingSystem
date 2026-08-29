<?php
/**
 * Generic QR Code Download Handler
 * Supports book and student QR codes.
 */

require_once __DIR__ . '/session_check.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/qr_security.php';

$code = trim((string)($_GET['code'] ?? ''));
$type = strtolower(trim((string)($_GET['type'] ?? 'auto')));

if ($code === '' || strlen($code) > 120 || !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
    http_response_code(400);
    exit('Invalid QR code.');
}

if (!in_array($type, ['auto', 'book', 'student'], true)) {
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

$actorType = function_exists('isAdmin') && isAdmin()
    ? 'admin'
    : (isset($_SESSION['student_id']) ? 'student' : 'guest');

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
    : (int)$record['book_id'];

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
