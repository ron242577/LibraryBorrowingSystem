<?php
/**
 * Transactions - Jose Abad Santos High School Library Borrowing System
 * Same-day borrowing and return records.
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

if (!isAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$filter_status = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$allowed_statuses = ['all', 'borrowed', 'returned'];
if (!in_array($filter_status, $allowed_statuses, true)) {
    $filter_status = 'all';
}

$where = ['1=1'];
if ($filter_status !== 'all') {
    $where[] = "t.status = '" . $conn->real_escape_string($filter_status) . "'";
}
if ($search !== '') {
    $term = $conn->real_escape_string($search);
    $where[] = "(s.full_name LIKE '%$term%' OR s.student_no LIKE '%$term%' OR b.title LIKE '%$term%' OR b.author LIKE '%$term%' OR b.book_number LIKE '%$term%' OR CAST(t.transaction_id AS CHAR) LIKE '%$term%')";
}
$where_sql = implode(' AND ', $where);

$transactions = [];
try {
    $query = "SELECT
                t.transaction_id,
                t.date_borrowed,
                t.due_date,
                t.return_date,
                t.status,
                s.student_no,
                s.full_name AS student_name,
                b.book_number,
                b.title AS book_title,
                b.author AS book_author
              FROM transactions t
              INNER JOIN students s ON t.student_id = s.student_id
              INNER JOIN books b ON t.book_id = b.book_id
              WHERE $where_sql
              ORDER BY t.date_borrowed DESC";
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
    }
} catch (Exception $e) {
    logError('Transactions page error: ' . $e->getMessage());
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
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; color: #202A44; margin: 28px; }
        .report-header { text-align: center; border-bottom: 3px solid #141F52; padding-bottom: 18px; margin-bottom: 22px; }
        .report-header h1 { margin: 0 0 7px; color: #141F52; font-size: 25px; }
        .report-header p { margin: 4px 0; color: #52618D; font-size: 13px; }
        .summary { display: flex; gap: 14px; margin-bottom: 22px; }
        .summary-card { flex: 1; border: 1px solid #D2E2F6; border-left: 4px solid #141F52; padding: 14px; border-radius: 8px; }
        .summary-card strong { display: block; font-size: 24px; color: #141F52; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { padding: 9px; border: 1px solid #D2E2F6; text-align: left; }
        th { background: #141F52; color: white; }
        .print-actions { margin-bottom: 18px; text-align: right; }
        button { border: 0; background: #141F52; color: white; padding: 10px 16px; border-radius: 6px; cursor: pointer; }
        @media print { .print-actions { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
    <div class="print-actions"><button onclick="window.print()">Print / Save PDF</button></div>
    <div class="report-header">
        <h1>Jose Abad Santos High School Library Transaction Report</h1>
        <p>Same-day borrowing and return process</p>
        <p>Generated: <?php echo h($report_date); ?></p>
    </div>
    <div class="summary">
        <div class="summary-card"><strong><?php echo count($transactions); ?></strong>Records in this report</div>
        <div class="summary-card"><strong><?php echo $status_counts['borrowed']; ?></strong>Currently Borrowed</div>
        <div class="summary-card"><strong><?php echo $status_counts['returned']; ?></strong>Returned</div>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>Student</th><th>Book</th><th>Borrowed</th><th>Return By</th><th>Returned</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($transactions)): ?>
            <tr><td colspan="7" style="text-align:center;">No transaction records found.</td></tr>
        <?php else: foreach ($transactions as $t): ?>
            <tr>
                <td>#<?php echo (int)$t['transaction_id']; ?></td>
                <td><?php echo h($t['student_name']); ?><br><small><?php echo h($t['student_no']); ?></small></td>
                <td><?php echo h($t['book_title']); ?><br><small><?php echo h($t['book_number']); ?></small></td>
                <td><?php echo h(date('M d, Y h:i A', strtotime($t['date_borrowed']))); ?></td>
                <td><?php echo h(date('M d, Y', strtotime($t['due_date']))); ?> (same day)</td>
                <td><?php echo $t['return_date'] ? h(date('M d, Y h:i A', strtotime($t['return_date']))) : '—'; ?></td>
                <td><?php echo h(ucfirst($t['status'])); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
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
        .stat-card { background: white; padding: 20px; border-radius: 10px; border-left: 4px solid #141F52; box-shadow: 0 2px 8px rgba(0,0,0,.06); text-decoration: none; color: inherit; }
        .stat-card.active { box-shadow: 0 0 0 2px #F4F916 inset; }
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
                <input type="text" name="search" value="<?php echo h($search); ?>" placeholder="Search student, book, book number, or transaction ID">
                <button class="btn" type="submit">Search</button>
                <?php if ($search !== ''): ?><a class="btn secondary" href="?status=<?php echo h($filter_status); ?>">Clear</a><?php endif; ?>
            </form>
            <a class="btn secondary" href="?status=<?php echo h($filter_status); ?>&search=<?php echo urlencode($search); ?>&report=1" target="_blank">Print Report</a>
        </div>

        <section class="table-card">
            <?php if (empty($transactions)): ?>
                <div class="empty">No transaction records found.</div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr><th>ID</th><th>Student</th><th>Book</th><th>Borrowed</th><th>Return By</th><th>Returned</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($transactions as $t): ?>
                            <tr>
                                <td>#<?php echo (int)$t['transaction_id']; ?></td>
                                <td><strong><?php echo h($t['student_name']); ?></strong><br><small><?php echo h($t['student_no']); ?></small></td>
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
</body>
</html>
