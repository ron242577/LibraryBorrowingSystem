<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/reports_aggregator.php';
require_once __DIR__ . '/../../includes/print_charts.php';
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
<?php echo printReportStyles(); ?>
</style>
</head>
<body>
<main class="report">
<?php echo printReportToolbar(); ?>
<?php echo printFrameOpen(); ?>
<?php echo printReportHeader('Library ' . ($type === 'borrowing_trends' ? 'Borrowing Trends' : 'System Metrics') . ' Report', $start_date . ' to ' . $end_date); ?>

    <?php if ($type === 'borrowing_trends'): ?>
        <div class="summary">
            <div class="summary-card"><strong><?php echo (int)$data['total_borrows']; ?></strong><span>Total Borrows</span></div>
            <div class="summary-card"><strong><?php echo (int)$data['total_returns']; ?></strong><span>Total Returns</span></div>
            <div class="summary-card"><strong><?php echo $data['total_borrows'] > 0 ? round(($data['total_returns']/$data['total_borrows'])*100).'%' : '0%'; ?></strong><span>Return Rate</span></div>
        </div>
        <?php
        $data['borrowing_by_month'] = ReportsAggregator::fillMonths($data['borrowing_by_month'], $start_date, $end_date);
        $data['borrowing_by_day'] = ReportsAggregator::fillDays($data['borrowing_by_day']);
        $monthLabels = array_map(function ($r) { return date('M Y', strtotime($r['month'] . '-01')); }, $data['borrowing_by_month']);
        $monthValues = array_map(function ($r) { return (int)$r['count']; }, $data['borrowing_by_month']);
        $dayLabels = array_map(function ($r) { return $r['day_name']; }, $data['borrowing_by_day']);
        $dayValues = array_map(function ($r) { return (int)$r['count']; }, $data['borrowing_by_day']);
        $bookLabels = array_map(function ($r) { return $r['title']; }, $data['most_borrowed_books']);
        $bookValues = array_map(function ($r) { return (int)$r['borrow_count']; }, $data['most_borrowed_books']);
        $borrowerLabels = array_map(function ($r) { return ($r['borrower_name'] ?: 'Unknown borrower') . ' (' . $r['borrower_type'] . ')'; }, $data['borrowing_by_borrower']);
        $borrowerValues = array_map(function ($r) { return (int)$r['borrow_count']; }, $data['borrowing_by_borrower']);
        echo renderBarChart('Borrows vs Returns', ['Selected Period'], [
            ['name' => 'Borrows', 'color' => '#141F52', 'values' => [(int)$data['total_borrows']]],
            ['name' => 'Returns', 'color' => '#567D1F', 'values' => [(int)$data['total_returns']]],
        ]);
        echo renderBarChart('Borrowing by Month', $monthLabels, [['name' => 'Borrows', 'color' => '#141F52', 'values' => $monthValues]]);
        echo renderBarChart('Borrowing by Day of Week', $dayLabels, [['name' => 'Borrows', 'color' => '#52618D', 'values' => $dayValues]]);
        $bookCounts = array_filter($bookValues, function ($v) { return $v > 0; });
        echo renderHorizontalBarChart('Most Borrowed Books', array_slice($bookLabels, 0, count($bookCounts)), array_slice($bookValues, 0, count($bookCounts)), '#141F52');
        echo renderHorizontalBarChart('Top Borrowers', $borrowerLabels, $borrowerValues, '#52618D');
        ?>
        <h2>Most Borrowed Books</h2>
        <table><thead><tr><th>Title</th><th>Author</th><th>Borrows</th><th>Returns</th></tr></thead><tbody>
        <?php foreach ($data['most_borrowed_books'] as $book): ?><tr><td><?php echo h($book['title']); ?></td><td><?php echo h($book['author']); ?></td><td><?php echo (int)$book['borrow_count']; ?></td><td><?php echo (int)$book['return_count']; ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <h2>Top Borrowers</h2>
        <table><thead><tr><th>Borrower</th><th>Type</th><th>Borrows</th><th>Returns</th></tr></thead><tbody>
        <?php foreach ($data['borrowing_by_borrower'] as $borrower): ?><tr><td><?php echo h($borrower['borrower_name'] ?: 'Unknown borrower'); ?></td><td><?php echo h($borrower['borrower_type']); ?></td><td><?php echo (int)$borrower['borrow_count']; ?></td><td><?php echo (int)$borrower['return_count']; ?></td></tr><?php endforeach; ?>
        </tbody></table>
    <?php else: ?>
        <div class="summary">
            <div class="summary-card"><strong><?php echo (int)$data['active_students']; ?></strong><span>Active Students</span></div>
            <div class="summary-card"><strong><?php echo (int)$data['total_books']; ?></strong><span>Total Book Copies</span></div>
            <div class="summary-card"><strong><?php echo (int)$data['active_transactions']; ?></strong><span>Currently Borrowed</span></div>
        </div>
        <?php
        echo renderBarChart('Book Copies: Available vs Borrowed', ['Book Copies'], [
            ['name' => 'Total', 'color' => '#141F52', 'values' => [(int)$data['total_books']]],
            ['name' => 'Available', 'color' => '#567D1F', 'values' => [(int)$data['available_books']]],
            ['name' => 'Borrowed', 'color' => '#9B2335', 'values' => [(int)$data['borrowed_books']]],
        ]);
        echo renderBarChart('Users Overview', ['Students', 'Active Students', 'Staff', 'Active Staff'], [
            ['name' => 'Count', 'color' => '#52618D', 'values' => [(int)$data['total_students'], (int)$data['active_students'], (int)$data['total_users'], (int)$data['active_users']]],
        ]);
        echo renderBarChart('Activity in Selected Period', ['Borrows', 'Returns', 'Currently Borrowed'], [
            ['name' => 'Count', 'color' => '#141F52', 'values' => [(int)$data['most_used_features']['total_borrows'], (int)$data['most_used_features']['total_returns'], (int)$data['active_transactions']]],
        ]);
        $inv = $aggregator->getInventoryStatus();
        echo renderBarChart('Inventory by Book Status (copies)', array_map(function ($r) { return ucfirst((string)$r['book_status']); }, $inv), [
            ['name' => 'Total', 'color' => '#141F52', 'values' => array_map(function ($r) { return (int)$r['total_copies']; }, $inv)],
            ['name' => 'Available', 'color' => '#567D1F', 'values' => array_map(function ($r) { return (int)$r['available_copies']; }, $inv)],
            ['name' => 'Borrowed', 'color' => '#9B2335', 'values' => array_map(function ($r) { return (int)$r['borrowed_copies']; }, $inv)],
        ]);
        ?>
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
<?php echo printFrameClose(); ?>
</main>
</body>
</html>
