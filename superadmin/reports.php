<?php
/**
 * Reports Dashboard - Library Borrowing System
 * SuperAdmin Only - Comprehensive reporting and statistics
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/reports/reports_aggregator.php';

// Check if user is super admin
if (!isSuperAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

// Get filter parameters
$report_type = $_GET['report'] ?? 'dashboard';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Initialize data arrays
$borrowing_trends = [];
$overdue_data = [];
$system_metrics = [];
$inventory_status = [];

try {
    $aggregator = new ReportsAggregator($conn);
    
    if ($report_type === 'dashboard' || $report_type === 'borrowing_trends') {
        $borrowing_trends = $aggregator->getBorrowingTrends($start_date, $end_date);
    }
    if ($report_type === 'dashboard' || $report_type === 'overdue_books') {
        $overdue_data = $aggregator->getOverdueData($start_date, $end_date);
    }
    if ($report_type === 'dashboard' || $report_type === 'system_metrics') {
        $system_metrics = $aggregator->getSystemMetrics($start_date, $end_date);
        $inventory_status = $aggregator->getInventoryStatus();
    }
} catch (Exception $e) {
    logError('Error generating reports: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Library System</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #F3F7FC;
            min-height: 100vh;
            color: #202A44;
        }

        /* Adjust main content for sidebar */
        body {
            margin-left: 260px;
        }

        @media (max-width: 992px) {
            body {
                margin-left: 0;
            }
        }

        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .breadcrumb {
            margin-bottom: 20px;
            font-size: 14px;
            color: #666;
        }

        .breadcrumb a {
            color: #52618D;
            text-decoration: none;
            font-weight: 600;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        .breadcrumb span {
            color: #999;
            margin: 0 8px;
        }

        /* Page Header */
        .page-header {
            background: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .page-header h1 {
            color: #141F52;
            margin-bottom: 10px;
            font-size: 28px;
            font-weight: 600;
        }

        .page-header p {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        .filter-section h2 {
            color: #202A44;
            margin-bottom: 20px;
            font-size: 16px;
            font-weight: 600;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 600;
            color: #202A44;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 1px solid #D2E2F6;
            border-radius: 5px;
            font-size: 14px;
            background: white;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: all 0.3s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #141F52;
            box-shadow: 0 0 0 3px rgba(244, 249, 22, 0.35);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary {
            background: #141F52;
            color: white;
        }

        .btn-primary:hover {
            background: #52618D;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(20, 31, 82, 0.3);
        }

        .btn-secondary {
            background: white;
            color: #141F52;
            border: 1px solid #D2E2F6;
        }

        .btn-secondary:hover {
            background: #F7F9FC;
            border-color: #141F52;
        }

        .btn-success {
            background: #567D1F;
            color: white;
        }

        .btn-success:hover {
            background: #3F5F16;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-warning {
            background: #F4F916;
            color: #202A44;
        }

        .btn-warning:hover {
            background: #C7CB0F;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
        }

        /* Report Tabs */
        .report-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .report-tabs a {
            padding: 12px 24px;
            background: white;
            color: #141F52;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .report-tabs a:hover,
        .report-tabs a.active {
            background: #141F52;
            color: white;
            border-color: #F4F916;
        }

        /* Metrics Cards */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .metric-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            border-left: 4px solid #141F52;
            transition: all 0.3s;
        }

        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.12);
        }

        .metric-card h3 {
            color: #666;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .metric-card .value {
            font-size: 32px;
            font-weight: bold;
            color: #141F52;
        }

        .metric-card.warning {
            border-left-color: #F4F916;
        }

        .metric-card.warning .value {
            color: #F4F916;
        }

        .metric-card.danger {
            border-left-color: #dc3545;
        }

        .metric-card.danger .value {
            color: #dc3545;
        }

        .metric-card.success {
            border-left-color: #567D1F;
        }

        .metric-card.success .value {
            color: #567D1F;
        }

        /* Chart Containers */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-container {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            position: relative;
        }

        .chart-container h3 {
            color: #202A44;
            margin-bottom: 20px;
            font-size: 16px;
            font-weight: 600;
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
        }

        /* Table Styles */
        .table-container {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            overflow-x: auto;
        }

        .table-container h3 {
            color: #202A44;
            margin-bottom: 20px;
            font-size: 16px;
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table thead {
            background: #F7F9FC;
        }

        table th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #202A44;
            border-bottom: 1px solid #D2E2F6;
            font-size: 13px;
        }

        table td {
            padding: 12px 15px;
            border-bottom: 1px solid #D2E2F6;
            font-size: 14px;
        }

        table tbody tr {
            transition: background-color 0.2s;
        }

        table tbody tr:last-child td {
            border-bottom: none;
        }

        table tbody tr:hover {
            background: #F7F9FC;
        }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .badge-success {
            background: #EDF5DD;
            color: #344E15;
        }

        .badge-warning {
            background: #FBFDCB;
            color: #5C5F05;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }

        /* No Data Message */
        .no-data {
            background: white;
            padding: 40px;
            text-align: center;
            border-radius: 8px;
            color: #999;
            font-style: italic;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        /* Export Section */
        .export-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #D2E2F6;
        }

        .export-section h3 {
            color: #202A44;
            margin-bottom: 15px;
            font-size: 14px;
            font-weight: 600;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Responsive */
        @media (max-width: 992px) {
            body {
                margin-left: 0;
            }

            .container {
                margin: 20px auto;
            }

            .page-header {
                margin-bottom: 20px;
                padding: 20px;
            }

            .page-header h1 {
                font-size: 24px;
            }
        }

        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .metric-card .value {
                font-size: 28px;
            }

            .page-header h1 {
                font-size: 24px;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 12px;
            }

            table th, table td {
                padding: 10px 8px;
            }

            .metrics-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }

            .metric-card .value {
                font-size: 24px;
            }
        }

        @media (max-width: 600px) {
            body {
                margin-left: 0;
            }

            .container {
                margin: 15px auto;
                padding: 0 10px;
            }

            .page-header {
                padding: 20px 15px;
                margin-bottom: 15px;
            }

            .page-header h1 {
                font-size: 22px;
            }

            .filter-grid {
                gap: 10px;
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .charts-grid {
                gap: 15px;
            }

            .export-buttons {
                flex-direction: column;
            }

            .export-buttons a {
                width: 100%;
            }

            table {
                font-size: 11px;
            }

            table th, table td {
                padding: 8px 5px;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>

    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="../dashboard.php">🏠 Dashboard</a>
            <span>></span>
            <span>📊 Reports & Analytics</span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <h1>Reports & Analytics</h1>
            <p>Comprehensive insights into borrowing patterns, overdue tracking, and system usage</p>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <h2>Filter Options</h2>
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label for="report">Report Type</label>
                        <select name="report" id="report">
                            <option value="dashboard" <?php echo $report_type === 'dashboard' ? 'selected' : ''; ?>>Full Dashboard</option>
                            <option value="borrowing_trends" <?php echo $report_type === 'borrowing_trends' ? 'selected' : ''; ?>>Borrowing Trends</option>
                            <option value="overdue_books" <?php echo $report_type === 'overdue_books' ? 'selected' : ''; ?>>Overdue Books</option>
                            <option value="system_metrics" <?php echo $report_type === 'system_metrics' ? 'selected' : ''; ?>>System Metrics</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="filter-group">
                        <label for="end_date">End Date</label>
                        <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="?report=dashboard" class="btn btn-secondary">↺ Reset</a>
                </div>
            </form>
        </div>

        <!-- Report Content -->
        <?php if ($report_type === 'dashboard' || $report_type === 'borrowing_trends'): ?>

        <!-- BORROWING TRENDS SECTION -->
        <div class="metrics-grid">
            <div class="metric-card success">
                <h3>Total Borrows</h3>
                <div class="value"><?php echo !empty($borrowing_trends) ? $borrowing_trends['total_borrows'] : 0; ?></div>
            </div>
            <div class="metric-card success">
                <h3>Total Returns</h3>
                <div class="value"><?php echo !empty($borrowing_trends) ? $borrowing_trends['total_returns'] : 0; ?></div>
            </div>
            <div class="metric-card">
                <h3>Avg Return Rate</h3>
                <div class="value">
                    <?php 
                    if (!empty($borrowing_trends) && $borrowing_trends['total_borrows'] > 0) {
                        echo round(($borrowing_trends['total_returns'] / $borrowing_trends['total_borrows']) * 100) . '%';
                    } else {
                        echo '0%';
                    }
                    ?>
                </div>
            </div>
        </div>

        <!-- Most Borrowed Books Table -->
        <?php if (!empty($borrowing_trends['most_borrowed_books'])): ?>
        <div class="table-container">
            <h3>Top 10 Most Borrowed Books</h3>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Title</th>
                        <th>Author</th>
                        <th>Total Borrows</th>
                        <th>Total Returns</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 1; foreach (array_slice($borrowing_trends['most_borrowed_books'], 0, 10) as $book): ?>
                    <tr>
                        <td><strong><?php echo $rank++; ?></strong></td>
                        <td><?php echo htmlspecialchars($book['title']); ?></td>
                        <td><?php echo htmlspecialchars($book['author']); ?></td>
                        <td><?php echo $book['borrow_count']; ?></td>
                        <td><?php echo $book['return_count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Charts for Borrowing Trends -->
        <?php if (!empty($borrowing_trends['borrowing_by_month'])): ?>
        <div class="charts-grid">
            <div class="chart-container">
                <h3>Borrowing by Month</h3>
                <div class="chart-wrapper">
                    <canvas id="borrowingByMonthChart"></canvas>
                </div>
            </div>

            <?php if (!empty($borrowing_trends['borrowing_by_day'])): ?>
            <div class="chart-container">
                <h3>Borrowing by Day of Week</h3>
                <div class="chart-wrapper">
                    <canvas id="borrowingByDayChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Top Borrowers by Student -->
        <?php if (!empty($borrowing_trends['borrowing_by_student'])): ?>
        <div class="table-container">
            <h3>Top Student Borrowers</h3>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Student Name</th>
                        <th>Total Borrows</th>
                        <th>Overdue Items</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 1; foreach ($borrowing_trends['borrowing_by_student'] as $student): ?>
                    <tr>
                        <td><strong><?php echo $rank++; ?></strong></td>
                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                        <td><?php echo $student['borrow_count']; ?></td>
                        <td>
                            <?php 
                            if ($student['overdue_count'] > 0) {
                                echo '<span class="badge badge-warning">' . $student['overdue_count'] . '</span>';
                            } else {
                                echo '<span class="badge badge-success">0</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <!-- OVERDUE BOOKS SECTION -->
        <?php if ($report_type === 'dashboard' || $report_type === 'overdue_books'): ?>

        <div class="metrics-grid">
            <div class="metric-card danger">
                <h3>Total Overdue Items</h3>
                <div class="value"><?php echo !empty($overdue_data) ? $overdue_data['total_overdue_count'] : 0; ?></div>
            </div>
            <div class="metric-card warning">
                <h3>Total Penalty Amount</h3>
                <div class="value">$<?php echo !empty($overdue_data) ? number_format($overdue_data['total_penalty_amount'], 0) : 0; ?></div>
            </div>
        </div>

        <!-- Current Overdue Items -->
        <?php if (!empty($overdue_data['overdues_list'])): ?>
        <div class="table-container">
            <h3>Current Overdue Items (Latest 15)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Book Title</th>
                        <th>Due Date</th>
                        <th>Days Overdue</th>
                        <th>Penalty</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($overdue_data['overdues_list'], 0, 15) as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['student_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['book_title']); ?></td>
                        <td><?php echo date('M d, Y', strtotime($item['due_date'])); ?></td>
                        <td>
                            <?php 
                            $days = $item['days_overdue'];
                            $badgeClass = $days > 30 ? 'badge-danger' : 'badge-warning';
                            echo '<span class="badge ' . $badgeClass . '">' . $days . ' days</span>';
                            ?>
                        </td>
                        <td>$<?php echo number_format($item['penalty_amount'], 2); ?></td>
                        <td><span class="badge badge-danger"><?php echo ucfirst($item['status']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <?php endif; ?>

        <!-- Overdue by User -->
        <?php if (!empty($overdue_data['overdue_by_user'])): ?>
        <div class="table-container">
            <h3>Overdue Summary by Student</h3>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Student Name</th>
                        <th>Overdue Count</th>
                        <th>Total Days Overdue</th>
                        <th>Total Penalty</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 1; foreach ($overdue_data['overdue_by_user'] as $user): ?>
                    <tr>
                        <td><strong><?php echo $rank++; ?></strong></td>
                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td><span class="badge badge-danger"><?php echo $user['overdue_count']; ?></span></td>
                        <td><?php echo $user['total_days_overdue'] ?? 0; ?></td>
                        <td>$<?php echo number_format($user['total_penalty'] ?? 0, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Repeated Offenders -->
        <?php if (!empty($overdue_data['repeated_offenders'])): ?>
        <div class="table-container">
            <h3>Repeated Offenders (3+ Late Returns)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Student Name</th>
                        <th>Total Transactions</th>
                        <th>Late Returns</th>
                        <th>Late Return %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $rank = 1; foreach ($overdue_data['repeated_offenders'] as $offender): ?>
                    <tr>
                        <td><strong><?php echo $rank++; ?></strong></td>
                        <td><?php echo htmlspecialchars($offender['full_name']); ?></td>
                        <td><?php echo $offender['total_transactions']; ?></td>
                        <td><span class="badge badge-danger"><?php echo $offender['late_returns']; ?></span></td>
                        <td><?php echo $offender['late_percent']; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php endif; ?>

        <!-- SYSTEM METRICS SECTION -->
        <?php if ($report_type === 'dashboard' || $report_type === 'system_metrics'): ?>

        <div class="metrics-grid">
            <div class="metric-card success">
                <h3>Active Students</h3>
                <div class="value"><?php echo $system_metrics['active_students'] ?? 0; ?></div>
            </div>
            <div class="metric-card">
                <h3>Total Students</h3>
                <div class="value"><?php echo $system_metrics['total_students'] ?? 0; ?></div>
            </div>
            <div class="metric-card success">
                <h3>Active Staff</h3>
                <div class="value"><?php echo $system_metrics['active_users'] ?? 0; ?></div>
            </div>
            <div class="metric-card">
                <h3>Total Books</h3>
                <div class="value"><?php echo $system_metrics['total_books'] ?? 0; ?></div>
            </div>
            <div class="metric-card success">
                <h3>Available</h3>
                <div class="value"><?php echo $system_metrics['available_books'] ?? 0; ?></div>
            </div>
            <div class="metric-card warning">
                <h3>Borrowed</h3>
                <div class="value"><?php echo $system_metrics['borrowed_books'] ?? 0; ?></div>
            </div>
        </div>

        <!-- Inventory Status Chart -->
        <?php if (!empty($inventory_status)): ?>
        <div class="charts-grid">
            <div class="chart-container">
                <h3>Inventory Status Distribution</h3>
                <div class="chart-wrapper">
                    <canvas id="inventoryStatusChart"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Inventory Table -->
        <?php if (!empty($inventory_status)): ?>
        <div class="table-container">
            <h3>Detailed Inventory Status</h3>
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Number of Books</th>
                        <th>Total Copies</th>
                        <th>Available</th>
                        <th>Borrowed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventory_status as $item): ?>
                    <tr>
                        <td><strong><?php echo ucfirst(str_replace('_', ' ', $item['book_status'])); ?></strong></td>
                        <td><?php echo $item['count']; ?></td>
                        <td><?php echo $item['total_copies'] ?? 0; ?></td>
                        <td><?php echo $item['available_copies'] ?? 0; ?></td>
                        <td><?php echo $item['borrowed_copies'] ?? 0; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- Chart.js Scripts -->
    <script>
    // Set Chart.js defaults
    Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";

    // Borrowing by Month Chart
    <?php if (!empty($borrowing_trends['borrowing_by_month'])): ?>
    const borrowingByMonthCtx = document.getElementById('borrowingByMonthChart');
    if (borrowingByMonthCtx) {
        new Chart(borrowingByMonthCtx, {
            type: 'line',
            data: {
                labels: [<?php echo implode(',', array_map(function($m) { return "'" . $m['month'] . "'"; }, $borrowing_trends['borrowing_by_month'])); ?>],
                datasets: [{
                    label: 'Number of Borrows',
                    data: [<?php echo implode(',', array_map(function($m) { return $m['count']; }, $borrowing_trends['borrowing_by_month'])); ?>],
                    borderColor: '#52618D',
                    backgroundColor: 'rgba(20, 31, 82, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointRadius: 5,
                    pointBackgroundColor: '#52618D'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        labels: { usePointStyle: true }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#D2E2F6' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    // Borrowing by Day Chart
    <?php if (!empty($borrowing_trends['borrowing_by_day'])): ?>
    const borrowingByDayCtx = document.getElementById('borrowingByDayChart');
    if (borrowingByDayCtx) {
        new Chart(borrowingByDayCtx, {
            type: 'bar',
            data: {
                labels: [<?php echo implode(',', array_map(function($d) { return "'" . $d['day_name'] . "'"; }, $borrowing_trends['borrowing_by_day'])); ?>],
                datasets: [{
                    label: 'Number of Borrows',
                    data: [<?php echo implode(',', array_map(function($d) { return $d['count']; }, $borrowing_trends['borrowing_by_day'])); ?>],
                    backgroundColor: 'rgba(20, 31, 82, 0.7)',
                    borderColor: '#52618D',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#D2E2F6' }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    // Inventory Status Chart
    <?php if (!empty($inventory_status)): ?>
    const inventoryStatusCtx = document.getElementById('inventoryStatusChart');
    if (inventoryStatusCtx) {
        new Chart(inventoryStatusCtx, {
            type: 'doughnut',
            data: {
                labels: [<?php echo implode(',', array_map(function($i) { return "'" . ucfirst(str_replace('_', ' ', $i['book_status'])) . "'"; }, $inventory_status)); ?>],
                datasets: [{
                    data: [<?php echo implode(',', array_map(function($i) { return $i['total_copies'] ?? 0; }, $inventory_status)); ?>],
                    backgroundColor: [
                        '#567D1F',
                        '#F4F916',
                        '#dc3545',
                        '#6c757d'
                    ],
                    borderColor: 'white',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true }
                    }
                }
            }
        });
    }
    <?php endif; ?>
    </script>
</body>
</html>
<?php
// Log report view
logError('Report generated: ' . $report_type . ' from ' . $start_date . ' to ' . $end_date);
?>
