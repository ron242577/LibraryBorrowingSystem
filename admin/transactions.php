<?php
/**
 * Transactions - Jose Abad Santos High School Library Borrowing System
 * Same-day borrowing and return records.
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/print_charts.php';
require_once __DIR__ . '/../includes/book_copies.php';
bcEnsureSchema($conn);

if (!isAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$filter_status = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');
$allowed_statuses = ['all', 'borrowed', 'returned'];
if (!in_array($filter_status, $allowed_statuses, true)) {
    $filter_status = 'all';
}

$where = ['1=1'];
if ($filter_status !== 'all') {
    $where[] = "t.status = '" . $conn->real_escape_string($filter_status) . "'";
}
if ($start_date !== '') {
    $start_date_sql = $conn->real_escape_string($start_date);
    $where[] = "DATE(t.date_borrowed) >= '$start_date_sql'";
}
if ($end_date !== '') {
    $end_date_sql = $conn->real_escape_string($end_date);
    $where[] = "DATE(t.date_borrowed) <= '$end_date_sql'";
}
if ($search !== '') {
    $term = $conn->real_escape_string($search);
    // Explicitly normalize the search value and every text operand to the same
    // utf8mb4 collation. Some existing installations use utf8mb4_bin or a newer
    // MySQL 8 collation on individual columns, which otherwise causes LIKE errors.
    $likeTerm = "CONVERT('%$term%' USING utf8mb4) COLLATE utf8mb4_unicode_ci";
    $where[] = "(
        (s.full_name COLLATE utf8mb4_unicode_ci) LIKE $likeTerm OR
        (te.full_name COLLATE utf8mb4_unicode_ci) LIKE $likeTerm OR
        (s.student_no COLLATE utf8mb4_unicode_ci) LIKE $likeTerm OR
        (te.teacher_no COLLATE utf8mb4_unicode_ci) LIKE $likeTerm OR
        (b.title COLLATE utf8mb4_unicode_ci) LIKE $likeTerm OR
        (b.author COLLATE utf8mb4_unicode_ci) LIKE $likeTerm OR
        (COALESCE(c.book_number,b.book_number) COLLATE utf8mb4_unicode_ci) LIKE $likeTerm OR
        (CONVERT(CAST(t.transaction_id AS CHAR) USING utf8mb4) COLLATE utf8mb4_unicode_ci) LIKE $likeTerm
    )";
}
$where_sql = implode(' AND ', $where);

$transactions = [];
$transaction_query_error = null;
try {
    $query = "SELECT
                t.transaction_id,
                t.date_borrowed,
                t.due_date,
                t.return_date,
                t.status,
                COALESCE(s.student_no, te.teacher_no, '—') AS borrower_no,
                COALESCE(s.full_name, te.full_name, 'Unknown borrower') AS borrower_name,
                c.copy_id,
                COALESCE(c.book_number, b.book_number, CONCAT('ID ', t.book_id)) AS book_number,
                c.qr_code AS copy_qr_code,
                COALESCE(NULLIF(b.title, ''), CONCAT('Book #', t.book_id)) AS book_title,
                COALESCE(b.author, '') AS book_author
              FROM transactions t
              LEFT JOIN students s ON t.student_id = s.student_id
              LEFT JOIN teachers te ON t.teacher_id = te.teacher_id
              LEFT JOIN books b ON t.book_id = b.book_id
              LEFT JOIN book_copies c ON t.copy_id = c.copy_id
              WHERE $where_sql
              ORDER BY t.date_borrowed DESC";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
    }
} catch (Throwable $e) {
    $transaction_query_error = $e->getMessage();
    logError('Transactions page error: ' . $e->getMessage());

    // Production-safe fallback: always expose the transaction rows even if an
    // optional book/copy field or collation in an older database differs.
    try {
        $fallbackWhere = ['1=1'];
        if ($filter_status !== 'all') {
            $fallbackWhere[] = "t.status = '" . $conn->real_escape_string($filter_status) . "'";
        }
        if ($start_date !== '') {
            $fallbackWhere[] = "DATE(t.date_borrowed) >= '" . $conn->real_escape_string($start_date) . "'";
        }
        if ($end_date !== '') {
            $fallbackWhere[] = "DATE(t.date_borrowed) <= '" . $conn->real_escape_string($end_date) . "'";
        }
        $fallbackSql = implode(' AND ', $fallbackWhere);
        $fallbackQuery = "SELECT
                t.transaction_id, t.date_borrowed, t.due_date, t.return_date, t.status,
                COALESCE(s.student_no, te.teacher_no, 'N/A') AS borrower_no,
                COALESCE(s.full_name, te.full_name, 'Unknown borrower') AS borrower_name,
                NULL AS copy_id,
                CONCAT('ID ', t.book_id) AS book_number,
                NULL AS copy_qr_code,
                CONCAT('Book #', t.book_id) AS book_title,
                '' AS book_author
              FROM transactions t
              LEFT JOIN students s ON t.student_id = s.student_id
              LEFT JOIN teachers te ON t.teacher_id = te.teacher_id
              WHERE $fallbackSql
              ORDER BY t.date_borrowed DESC";
        $fallbackResult = $conn->query($fallbackQuery);
        if ($fallbackResult) {
            while ($row = $fallbackResult->fetch_assoc()) {
                $transactions[] = $row;
            }
        }
    } catch (Throwable $fallbackError) {
        logError('Transactions fallback query error: ' . $fallbackError->getMessage());
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    if (empty($transactions)) {
        if ($transaction_query_error) {
            echo '<div class="empty">Unable to load transaction records. Please check the database connection/schema.</div>';
        } else {
            echo '<div class="empty">No transaction records found.</div>';
        }
    } else {
        echo '<div class="table-wrapper"><table><thead><tr><th>ID</th><th>Borrower</th><th>Book / Physical Copy</th><th>Borrowed</th><th>Return By</th><th>Returned</th><th>Status</th></tr></thead><tbody>';
        foreach ($transactions as $t) {
            echo '<tr>';
            echo '<td>#'.(int)$t['transaction_id'].'</td>';
            echo '<td><strong>'.h($t['borrower_name']).'</strong><br><small>'.h($t['borrower_no']).'</small></td>';
            echo '<td><strong>'.h($t['book_title']).'</strong><br><small>'.h($t['book_author']).' · '.h($t['book_number']).($t['copy_qr_code'] ? ' · '.h($t['copy_qr_code']) : '').'</small></td>';
            echo '<td>'.h(date('M d, Y h:i A', strtotime($t['date_borrowed']))).'</td>';
            echo '<td>'.h(date('M d, Y', strtotime($t['due_date']))).'<br><small>Same-day return</small></td>';
            echo '<td>'.($t['return_date'] ? h(date('M d, Y h:i A', strtotime($t['return_date']))) : '—').'</td>';
            echo '<td><span class="badge '.h($t['status']).'">'.h(ucfirst($t['status'])).'</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    exit;
}

$status_counts = ['all' => 0, 'borrowed' => 0, 'returned' => 0];
try {
    $count_result = $conn->query("SELECT status, COUNT(*) AS count FROM transactions GROUP BY status");
    if ($count_result) {
        while ($row = $count_result->fetch_assoc()) {
            if (isset($status_counts[$row['status']])) {
                $status_counts[$row['status']] = (int)$row['count'];
            }
        }
    }
    $status_counts['all'] = $status_counts['borrowed'] + $status_counts['returned'];
} catch (Exception $e) {
    logError('Transaction count error: ' . $e->getMessage());
}

if (isset($_GET['report']) && $_GET['report'] === '1'):
    $report_date = date('F d, Y h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Report - Jose Abad Santos High School</title>
    <style>
<?php echo printReportStyles(); ?>
    .auto-search-submit,.auto-filter-submit{display:none !important;}
    .content-container,.container{margin-top:0 !important;}
</style>
</head>
<body>
<main class="report">
    <?php echo printReportToolbar(); ?>
    <?php echo printFrameOpen(); ?>
    <?php
    if ($start_date !== '' && $end_date !== '') $txPeriod = $start_date . ' to ' . $end_date;
    elseif ($start_date !== '') $txPeriod = 'From ' . $start_date;
    elseif ($end_date !== '') $txPeriod = 'Up to ' . $end_date;
    else $txPeriod = 'All dates';
    if ($filter_status !== 'all') $txPeriod .= ' · Status: ' . ucfirst($filter_status);
    echo printReportHeader('Library Transaction Report', $txPeriod);
    ?>
    <div class="summary">
        <div class="summary-card"><strong><?php echo count($transactions); ?></strong><span>Records in this report</span></div>
        <div class="summary-card"><strong><?php echo $status_counts['borrowed']; ?></strong><span>Currently Borrowed</span></div>
        <div class="summary-card"><strong><?php echo $status_counts['returned']; ?></strong><span>Returned</span></div>
    </div>
    <?php
    $txBorrowed = 0; $txReturned = 0; $txByDay = []; $txByBook = [];
    foreach ($transactions as $t) {
        if ($t['status'] === 'returned') $txReturned++; else $txBorrowed++;
        $d = date('M d', strtotime($t['date_borrowed']));
        $txByDay[$d] = ($txByDay[$d] ?? 0) + 1;
        $txByBook[$t['book_title']] = ($txByBook[$t['book_title']] ?? 0) + 1;
    }
    $txByDay = array_slice(array_reverse($txByDay, true), -14, null, true);
    arsort($txByBook);
    $txByBook = array_slice($txByBook, 0, 10, true);
    echo renderBarChart('Transactions by Status (records in this report)', ['Borrowed', 'Returned'], [
        ['name' => 'Records', 'color' => '#141F52', 'values' => [$txBorrowed, $txReturned]],
    ]);
    echo renderLineChart('Borrowing Activity per Day (latest 14 days in report)', array_keys($txByDay), array_values($txByDay), '#141F52', 'Borrows');
    echo renderHorizontalBarChart('Most Borrowed Books in this Report', array_keys($txByBook), array_values($txByBook), '#52618D');
    ?>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>Borrower</th><th>Book / Physical Copy</th><th>Borrowed</th><th>Return By</th><th>Returned</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($transactions)): ?>
            <tr><td colspan="7" style="text-align:center;">No transaction records found.</td></tr>
        <?php else: foreach ($transactions as $t): ?>
            <tr>
                <td>#<?php echo (int)$t['transaction_id']; ?></td>
                <td><?php echo h($t['borrower_name']); ?><br><small><?php echo h($t['borrower_no']); ?></small></td>
                <td><?php echo h($t['book_title']); ?><br><small><?php echo h($t['book_number']); ?><?php echo !empty($t['copy_qr_code']) ? ' · ' . h($t['copy_qr_code']) : ''; ?></small></td>
                <td><?php echo h(date('M d, Y h:i A', strtotime($t['date_borrowed']))); ?></td>
                <td><?php echo h(date('M d, Y', strtotime($t['due_date']))); ?> (same day)</td>
                <td><?php echo $t['return_date'] ? h(date('M d, Y h:i A', strtotime($t['return_date']))) : '—'; ?></td>
                <td><?php echo h(ucfirst($t['status'])); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

<?php echo printFrameClose(); ?>
</main>
<script id="transactionAutoFilterEnhancement">
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.toolbar form');
    if (!form) return;
    const search = form.querySelector('input[name="search"]');
    let timer = null;
    if (search) {
        search.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () {
                form.submit();
            }, 350);
        });
    }
    const statusCards = document.querySelectorAll('.stats-grid .stat-card[href]');
    statusCards.forEach(function (card) {
        // Status cards remain click navigation by design.
    });
});
</script>

</body>
</html>
<?php exit(); endif; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - Library Borrowing System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #F3F7FC; color: #202A44; }
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .page-header { background: white; padding: 26px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,.08); margin-bottom: 22px; }
        .page-header h1 { color: #141F52; margin-bottom: 7px; font-size: 28px; }
        .page-header p { color: #52618D; font-size: 14px; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 22px; }
        .stat-card { background: white; padding: 20px; border-radius: 10px;  box-shadow: 0 2px 8px rgba(0,0,0,.06); text-decoration: none; color: inherit; }
        .stat-card.active { box-shadow: 0 0 0 2px #000000 inset; }
        .stat-label { color: #52618D; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .stat-value { color: #141F52; font-size: 30px; font-weight: 800; margin-top: 6px; }
        .toolbar { background: white; padding: 18px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.06); margin-bottom: 22px; display: flex; gap: 10px; flex-wrap: wrap; }
        .toolbar form { display: flex; gap: 10px; flex: 1; min-width: 260px; }
        .toolbar input { flex: 1; padding: 11px 13px; border: 1px solid #D2E2F6; border-radius: 7px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 11px 16px; border: 0; border-radius: 7px; background: #141F52; color: white; text-decoration: none; font-weight: 700; cursor: pointer; }
        .btn.secondary { background: #E7EEF7; color: #202A44; }
        .table-card { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.06); overflow: hidden; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #141F52; color: white; padding: 13px; text-align: left; font-size: 12px; }
        td { padding: 13px; border-bottom: 1px solid #E7EEF7; font-size: 13px; vertical-align: top; }
        tbody tr:hover { background: #F7F9FC; }
        .badge { display: inline-block; padding: 5px 10px; border-radius: 999px; font-size: 11px; font-weight: 800; text-transform: uppercase; }
        .badge.borrowed { background: #FBFDCB; color: #5C5F05; }
        .badge.returned { background: #EDF5DD; color: #344E15; }
        .empty { padding: 38px; text-align: center; color: #52618D; }
        @media (max-width: 700px) { .stats-grid { grid-template-columns: 1fr; } .toolbar form { width: 100%; } }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>
    <main class="container">
        <section class="page-header">
            <h1>Transactions</h1>
            <p>Borrowing and return history. All borrowed books are intended for same-day return.</p>
        </section>

        <div class="stats-grid">
            <a class="stat-card <?php echo $filter_status === 'all' ? 'active' : ''; ?>" href="?status=all">
                <div class="stat-label">All Transactions</div><div class="stat-value"><?php echo $status_counts['all']; ?></div>
            </a>
            <a class="stat-card <?php echo $filter_status === 'borrowed' ? 'active' : ''; ?>" href="?status=borrowed">
                <div class="stat-label">Currently Borrowed</div><div class="stat-value"><?php echo $status_counts['borrowed']; ?></div>
            </a>
            <a class="stat-card <?php echo $filter_status === 'returned' ? 'active' : ''; ?>" href="?status=returned">
                <div class="stat-label">Returned</div><div class="stat-value"><?php echo $status_counts['returned']; ?></div>
            </a>
        </div>

        <div class="toolbar">
            <form method="GET">
                <input type="hidden" name="status" value="<?php echo h($filter_status); ?>">
                <input type="date" name="start_date" value="<?php echo h($start_date); ?>" title="From date">
                <input type="date" name="end_date" value="<?php echo h($end_date); ?>" title="To date">
                <input type="text" name="search" value="<?php echo h($search); ?>" placeholder="Search borrower, book, book number, or transaction ID">
                <button class="btn auto-search-submit" type="submit">Search</button>
                <?php if ($search !== ''): ?><a class="btn secondary" href="?status=<?php echo h($filter_status); ?>">Clear</a><?php endif; ?>
            </form>
            <a class="btn secondary" href="?status=<?php echo h($filter_status); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&search=<?php echo urlencode($search); ?>&report=1" target="_blank">Print Report</a>
        </div>

        <section class="table-card">
            <?php if (empty($transactions)): ?>
                <div class="empty"><?php echo $transaction_query_error ? 'Unable to load transaction records. Please check the database connection/schema.' : 'No transaction records found.'; ?></div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Borrower</th><th>Book</th><th>Borrowed</th><th>Return By</th><th>Returned</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($transactions as $t): ?>
                            <tr>
                                <td>#<?php echo (int)$t['transaction_id']; ?></td>
                                <td><strong><?php echo h($t['borrower_name']); ?></strong><br><small><?php echo h($t['borrower_no']); ?></small></td>
                                <td><strong><?php echo h($t['book_title']); ?></strong><br><small><?php echo h($t['book_author']); ?> · <?php echo h($t['book_number']); ?></small></td>
                                <td><?php echo h(date('M d, Y h:i A', strtotime($t['date_borrowed']))); ?></td>
                                <td><?php echo h(date('M d, Y', strtotime($t['due_date']))); ?><br><small>Same-day return</small></td>
                                <td><?php echo $t['return_date'] ? h(date('M d, Y h:i A', strtotime($t['return_date']))) : '—'; ?></td>
                                <td><span class="badge <?php echo h($t['status']); ?>"><?php echo h(ucfirst($t['status'])); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.toolbar form');
    const tableCard = document.querySelector('.table-card');
    if (!form || !tableCard) return;

    const search = form.querySelector('input[name="search"]');
    const inputs = form.querySelectorAll('input');
    let timer;

    function updateTable() {
        const params = new URLSearchParams(new FormData(form));
        params.set('ajax','1');
        fetch('transactions.php?' + params.toString(), {headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(r => r.text())
            .then(html => { tableCard.innerHTML = html; });
    }

    if (search) {
        search.addEventListener('input', function(){
            clearTimeout(timer);
            timer=setTimeout(updateTable, 250);
        });
    }

    form.querySelectorAll('input[type=date]').forEach(function(input){
        input.addEventListener('change', updateTable);
    });
});
</script>
</body>
</html>
