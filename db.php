<?php
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
define('SHOW_ERRORS', true); // Set to false in production
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
