<?php
require_once __DIR__ . '/../config/mail_config.php';

function smtpReadResponse($socket): string {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $response;
}

function smtpExpect($socket, array $allowedCodes): string {
    $response = smtpReadResponse($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $allowedCodes, true)) {
        throw new RuntimeException('SMTP server rejected the request. Code: ' . $code);
    }
    return $response;
}

function smtpCommand($socket, string $command, array $allowedCodes): string {
    fwrite($socket, $command . "\r\n");
    return smtpExpect($socket, $allowedCodes);
}

function sendStudentRegistrationVerificationEmail(string $toEmail, string $studentName, string $code): bool {
    if (!MAIL_ENABLED) {
        throw new RuntimeException('Gmail verification is not configured yet.');
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The student does not have a valid email address.');
    }
    if (!filter_var(MAIL_USERNAME, FILTER_VALIDATE_EMAIL) || MAIL_APP_PASSWORD === '') {
        throw new RuntimeException('The Gmail SMTP username or App Password is missing.');
    }

    if (!extension_loaded('openssl')) {
        throw new RuntimeException('PHP OpenSSL extension is required for Gmail SMTP.');
    }

    $remote = 'tcp://' . MAIL_HOST . ':' . MAIL_PORT;
    $errno = 0;
    $errstr = '';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => MAIL_HOST,
            'SNI_enabled' => true,
        ]
    ]);
    $socket = @stream_socket_client($remote, $errno, $errstr, MAIL_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        throw new RuntimeException('Unable to connect to Gmail SMTP. ' . $errstr);
    }
    stream_set_timeout($socket, MAIL_TIMEOUT);

    try {
        smtpExpect($socket, [220]);
        smtpCommand($socket, 'EHLO localhost', [250]);
        smtpCommand($socket, 'STARTTLS', [220]);

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Unable to establish a secure TLS connection to Gmail.');
        }

        smtpCommand($socket, 'EHLO localhost', [250]);
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode(MAIL_USERNAME), [334]);
        smtpCommand($socket, base64_encode(str_replace(' ', '', MAIL_APP_PASSWORD)), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . MAIL_FROM_EMAIL . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $safeName = preg_replace('/[\r\n]+/', ' ', $studentName);
        $subject = 'Jose Abad Santos High School Library Registration Verification Code';
        $body = "Hello {$safeName},\r\n\r\n"
            . "Your student registration verification code is: {$code}\r\n\r\n"
            . "Enter this code on the registration page to finish creating your library account. The code expires in 5 minutes. Do not share it with anyone.\r\n\r\n"
            . "If you did not try to register for the Student Portal, you can ignore this email.\r\n\r\n"
            . "Jose Abad Santos High School Library";

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
        $message = preg_replace('/\r?\n\./', "\r\n..", $message);
        fwrite($socket, $message . "\r\n.\r\n");
        smtpExpect($socket, [250]);
        smtpCommand($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        fclose($socket);
        throw $e;
    }
}

