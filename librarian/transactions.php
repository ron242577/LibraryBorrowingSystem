<?php
/**
 * Transactions & Overdue Books - Librarian Panel
 * View all borrowing/return transactions, overdue tracking, penalty calculations, and report generation
 * Penalty: 5 PHP per day
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

// Check if user is librarian
if (!isLibrarian() && !isSuperAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$transactions  = [];
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_query  = isset($_GET['search']) ? $_GET['search'] : '';

// ── Generate Report (PDF/Print) ─────────────────────────────────────────────
$generate_report = isset($_GET['report']) && $_GET['report'] === '1';

try {
    $query = "SELECT 
                t.transaction_id,
                t.date_borrowed,
                t.due_date,
                t.return_date,
                t.penalty_amount,
                t.status,
                s.student_id,
                s.full_name   AS student_name,
                s.contact_number,
                b.book_id,
                b.title       AS book_title,
                b.author,
                CASE
                    WHEN t.status = 'borrowed' AND t.due_date < CURDATE()
                    THEN DATEDIFF(CURDATE(), t.due_date)
                    ELSE 0
                END AS days_overdue
              FROM transactions t
              JOIN students s ON t.student_id = s.student_id
              JOIN books b    ON t.book_id    = b.book_id
              WHERE 1=1";

    if ($filter_status !== 'all') {
        $query .= " AND t.status = '" . $conn->real_escape_string($filter_status) . "'";
    }

    // "overdue" is a virtual status: borrowed + past due_date
    if ($filter_status === 'overdue') {
        $query = str_replace("AND t.status = 'overdue'",
                             "AND t.status = 'borrowed' AND t.due_date < CURDATE()",
                             $query);
    }

    if (!empty($search_query)) {
        $search = $conn->real_escape_string($search_query);
        $query .= " AND (s.full_name LIKE '%$search%'
                      OR b.title    LIKE '%$search%'
                      OR b.author   LIKE '%$search%')";
    }

    $query .= " ORDER BY t.date_borrowed DESC";

    $result = $conn->query($query);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Always compute live penalty for overdue rows
            $days_late = max(0, (int)$row['days_overdue']);
            $row['calculated_penalty'] = $days_late * 5;
            $transactions[] = $row;
        }
    }
} catch (Exception $e) {
    logError('Error fetching transactions: ' . $e->getMessage());
}

// ── Status counts (sidebar cards) ───────────────────────────────────────────
$status_counts = ['all' => 0, 'borrowed' => 0, 'returned' => 0, 'overdue' => 0];
try {
    // Regular statuses
    $count_result = $conn->query("SELECT status, COUNT(*) AS count FROM transactions GROUP BY status");
    if ($count_result) {
        while ($row = $count_result->fetch_assoc()) {
            if (isset($status_counts[$row['status']])) {
                $status_counts[$row['status']] = (int)$row['count'];
            }
        }
    }
    // Real overdue count (borrowed + past due)
    $overdue_res = $conn->query("SELECT COUNT(*) AS count FROM transactions WHERE status = 'borrowed' AND due_date < CURDATE()");
    if ($overdue_res) {
        $status_counts['overdue'] = (int)$overdue_res->fetch_assoc()['count'];
    }
    $status_counts['all'] = $status_counts['borrowed'] + $status_counts['returned'];
} catch (Exception $e) {
    logError('Error counting transactions: ' . $e->getMessage());
}

// ── Summary totals ───────────────────────────────────────────────────────────
$total_penalty = 0;
$total_overdue = $status_counts['overdue'];
foreach ($transactions as $t) {
    $total_penalty += $t['calculated_penalty'];
}

// ── If report mode: render printable page then exit ─────────────────────────
if ($generate_report) {
    $report_date  = date('F d, Y');
    $report_label = $filter_status === 'all' ? 'All Transactions' : ucfirst($filter_status) . ' Transactions';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report – <?php echo $report_label; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #222; padding: 30px; }
        .report-header { text-align: center; margin-bottom: 24px; border-bottom: 2px solid #003366; padding-bottom: 16px; }
        .report-header h1 { font-size: 20px; color: #003366; }
        .report-header p  { color: #555; font-size: 12px; margin-top: 4px; }
        .summary-row { display: flex; gap: 20px; margin-bottom: 20px; }
        .summary-box { flex: 1; border: 1px solid #ddd; border-radius: 6px; padding: 12px; text-align: center; }
        .summary-box .val { font-size: 22px; font-weight: 700; }
        .summary-box .lbl { font-size: 11px; color: #777; text-transform: uppercase; margin-top: 4px; }
        .overdue-val { color: #e74c3c; }
        .penalty-val { color: #f39c12; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        thead { background: #003366; color: white; }
        th { padding: 8px 10px; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; }
        td { padding: 7px 10px; border-bottom: 1px solid #eee; font-size: 11px; }
        tbody tr:nth-child(even) { background: #f9f9f9; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .badge-borrowed  { background: #fff3cd; color: #856404; }
        .badge-returned  { background: #d4edda; color: #155724; }
        .badge-overdue   { background: #f8d7da; color: #721c24; }
        .penalty-col { color: #e67e22; font-weight: 700; }
        .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #aaa; }
        @media print {
            .no-print { display: none !important; }
        }
        .print-btn {
            display: block;
            margin: 0 auto 20px;
            padding: 10px 28px;
            background: #003366;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <button class="print-btn no-print" onclick="window.print()">🖨️ Print / Save as PDF</button>

    <div class="report-header">
        <h1>Library Borrowing System — <?php echo $report_label; ?></h1>
        <p>Generated on <?php echo $report_date; ?> &nbsp;|&nbsp; Penalty rate: ₱5.00 / day</p>
        <?php if (!empty($search_query)): ?>
            <p>Search filter: "<strong><?php echo htmlspecialchars($search_query); ?></strong>"</p>
        <?php endif; ?>
    </div>

    <div class="summary-row">
        <div class="summary-box">
            <div class="val"><?php echo count($transactions); ?></div>
            <div class="lbl">Records Shown</div>
        </div>
        <div class="summary-box">
            <div class="val overdue-val"><?php echo $total_overdue; ?></div>
            <div class="lbl">Total Overdue</div>
        </div>
        <div class="summary-box">
            <div class="val penalty-val">₱<?php echo number_format($total_penalty, 2); ?></div>
            <div class="lbl">Total Penalties</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Student</th>
                <th>Contact</th>
                <th>Book</th>
                <th>Borrowed</th>
                <th>Due Date</th>
                <th>Returned</th>
                <th>Status</th>
                <th>Days Late</th>
                <th>Penalty</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($transactions as $t): ?>
                <?php
                    $badge = $t['status'];
                    if ($t['status'] === 'borrowed' && $t['days_overdue'] > 0) $badge = 'overdue';
                ?>
                <tr>
                    <td style="color:#003366;font-family:monospace;font-weight:700;">
                        #<?php echo str_pad($t['transaction_id'], 4, '0', STR_PAD_LEFT); ?>
                    </td>
                    <td><?php echo htmlspecialchars($t['student_name']); ?></td>
                    <td><?php echo htmlspecialchars($t['contact_number'] ?? '—'); ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars(substr($t['book_title'], 0, 30)); ?></strong><br>
                        <span style="color:#777;"><?php echo htmlspecialchars(substr($t['author'], 0, 25)); ?></span>
                    </td>
                    <td><?php echo date('M d, Y', strtotime($t['date_borrowed'])); ?></td>
                    <td><?php echo date('M d, Y', strtotime($t['due_date'])); ?></td>
                    <td>
                        <?php echo $t['return_date']
                            ? date('M d, Y', strtotime($t['return_date']))
                            : '—'; ?>
                    </td>
                    <td><span class="badge badge-<?php echo $badge; ?>"><?php echo ucfirst($badge); ?></span></td>
                    <td><?php echo $t['days_overdue'] > 0 ? $t['days_overdue'] . ' days' : '—'; ?></td>
                    <td class="penalty-col">
                        <?php echo $t['calculated_penalty'] > 0
                            ? '₱' . number_format($t['calculated_penalty'], 2)
                            : '—'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer">
        Library Borrowing System &mdash; Confidential &mdash; For internal use only
    </div>
</body>
</html>
    <?php
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions – Library Borrowing System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #2c3e50;
            padding-bottom: 40px;
        }

        a { text-decoration: none; color: inherit; }

        /* ── Layout ─────────────────────────────────── */
        .container {
            max-width: 1400px;
            margin: 100px auto 30px;
            padding: 0 20px;
        }

        /* ── Page header ─────────────────────────────── */
        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 28px;
            color: #2c3e50;
            margin-bottom: 6px;
        }

        .page-header p { color: #7f8c8d; font-size: 14px; }

        /* ── Generate Report button ──────────────────── */
        .btn-report {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 22px;
            background: #27ae60;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.25s, transform 0.2s;
            white-space: nowrap;
        }

        .btn-report:hover { background: #219a52; transform: translateY(-2px); }

        /* ── Stat cards ──────────────────────────────── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            border-left: 5px solid #003366;
            cursor: pointer;
            transition: all .3s;
            display: block;
        }

        .stat-card:hover             { transform: translateY(-3px); box-shadow: 0 4px 12px rgba(0,0,0,.12); }
        .stat-card.active            { background: #eef2ff; }
        .stat-card-all               { border-left-color: #003366; }
        .stat-card-borrowed          { border-left-color: #f39c12; }
        .stat-card-returned          { border-left-color: #27ae60; }
        .stat-card-overdue           { border-left-color: #e74c3c; }

        .stat-label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: .5px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .stat-value { font-size: 32px; font-weight: 700; color: #2c3e50; }
        .stat-card-borrowed .stat-value { color: #f39c12; }
        .stat-card-returned .stat-value { color: #27ae60; }
        .stat-card-overdue  .stat-value { color: #e74c3c; }

        /* ── Overdue + Penalty summary ───────────────── */
        .overdue-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 22px 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            border-left: 5px solid;
            transition: transform .3s;
        }

        .summary-card:hover          { transform: translateY(-3px); }
        .summary-card-overdue        { border-left-color: #e74c3c; }
        .summary-card-penalty        { border-left-color: #f39c12; }

        .summary-label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: .5px;
            margin-bottom: 8px;
        }

        .summary-value { font-size: 30px; font-weight: 700; }
        .summary-card-overdue .summary-value { color: #e74c3c; }
        .summary-card-penalty .summary-value { color: #f39c12; }
        .summary-subtext { font-size: 11px; color: #95a5a6; margin-top: 6px; }

        /* ── Filter section ──────────────────────────── */
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            margin-bottom: 30px;
        }

        .filter-section h3 {
            font-size: 15px;
            color: #2c3e50;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 2px solid #f0f0f0;
        }

        .filter-controls {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-box {
            flex: 1;
            min-width: 240px;
            display: flex;
            gap: 10px;
        }

        .search-box input {
            flex: 1;
            padding: 11px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all .3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #003366;
            box-shadow: 0 0 8px rgba(0,51,102,.15);
        }

        .btn {
            padding: 11px 22px;
            background: #003366;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background .25s;
            white-space: nowrap;
        }

        .btn:hover      { background: #00254d; }
        .btn-reset      { background: #e0e0e0; color: #2c3e50; }
        .btn-reset:hover{ background: #c8c8c8; }

        /* ── Table section ───────────────────────────── */
        .table-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }

        .table-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 2px solid #f0f0f0;
            flex-wrap: wrap;
            gap: 10px;
        }

        .table-top h2 { font-size: 18px; color: #2c3e50; }
        .result-count { font-size: 13px; color: #7f8c8d; font-weight: 500; }

        .table-wrapper { overflow-x: auto; }

        table { width: 100%; border-collapse: collapse; }

        thead { background: #f8f9fa; border-bottom: 2px solid #e0e0e0; }

        th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #555;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        tbody tr:hover { background: #f8f9fa; }

        .student-cell    { font-weight: 600; color: #2c3e50; }
        .student-contact { font-size: 11px; color: #7f8c8d; }

        .book-cell  { max-width: 180px; }
        .book-title { font-weight: 600; color: #2c3e50; margin-bottom: 3px; }
        .book-author{ font-size: 11px; color: #7f8c8d; }

        .status-badge {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-borrowed { background: #fff3cd; color: #856404; }
        .status-returned { background: #d4edda; color: #155724; }
        .status-overdue  { background: #f8d7da; color: #721c24; }

        .days-overdue {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 6px;
            background: #ffe6e6;
            color: #e74c3c;
            font-size: 12px;
            font-weight: 700;
        }

        .penalty-amount { font-weight: 700; color: #f39c12; font-size: 13px; }

        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state-text { color: #95a5a6; font-size: 14px; }

        /* ── Responsive ──────────────────────────────── */
        @media (max-width: 768px) {
            .page-header h1    { font-size: 22px; }
            .stats-grid        { grid-template-columns: repeat(2, 1fr); }
            .filter-controls   { flex-direction: column; }
            .search-box        { flex-direction: column; }
            th, td             { padding: 9px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>

    <div class="container">

        <!-- ── Page Header ───────────────────────────── -->
        <div class="page-header">
            <div>
                <h1>Transactions &amp; Overdue Books</h1>
                <p>Complete borrowing history, overdue tracking, and penalty calculations (₱5 / day)</p>
            </div>
            <!-- Generate Report button -->
            <?php
                $report_url = '?report=1&status=' . urlencode($filter_status);
                if (!empty($search_query)) $report_url .= '&search=' . urlencode($search_query);
            ?>
            <a href="<?php echo $report_url; ?>" target="_blank" class="btn-report">
                📄 Generate Report
            </a>
        </div>

        <!-- ── Status Filter Cards ───────────────────── -->
        <div class="stats-grid">
            <a href="?status=all" class="stat-card stat-card-all <?php echo $filter_status==='all' ? 'active' : ''; ?>">
                <div class="stat-label">All Transactions</div>
                <div class="stat-value"><?php echo $status_counts['all']; ?></div>
            </a>
            <a href="?status=borrowed" class="stat-card stat-card-borrowed <?php echo $filter_status==='borrowed' ? 'active' : ''; ?>">
                <div class="stat-label">Borrowed</div>
                <div class="stat-value"><?php echo $status_counts['borrowed']; ?></div>
            </a>
            <a href="?status=returned" class="stat-card stat-card-returned <?php echo $filter_status==='returned' ? 'active' : ''; ?>">
                <div class="stat-label">Returned</div>
                <div class="stat-value"><?php echo $status_counts['returned']; ?></div>
            </a>
            <a href="?status=overdue" class="stat-card stat-card-overdue <?php echo $filter_status==='overdue' ? 'active' : ''; ?>">
                <div class="stat-label">Overdue</div>
                <div class="stat-value"><?php echo $status_counts['overdue']; ?></div>
            </a>
        </div>

        <!-- ── Overdue + Penalty Summary ─────────────── -->
        <div class="overdue-summary">
            <div class="summary-card summary-card-overdue">
                <div class="summary-label">Total Overdue Books</div>
                <div class="summary-value"><?php echo $total_overdue; ?></div>
                <div class="summary-subtext">Books past their due date</div>
            </div>
            <div class="summary-card summary-card-penalty">
                <div class="summary-label">
                    <?php echo $filter_status === 'all' ? 'Accumulated Penalties (Overdue)' : 'Penalties in View'; ?>
                </div>
                <div class="summary-value">₱<?php echo number_format($total_penalty, 2); ?></div>
                <div class="summary-subtext">At ₱5.00 per overdue day</div>
            </div>
        </div>

        <!-- ── Search & Filter ───────────────────────── -->
        <div class="filter-section">
            <h3>Search</h3>
            <form method="GET" class="filter-controls">
                <div class="search-box">
                    <input type="text"
                           name="search"
                           placeholder="Search by student name, book title, or author..." autofocus
                           value="<?php echo htmlspecialchars($search_query); ?>">
                    <button type="submit" class="btn">Search</button>
                </div>
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                <a href="/LibraryBorrowingSystem/librarian/transactions.php" class="btn btn-reset">Reset</a>
            </form>
        </div>

        <!-- ── Transactions Table ────────────────────── -->
        <div class="table-section">
            <div class="table-top">
                <h2>Transaction Records</h2>
                <span class="result-count">
                    Showing <?php echo count($transactions); ?> record(s)
                </span>
            </div>

            <?php if (count($transactions) > 0): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>TXN ID</th>
                                <th>Student</th>
                                <th>Book</th>
                                <th>Borrowed</th>
                                <th>Due Date</th>
                                <th>Returned</th>
                                <th>Status</th>
                                <th>Days Late</th>
                                <th>Penalty</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                                <?php
                                    // Determine display badge
                                    $badge = $t['status'];
                                    if ($t['status'] === 'borrowed' && $t['days_overdue'] > 0) {
                                        $badge = 'overdue';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <small style="color:#003366;font-family:monospace;font-weight:700;">
                                            #<?php echo str_pad($t['transaction_id'], 4, '0', STR_PAD_LEFT); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="student-cell"><?php echo htmlspecialchars($t['student_name']); ?></div>
                                        <div class="student-contact"><?php echo htmlspecialchars($t['contact_number'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td class="book-cell">
                                        <div class="book-title"><?php echo htmlspecialchars(substr($t['book_title'], 0, 25)); ?></div>
                                        <div class="book-author"><?php echo htmlspecialchars(substr($t['author'], 0, 30)); ?></div>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($t['date_borrowed'])); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($t['due_date'])); ?></td>
                                    <td>
                                        <?php echo $t['return_date']
                                            ? date('M d, Y', strtotime($t['return_date']))
                                            : '<span style="color:#95a5a6;">—</span>'; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $badge; ?>">
                                            <?php echo ucfirst($badge); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($t['days_overdue'] > 0): ?>
                                            <span class="days-overdue"><?php echo $t['days_overdue']; ?> days</span>
                                        <?php else: ?>
                                            <span style="color:#95a5a6;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($t['calculated_penalty'] > 0): ?>
                                            <span class="penalty-amount">₱<?php echo number_format($t['calculated_penalty'], 2); ?></span>
                                        <?php else: ?>
                                            <span style="color:#95a5a6;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-text">
                        <?php if (!empty($search_query) || $filter_status !== 'all'): ?>
                            No transactions found matching your criteria.
                        <?php else: ?>
                            No transactions recorded yet.
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div><!-- /.container -->
</body>
</html>