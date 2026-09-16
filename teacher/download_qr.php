<?php
/**
 * Teacher QR Download
 * Downloads the QR code belonging to the currently logged-in teacher.
 */
require_once __DIR__ . '/../includes/teacher_session.php';
require_once __DIR__ . '/../db.php';

$teacherId = (int)($_SESSION['teacher_id'] ?? 0);

if ($teacherId <= 0) {
    http_response_code(401);
    exit('Teacher login required.');
}

$stmt = $conn->prepare("
    SELECT teacher_id, full_name, qr_code
    FROM teachers
    WHERE teacher_id = ?
      AND status = 'active'
      AND COALESCE(is_archived,0) = 0
    LIMIT 1
");
$stmt->bind_param('i', $teacherId);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$teacher || trim((string)$teacher['qr_code']) === '') {
    http_response_code(404);
    exit('Teacher QR code is not available.');
}

$qrCode = trim((string)$teacher['qr_code']);

if (!preg_match('/^[A-Za-z0-9_-]+$/', $qrCode)) {
    http_response_code(400);
    exit('Teacher QR code is invalid.');
}

$qrDir = __DIR__ . '/../qr_codes';
if (!is_dir($qrDir)) {
    @mkdir($qrDir, 0755, true);
}

$filePath = $qrDir . '/' . $qrCode . '.png';

if (!is_file($filePath) || filesize($filePath) === 0) {
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . rawurlencode($qrCode);

    $image = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($qrUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $image = curl_exec($ch);
        curl_close($ch);
    }

    if ($image === false || strlen((string)$image) === 0) {
        $context = stream_context_create([
            'http' => ['timeout' => 15],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);
        $image = @file_get_contents($qrUrl, false, $context);
    }

    if ($image === false || strlen((string)$image) === 0) {
        http_response_code(503);
        exit('Unable to generate the teacher QR code right now. Please try again.');
    }

    if (@file_put_contents($filePath, $image) === false) {
        http_response_code(500);
        exit('Unable to prepare the teacher QR code for download.');
    }
}

$safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $teacher['full_name']);
$downloadName = ($safeName ?: 'Teacher') . '_QR.png';

header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');

readfile($filePath);
exit;
