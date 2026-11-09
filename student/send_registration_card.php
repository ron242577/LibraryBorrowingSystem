<?php
/**
 * Sends the exact Library Access Card JPEG rendered by the registration page.
 * This keeps the Gmail attachment visually identical to the card the user
 * downloads locally, regardless of server OS/font availability.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/gmail_smtp.php';

header('Content-Type: application/json; charset=utf-8');

try {
    requireValidCsrf($_POST['csrf_token'] ?? '');

    $data = $_SESSION['registration_card_download'] ?? null;
    if (!is_array($data)) {
        throw new RuntimeException('Your registration card session has expired. Please register again.');
    }

    $emailSent = !empty($data['email_sent']) || !empty($_SESSION['registration_card_email_sent']);
    if ($emailSent) {
        echo json_encode([
            'success' => true,
            'already_sent' => true,
            'message' => 'Your Library Access Card has already been sent to your email address.'
        ]);
        exit;
    }

    $role = (($data['registration_role'] ?? 'student') === 'teacher') ? 'teacher' : 'student';
    $toEmail = trim((string)($data['email'] ?? ''));
    $fullName = trim((string)($data['full_name'] ?? ''));
    $idNumber = trim((string)($role === 'teacher' ? ($data['teacher_no'] ?? '') : ($data['student_id_number'] ?? '')));
    $qrCode = trim((string)($data['qr_code'] ?? ''));

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL) || $fullName === '' || $idNumber === '' || $qrCode === '') {
        throw new RuntimeException('Registration card details are incomplete.');
    }

    // Generate the card on the server using the same renderer as the download.
    // Do not trust or depend on a browser/canvas image: phones and production
    // browsers can have different fonts, which previously caused the Gmail card
    // to fall back to the old-looking format.
    $sent = sendLibraryAccessCardEmail($toEmail, $fullName, $idNumber, $qrCode, $role, null);
    if (!$sent) {
        throw new RuntimeException('The Library Access Card email could not be sent.');
    }

    $_SESSION['registration_card_email_sent'] = true;
    $_SESSION['registration_card_download']['email_sent'] = true;
    $_SESSION['registration_card_download']['email_pending'] = false;
    // Keep the original success payload in sync so a refresh during the
    // transition cannot overwrite the sent state with stale pending data.
    if (isset($_SESSION['registration_success']) && is_array($_SESSION['registration_success'])) {
        $_SESSION['registration_success']['email_sent'] = true;
        $_SESSION['registration_success']['email_pending'] = false;
        unset($_SESSION['registration_success']['email_error']);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Your Library Access Card was sent successfully to your email address.'
    ]);
} catch (Throwable $e) {
    http_response_code($e instanceof RuntimeException ? 400 : 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
