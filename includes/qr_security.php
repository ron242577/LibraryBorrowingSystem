<?php
/**
 * QR transaction security helpers.
 *
 * QR values remain compatible with existing printed codes. Security is enforced
 * by validating the code shape, checking ownership/existence in the database,
 * rejecting archived/inactive records, limiting lookup attempts, and applying
 * server-side transaction checks.
 */

function qrClientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function qrSecurityTableExists(mysqli $conn): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    $result = $conn->query("SHOW TABLES LIKE 'qr_security_log'");
    $exists = $result && $result->num_rows > 0;
    return $exists;
}

function qrSecurityThrottle(mysqli $conn, string $context, string $code): bool
{
    if (!qrSecurityTableExists($conn)) {
        return true;
    }

    $context = substr($context, 0, 30);
    $ip = qrClientIp();

    // Hash the identifier instead of storing raw QR values in the security log.
    $identifierHash = hash('sha256', $context . '|' . $code);

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS attempts
        FROM qr_security_log
        WHERE context = ?
          AND identifier_hash = ?
          AND ip_address = ?
          AND created_at >= (NOW() - INTERVAL 1 MINUTE)
    ");

    if (!$stmt) {
        return true;
    }

    $stmt->bind_param('sss', $context, $identifierHash, $ip);
    $stmt->execute();
    $attempts = (int)($stmt->get_result()->fetch_assoc()['attempts'] ?? 0);
    $stmt->close();

    return $attempts < 15;
}

function qrSecurityLog(
    mysqli $conn,
    string $context,
    string $code,
    string $result,
    ?int $actorId = null,
    ?string $actorType = null,
    ?int $targetId = null,
    ?string $message = null
): void {
    if (!qrSecurityTableExists($conn)) {
        return;
    }

    $identifierHash = hash('sha256', $context . '|' . $code);
    $ip = qrClientIp();
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

    $stmt = $conn->prepare("
        INSERT INTO qr_security_log
        (context, identifier_hash, result, actor_id, actor_type, target_id, ip_address, user_agent, message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'sssisssss',
        $context,
        $identifierHash,
        $result,
        $actorId,
        $actorType,
        $targetId,
        $ip,
        $userAgent,
        $message
    );
    $stmt->execute();
    $stmt->close();
}

function validateStudentQrFormat(string $code): bool
{
    return (bool)preg_match('/^STU-[A-Z0-9][A-Z0-9_-]{3,99}$/i', $code);
}

function validateBookQrFormat(string $code): bool
{
    return (bool)preg_match('/^BOOK-[A-Z0-9][A-Z0-9_-]{5,99}$/i', $code);
}

function sanitizeQrInput($value): string
{
    $value = trim((string)$value);
    if ($value === '' || strlen($value) > 120) {
        return '';
    }
    return $value;
}
?>
