<?php
/**
 * Central security helpers for Jose Abad Santos High School Library Borrowing System.
 */

function isHttpsRequest(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', isHttpsRequest() ? '1' : '0');

    session_name('JASHS_LIBRARY_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function applySecurityHeaders(): void {
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://api.qrserver.com; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; connect-src 'self'; font-src 'self' data:; media-src 'self' blob:; worker-src 'self' blob:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

function csrfToken(): string {
    startSecureSession();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrfToken(?string $token): bool {
    startSecureSession();
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function requireValidCsrf(?string $token = null): void {
    $token = $token ?? ($_POST['csrf_token'] ?? '');
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        throw new RuntimeException('Your request could not be verified. Refresh the page and try again.');
    }
}

function passwordPolicyErrors(string $password): array {
    $errors = [];
    if (strlen($password) < 10) {
        $errors[] = 'at least 10 characters';
    }
    if (strlen($password) > 128) {
        $errors[] = 'no more than 128 characters';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'an uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'a lowercase letter';
    }
    if (!preg_match('/\d/', $password)) {
        $errors[] = 'a number';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'a special character';
    }

    $weak = ['password', 'password123', '123456', '12345678', 'qwerty123', 'admin123', 'letmein'];
    if (in_array(strtolower($password), $weak, true)) {
        $errors[] = 'a password that is not commonly used';
    }
    return $errors;
}

function strongPasswordMessage(array $errors): string {
    return empty($errors)
        ? ''
        : 'Password must contain ' . implode(', ', $errors) . '.';
}

function maskEmail(string $email): string {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'your registered email';
    }
    [$local, $domain] = explode('@', $email, 2);
    $len = strlen($local);
    if ($len <= 2) {
        $maskedLocal = substr($local, 0, 1) . '*';
    } else {
        $maskedLocal = substr($local, 0, 1) . str_repeat('*', min(6, $len - 2)) . substr($local, -1);
    }
    return $maskedLocal . '@' . $domain;
}

function generateOtpCode(): string {
    return (string)random_int(100000, 999999);
}

function captchaSessionKey(string $context, string $suffix): string {
    $context = preg_replace('/[^a-z0-9_]/i', '', strtolower($context));
    if ($context === '') {
        $context = 'student';
    }
    return $context . '_captcha_' . $suffix;
}

function createMathCaptcha(string $context = 'student'): array {
    startSecureSession();
    $a = random_int(2, 9);
    $b = random_int(2, 9);
    $operator = random_int(0, 1) === 1 ? '+' : '-';
    if ($operator === '-' && $b > $a) {
        [$a, $b] = [$b, $a];
    }
    $answer = $operator === '+' ? $a + $b : $a - $b;
    $_SESSION[captchaSessionKey($context, 'data')] = [
        'answer_hash' => hash('sha256', (string)$answer),
        'expires_at' => time() + 300,
    ];
    $question = "$a $operator $b = ?";
    $_SESSION[captchaSessionKey($context, 'question')] = $question;
    return ['question' => $question];
}

function currentMathCaptchaQuestion(string $context = 'student'): string {
    startSecureSession();
    $key = captchaSessionKey($context, 'question');
    if (empty($_SESSION[$key])) {
        $captcha = createMathCaptcha($context);
        $_SESSION[$key] = $captcha['question'];
    }
    return (string)$_SESSION[$key];
}

function refreshMathCaptcha(string $context = 'student'): string {
    startSecureSession();
    return createMathCaptcha($context)['question'];
}

function verifyMathCaptcha(string $answer, string $context = 'student'): bool {
    startSecureSession();
    $data = $_SESSION[captchaSessionKey($context, 'data')] ?? null;
    if (!is_array($data) || empty($data['answer_hash']) || empty($data['expires_at']) || time() > (int)$data['expires_at']) {
        return false;
    }
    return hash_equals($data['answer_hash'], hash('sha256', trim($answer)));
}

function clearMathCaptcha(string $context = 'student'): void {
    startSecureSession();
    unset(
        $_SESSION[captchaSessionKey($context, 'data')],
        $_SESSION[captchaSessionKey($context, 'question')]
    );
}

function clientIpAddress(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function loginAttemptKey(string $context, string $identifier): string {
    return hash('sha256', strtolower(trim($context)) . '|' . strtolower(trim($identifier)));
}

function isLoginLocked(mysqli $conn, string $context, string $identifier): array {
    $key = loginAttemptKey($context, $identifier);
    $ip = clientIpAddress();
    $stmt = $conn->prepare('SELECT failed_attempts, locked_until FROM login_security WHERE context = ? AND identifier_hash = ? AND ip_address = ? LIMIT 1');
    if (!$stmt) {
        return ['locked' => false, 'seconds' => 0];
    }
    $stmt->bind_param('sss', $context, $key, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['locked_until'])) {
        return ['locked' => false, 'seconds' => 0];
    }
    $until = strtotime($row['locked_until']);
    if ($until !== false && $until > time()) {
        return ['locked' => true, 'seconds' => $until - time()];
    }
    return ['locked' => false, 'seconds' => 0];
}

function recordFailedLogin(mysqli $conn, string $context, string $identifier, int $limit = 5, int $lockSeconds = 900): void {
    $key = loginAttemptKey($context, $identifier);
    $ip = clientIpAddress();
    $stmt = $conn->prepare('SELECT failed_attempts, first_failed_at FROM login_security WHERE context = ? AND identifier_hash = ? AND ip_address = ? LIMIT 1');
    if (!$stmt) return;
    $stmt->bind_param('sss', $context, $key, $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $now = time();
    $attempts = 1;
    $first = date('Y-m-d H:i:s', $now);
    if ($row && !empty($row['first_failed_at']) && strtotime($row['first_failed_at']) >= $now - 900) {
        $attempts = (int)$row['failed_attempts'] + 1;
        $first = $row['first_failed_at'];
    }
    $lockedUntil = $attempts >= $limit ? date('Y-m-d H:i:s', $now + $lockSeconds) : null;

    $stmt = $conn->prepare('INSERT INTO login_security (context, identifier_hash, ip_address, failed_attempts, first_failed_at, last_failed_at, locked_until) VALUES (?, ?, ?, ?, ?, NOW(), ?) ON DUPLICATE KEY UPDATE failed_attempts = VALUES(failed_attempts), first_failed_at = VALUES(first_failed_at), last_failed_at = NOW(), locked_until = VALUES(locked_until)');
    if (!$stmt) return;
    $stmt->bind_param('sssiss', $context, $key, $ip, $attempts, $first, $lockedUntil);
    $stmt->execute();
    $stmt->close();
}

function clearFailedLogins(mysqli $conn, string $context, string $identifier): void {
    $key = loginAttemptKey($context, $identifier);
    $ip = clientIpAddress();
    $stmt = $conn->prepare('DELETE FROM login_security WHERE context = ? AND identifier_hash = ? AND ip_address = ?');
    if (!$stmt) return;
    $stmt->bind_param('sss', $context, $key, $ip);
    $stmt->execute();
    $stmt->close();
}


function validateSpreadsheetUpload(array $file, int $maxBytes = 5242880): array {
    if (empty($file) || !isset($file['tmp_name'], $file['name'], $file['error'])) {
        throw new RuntimeException('No upload was received.');
    }
    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The uploaded file could not be received correctly.');
    }
    if (!is_file($file['tmp_name'])) {
        throw new RuntimeException('The uploaded file is not available.');
    }
    if ((int)($file['size'] ?? filesize($file['tmp_name'])) > $maxBytes) {
        throw new RuntimeException('The upload is too large. Maximum file size is 5 MB.');
    }

    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx'], true)) {
        throw new RuntimeException('Only CSV and XLSX files are allowed.');
    }

    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($file['tmp_name']);
        $allowed = [
            'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel',
            'application/zip', 'application/x-zip-compressed',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/octet-stream'
        ];
        if ($mime !== '' && !in_array($mime, $allowed, true)) {
            throw new RuntimeException('The uploaded file type is not allowed.');
        }
    }

    return ['tmp_name' => $file['tmp_name'], 'name' => basename((string)$file['name']), 'extension' => $ext];
}

function createSessionFingerprint(): string {
    return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
}

function constantTimePasswordVerify(string $password, ?string $storedHash): bool {
    if (!$storedHash) {
        password_verify($password, '$2y$10$abcdefghijklmnopqrstuu5z.G0YwM8z8M5Z0xWs0vYHcuQz1YQ6e');
        return false;
    }
    if (password_verify($password, $storedHash)) {
        return true;
    }
    if (strlen($storedHash) === 64 && ctype_xdigit($storedHash)) {
        return hash_equals(strtolower($storedHash), hash('sha256', $password));
    }
    return false;
}

applySecurityHeaders();
