<?php
/**
 * Per-copy book tracking.
 *
 * books       = one row per title (metadata + aggregate counts)
 * book_copies = one row per physical copy (unique BK number + unique QR)
 *
 * The copy row is authoritative for availability, borrowing, returning,
 * book numbers, and book QR codes. The parent books row is kept in sync
 * for backward compatibility with older parts of the application.
 */

function bcEnsureSchema(mysqli $conn): void {
    static $done = false;
    if ($done) return;

    $sql = "CREATE TABLE IF NOT EXISTS book_copies (
        copy_id INT NOT NULL AUTO_INCREMENT,
        book_id INT NOT NULL,
        book_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        qr_code VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
        copy_status ENUM('available','borrowed','damaged','lost','removed') NOT NULL DEFAULT 'available',
        copy_condition VARCHAR(20) NOT NULL DEFAULT 'New',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (copy_id),
        UNIQUE KEY uq_copy_qr_code (qr_code),
        KEY idx_copy_book (book_id, copy_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$conn->query($sql)) {
        throw new RuntimeException('Unable to initialize book copy tracking.');
    }

    foreach ([
        'archived_at' => "ALTER TABLE book_copies ADD COLUMN archived_at DATETIME NULL AFTER copy_condition",
        'archived_by' => "ALTER TABLE book_copies ADD COLUMN archived_by INT NULL AFTER archived_at"
    ] as $archiveColumn => $archiveSql) {
        $safeColumn = $conn->real_escape_string($archiveColumn);
        $checkArchiveColumn = $conn->query("SHOW COLUMNS FROM book_copies LIKE '{$safeColumn}'");
        if ($checkArchiveColumn && $checkArchiveColumn->num_rows === 0) {
            if (!$conn->query($archiveSql)) {
                throw new RuntimeException('Unable to initialize book copy archive fields.');
            }
        }
    }

    // Removed copies keep their history, but their old BK number must no longer
    // block a future active copy from reusing that number. Older databases may
    // already have the old UNIQUE index, so remove it once during setup.
    $copyNumberIndex = $conn->query("SHOW INDEX FROM book_copies WHERE Key_name = 'uq_copy_book_number'");
    if ($copyNumberIndex && $copyNumberIndex->num_rows > 0) {
        if (!$conn->query("ALTER TABLE book_copies DROP INDEX uq_copy_book_number")) {
            throw new RuntimeException('Unable to update book copy number indexing.');
        }
    }

    $col = $conn->query("SHOW COLUMNS FROM transactions LIKE 'copy_id'");
    if ($col && $col->num_rows === 0) {
        if (!$conn->query("ALTER TABLE transactions ADD COLUMN copy_id INT NULL AFTER book_id, ADD KEY idx_tx_copy (copy_id)")) {
            throw new RuntimeException('Unable to add transaction copy tracking.');
        }
    }

    $done = true;

    try {
        bcRepairExistingData($conn);
    } catch (Throwable $e) {
        if (function_exists('logError')) logError('Book copy repair warning: ' . $e->getMessage());
    }
}

/** Strictly use physical-copy numbers in BK-001, BK-002 ... format. */
function bcIsBookNumber(string $number): bool {
    return (bool)preg_match('/^BK-\d+$/i', trim($number));
}

/** Returns the highest physical-copy BK number currently in use. */
function bcNumberState(mysqli $conn): array {
    $best = ['prefix' => 'BK-', 'width' => 3, 'max' => 0];
    $res = $conn->query("SELECT book_number FROM book_copies WHERE copy_status<>'removed' AND book_number REGEXP '^BK-[0-9]+$'");
    while ($res && ($row = $res->fetch_row())) {
        $number = strtoupper(trim((string)$row[0]));
        if (preg_match('/^BK-(\d+)$/', $number, $m)) {
            $n = (int)$m[1];
            if ($n > $best['max']) $best['max'] = $n;
            if (strlen($m[1]) > $best['width']) $best['width'] = strlen($m[1]);
        }
    }
    return $best;
}

function bcNumberExists(mysqli $conn, string $number): bool {
    $s = $conn->prepare("SELECT 1 FROM book_copies WHERE book_number = ? AND copy_status<>'removed' LIMIT 1");
    $s->bind_param('s', $number);
    $s->execute();
    $found = $s->get_result()->num_rows > 0;
    $s->close();
    return $found;
}

function bcNextNumber(mysqli $conn, array &$state): string {
    do {
        $state['max']++;
        $number = 'BK-' . str_pad((string)$state['max'], max(3, (int)$state['width']), '0', STR_PAD_LEFT);
    } while (bcNumberExists($conn, $number));
    return $number;
}

function bcNewQrCode(mysqli $conn): string {
    do {
        $code = 'BOOK-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $s = $conn->prepare('SELECT 1 FROM book_copies WHERE qr_code = ? UNION SELECT 1 FROM books WHERE qr_code = ? LIMIT 1');
        $s->bind_param('ss', $code, $code);
        $s->execute();
        $exists = $s->get_result()->num_rows > 0;
        $s->close();
    } while ($exists);
    return $code;
}

function bcEnsureQrImage(string $qrCode): void {
    $qrDir = __DIR__ . '/../qr_codes';
    if (!is_dir($qrDir)) @mkdir($qrDir, 0755, true);

    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $qrCode);
    if ($safe === '') return;
    $file = $qrDir . '/' . $safe . '.png';
    if (is_file($file) && filesize($file) > 0) return;

    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . rawurlencode($qrCode);
    $image = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $image = curl_exec($ch);
        curl_close($ch);
    }
    if ($image === false || strlen((string)$image) === 0) $image = @file_get_contents($url);
    if ($image !== false && strlen((string)$image) > 0) @file_put_contents($file, $image);
}

function bcDeleteQrImage(string $qrCode): bool {
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', trim($qrCode));
    if ($safe === '' || $safe !== trim($qrCode)) return false;

    $file = __DIR__ . '/../qr_codes/' . $safe . '.png';
    if (!is_file($file)) return true;

    $deleted = @unlink($file);
    if (!$deleted && function_exists('logError')) {
        logError('Unable to delete removed book QR image: ' . $file);
    }
    return $deleted;
}

/** Removes QR image files belonging to copies already marked as removed. */
function bcCleanupRemovedQrImages(mysqli $conn): void {
    $res = $conn->query("SELECT qr_code FROM book_copies WHERE copy_status='removed' AND qr_code<>''");
    while ($res && ($row = $res->fetch_assoc())) {
        bcDeleteQrImage((string)$row['qr_code']);
    }
}

function bcInsertCopy(mysqli $conn, int $bookId, string $number, string $qr, string $status, string $condition): int {
    $s = $conn->prepare('INSERT INTO book_copies (book_id, book_number, qr_code, copy_status, copy_condition) VALUES (?,?,?,?,?)');
    $s->bind_param('issss', $bookId, $number, $qr, $status, $condition);
    if (!$s->execute()) {
        $err = $s->error;
        $s->close();
        throw new Exception('Unable to create book copy: ' . $err);
    }
    $id = (int)$conn->insert_id;
    $s->close();
    bcEnsureQrImage($qr);
    return $id;
}

/** Creates new physical copies. Every copy receives a globally unique BK number and QR. */
function bcCreateCopies(mysqli $conn, int $bookId, int $count, string $condition = 'New'): array {
    $created = [];
    if ($count <= 0) return $created;

    $lock = $conn->query("SELECT GET_LOCK('bc_numbers', 15) AS acquired");
    $acquired = $lock ? (int)($lock->fetch_assoc()['acquired'] ?? 0) : 0;
    if ($acquired !== 1) throw new RuntimeException('Unable to reserve the next book numbers. Please try again.');

    try {
        $state = bcNumberState($conn);
        for ($i = 0; $i < $count; $i++) {
            $number = bcNextNumber($conn, $state);
            $qr = bcNewQrCode($conn);
            $id = bcInsertCopy($conn, $bookId, $number, $qr, 'available', $condition ?: 'New');
            $created[] = ['copy_id' => $id, 'book_number' => $number, 'qr_code' => $qr];
        }
    } finally {
        $conn->query("SELECT RELEASE_LOCK('bc_numbers')");
    }
    return $created;
}

/** Synchronizes aggregate title counts and the legacy parent book_number/qr_code fields. */
function bcSync(mysqli $conn, int $bookId): void {
    $s = $conn->prepare("SELECT
            SUM(copy_status <> 'removed') total,
            SUM(copy_status = 'available') available,
            SUM(copy_status = 'borrowed') borrowed,
            SUM(copy_status = 'lost') lost,
            SUM(copy_status = 'damaged') damaged
        FROM book_copies WHERE book_id = ?");
    $s->bind_param('i', $bookId);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();

    $total = (int)($r['total'] ?? 0);
    $av = (int)($r['available'] ?? 0);
    $bo = (int)($r['borrowed'] ?? 0);
    $lo = (int)($r['lost'] ?? 0);
    $da = (int)($r['damaged'] ?? 0);

    if ($av > 0) $status = 'available';
    elseif ($bo === 0 && $lo > 0 && $da === 0) $status = 'lost';
    elseif ($bo === 0 && $da > 0 && $lo === 0) $status = 'damaged';
    else $status = 'out_of_stock';

    $metaStmt = $conn->prepare("SELECT book_number, qr_code FROM book_copies WHERE book_id=? AND copy_status<>'removed' ORDER BY CAST(SUBSTRING(book_number,4) AS UNSIGNED), copy_id LIMIT 1");
    $metaStmt->bind_param('i', $bookId);
    $metaStmt->execute();
    $meta = $metaStmt->get_result()->fetch_assoc();
    $metaStmt->close();

    // A title may remain in the catalogue at zero active copies. Keep its
    // parent-row fields unique without pretending a physical BK/QR still exists.
    if (!$meta) {
        $meta = [
            'book_number' => 'NO-COPY-' . $bookId,
            'qr_code' => 'NO-COPY-QR-' . $bookId
        ];
    }

    $u = $conn->prepare("UPDATE books SET total_copies=?, available_copies=?, borrowed_copies=?, lost_copies=?, damaged_copies=?,
            book_number=?, qr_code=?,
            book_status=IF(COALESCE(is_archived,0)=1, book_status, ?) WHERE book_id=?");
    $u->bind_param(
        'iiiiisssi',
        $total, $av, $bo, $lo, $da,
        $meta['book_number'], $meta['qr_code'],
        $status, $bookId
    );
    $u->execute();
    $u->close();
}


/** Returns every active physical copy for a title, ordered by BK number. */
function bcListCopies(mysqli $conn, int $bookId): array {
    $s = $conn->prepare("SELECT copy_id, book_number, qr_code, copy_status, copy_condition, archived_at, archived_by FROM book_copies WHERE book_id=? AND copy_status<>'removed' ORDER BY CAST(SUBSTRING(book_number,4) AS UNSIGNED), copy_id");
    $s->bind_param('i', $bookId);
    $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    $s->close();
    return $rows;
}

/** Returns all active copies indexed by parent title ID in one query. */
function bcListCopiesByBooks(mysqli $conn): array {
    $map = [];
    $res = $conn->query("SELECT copy_id, book_id, book_number, qr_code, copy_status, copy_condition, archived_at, archived_by FROM book_copies WHERE copy_status<>'removed' ORDER BY book_id, CAST(SUBSTRING(book_number,4) AS UNSIGNED), copy_id");
    while ($res && ($row = $res->fetch_assoc())) {
        $map[(int)$row['book_id']][] = $row;
    }
    return $map;
}

/** Returns one active physical copy joined with its title metadata. */
function bcGetCopyById(mysqli $conn, int $copyId, bool $includeRemoved = false): ?array {
    $statusWhere = $includeRemoved ? '' : " AND c.copy_status<>'removed'";
    $sql = "SELECT c.copy_id, c.book_id, c.book_number, c.qr_code, c.copy_status, c.copy_condition, c.archived_at, c.archived_by,
                    b.title, b.author, b.co_authors, b.place_of_publication, b.publication_date, b.book_pages,
                    b.source_of_funds, b.cost_price, b.publisher, b.edition, b.volumes, b.class, b.type_of_material,
                    b.location_collection, b.library_building, b.shelf_number, b.library_section, b.book_condition,
                    b.total_copies, b.available_copies, b.borrowed_copies, b.lost_copies, b.damaged_copies,
                    b.book_status, b.is_archived, b.created_at
             FROM book_copies c INNER JOIN books b ON b.book_id=c.book_id
             WHERE c.copy_id=? {$statusWhere} LIMIT 1";
    $s = $conn->prepare($sql);
    $s->bind_param('i', $copyId);
    $s->execute();
    $row = $s->get_result()->fetch_assoc() ?: null;
    $s->close();
    return $row;
}

/** Finds a single physical copy by its QR code. */
function bcGetCopyByQr(mysqli $conn, string $qrCode): ?array {
    $s = $conn->prepare("SELECT c.copy_id, c.book_id, c.book_number, c.qr_code, c.copy_status, c.copy_condition, b.title, b.author, b.book_status, b.book_pages, b.total_copies, b.available_copies, b.borrowed_copies FROM book_copies c INNER JOIN books b ON b.book_id=c.book_id WHERE c.qr_code=? AND c.copy_status<>'removed' AND COALESCE(b.is_archived,0)=0 LIMIT 1");
    $s->bind_param('s', $qrCode);
    $s->execute();
    $row = $s->get_result()->fetch_assoc() ?: null;
    $s->close();
    return $row;
}

function bcFindTitle(mysqli $conn, string $title): ?int {
    $s = $conn->prepare('SELECT book_id FROM books WHERE LOWER(TRIM(title)) = LOWER(TRIM(?)) AND COALESCE(is_archived,0) = 0 ORDER BY book_id LIMIT 1');
    $s->bind_param('s', $title);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ? (int)$row['book_id'] : null;
}

/** Claims exactly one currently-available physical copy for a title and links it to the transaction. */
function bcClaimAvailableCopy(mysqli $conn, int $bookId, int $transactionId): ?array {
    $s = $conn->prepare("SELECT copy_id, book_number, qr_code, copy_condition FROM book_copies WHERE book_id=? AND copy_status='available' ORDER BY copy_id LIMIT 1 FOR UPDATE");
    $s->bind_param('i', $bookId);
    $s->execute();
    $copy = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$copy) return null;

    $cid = (int)$copy['copy_id'];
    $u = $conn->prepare("UPDATE book_copies SET copy_status='borrowed' WHERE copy_id=? AND copy_status='available'");
    $u->bind_param('i', $cid);
    $u->execute();
    $changed = $u->affected_rows;
    $u->close();
    if ($changed !== 1) return null;

    $t = $conn->prepare("UPDATE transactions SET copy_id=? WHERE transaction_id=? AND status='borrowed'");
    $t->bind_param('ii', $cid, $transactionId);
    $t->execute();
    $tChanged = $t->affected_rows;
    $t->close();
    if ($tChanged !== 1) return null;

    bcSync($conn, $bookId);
    return $copy;
}

/** Returns copies affected by the title-level lost/damaged editor without touching borrowed copies. */
function bcApplyAffected(mysqli $conn, int $bookId, string $status, int $affected): void {
    $conn->query("UPDATE book_copies SET copy_status='available' WHERE book_id=" . (int)$bookId . " AND copy_status IN ('lost','damaged')");
    if (in_array($status, ['lost','damaged'], true) && $affected > 0) {
        $s = $conn->prepare("UPDATE book_copies SET copy_status=? WHERE book_id=? AND copy_status='available' ORDER BY copy_id DESC LIMIT ?");
        $s->bind_param('sii', $status, $bookId, $affected);
        $s->execute();
        $s->close();
    }
    bcSync($conn, $bookId);
}

function bcRemoveCopies(mysqli $conn, int $bookId, int $count): array {
    if ($count <= 0) return [];

    // Always remove the highest BK numbers first, so removing the newest copies
    // leaves the active sequence compact (for example BK-001 ... BK-006).
    $pick = $conn->prepare("SELECT copy_id, book_number, qr_code FROM book_copies
        WHERE book_id=? AND copy_status='available'
        ORDER BY CAST(SUBSTRING(book_number,4) AS UNSIGNED) DESC, copy_id DESC LIMIT ?");
    $pick->bind_param('ii', $bookId, $count);
    $pick->execute();
    $rows = $pick->get_result()->fetch_all(MYSQLI_ASSOC);
    $pick->close();

    if (!$rows) return [];

    $update = $conn->prepare("UPDATE book_copies SET copy_status='removed' WHERE copy_id=? AND book_id=? AND copy_status='available'");
    $removed = [];
    foreach ($rows as $row) {
        $copyId = (int)$row['copy_id'];
        $update->bind_param('ii', $copyId, $bookId);
        $update->execute();
        if ($update->affected_rows === 1) {
            $removed[] = $row;
        }
    }
    $update->close();

    bcSync($conn, $bookId);
    return $removed;
}


/**
 * Repairs data introduced by earlier versions of the multi-copy feature.
 * It is intentionally conservative: valid BK numbers and QR IDs are kept,
 * invalid book numbers are assigned the next BK number, active loans are
 * guaranteed to point to a real physical copy, and parent totals are synced.
 */
function bcRepairExistingData(mysqli $conn): void {
    static $repairDone = false;
    if ($repairDone) return;
    $repairDone = true;

    $cntRow = $conn->query("SELECT COUNT(*) AS c FROM book_copies")->fetch_assoc();
    $copyCount = (int)($cntRow['c'] ?? 0);

    $bookRows = $conn->query("SELECT book_id, title, total_copies, book_number, qr_code, book_condition, COALESCE(is_archived,0) AS is_archived FROM books ORDER BY book_id")->fetch_all(MYSQLI_ASSOC);

    if ($copyCount === 0 && !empty($bookRows)) {
        bcMigrateExistingBooks($conn);
        return;
    }

    // Complete any title that has a legacy parent counter but no physical copy rows.
    foreach ($bookRows as $book) {
        $bookId = (int)$book['book_id'];
        $c = $conn->prepare("SELECT COUNT(*) AS c FROM book_copies WHERE book_id=?");
        $c->bind_param('i', $bookId);
        $c->execute();
        $hasCopies = (int)($c->get_result()->fetch_assoc()['c'] ?? 0);
        $c->close();
        if ($hasCopies === 0 && (int)$book['total_copies'] > 0) {
            bcCreateCopies($conn, $bookId, (int)$book['total_copies'], trim((string)$book['book_condition']) ?: 'New');
        }
    }

    // Replace non-BK copy numbers with unique temporary placeholders first.
    $invalid = $conn->query("SELECT copy_id FROM book_copies WHERE book_number NOT REGEXP '^BK-[0-9]+$' ORDER BY copy_id")->fetch_all(MYSQLI_NUM);
    foreach ($invalid as $copyId) {
        $tmp = 'TMP-COPY-' . (int)$copyId;
        $u = $conn->prepare("UPDATE book_copies SET book_number=? WHERE copy_id=?");
        $u->bind_param('si', $tmp, $copyId);
        $u->execute();
        $u->close();
    }

    $state = bcNumberState($conn);
    $badRows = $conn->query("SELECT copy_id FROM book_copies WHERE book_number LIKE 'TMP-COPY-%' ORDER BY copy_id")->fetch_all(MYSQLI_NUM);
    foreach ($badRows as $copyId) {
        $number = bcNextNumber($conn, $state);
        $u = $conn->prepare("UPDATE book_copies SET book_number=? WHERE copy_id=?");
        $u->bind_param('si', $number, $copyId);
        $u->execute();
        $u->close();
    }

    // Removed copies are intentionally retained in the archive. Their QR image is
    // deleted only when an administrator permanently removes the archived copy.

    // Ensure every non-empty copy row has a usable QR and image.
    $qrRows = $conn->query("SELECT copy_id, qr_code FROM book_copies ORDER BY copy_id")->fetch_all(MYSQLI_ASSOC);
    foreach ($qrRows as $copy) {
        $qr = trim((string)$copy['qr_code']);
        if ($qr === '') {
            $newQr = bcNewQrCode($conn);
            $u = $conn->prepare("UPDATE book_copies SET qr_code=? WHERE copy_id=?");
            $u->bind_param('si', $newQr, $copy['copy_id']);
            $u->execute();
            $u->close();
            $qr = $newQr;
        }
        bcEnsureQrImage($qr);
    }

    // Active borrowing transactions must each occupy a different physical copy.
    $active = $conn->query("SELECT transaction_id, book_id, copy_id FROM transactions WHERE status='borrowed' ORDER BY transaction_id")->fetch_all(MYSQLI_ASSOC);
    $usedByBook = [];
    foreach ($active as $tx) {
        $txId = (int)$tx['transaction_id'];
        $bookId = (int)$tx['book_id'];
        $copyId = (int)($tx['copy_id'] ?? 0);

        $valid = false;
        if ($copyId > 0) {
            $s = $conn->prepare("SELECT book_id, copy_status FROM book_copies WHERE copy_id=? LIMIT 1");
            $s->bind_param('i', $copyId);
            $s->execute();
            $copy = $s->get_result()->fetch_assoc();
            $s->close();
            $valid = $copy
                && (int)$copy['book_id'] === $bookId
                && ($copy['copy_status'] ?? '') !== 'removed'
                && !isset($usedByBook[$bookId][$copyId]);
            if ($valid && $copy['copy_status'] !== 'borrowed') {
                $u = $conn->prepare("UPDATE book_copies SET copy_status='borrowed' WHERE copy_id=? AND copy_status IN ('available','damaged','lost')");
                $u->bind_param('i', $copyId);
                $u->execute();
                $changed = $u->affected_rows;
                $u->close();
                if ($changed !== 1) $valid = false;
            }
        }

        if (!$valid) {
            $s = $conn->prepare("SELECT copy_id, book_number, qr_code FROM book_copies WHERE book_id=? AND copy_status='available' AND NOT EXISTS (SELECT 1 FROM transactions tx2 WHERE tx2.copy_id=book_copies.copy_id AND tx2.status='borrowed') ORDER BY copy_id LIMIT 1 FOR UPDATE");
            $s->bind_param('i', $bookId);
            $s->execute();
            $replacement = $s->get_result()->fetch_assoc();
            $s->close();
            if ($replacement) {
                $copyId = (int)$replacement['copy_id'];
                $u = $conn->prepare("UPDATE book_copies SET copy_status='borrowed' WHERE copy_id=? AND copy_status='available'");
                $u->bind_param('i', $copyId);
                $u->execute();
                $u->close();
                $t = $conn->prepare("UPDATE transactions SET copy_id=? WHERE transaction_id=?");
                $t->bind_param('ii', $copyId, $txId);
                $t->execute();
                $t->close();
            } else {
                // Legacy data can contain more active transactions than recorded copies.
                // Preserve the active loan by creating one dedicated physical copy.
                $newNumber = bcNextNumber($conn, $state);
                $newQr = bcNewQrCode($conn);
                $copyId = bcInsertCopy($conn, $bookId, $newNumber, $newQr, 'borrowed', 'Good');
                $t = $conn->prepare("UPDATE transactions SET copy_id=? WHERE transaction_id=?");
                $t->bind_param('ii', $copyId, $txId);
                $t->execute();
                $t->close();
            }
        }

        if ($copyId > 0) $usedByBook[$bookId][$copyId] = true;
    }

    foreach ($bookRows as $book) bcSync($conn, (int)$book['book_id']);
}

/**
 * Legacy migration when a database has never had book_copies rows.
 * Existing QR IDs are preserved for the first physical copy whenever possible;
 * physical book numbers are reissued as BK-001, BK-002, ... globally.
 */
function bcMigrateExistingBooks(mysqli $conn): void {
    $lock = $conn->query("SELECT GET_LOCK('bc_migrate', 30) AS acquired");
    $acquired = $lock ? (int)($lock->fetch_assoc()['acquired'] ?? 0) : 0;
    if ($acquired !== 1) throw new RuntimeException('Unable to initialize physical book copies.');

    try {
        $cnt = $conn->query("SELECT COUNT(*) AS c FROM book_copies")->fetch_assoc();
        if ((int)$cnt['c'] > 0) return;

        $rows = $conn->query('SELECT * FROM books ORDER BY book_id')->fetch_all(MYSQLI_ASSOC);
        if (!$rows) return;

        $hasRes = $conn->query("SHOW TABLES LIKE 'book_reservations'");
        $hasRes = $hasRes && $hasRes->num_rows > 0;

        // Merge duplicate active titles before creating copies.
        $groups = [];
        foreach ($rows as $r) {
            $key = ((int)($r['is_archived'] ?? 0) === 1)
                ? 'a' . $r['book_id']
                : 't' . mb_strtolower(trim((string)$r['title']));
            $groups[$key][] = $r;
        }

        $state = ['prefix'=>'BK-', 'width'=>3, 'max'=>0];
        $keepers = [];
        $conn->begin_transaction();
        try {
            foreach ($groups as $group) {
                $keeper = (int)$group[0]['book_id'];
                $keepers[] = $keeper;
                $copyIds = [];
                $lost = 0; $damaged = 0;

                foreach ($group as $row) {
                    $oldBookId = (int)$row['book_id'];
                    if ($oldBookId !== $keeper) {
                        $conn->query("UPDATE transactions SET book_id=$keeper WHERE book_id=$oldBookId");
                        if ($hasRes) $conn->query("UPDATE book_reservations SET book_id=$keeper WHERE book_id=$oldBookId");
                    }

                    $n = max(0, (int)($row['total_copies'] ?? 0));
                    $lost += (int)($row['lost_copies'] ?? 0);
                    $damaged += (int)($row['damaged_copies'] ?? 0);
                    $cond = trim((string)($row['book_condition'] ?? '')) ?: 'Good';

                    for ($c = 0; $c < $n; $c++) {
                        $number = bcNextNumber($conn, $state);
                        $qr = $c === 0 && trim((string)($row['qr_code'] ?? '')) !== ''
                            ? trim((string)$row['qr_code'])
                            : bcNewQrCode($conn);
                        $copyIds[] = bcInsertCopy($conn, $keeper, $number, $qr, 'available', $cond);
                    }
                }

                $tx = $conn->query("SELECT transaction_id FROM transactions WHERE book_id=$keeper AND status='borrowed' ORDER BY transaction_id")->fetch_all(MYSQLI_NUM);
                foreach ($tx as $k => $t) {
                    if (!isset($copyIds[$k])) {
                        $copyIds[] = bcInsertCopy($conn, $keeper, bcNextNumber($conn, $state), bcNewQrCode($conn), 'borrowed', 'Good');
                    }
                    $cid = (int)$copyIds[$k];
                    $conn->query("UPDATE book_copies SET copy_status='borrowed' WHERE copy_id=$cid");
                    $conn->query("UPDATE transactions SET copy_id=$cid WHERE transaction_id=" . (int)$t[0]);
                }

                $free = array_reverse(array_slice($copyIds, count($tx)));
                foreach ($free as $cid) {
                    if ($lost > 0) { $conn->query("UPDATE book_copies SET copy_status='lost' WHERE copy_id=$cid"); $lost--; }
                    elseif ($damaged > 0) { $conn->query("UPDATE book_copies SET copy_status='damaged' WHERE copy_id=$cid"); $damaged--; }
                }

                $first = $conn->query("SELECT book_number, qr_code FROM book_copies WHERE book_id=$keeper ORDER BY copy_id LIMIT 1")->fetch_assoc();
                if ($first) {
                    $st = $conn->prepare("UPDATE books SET book_number=?, qr_code=? WHERE book_id=?");
                    $st->bind_param('ssi', $first['book_number'], $first['qr_code'], $keeper);
                    $st->execute();
                    $st->close();
                }
            }

            foreach ($groups as $group) {
                foreach (array_slice($group, 1) as $row) $conn->query('DELETE FROM books WHERE book_id=' . (int)$row['book_id']);
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        foreach ($keepers as $k) bcSync($conn, $k);
    } finally {
        $conn->query("SELECT RELEASE_LOCK('bc_migrate')");
    }
}
