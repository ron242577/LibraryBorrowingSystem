<?php
/**
 * Download the Library Access Card JPEG from the just-completed registration flow.
 * Uses the one-time-ish registration_success session payload shown on the success page.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/library_access_card.php';

$data = $_SESSION['registration_card_download'] ?? null;
if (!is_array($data)) {
    http_response_code(404);
    exit('Registration access-card data is no longer available. Please sign in to save your Library Access Card.');
}

$role = ($data['registration_role'] ?? 'student') === 'teacher' ? 'teacher' : 'student';
$fullName = trim((string)($data['full_name'] ?? ''));
$idNumber = trim((string)($role === 'teacher' ? ($data['teacher_no'] ?? '') : ($data['student_id_number'] ?? '')));
$qrCode = trim((string)($data['qr_code'] ?? ''));

if ($fullName === '' || $idNumber === '' || $qrCode === '') {
    http_response_code(404);
    exit('Registration access-card data is incomplete.');
}

try {
    $qrBytes = fetchLibraryQrPngBytes($qrCode);
    if ($qrBytes === null) throw new RuntimeException('Unable to prepare the QR image right now.');
    $cardJpeg = buildLibraryAccessCardJpeg($fullName, $idNumber, $qrCode, $qrBytes);
    $format = strtolower(trim((string)($_GET['format'] ?? 'jpg')));
    if (!in_array($format, ['jpg','jpeg','pdf'], true)) $format='jpg';
    $cardPdf = $format === 'pdf' ? buildLibraryAccessCardPdf($fullName, $idNumber, $qrCode, $qrBytes) : '';
} catch (Throwable $e) {
    http_response_code(503);
    exit($e->getMessage());
}

$safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $fullName);
$baseName = ($safeName ?: ucfirst($role)) . '_Library_Access_Card';
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
