<?php
date_default_timezone_set('Asia/Manila');

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
define('SHOW_ERRORS', true); // Keep database details hidden from users
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

    // Keep MySQL date/time functions aligned with the application's Asia/Manila clock.
    // This makes the 3:00 PM automatic attendance timeout deterministic on Laragon/MySQL.
    $conn->query("SET time_zone = '+08:00'");

    // Backfill the reservation table for databases created before the reservation
    // module was introduced. Keeping this schema initialization here lets every
    // module safely share the same table without failing on first request.
    $conn->query("CREATE TABLE IF NOT EXISTS book_reservations (
        reservation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        student_id INT NULL,
        teacher_id INT NULL,
        book_id INT NOT NULL,
        status ENUM('pending','ready','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
        reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ready_at DATETIME NULL,
        fulfilled_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        notes VARCHAR(500) NULL,
        PRIMARY KEY (reservation_id),
        KEY idx_reservations_book_status (book_id, status, reserved_at),
        KEY idx_reservations_student (student_id, status),
        KEY idx_reservations_teacher (teacher_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Teacher reservations share the existing reservation workflow.
    $reservationColumn = $conn->query("SHOW COLUMNS FROM book_reservations LIKE 'teacher_id'");
    if ($reservationColumn && $reservationColumn->num_rows === 0) {
        $conn->query("ALTER TABLE book_reservations ADD COLUMN teacher_id INT NULL AFTER student_id");
        $conn->query("ALTER TABLE book_reservations ADD KEY idx_reservations_teacher (teacher_id)");
    }
    $studentReservationColumn = $conn->query("SHOW COLUMNS FROM book_reservations LIKE 'student_id'");
    if ($studentReservationColumn && ($studentReservationDefinition = $studentReservationColumn->fetch_assoc()) && strtoupper((string)$studentReservationDefinition['Null']) === 'NO') {
        $conn->query("ALTER TABLE book_reservations MODIFY student_id INT NULL");
    }

    // Backfill columns added to the reservation workflow so older installations
    // can run the same ready/fulfilled/cancelled flow as new databases.
    $reservationMigrations = [
        'ready_at' => "ALTER TABLE book_reservations ADD COLUMN ready_at DATETIME NULL AFTER reserved_at",
        'fulfilled_at' => "ALTER TABLE book_reservations ADD COLUMN fulfilled_at DATETIME NULL AFTER ready_at",
        'cancelled_at' => "ALTER TABLE book_reservations ADD COLUMN cancelled_at DATETIME NULL AFTER fulfilled_at",
        'notes' => "ALTER TABLE book_reservations ADD COLUMN notes VARCHAR(500) NULL AFTER cancelled_at",
    ];
    foreach ($reservationMigrations as $columnName => $alterSql) {
        $safeColumn = $conn->real_escape_string($columnName);
        $columnCheck = $conn->query("SHOW COLUMNS FROM book_reservations LIKE '{$safeColumn}'");
        if ($columnCheck && $columnCheck->num_rows === 0) {
            if (!$conn->query($alterSql)) {
                throw new Exception('Unable to initialize reservation field: ' . $columnName);
            }
        }
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

    $conn->query("CREATE TABLE IF NOT EXISTS library_attendance (
        attendance_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        student_id INT NULL,
        teacher_id INT NULL,
        visit_date DATE NOT NULL,
        time_in DATETIME NOT NULL,
        time_out DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (attendance_id),
        KEY idx_attendance_student_date (student_id, visit_date),
        KEY idx_attendance_teacher_date (teacher_id, visit_date),
        KEY idx_attendance_time_in (time_in),
        CONSTRAINT fk_attendance_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
        CONSTRAINT fk_attendance_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $attendanceTeacherColumn = $conn->query("SHOW COLUMNS FROM library_attendance LIKE 'teacher_id'");
    if ($attendanceTeacherColumn && $attendanceTeacherColumn->num_rows === 0) {
        $conn->query("ALTER TABLE library_attendance ADD COLUMN teacher_id INT NULL AFTER student_id");
        $conn->query("ALTER TABLE library_attendance ADD KEY idx_attendance_teacher_date (teacher_id, visit_date)");
    }
    $conn->query("ALTER TABLE library_attendance MODIFY student_id INT NULL");
    $attendanceTeacherConstraint = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'library_attendance' AND CONSTRAINT_NAME = 'fk_attendance_teacher'");
    if ($attendanceTeacherConstraint && $attendanceTeacherConstraint->num_rows === 0) {
        $conn->query("ALTER TABLE library_attendance ADD CONSTRAINT fk_attendance_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE");
    }

    // Close forgotten attendance sessions at 3:00 PM whenever the application receives a request.
    // The attendance kiosk also polls a dedicated endpoint, so an open kiosk records the timeout
    // shortly after 3:00 PM even when nobody scans another QR code.
    require_once __DIR__ . '/includes/attendance_auto_timeout.php';
    autoCloseForgottenAttendance($conn);
    
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
