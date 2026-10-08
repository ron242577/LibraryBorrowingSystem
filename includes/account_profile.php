<?php
/**
 * Shared authenticated-account profile helpers for student and teacher portals.
 */

function apEnsureProfileSchema(mysqli $conn, string $table): void
{
    if (!in_array($table, ['students', 'teachers'], true)) {
        throw new InvalidArgumentException('Unsupported account table.');
    }

    $check = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE 'profile_picture'");
    if ($check && $check->num_rows === 0) {
        if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN profile_picture VARCHAR(500) NULL AFTER qr_code")) {
            throw new RuntimeException('Unable to prepare profile picture storage.');
        }
    }
}

function apProfilePictureUrl(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return '/LibraryBorrowingSystem/' . ltrim($path, '/');
}

function apPasswordRequirements(string $password): ?string
{
    if (strlen($password) < 10) return 'Password must be at least 10 characters.';
    if (!preg_match('/[A-Z]/', $password)) return 'Password must contain an uppercase letter.';
    if (!preg_match('/[a-z]/', $password)) return 'Password must contain a lowercase letter.';
    if (!preg_match('/\d/', $password)) return 'Password must contain a number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) return 'Password must contain a special character.';
    return null;
}

function apValidateName(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '' || mb_strlen($name) < 2) {
        throw new RuntimeException('Please enter your full name.');
    }
    if ((function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 255) {
        throw new RuntimeException('Full name is too long.');
    }
    return $name;
}

function apValidateContact(string $contact): ?string
{
    $contact = trim($contact);
    if ($contact === '') return null;
    if (!preg_match('/^[0-9+()\-\s]{7,20}$/', $contact)) {
        throw new RuntimeException('Please enter a valid contact number.');
    }
    return $contact;
}

function apProfilePictureUpload(string $role, int $userId, array $file, ?string $oldPath = null): string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return trim((string)$oldPath);
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The profile picture could not be uploaded.');
    }
    if ((int)($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Profile picture must be 2 MB or smaller.');
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid profile picture upload.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Profile picture must be JPG, PNG, or WebP.');
    }

    $root = dirname(__DIR__);
    $relativeDir = 'uploads/profile_pictures';
    $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create the profile picture folder.');
    }

    $safeRole = $role === 'teacher' ? 'teacher' : 'student';
    $filename = $safeRole . '_' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    $destination = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Unable to save the profile picture.');
    }

    $newRelative = $relativeDir . '/' . $filename;
    apDeleteStoredProfilePicture($oldPath);
    return $newRelative;
}

function apDeleteStoredProfilePicture(?string $path): void
{
    $path = trim((string)$path);
    if ($path === '' || preg_match('#^https?://#i', $path)) return;
    $normalized = ltrim(str_replace('\\', '/', $path), '/');
    if (strpos($normalized, 'uploads/profile_pictures/') !== 0) return;
    $root = dirname(__DIR__);
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (is_file($full)) @unlink($full);
}

function apSendPasswordChangeCode(string $role, string $email, string $name, string $code): bool
{
    require_once dirname(__DIR__) . '/includes/gmail_smtp.php';
    if ($role === 'teacher') {
        return sendTeacherPasswordResetEmail($email, $name, $code);
    }
    return sendStudentPasswordResetEmail($email, $name, $code);
}

function apPasswordSessionKey(string $role): string
{
    return $role === 'teacher' ? 'teacher_account_password_change' : 'student_account_password_change';
}

function apClearPasswordChange(string $role): void
{
    unset($_SESSION[apPasswordSessionKey($role)]);
}

function apMaskEmail(string $email): string
{
    $email = trim($email);
    $at = strrpos($email, '@');
    if ($at === false) return 'your registered email';
    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    if ($local === '') return '***@' . $domain;
    $localLen = function_exists('mb_strlen') ? mb_strlen($local) : strlen($local);
    $visible = function_exists('mb_substr') ? mb_substr($local, 0, 1) : substr($local, 0, 1);
    return $visible . str_repeat('*', max(1, min(5, $localLen - 1))) . '@' . $domain;
}

function apStartPasswordChange(string $role, int $userId, string $email, string $name, string $currentPassword, string $storedPassword, string $newPassword): void
{
    if ($currentPassword === '' || !password_verify($currentPassword, $storedPassword)) {
        throw new RuntimeException('Your current password is incorrect.');
    }
    if ($newPassword === $currentPassword) {
        throw new RuntimeException('Your new password must be different from your current password.');
    }
    if ($rule = apPasswordRequirements($newPassword)) {
        throw new RuntimeException($rule);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Your account does not have a valid email address for verification.');
    }

    $lastSent = (int)(($_SESSION[apPasswordSessionKey($role)]['last_sent'] ?? 0));
    if ($lastSent > 0 && time() - $lastSent < 60) {
        throw new RuntimeException('Please wait before requesting another verification code.');
    }

    $code = (string)random_int(100000, 999999);
    apSendPasswordChangeCode($role, $email, $name, $code);

    $_SESSION[apPasswordSessionKey($role)] = [
        'user_id' => $userId,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'code_expires' => time() + 300,
        'attempts' => 0,
        'last_sent' => time(),
        'new_password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'email' => $email,
    ];
}

function apVerifyPasswordChange(string $role, int $userId, string $code): bool
{
    $key = apPasswordSessionKey($role);
    $state = $_SESSION[$key] ?? null;
    if (!is_array($state) || (int)($state['user_id'] ?? 0) !== $userId) {
        throw new RuntimeException('Start the password change process again.');
    }
    if (time() > (int)($state['code_expires'] ?? 0)) {
        apClearPasswordChange($role);
        throw new RuntimeException('The verification code has expired. Request a new code.');
    }
    if ((int)($state['attempts'] ?? 0) >= 5) {
        apClearPasswordChange($role);
        throw new RuntimeException('Too many incorrect verification attempts. Request a new code.');
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        throw new RuntimeException('Enter the 6-digit verification code.');
    }

    $_SESSION[$key]['attempts'] = (int)$state['attempts'] + 1;
    if (!password_verify($code, (string)$state['code_hash'])) {
        throw new RuntimeException('Incorrect verification code.');
    }
    return true;
}
