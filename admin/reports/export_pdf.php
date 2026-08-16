<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/reports_aggregator.php';
require_once __DIR__ . '/../../session_check.php';

if (!isAdmin()) {
    header('HTTP/1.0 403 Forbidden');
    exit('Access Denied');
}

function h($value) { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }

$type = $_GET['type'] ?? 'borrowing_trends';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
if (!in_array($type, ['borrowing_trends', 'system_metrics'], true)) $type = 'borrowing_trends';

$aggregator = new ReportsAggregator($conn);
$data = $type === 'borrowing_trends'
    ? $aggregator->getBorrowingTrends($start_date, $end_date)
    : $aggregator->getSystemMetrics($start_date, $end_date);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Library Report - Jose Abad Santos High School</title>
<style>
    * { box-sizing:border-box; }
    body { font-family:Arial,sans-serif; background:#f4f6fa; color:#202A44; padding:22px; }
    .actions { text-align:right; margin-bottom:15px; }
    button { background:#141F52; color:white; border:0; border-radius:6px; padding:10px 15px; cursor:pointer; }
    .sheet { max-width:900px; margin:auto; background:white; padding:34px; box-shadow:0 2px 14px rgba(0,0,0,.1); }
    .head { text-align:center; border-bottom:3px solid #141F52; padding-bottom:18px; margin-bottom:22px; }
    .head h1 { color:#141F52; font-size:25px; margin:0 0 7px; }
    .head p { margin:4px 0; color:#52618D; font-size:13px; }
    .summary { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:24px; }
    .card { border:1px solid #D2E2F6; border-left:4px solid #141F52; border-radius:8px; padding:14px; }
    .card strong { display:block; font-size:25px; color:#141F52; }
    h2 { color:#141F52; font-size:18px; margin:24px 0 10px; }
    table { width:100%; border-collapse:collapse; font-size:12px; }
    th,td { border:1px solid #D2E2F6; padding:9px; text-align:left; }
    th { background:#141F52; color:white; }
    @media print { body { background:white; padding:0; } .actions { display:none; } .sheet { box-shadow:none; max-width:none; padding:0; } }
</style>
</head>
<body>
<div class="actions"><button onclick="window.print()">Print / Save PDF</button></div>
<div class="sheet">
    <div class="head">
        <h1>Jose Abad Santos High School Library Report</h1>
        <p><?php echo h(ucwords(str_replace('_',' ',$type))); ?></p>
        <p><?php echo h($start_date); ?> to <?php echo h($end_date); ?> · Same-day borrowing and return</p>
    </div>

    <?php if ($type === 'borrowing_trends'): ?>
        <div class="summary">
            <div class="card"><strong><?php echo (int)$data['total_borrows']; ?></strong>Total Borrows</div>
            <div class="card"><strong><?php echo (int)$data['total_returns']; ?></strong>Total Returns</div>
            <div class="card"><strong><?php echo $data['total_borrows'] > 0 ? round(($data['total_returns']/$data['total_borrows'])*100).'%' : '0%'; ?></strong>Return Rate</div>
        </div>
        <h2>Most Borrowed Books</h2>
        <table><thead><tr><th>Title</th><th>Author</th><th>Borrows</th><th>Returns</th></tr></thead><tbody>
        <?php foreach ($data['most_borrowed_books'] as $book): ?><tr><td><?php echo h($book['title']); ?></td><td><?php echo h($book['author']); ?></td><td><?php echo (int)$book['borrow_count']; ?></td><td><?php echo (int)$book['return_count']; ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <h2>Top Student Borrowers</h2>
        <table><thead><tr><th>Student</th><th>Borrows</th><th>Returns</th></tr></thead><tbody>
        <?php foreach ($data['borrowing_by_student'] as $student): ?><tr><td><?php echo h($student['full_name']); ?></td><td><?php echo (int)$student['borrow_count']; ?></td><td><?php echo (int)$student['return_count']; ?></td></tr><?php endforeach; ?>
        </tbody></table>
    <?php else: ?>
        <div class="summary">
            <div class="card"><strong><?php echo (int)$data['active_students']; ?></strong>Active Students</div>
            <div class="card"><strong><?php echo (int)$data['total_books']; ?></strong>Total Book Copies</div>
            <div class="card"><strong><?php echo (int)$data['active_transactions']; ?></strong>Currently Borrowed</div>
        </div>
        <h2>System Metrics</h2>
        <table><thead><tr><th>Metric</th><th>Value</th></tr></thead><tbody>
            <tr><td>Total Students</td><td><?php echo (int)$data['total_students']; ?></td></tr>
            <tr><td>Active Staff</td><td><?php echo (int)$data['active_users']; ?></td></tr>
            <tr><td>Total Staff</td><td><?php echo (int)$data['total_users']; ?></td></tr>
            <tr><td>Total Book Copies</td><td><?php echo (int)$data['total_books']; ?></td></tr>
            <tr><td>Available Copies</td><td><?php echo (int)$data['available_books']; ?></td></tr>
            <tr><td>Borrowed Copies</td><td><?php echo (int)$data['borrowed_books']; ?></td></tr>
            <tr><td>Active Borrow Transactions</td><td><?php echo (int)$data['active_transactions']; ?></td></tr>
            <tr><td>Borrows in Period</td><td><?php echo (int)$data['most_used_features']['total_borrows']; ?></td></tr>
            <tr><td>Returns in Period</td><td><?php echo (int)$data['most_used_features']['total_returns']; ?></td></tr>
        </tbody></table>
    <?php endif; ?>
</div>
</body>
</html>
