<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/audit_logger.php';
startSecureSession();

/**
 * Database Connection File - MySQLi with Error Handling
 * Library QR Borrowing System
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'library_borrowing_system');
define('DB_PORT', 3306);

// Error handling configuration
define('SHOW_ERRORS', false); // Keep database details hidden from users
define('LOG_ERRORS', true);
define('ERROR_LOG_FILE', __DIR__ . '/logs/error.log');

// Create logs directory if it doesn't exist
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

// Create MySQLi connection
try {
    // Procedural approach with error handling
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, DB_PORT);
    
    // Check connection
    if ($conn->connect_error) {
        throw new Exception('Database Connection Failed: ' . $conn->connect_error);
    }
    
    // Set charset to utf8mb4
    if (!$conn->set_charset('utf8mb4')) {
        throw new Exception('Error loading character set utf8mb4: ' . $conn->error);
    }

    // Security table used for login throttling. This does not change any password.
    $conn->query("CREATE TABLE IF NOT EXISTS login_security (
        login_security_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        context VARCHAR(30) NOT NULL,
        identifier_hash CHAR(64) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        first_failed_at DATETIME NOT NULL,
        last_failed_at DATETIME NOT NULL,
        locked_until DATETIME NULL,
        PRIMARY KEY (login_security_id),
        UNIQUE KEY uq_login_security (context, identifier_hash, ip_address),
        KEY idx_login_locked_until (locked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Register one sanitized database audit entry for every application request/process.
    registerAuditRequestLogger($conn);
    
} catch (Exception $e) {
    // Log error
    logError($e->getMessage());
    
    // Display error message (controlled by SHOW_ERRORS)
    if (SHOW_ERRORS) {
        die('Database Connection Error: ' . htmlspecialchars($e->getMessage()));
    } else {
        die('An error occurred. Please contact the administrator.');
    }
}

/**
 * Log errors to file
 * 
 * @param string $message Error message to log
 * @return void
 */
function logError($message) {
    if (LOG_ERRORS && ERROR_LOG_FILE) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] {$message}\n";
        error_log($log_message, 3, ERROR_LOG_FILE);
    }
}

/**
 * Execute query with error handling
 * 
 * @param mysqli $connection Database connection
 * @param string $query SQL query to execute
 * @param string $types Parameter types (if using prepared statement)
 * @param array $params Parameters (if using prepared statement)
 * @return mysqli_result|bool Result object or boolean
 * @throws Exception If query execution fails
 */
function executeQuery($connection, $query, $types = '', $params = []) {
    try {
        if (!empty($types) && !empty($params)) {
            // Using prepared statement
            $stmt = $connection->prepare($query);
            
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $connection->error);
            }
            
            // Bind parameters
            $stmt->bind_param($types, ...$params);
            
            // Execute statement
            if (!$stmt->execute()) {
                throw new Exception('Execute failed: ' . $stmt->error);
            }
            
            return $stmt->get_result();
        } else {
            // Direct query execution
            $result = $connection->query($query);
            
            if (!$result) {
                throw new Exception('Query failed: ' . $connection->error);
            }
            
            return $result;
        }
    } catch (Exception $e) {
        logError($e->getMessage());
        if (SHOW_ERRORS) {
            throw $e;
        }
        return false;
    }
}

/**
 * Get single row from query result
 * 
 * @param mysqli_result $result Query result
 * @return array|null Row as associative array or null if no rows
 */
function getRow($result) {
    return $result ? $result->fetch_assoc() : null;
}

/**
 * Get all rows from query result
 * 
 * @param mysqli_result $result Query result
 * @return array Array of rows
 */
function getRows($result) {
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

// Return connection object for use in other files
return $conn;
?>
