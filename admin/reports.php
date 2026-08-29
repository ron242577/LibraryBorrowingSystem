<?php
/**
 * Reports & Analytics - Jose Abad Santos High School Library Borrowing System
 * Same-day borrowing and return reporting.
 */
require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/reports/reports_aggregator.php';

if (!isAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$report_type = $_GET['report'] ?? 'dashboard';
$allowed_types = ['dashboard', 'borrowing_trends', 'system_metrics'];
if (!in_array($report_type, $allowed_types, true)) $report_type = 'dashboard';

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) $start_date = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) $end_date = date('Y-m-d');
if ($start_date > $end_date) [$start_date, $end_date] = [$end_date, $start_date];

$borrowing_trends = [];
$system_metrics = [];
$inventory_status = [];
try {
    $aggregator = new ReportsAggregator($conn);
    if ($report_type === 'dashboard' || $report_type === 'borrowing_trends') {
        $borrowing_trends = $aggregator->getBorrowingTrends($start_date, $end_date);
    }
    if ($report_type === 'dashboard' || $report_type === 'system_metrics') {
        $system_metrics = $aggregator->getSystemMetrics($start_date, $end_date);
        $inventory_status = $aggregator->getInventoryStatus();
    }
} catch (Exception $e) {
    logError('Report generation error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Library Borrowing System</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:#F3F7FC; color:#202A44; overflow-x:hidden; }
        .container { max-width:1200px; margin:30px auto; padding:0 20px; }
        .page-header,.filter-section,.table-card,.chart-card { background:white; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,.07); }
        .page-header { padding:28px; margin-bottom:22px; }
        .page-header h1 { color:#141F52; font-size:28px; margin-bottom:8px; }
        .page-header p { color:#52618D; font-size:14px; }
        .filter-section { padding:20px; margin-bottom:22px; }
        .filter-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
        .filter-group label { display:block; font-size:12px; font-weight:700; color:#52618D; margin-bottom:7px; }
        .filter-group input,.filter-group select { width:100%; padding:10px 12px; border:1px solid #D2E2F6; border-radius:7px; }
        .filter-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }
        .btn { display:inline-flex; align-items:center; justify-content:center; padding:10px 16px; border:0; border-radius:7px; background:#141F52; color:white; text-decoration:none; font-weight:700; cursor:pointer; }
        .btn.secondary { background:#E7EEF7; color:#202A44; }
        .tabs { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:22px; }
        .tabs a { padding:10px 16px; border-radius:7px; background:white; color:#141F52; text-decoration:none; font-weight:700; border:1px solid #D2E2F6; }
        .tabs a.active { background:#141F52; color:white; border-color:#141F52; }
        .metrics-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:22px; }
        .metric-card { background:white; padding:20px; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.06); border-left:4px solid #141F52; }
        .metric-card .label { font-size:12px; color:#52618D; font-weight:700; text-transform:uppercase; }
        .metric-card .value { font-size:30px; color:#141F52; font-weight:800; margin-top:6px; }
        .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:22px; }
        .chart-card,.table-card { padding:20px; margin-bottom:22px; }
        .chart-card h3,.table-card h3 { color:#141F52; font-size:17px; margin-bottom:16px; }
        .chart-wrap { height:300px; }
        .table-wrapper { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        th { background:#141F52; color:white; padding:11px; text-align:left; font-size:12px; }
        td { padding:11px; border-bottom:1px solid #E7EEF7; font-size:13px; }
        tbody tr:hover { background:#F7F9FC; }
        .empty { text-align:center; padding:30px; color:#52618D; }
        @media (max-width:800px) { .filter-grid,.grid-2 { grid-template-columns:1fr; } }
    .auto-search-submit,.auto-filter-submit{display:none !important;}
    
        .content-container,
        .container{margin-top:0 !important;}

</style>
</head>
<body>
<?php include __DIR__ . '/../navbar.php'; ?>
<?php include __DIR__ . '/../header.php'; ?>
<main class="container">
    <section class="page-header">
        <h1>Reports & Analytics</h1>
        <p>Borrowing, return, inventory, and system usage reports for the same-day library process.</p>
    </section>

    <section class="filter-section">
        <form method="GET">
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Report Type</label>
                    <select name="report">
                        <option value="dashboard" <?php echo $report_type==='dashboard'?'selected':''; ?>>Full Dashboard</option>
                        <option value="borrowing_trends" <?php echo $report_type==='borrowing_trends'?'selected':''; ?>>Borrowing Trends</option>
                        <option value="system_metrics" <?php echo $report_type==='system_metrics'?'selected':''; ?>>System Metrics</option>
                    </select>
                </div>
                <div class="filter-group"><label>Start Date</label><input type="date" name="start_date" value="<?php echo h($start_date); ?>"></div>
                <div class="filter-group"><label>End Date</label><input type="date" name="end_date" value="<?php echo h($end_date); ?>"></div>
            </div>
            <div class="filter-actions">
                <button class="btn auto-filter-submit" type="submit">Apply Filters</button>
                <a class="btn secondary" href="?report=dashboard">Reset</a>
                <?php if ($report_type !== 'dashboard'): ?>
                    <a class="btn secondary" href="reports/export_csv.php?type=<?php echo h($report_type); ?>&start_date=<?php echo h($start_date); ?>&end_date=<?php echo h($end_date); ?>">Export CSV</a>
                    <a class="btn secondary" target="_blank" href="reports/export_pdf.php?type=<?php echo h($report_type); ?>&start_date=<?php echo h($start_date); ?>&end_date=<?php echo h($end_date); ?>">Print / Save PDF</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <nav class="tabs">
        <a class="<?php echo $report_type==='dashboard'?'active':''; ?>" href="?report=dashboard&start_date=<?php echo h($start_date); ?>&end_date=<?php echo h($end_date); ?>">Dashboard</a>
        <a class="<?php echo $report_type==='borrowing_trends'?'active':''; ?>" href="?report=borrowing_trends&start_date=<?php echo h($start_date); ?>&end_date=<?php echo h($end_date); ?>">Borrowing Trends</a>
        <a class="<?php echo $report_type==='system_metrics'?'active':''; ?>" href="?report=system_metrics&start_date=<?php echo h($start_date); ?>&end_date=<?php echo h($end_date); ?>">System Metrics</a>
    </nav>

    <?php if ($report_type === 'dashboard' || $report_type === 'borrowing_trends'): ?>
        <div class="metrics-grid">
            <div class="metric-card"><div class="label">Total Borrows</div><div class="value"><?php echo (int)($borrowing_trends['total_borrows'] ?? 0); ?></div></div>
            <div class="metric-card"><div class="label">Total Returns</div><div class="value"><?php echo (int)($borrowing_trends['total_returns'] ?? 0); ?></div></div>
            <div class="metric-card"><div class="label">Return Rate</div><div class="value"><?php $b=(int)($borrowing_trends['total_borrows']??0); $r=(int)($borrowing_trends['total_returns']??0); echo $b>0?round(($r/$b)*100).'%':'0%'; ?></div></div>
        </div>

        <div class="grid-2">
            <section class="chart-card"><h3>Borrowing by Month</h3><div class="chart-wrap"><canvas id="monthChart"></canvas></div></section>
            <section class="chart-card"><h3>Borrowing by Day</h3><div class="chart-wrap"><canvas id="dayChart"></canvas></div></section>
        </div>

        <section class="table-card">
            <h3>Most Borrowed Books</h3>
            <?php if (empty($borrowing_trends['most_borrowed_books'])): ?><div class="empty">No borrowing data for this period.</div><?php else: ?>
            <div class="table-wrapper"><table><thead><tr><th>Title</th><th>Author</th><th>Borrows</th><th>Returns</th></tr></thead><tbody>
            <?php foreach ($borrowing_trends['most_borrowed_books'] as $book): ?><tr><td><?php echo h($book['title']); ?></td><td><?php echo h($book['author']); ?></td><td><?php echo (int)$book['borrow_count']; ?></td><td><?php echo (int)$book['return_count']; ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>

        <section class="table-card">
            <h3>Top Student Borrowers</h3>
            <?php if (empty($borrowing_trends['borrowing_by_student'])): ?><div class="empty">No student borrowing data for this period.</div><?php else: ?>
            <div class="table-wrapper"><table><thead><tr><th>Student</th><th>Borrows</th><th>Returns</th></tr></thead><tbody>
            <?php foreach ($borrowing_trends['borrowing_by_student'] as $student): ?><tr><td><?php echo h($student['full_name']); ?></td><td><?php echo (int)$student['borrow_count']; ?></td><td><?php echo (int)$student['return_count']; ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($report_type === 'dashboard' || $report_type === 'system_metrics'): ?>
        <div class="metrics-grid">
            <div class="metric-card"><div class="label">Active Students</div><div class="value"><?php echo (int)($system_metrics['active_students'] ?? 0); ?></div></div>
            <div class="metric-card"><div class="label">Total Book Copies</div><div class="value"><?php echo (int)($system_metrics['total_books'] ?? 0); ?></div></div>
            <div class="metric-card"><div class="label">Available Copies</div><div class="value"><?php echo (int)($system_metrics['available_books'] ?? 0); ?></div></div>
            <div class="metric-card"><div class="label">Currently Borrowed</div><div class="value"><?php echo (int)($system_metrics['borrowed_books'] ?? 0); ?></div></div>
        </div>

        <section class="table-card">
            <h3>Inventory Status</h3>
            <?php if (empty($inventory_status)): ?><div class="empty">No inventory data available.</div><?php else: ?>
            <div class="table-wrapper"><table><thead><tr><th>Status</th><th>Titles</th><th>Total Copies</th><th>Available</th><th>Borrowed</th></tr></thead><tbody>
            <?php foreach ($inventory_status as $row): ?><tr><td><?php echo h(ucwords(str_replace('_',' ',$row['book_status']))); ?></td><td><?php echo (int)$row['count']; ?></td><td><?php echo (int)$row['total_copies']; ?></td><td><?php echo (int)$row['available_copies']; ?></td><td><?php echo (int)$row['borrowed_copies']; ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<script>
<?php if ($report_type === 'dashboard' || $report_type === 'borrowing_trends'): ?>
const monthLabels = <?php echo json_encode(array_column($borrowing_trends['borrowing_by_month'] ?? [], 'month')); ?>;
const monthData = <?php echo json_encode(array_map('intval', array_column($borrowing_trends['borrowing_by_month'] ?? [], 'count'))); ?>;
const dayLabels = <?php echo json_encode(array_column($borrowing_trends['borrowing_by_day'] ?? [], 'day_name')); ?>;
const dayData = <?php echo json_encode(array_map('intval', array_column($borrowing_trends['borrowing_by_day'] ?? [], 'count'))); ?>;
if (document.getElementById('monthChart')) new Chart(document.getElementById('monthChart'), { type:'line', data:{labels:monthLabels,datasets:[{label:'Borrows',data:monthData,borderColor:'#141F52',backgroundColor:'rgba(20,31,82,.12)',fill:true,tension:.25}]}, options:{responsive:true,maintainAspectRatio:false} });
if (document.getElementById('dayChart')) new Chart(document.getElementById('dayChart'), { type:'bar', data:{labels:dayLabels,datasets:[{label:'Borrows',data:dayData,backgroundColor:'#52618D'}]}, options:{responsive:true,maintainAspectRatio:false} });
<?php endif; ?>
</script>

<script id="reportsAutoFilterEnhancement">
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.filter-section form');
    if (!form) return;
    form.querySelectorAll('select, input[type="date"]').forEach(function (field) {
        field.addEventListener('change', function () {
            form.submit();
        });
    });
});
</script>

</body>
</html>
