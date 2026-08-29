<?php
/**
 * Backup and restore helpers for the JASHS Library Borrowing System.
 * Backups are stored inside a non-public/protected storage directory.
 */

function backupStorageDir(): string
{
    $dir = dirname(__DIR__) . '/storage/backups';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create backup storage directory.');
    }
    return $dir;
}

function backupHistoryHasColumn(mysqli $conn, string $column): bool
{
    $column = $conn->real_escape_string($column);
    $result = $conn->query(
        "SELECT COUNT(*) AS c
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'backup_history'
           AND COLUMN_NAME = '{$column}'"
    );
    if (!$result) {
        return false;
    }
    $row = $result->fetch_assoc();
    return (int)($row['c'] ?? 0) > 0;
}

function ensureBackupHistoryTable(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS backup_history (
        backup_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        action ENUM('backup','restore') NOT NULL DEFAULT 'backup',
        filename VARCHAR(255) NULL,
        file_size BIGINT UNSIGNED NULL,
        status ENUM('started','success','failed') NOT NULL DEFAULT 'started',
        created_by INT NULL,
        created_by_name VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        notes VARCHAR(500) NULL,
        PRIMARY KEY (backup_id),
        KEY idx_backup_created_at (created_at),
        KEY idx_backup_action (action),
        KEY idx_backup_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    if (!$conn->query($sql)) {
        throw new RuntimeException('Unable to initialize backup history.');
    }

    /*
     * Older versions of the enhancement used `file_name`, while the current
     * backup UI expects `filename`. Normalize the existing table in place.
     */
    $hasFilename = backupHistoryHasColumn($conn, 'filename');
    $hasFileName = backupHistoryHasColumn($conn, 'file_name');

    if (!$hasFilename && $hasFileName) {
        if (!$conn->query("ALTER TABLE backup_history CHANGE COLUMN file_name filename VARCHAR(255) NULL")) {
            throw new RuntimeException('Unable to upgrade backup history filename column.');
        }
        $hasFilename = true;
    }

    if (!$hasFilename && !$hasFileName) {
        if (!$conn->query("ALTER TABLE backup_history ADD COLUMN filename VARCHAR(255) NULL AFTER action")) {
            throw new RuntimeException('Unable to add backup filename column.');
        }
    }

    $columnsToAdd = [
        'action' => "ALTER TABLE backup_history ADD COLUMN action ENUM('backup','restore') NOT NULL DEFAULT 'backup' AFTER backup_id",
        'file_size' => "ALTER TABLE backup_history ADD COLUMN file_size BIGINT UNSIGNED NULL AFTER filename",
        'status' => "ALTER TABLE backup_history ADD COLUMN status ENUM('started','success','failed') NOT NULL DEFAULT 'started' AFTER file_size",
        'created_by' => "ALTER TABLE backup_history ADD COLUMN created_by INT NULL AFTER status",
        'created_by_name' => "ALTER TABLE backup_history ADD COLUMN created_by_name VARCHAR(255) NULL AFTER created_by",
        'created_at' => "ALTER TABLE backup_history ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by_name",
        'completed_at' => "ALTER TABLE backup_history ADD COLUMN completed_at TIMESTAMP NULL DEFAULT NULL AFTER created_at",
        'notes' => "ALTER TABLE backup_history ADD COLUMN notes VARCHAR(500) NULL AFTER completed_at"
    ];

    foreach ($columnsToAdd as $column => $alterSql) {
        if (!backupHistoryHasColumn($conn, $column)) {
            if (!$conn->query($alterSql)) {
                throw new RuntimeException('Unable to upgrade backup history table.');
            }
        }
    }
}

function backupActor(): array
{
    return [
        'id' => !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        'name' => (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Chief Librarian')
    ];
}

function createDatabaseBackup(mysqli $conn): array
{
    ensureBackupHistoryTable($conn);
    $actor = backupActor();
    $filename = 'jaschs_library_backup_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(3)) . '.sql';
    $path = backupStorageDir() . DIRECTORY_SEPARATOR . $filename;

    $stmt = $conn->prepare("INSERT INTO backup_history (action, filename, status, created_by, created_by_name, ip_address, notes) VALUES ('backup', ?, 'started', ?, ?, ?, 'Database backup started')");
    if (!$stmt) throw new RuntimeException('Unable to start backup history entry.');
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    $stmt->bind_param('siss', $filename, $actor['id'], $actor['name'], $ip);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to start backup history entry.'); }
    $historyId = $conn->insert_id;
    $stmt->close();

    try {
        $tables = [];
        $result = $conn->query('SHOW TABLES');
        if (!$result) throw new RuntimeException('Unable to read database tables.');
        while ($row = $result->fetch_row()) { $tables[] = $row[0]; }

        $sql = "-- Jose Abad Santos High School Library Borrowing System Backup\n";
        $sql .= '-- Generated: ' . date('Y-m-d H:i:s') . "\n";
        $sql .= '-- Database: ' . DB_NAME . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

        foreach ($tables as $table) {
            $safeTable = str_replace('`', '``', $table);
            $create = $conn->query("SHOW CREATE TABLE `{$safeTable}`");
            if ($create && ($row = $create->fetch_row())) {
                $sql .= "DROP TABLE IF EXISTS `{$safeTable}`;\n" . $row[1] . ";\n\n";
            }

            $data = $conn->query("SELECT * FROM `{$safeTable}`");
            if ($data && $data->num_rows > 0) {
                $columnsResult = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");
                $columns = [];
                while ($col = $columnsResult->fetch_assoc()) { $columns[] = $col['Field']; }
                $escapedColumns = array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', $columns);
                while ($record = $data->fetch_assoc()) {
                    $values = [];
                    foreach ($columns as $column) {
                        $value = $record[$column];
                        $values[] = $value === null ? 'NULL' : "'" . $conn->real_escape_string((string)$value) . "'";
                    }
                    $sql .= 'INSERT INTO `' . $safeTable . '` (' . implode(', ', $escapedColumns) . ') VALUES (' . implode(', ', $values) . ");\n";
                }
                $sql .= "\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        if (file_put_contents($path, $sql, LOCK_EX) === false) {
            throw new RuntimeException('Unable to save the database backup file.');
        }
        $size = filesize($path) ?: 0;
        $update = $conn->prepare("UPDATE backup_history SET status='success', file_size=?, completed_at=NOW(), notes='Database backup completed successfully' WHERE backup_id=?");
        $update->bind_param('ii', $size, $historyId);
        $update->execute();
        $update->close();
        return ['backup_id' => $historyId, 'filename' => $filename, 'path' => $path, 'size' => $size];
    } catch (Throwable $e) {
        @unlink($path);
        $update = $conn->prepare("UPDATE backup_history SET status='failed', completed_at=NOW(), notes=? WHERE backup_id=?");
        $note = substr($e->getMessage(), 0, 500);
        $update->bind_param('si', $note, $historyId);
        $update->execute();
        $update->close();
        throw $e;
    }
}

function listBackupHistory(mysqli $conn, int $limit = 30): array
{
    ensureBackupHistoryTable($conn);
    $limit = max(1, min(100, $limit));
    $rows = [];
    $result = $conn->query("SELECT backup_id, action, filename, file_size, status, created_by_name, created_at, completed_at, notes FROM backup_history ORDER BY created_at DESC LIMIT {$limit}");
    if ($result) { while ($row = $result->fetch_assoc()) $rows[] = $row; }
    return $rows;
}

function safeBackupFilename(string $filename): string
{
    $filename = basename($filename);
    if (!preg_match('/^jaschs_library_backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}_[a-f0-9]{6}\.sql$/i', $filename)) {
        throw new RuntimeException('Invalid backup filename.');
    }
    return $filename;
}

function restoreDatabaseBackup(mysqli $conn, string $filename): void
{
    ensureBackupHistoryTable($conn);
    $filename = safeBackupFilename($filename);
    $path = backupStorageDir() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) throw new RuntimeException('Backup file not found.');
    $size = filesize($path);
    if ($size === false || $size <= 0) throw new RuntimeException('Backup file is empty.');
    if ($size > 50 * 1024 * 1024) throw new RuntimeException('Backup file exceeds the 50 MB restore limit.');

    $actor = backupActor();
    $stmt = $conn->prepare("INSERT INTO backup_history (action, filename, file_size, status, created_by, created_by_name, notes) VALUES ('restore', ?, ?, 'started', ?, ?, 'Database restore started')");
    $stmt->bind_param('siis', $filename, $size, $actor['id'], $actor['name']);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to start restore history entry.'); }
    $historyId = $conn->insert_id;
    $stmt->close();

    try {
        $sql = file_get_contents($path);
        if ($sql === false) throw new RuntimeException('Unable to read backup file.');

        // Reject obvious unsafe commands that should never be part of an application restore file.
        foreach (['CREATE USER', 'GRANT ', 'REVOKE ', 'DROP DATABASE', 'CREATE DATABASE', 'ALTER USER'] as $blocked) {
            if (stripos($sql, $blocked) !== false) {
                throw new RuntimeException('Backup contains a prohibited database administration command.');
            }
        }

        if (!$conn->multi_query($sql)) {
            throw new RuntimeException('Database restore failed: ' . $conn->error);
        }
        do {
            $result = $conn->store_result();
            if ($result instanceof mysqli_result) $result->free();
        } while ($conn->more_results() && $conn->next_result());
        if ($conn->errno) throw new RuntimeException('Database restore failed: ' . $conn->error);

        // A restore may replace the backup_history table itself. Recreate it so
        // the current restore operation can still be recorded safely.
        ensureBackupHistoryTable($conn);
        $update = $conn->prepare("UPDATE backup_history SET status='success', completed_at=NOW(), notes='Database restore completed successfully' WHERE backup_id=?");
        if ($update) {
            $update->bind_param('i', $historyId);
            $update->execute();
            $update->close();
        } else {
            $actor = backupActor();
            $insert = $conn->prepare("INSERT INTO backup_history (action, filename, file_size, status, created_by, created_by_name, notes, completed_at) VALUES ('restore', ?, ?, 'success', ?, ?, 'Database restore completed successfully', NOW())");
            if ($insert) {
                $insert->bind_param('siis', $filename, $size, $actor['id'], $actor['name']);
                $insert->execute();
                $insert->close();
            }
        }
    } catch (Throwable $e) {
        $note = substr($e->getMessage(), 0, 500);
        $update = $conn->prepare("UPDATE backup_history SET status='failed', completed_at=NOW(), notes=? WHERE backup_id=?");
        $update->bind_param('si', $note, $historyId);
        $update->execute();
        $update->close();
        throw $e;
    }
}
