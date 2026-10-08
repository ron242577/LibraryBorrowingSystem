<?php
/**
 * Automatically closes forgotten library attendance sessions at 3:00 PM.
 * Records are stamped with exactly 15:00:00 on their visit date.
 */
function autoCloseForgottenAttendance(mysqli $conn): int {
    $sql = "UPDATE library_attendance
            SET time_out = TIMESTAMP(visit_date, '15:00:00')
            WHERE time_out IS NULL
              AND time_in < TIMESTAMP(visit_date, '15:00:00')
              AND (visit_date < CURDATE() OR (visit_date = CURDATE() AND CURTIME() >= '15:00:00'))";
    if (!$conn->query($sql)) {
        if (function_exists('logError')) {
            logError('Attendance auto-timeout error: ' . $conn->error);
        }
        return 0;
    }
    return max(0, (int)$conn->affected_rows);
}
