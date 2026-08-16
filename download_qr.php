<?php
require_once __DIR__ . '/db.php';

/**
 * QR Code Download Handler
 * Serves a QR code as a downloadable PNG file.
 */

$code = trim($_GET['code'] ?? '');

if ($code === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
    http_response_code(400);
    exit('Invalid QR code.');
}

$qr_dir = __DIR__ . '/qr_codes';
if (!is_dir($qr_dir)) {
    mkdir($qr_dir, 0755, true);
}

$filename = $code . '.png';
$filepath = $qr_dir . '/' . $filename;

// If the QR image does not exist yet, create it from the QR service and cache it locally.
if (!is_file($filepath) || filesize($filepath) === 0) {
    $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($code);
    $context = stream_context_create([
        'http' => ['timeout' => 10],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true
        ]
    ]);

    $image = @file_get_contents($qr_url, false, $context);
    if ($image === false || strlen($image) === 0) {
        http_response_code(503);
        exit('Unable to generate the QR code right now. Please check the internet connection and try again.');
    }

    if (@file_put_contents($filepath, $image) === false) {
        http_response_code(500);
        exit('Unable to save the QR code image.');
    }
}

$download_name = $code . '_qr.png';
header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
readfile($filepath);
exit();
