<?php
/**
 * Student personal Library Access Card JPEG download.
 * The signed-in student can only download their own card.
 */
require_once __DIR__ . '/../includes/student_session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/library_access_card.php';

$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId <= 0) {
    http_response_code(401);
    exit('Student login required.');
}

$stmt = $conn->prepare("SELECT student_id, full_name, student_no, qr_code FROM students WHERE student_id = ? AND status = 'active' AND COALESCE(is_archived,0) = 0 LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student || trim((string)$student['qr_code']) === '') {
    http_response_code(404);
    exit('Your Library Access Card is not available.');
}

try {
    $qrBytes = fetchLibraryQrPngBytes((string)$student['qr_code']);
    if ($qrBytes === null) throw new RuntimeException('Unable to prepare the QR image right now.');
    $idValue = (string)$student['student_no'];
    $qrValue = (string)$student['qr_code'];
    $cardJpeg = buildLibraryAccessCardJpeg((string)$student['full_name'], $idValue, $qrValue, $qrBytes);
    $format = strtolower(trim((string)($_GET['format'] ?? 'jpg')));
    if (!in_array($format, ['jpg','jpeg','pdf'], true)) $format='jpg';
    $cardPdf = $format === 'pdf' ? buildLibraryAccessCardPdf((string)$student['full_name'], $idValue, $qrValue, $qrBytes) : '';
} catch (Throwable $e) {
    http_response_code(503);
    exit($e->getMessage());
}

$safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$student['full_name']);
$baseName = ($safeName ?: 'Student') . '_Library_Access_Card';
if ($format === 'pdf') {
    $downloadName = $baseName . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . strlen($cardPdf));
    header('Cache-Control: private, no-store, max-age=0, must-revalidate');
    header('Pragma: no-cache');
    echo $cardPdf;
} else {
    $downloadName = $baseName . '.jpg';
    header('Content-Type: image/jpeg');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . strlen($cardJpeg));
    header('Cache-Control: private, no-store, max-age=0, must-revalidate');
    header('Pragma: no-cache');
    echo $cardJpeg;
}
exit;
