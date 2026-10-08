<?php
require_once __DIR__ . '/../config/mail_config.php';
require_once __DIR__ . '/library_access_card.php';

function smtpReadResponse($socket): string {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('Gmail SMTP did not return a response. Check your internet connection or Windows firewall.');
    }

    return $response;
}

function smtpResponseSummary(string $response): string {
    $lines = preg_split('/\r?\n/', trim($response));
    $clean = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $clean[] = preg_replace('/^\d{3}[- ]?/', '', $line);
        }
    }
    $summary = trim(implode(' ', $clean));
    return $summary !== '' ? mb_substr($summary, 0, 500) : 'No additional details were returned.';
}

function smtpExpect($socket, array $allowedCodes): string {
    $response = smtpReadResponse($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $allowedCodes, true)) {
        $detail = smtpResponseSummary($response);
        throw new RuntimeException('Gmail SMTP rejected the request (SMTP ' . $code . '). ' . $detail);
    }
    return $response;
}

function smtpCommand($socket, string $command, array $allowedCodes): string {
    $written = @fwrite($socket, $command . "\r\n");
    if ($written === false) {
        throw new RuntimeException('Unable to write to the Gmail SMTP connection.');
    }
    return smtpExpect($socket, $allowedCodes);
}

function gmailCaFile(): ?string {
    $candidates = [];

    // Prefer the CA bundle shipped with this application. This avoids a common
    // XAMPP-on-Windows problem where openssl.cafile/curl.cainfo points to an
    // old or missing CA bundle and Gmail's certificate cannot be verified.
    $projectCa = realpath(__DIR__ . '/../certs/cacert.pem');
    if ($projectCa) {
        $candidates[] = $projectCa;
    }

    $iniCa = (string)ini_get('openssl.cafile');
    if ($iniCa !== '') {
        $candidates[] = $iniCa;
    }

    $curlCa = (string)ini_get('curl.cainfo');
    if ($curlCa !== '') {
        $candidates[] = $curlCa;
    }

    // Common XAMPP location on Windows.
    $phpExe = realpath(PHP_BINARY);
    if ($phpExe) {
        $xamppRoot = dirname(dirname($phpExe));
        $candidates[] = $xamppRoot . DIRECTORY_SEPARATOR . 'apache' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'curl-ca-bundle.crt';
        $candidates[] = $xamppRoot . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'cacert.pem';
    }

    if (function_exists('openssl_get_cert_locations')) {
        $locations = openssl_get_cert_locations();
        foreach (['default_cert_file', 'ini_cafile'] as $key) {
            if (!empty($locations[$key])) {
                $candidates[] = $locations[$key];
            }
        }
    }

    foreach (array_unique($candidates) as $path) {
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }

    return null;
}

function gmailIsLocalDevelopmentHost(): bool {
    $host = '';
    if (!empty($_SERVER['HTTP_HOST'])) {
        $host = (string)$_SERVER['HTTP_HOST'];
    } elseif (!empty($_SERVER['SERVER_NAME'])) {
        $host = (string)$_SERVER['SERVER_NAME'];
    }
    $host = strtolower(trim($host));
    if ($host === '') return false;
    $host = preg_replace('/:\d+$/', '', $host);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || (bool)preg_match('/\.(test|local|localhost)$/', $host);
}

function openGmailSmtpConnectionAttempt(bool $verifyPeer) {
    if (!MAIL_ENABLED) {
        throw new RuntimeException('Gmail delivery is disabled in config/mail_config.php.');
    }
    if (!filter_var(MAIL_USERNAME, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('MAIL_USERNAME is not a valid Gmail address.');
    }
    $appPassword = preg_replace('/\s+/', '', (string)MAIL_APP_PASSWORD);
    if ($appPassword === '' || str_contains($appPassword, 'PASTE_YOUR_16_CHARACTER')) {
        throw new RuntimeException('Gmail App Password is not configured. Your normal Gmail password cannot be used for SMTP. Create a 16-character App Password and put it in config/mail_config.php.');
    }
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('PHP OpenSSL extension is required for Gmail SMTP. Enable extension=openssl in your Laragon php.ini.');
    }

    $remote = 'tcp://' . MAIL_HOST . ':' . MAIL_PORT;
    $errno = 0;
    $errstr = '';
    $ssl = [
        'verify_peer' => $verifyPeer,
        'verify_peer_name' => $verifyPeer,
        'allow_self_signed' => !$verifyPeer,
        'peer_name' => MAIL_HOST,
        'SNI_enabled' => true,
        'disable_compression' => true,
    ];

    $caFile = gmailCaFile();
    if ($verifyPeer && $caFile !== null) {
        $ssl['cafile'] = $caFile;
    }

    $context = stream_context_create(['ssl' => $ssl]);
    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        MAIL_TIMEOUT,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        $detail = trim($errstr);
        throw new RuntimeException(
            'Unable to connect to Gmail SMTP (' . MAIL_HOST . ':' . MAIL_PORT . ').' .
            ($detail !== '' ? ' ' . $detail : ' Check that your PC is online and Windows Firewall/antivirus is not blocking PHP.')
        );
    }

    stream_set_timeout($socket, MAIL_TIMEOUT);

    try {
        smtpExpect($socket, [220]);
        smtpCommand($socket, 'EHLO localhost', [250]);
        smtpCommand($socket, 'STARTTLS', [220]);

        $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($crypto !== true) {
            $tlsMessages = [];
            $last = error_get_last();
            if (is_array($last) && !empty($last['message'])) {
                $tlsMessages[] = trim($last['message']);
            }
            while (($opensslMessage = openssl_error_string()) !== false) {
                $tlsMessages[] = trim($opensslMessage);
            }
            $tlsMessages = array_values(array_unique(array_filter($tlsMessages)));
            $detail = $tlsMessages ? ' ' . implode(' | ', $tlsMessages) : '';
            $caMessage = $verifyPeer
                ? (($caFile ?? null) === null
                    ? ' PHP cannot find a readable CA certificate bundle.'
                    : ' CA bundle used: ' . $caFile . '.')
                : ' Certificate verification was intentionally disabled for this local-development retry.';
            throw new RuntimeException('Unable to establish a secure TLS connection to Gmail.' . $detail . $caMessage);
        }

        smtpCommand($socket, 'EHLO localhost', [250]);
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode(MAIL_USERNAME), [334]);
        smtpCommand($socket, base64_encode($appPassword), [235]);

        return $socket;
    } catch (Throwable $e) {
        fclose($socket);
        throw $e;
    }
}

function openGmailSmtpConnection() {
    try {
        return openGmailSmtpConnectionAttempt((bool)MAIL_TLS_VERIFY_PEER);
    } catch (Throwable $firstError) {
        $message = $firstError->getMessage();
        $canFallback = defined('MAIL_ALLOW_INSECURE_TLS_FALLBACK')
            && MAIL_ALLOW_INSECURE_TLS_FALLBACK
            && gmailIsLocalDevelopmentHost()
            && stripos($message, 'certificate verify failed') !== false;

        if (!$canFallback) {
            throw $firstError;
        }

        error_log('[LibraryBorrowingSystem] Gmail TLS certificate verification failed on localhost; retrying Gmail SMTP with certificate verification disabled for local development only.');
        try {
            return openGmailSmtpConnectionAttempt(false);
        } catch (Throwable $fallbackError) {
            throw new RuntimeException(
                $fallbackError->getMessage() .
                ' The localhost TLS fallback was also unsuccessful. Check the Gmail App Password and your internet/antivirus settings.',
                0,
                $fallbackError
            );
        }
    }
}

function smtpSendAuthenticatedMessage($socket, string $toEmail, string $safeName, string $subject, string $message): bool {
    smtpCommand($socket, 'MAIL FROM:<' . MAIL_FROM_EMAIL . '>', [250]);
    smtpCommand($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
    smtpCommand($socket, 'DATA', [354]);

    $message = preg_replace('/\r?\n\./', "\r\n..", $message);
    $written = @fwrite($socket, $message . "\r\n.\r\n");
    if ($written === false) {
        throw new RuntimeException('Unable to send the email content to Gmail SMTP.');
    }

    smtpExpect($socket, [250]);
    smtpCommand($socket, 'QUIT', [221]);
    fclose($socket);
    return true;
}

function sendPlainGmailMessage(string $toEmail, string $name, string $subject, string $body): bool {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The registered account email is invalid.');
    }

    $safeName = trim((string)preg_replace('/[\r\n]+/', ' ', $name));
    $safeName = $safeName !== '' ? $safeName : 'Library User';

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . '>',
        'To: ' . $safeName . ' <' . $toEmail . '>',
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $socket = openGmailSmtpConnection();
    try {
        return smtpSendAuthenticatedMessage($socket, $toEmail, $safeName, $subject, $message);
    } catch (Throwable $e) {
        if (is_resource($socket)) {
            fclose($socket);
        }
        throw $e;
    }
}

/**
 * Kept for compatibility with older parts of the application.
 */
function fetchRegistrationQrPng(string $qrCode): ?string {
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=420x420&margin=0&ecc=M&data=' . rawurlencode($qrCode);
    $image = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($qrUrl);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Jose Abad Santos High School Library QR Mailer',
        ];
        $qrCaFile = gmailCaFile();
        if ($qrCaFile !== null) {
            $curlOptions[CURLOPT_CAINFO] = $qrCaFile;
        }
        curl_setopt_array($ch, $curlOptions);
        $image = curl_exec($ch);
        curl_close($ch);
    }

    if ($image === false || !is_string($image) || strlen($image) === 0) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Jose Abad Santos High School Library QR Mailer'
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true
            ]
        ]);
        $image = @file_get_contents($qrUrl, false, $context);
    }

    return ($image !== false && is_string($image) && strlen($image) > 0) ? $image : null;
}

function sendLibraryAccessCardEmail(string $toEmail, string $fullName, string $idNumber, string $qrCode, string $role = 'student'): bool {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The registered account email is invalid.');
    }

    $roleLabel = $role === 'teacher' ? 'Teacher' : 'Student';
    $safeName = trim((string)preg_replace('/[\r\n]+/', ' ', $fullName));
    $safeId = trim((string)preg_replace('/[\r\n]+/', ' ', $idNumber));
    $safeQr = trim((string)preg_replace('/[^A-Za-z0-9_-]/', '', $qrCode));
    if ($safeQr === '') {
        throw new RuntimeException('The generated QR code is invalid.');
    }

    $qrBytes = fetchLibraryQrPngBytes($safeQr);
    if ($qrBytes === null) {
        throw new RuntimeException('Unable to prepare the QR image for the access-card email. Make sure the local computer has internet access to the QR image service.');
    }

    $cardJpeg = buildLibraryAccessCardJpeg($safeName, $safeId, $safeQr, $qrBytes);
    if ($cardJpeg === '') {
        throw new RuntimeException('Unable to create the Library Access Card JPEG.');
    }

    $safeRecipientName = $safeName !== '' ? $safeName : 'Library User';
    $subject = 'Your Jose Abad Santos High School Library Access Card';
    $mixedBoundary = 'mixed_' . bin2hex(random_bytes(12));
    $safeFileName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $safeRecipientName);
    $filename = ($safeFileName ?: strtolower($roleLabel)) . '_Library_Access_Card.jpg';

    $cardContentId = 'library-access-card-' . bin2hex(random_bytes(8)) . '@jas-library.local';
    $html = '<!doctype html><html><body style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#141414;">'
        . '<div style="max-width:620px;margin:0 auto;background:#fff;border:1px solid #d9d9d9;border-radius:18px;padding:28px;text-align:center;">'
        . '<div style="font-size:14px;font-weight:700;color:#666;letter-spacing:.8px;text-transform:uppercase;">Jose Abad Santos High School</div>'
        . '<h1 style="margin:10px 0 8px;font-size:25px;color:#141414;">Library Access Card</h1>'
        . '<p style="margin:0;font-size:14px;line-height:1.6;color:#555;">Hello ' . htmlspecialchars($safeRecipientName, ENT_QUOTES, 'UTF-8') . ', your ' . strtolower($roleLabel) . ' account has been created.</p>'
        . '<p style="margin:10px 0 18px;font-size:14px;line-height:1.6;color:#555;">Your Library Access Card is shown below. You can also save the attached JPEG image to your phone or computer.</p>'
        . '<img src="cid:' . $cardContentId . '" alt="Library Access Card" style="display:block;width:590px;max-width:100%;height:auto;margin:0 auto;border:1px solid #d9d9d9;border-radius:8px;">'
        . '<p style="margin:18px 0 0;font-size:12px;line-height:1.5;color:#777;">Keep this card available for library transactions.</p>'
        . '</div></body></html>';

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . '>',
        'To: ' . $safeRecipientName . ' <' . $toEmail . '>',
        'Subject: ' . $subject,
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"',
    ];

    $relatedBoundary = 'related_' . bin2hex(random_bytes(12));
    $message = implode("\r\n", $headers) . "\r\n\r\n";
    $message .= '--' . $mixedBoundary . "\r\n";
    $message .= 'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"' . "\r\n\r\n";
    $message .= '--' . $relatedBoundary . "\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $message .= quoted_printable_encode($html) . "\r\n";
    $message .= '--' . $relatedBoundary . "\r\n";
    $message .= 'Content-Type: image/jpeg; name="' . $filename . "\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= 'Content-ID: <' . $cardContentId . ">\r\n";
    $message .= "Content-Disposition: inline; filename=\"" . $filename . "\"\r\n\r\n";
    $message .= chunk_split(base64_encode($cardJpeg)) . "\r\n";
    $message .= '--' . $relatedBoundary . "--\r\n";
    $message .= '--' . $mixedBoundary . "\r\n";
    $message .= 'Content-Type: image/jpeg; name="' . $filename . "\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= 'Content-Disposition: attachment; filename="' . $filename . "\"\r\n\r\n";
    $message .= chunk_split(base64_encode($cardJpeg)) . "\r\n";
    $message .= '--' . $mixedBoundary . "--\r\n";

    $socket = openGmailSmtpConnection();
    try {
        return smtpSendAuthenticatedMessage($socket, $toEmail, $safeRecipientName, $subject, $message);
    } catch (Throwable $e) {
        if (is_resource($socket)) {
            fclose($socket);
        }
        throw $e;
    }
}

function sendStudentRegistrationVerificationEmail(string $toEmail, string $studentName, string $code, string $role = 'student'): bool {
    $roleLabel = $role === 'teacher' ? 'teacher' : 'student';
    $safeName = trim((string)preg_replace('/[\r\n]+/', ' ', $studentName));
    $body = "Hello {$safeName},\r\n\r\n"
        . "Your {$roleLabel} registration verification code is: {$code}\r\n\r\n"
        . "Enter this code on the registration page to finish creating your library account. The code expires in 5 minutes. Do not share it with anyone.\r\n\r\n"
        . "If you did not try to register for the Library Portal, you can ignore this email.\r\n\r\n"
        . "Jose Abad Santos High School Library";

    return sendPlainGmailMessage(
        $toEmail,
        $safeName,
        'Jose Abad Santos High School Library Registration Verification Code',
        $body
    );
}

function sendStudentPasswordResetEmail(string $toEmail, string $studentName, string $code): bool {
    $safeName = trim((string)preg_replace('/[\r\n]+/', ' ', $studentName));
    $body = "Hello {$safeName},\r\n\r\n"
        . "Your student password reset verification code is: {$code}\r\n\r\n"
        . "Enter this code on the Student Password Recovery page. The code expires in 5 minutes. Do not share it with anyone.\r\n\r\n"
        . "If you did not request a password reset, you can ignore this email.\r\n\r\n"
        . "Jose Abad Santos High School Library";

    return sendPlainGmailMessage(
        $toEmail,
        $safeName,
        'Jose Abad Santos High School Library Student Password Reset Code',
        $body
    );
}

function sendTeacherPasswordResetEmail(string $toEmail, string $teacherName, string $code): bool {
    $safeName = trim((string)preg_replace('/[\r\n]+/', ' ', $teacherName));
    $body = "Hello {$safeName},\r\n\r\n"
        . "Your teacher password reset verification code is: {$code}\r\n\r\n"
        . "Enter this code on the Teacher Password Recovery page. The code expires in 5 minutes. Do not share it with anyone.\r\n\r\n"
        . "If you did not request a password reset, you can ignore this email.\r\n\r\n"
        . "Jose Abad Santos High School Library";

    return sendPlainGmailMessage(
        $toEmail,
        $safeName,
        'Jose Abad Santos High School Library Teacher Password Reset Code',
        $body
    );
}
?>
