<?php
/**
 * Database audit logger for Jose Abad Santos High School Library Borrowing System.
 *
 * This logger records every application request/process that reaches PHP and uses
 * the database connection. Sensitive values such as passwords, verification
 * codes, CAPTCHA answers, CSRF tokens, and mail credentials are never stored.
 */

if (function_exists('auditLogEvent')) {
    return;
}

function ensureAuditLogTable(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS audit_logs (
        audit_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type VARCHAR(120) NOT NULL,
        module VARCHAR(120) NOT NULL,
        description VARCHAR(500) NOT NULL,
        status ENUM('info','success','failure','attempt') NOT NULL DEFAULT 'info',
        actor_type ENUM('admin','student','guest','system') NOT NULL DEFAULT 'guest',
        actor_id INT NULL,
        actor_name VARCHAR(255) NULL,
        target_type VARCHAR(80) NULL,
        target_id VARCHAR(120) NULL,
        request_method VARCHAR(10) NOT NULL,
        request_uri VARCHAR(500) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent VARCHAR(500) NULL,
        metadata LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (audit_log_id),
        KEY idx_audit_created_at (created_at),
        KEY idx_audit_actor (actor_type, actor_id),
        KEY idx_audit_event_type (event_type),
        KEY idx_audit_module (module),
        KEY idx_audit_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        error_log('Unable to create audit_logs table: ' . $conn->error);
    }
}

function auditSensitiveKey(string $key): bool
{
    $normalized = strtolower(trim($key));
    $sensitive = [
        'password', 'current_password', 'new_password', 'confirm_password',
        'password_confirmation', 'verification_code', 'otp', 'otp_code',
        'captcha', 'captcha_answer', 'csrf_token', 'token', 'secret',
        'app_password', 'mail_app_password', 'authorization'
    ];

    if (in_array($normalized, $sensitive, true)) {
        return true;
    }

    foreach (['password', 'secret', 'token', 'captcha', 'verification', 'otp'] as $needle) {
        if (str_contains($normalized, $needle)) {
            return true;
        }
    }

    return false;
}

function auditSanitizeValue(mixed $value, int $depth = 0): mixed
{
    if ($depth > 4) {
        return '[TRUNCATED]';
    }

    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            $keyString = (string)$key;
            if (auditSensitiveKey($keyString)) {
                $clean[$keyString] = '[REDACTED]';
                continue;
            }
            $clean[$keyString] = auditSanitizeValue($item, $depth + 1);
        }
        return $clean;
    }

    if (is_object($value)) {
        return '[OBJECT]';
    }

    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
        return $value;
    }

    $text = trim((string)$value);
    if (mb_strlen($text) > 500) {
        $text = mb_substr($text, 0, 500) . '…';
    }
    return $text;
}

function auditCurrentActor(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return ['type' => 'guest', 'id' => null, 'name' => null];
    }

    if (!empty($_SESSION['user_id'])) {
        return [
            'type' => 'admin',
            'id' => (int)$_SESSION['user_id'],
            'name' => (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin')
        ];
    }

    if (!empty($_SESSION['student_id'])) {
        return [
            'type' => 'student',
            'id' => (int)$_SESSION['student_id'],
            'name' => (string)($_SESSION['student_name'] ?? $_SESSION['student_no'] ?? 'Student')
        ];
    }

    return ['type' => 'guest', 'id' => null, 'name' => null];
}

function auditRequestMethod(): string
{
    return substr(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI')), 0, 10);
}

function auditRequestUri(): string
{
    return substr((string)($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'] ?? 'unknown'), 0, 500);
}

function auditIpAddress(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

function auditUserAgent(): ?string
{
    $agent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $agent === '' ? null : mb_substr($agent, 0, 500);
}

function auditModuleFromPath(): string
{
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'] ?? ''), PHP_URL_PATH) ?: '';
    $path = trim($path, '/');
    if ($path === '') {
        return 'system';
    }

    $parts = explode('/', $path);
    $libraryIndex = array_search('LibraryBorrowingSystem', $parts, true);
    if ($libraryIndex !== false) {
        $parts = array_slice($parts, $libraryIndex + 1);
    }

    if (empty($parts)) {
        return 'system';
    }

    if (($parts[0] ?? '') === 'admin') {
        return 'admin/' . pathinfo((string)($parts[1] ?? 'dashboard'), PATHINFO_FILENAME);
    }
    if (($parts[0] ?? '') === 'student') {
        return 'student/' . pathinfo((string)($parts[1] ?? 'portal'), PATHINFO_FILENAME);
    }

    return pathinfo((string)end($parts), PATHINFO_FILENAME) ?: 'system';
}

function auditInferEventType(): string
{
    $method = auditRequestMethod();
    $module = auditModuleFromPath();

    $candidate = $_POST['action']
        ?? $_POST['api']
        ?? $_GET['api']
        ?? $_GET['action']
        ?? null;

    if (is_string($candidate) && trim($candidate) !== '') {
        return strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($candidate)));
    }

    if ($module === 'login' && $method === 'POST') {
        return 'admin_login';
    }
    if ($module === 'logout') {
        return 'admin_logout';
    }
    if ($module === 'student/portal' && $method === 'POST') {
        return 'student_login';
    }
    if ($module === 'student/register' && $method === 'POST') {
        return 'student_registration';
    }
    if ($module === 'download_qr') {
        return 'download_qr';
    }

    return strtolower(str_replace('/', '_', $module)) . '_' . strtolower($method);
}

function auditLogEvent(
    mysqli $conn,
    string $eventType,
    string $module,
    string $description,
    string $status = 'info',
    ?string $targetType = null,
    int|string|null $targetId = null,
    array $metadata = []
): void {
    if (!in_array($status, ['info', 'success', 'failure', 'attempt'], true)) {
        $status = 'info';
    }

    $actor = auditCurrentActor();
    $requestMethod = auditRequestMethod();
    $requestUri = auditRequestUri();
    $ipAddress = auditIpAddress();
    $userAgent = auditUserAgent();

    $safeMetadata = auditSanitizeValue($metadata);
    $metadataJson = empty($safeMetadata)
        ? null
        : json_encode($safeMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

    $actorId = $actor['id'];
    $actorName = $actor['name'];
    $targetIdString = $targetId === null ? null : (string)$targetId;

    $stmt = $conn->prepare("INSERT INTO audit_logs
        (event_type, module, description, status, actor_type, actor_id, actor_name, target_type, target_id, request_method, request_uri, ip_address, user_agent, metadata)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        error_log('Audit logger prepare failed: ' . $conn->error);
        return;
    }

    $stmt->bind_param(
        'sssssissssssss',
        $eventType,
        $module,
        $description,
        $status,
        $actor['type'],
        $actorId,
        $actorName,
        $targetType,
        $targetIdString,
        $requestMethod,
        $requestUri,
        $ipAddress,
        $userAgent,
        $metadataJson
    );

    if (!$stmt->execute()) {
        error_log('Audit logger execute failed: ' . $stmt->error);
    }
    $stmt->close();
}

function auditRequestMetadata(float $startedAt): array
{
    $metadata = [
        'query' => auditSanitizeValue($_GET),
        'form' => auditSanitizeValue($_POST),
        'response_code' => http_response_code(),
        'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2)
    ];

    if (!empty($_FILES)) {
        $files = [];
        foreach ($_FILES as $field => $file) {
            $files[$field] = [
                'name' => basename((string)($file['name'] ?? '')),
                'size' => (int)($file['size'] ?? 0),
                'error' => (int)($file['error'] ?? 0)
            ];
        }
        $metadata['files'] = $files;
    }

    return $metadata;
}

function registerAuditRequestLogger(mysqli $conn): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    ensureAuditLogTable($conn);
    $startedAt = microtime(true);

    register_shutdown_function(function () use ($conn, $startedAt): void {
        if (!($conn instanceof mysqli)) {
            return;
        }

        try {
            if (!$conn->ping()) {
                return;
            }

            $module = auditModuleFromPath();
            $eventType = auditInferEventType();
            $method = auditRequestMethod();
            $responseCode = http_response_code();
            $description = $method . ' ' . auditRequestUri();

            // A request log records the process itself. Logical success/failure
            // details remain in the application tables and UI; HTTP errors are
            // marked as failures here.
            $status = $responseCode >= 400 ? 'failure' : 'info';

            auditLogEvent(
                $conn,
                $eventType,
                $module,
                $description,
                $status,
                null,
                null,
                auditRequestMetadata($startedAt)
            );
        } catch (Throwable $e) {
            error_log('Audit request logger failed: ' . $e->getMessage());
        }
    });
}
