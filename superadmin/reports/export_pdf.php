<?php
/**
 * PDF Export Handler - Reports Module
 * Uses HTML rendering approach or library integration
 */

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/reports_aggregator.php';

// Verify super admin
require_once __DIR__ . '/../../session_check.php';
if (!isSuperAdmin()) {
    header('HTTP/1.0 403 Forbidden');
    exit('Access Denied');
}

// Get parameters
$report_type = $_GET['type'] ?? 'borrowing_trends';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'html'; // html or pdf

header('Content-Type: text/html; charset=utf-8');

$aggregator = new ReportsAggregator($conn);

// Generate PDF-ready HTML
$html = generateReportHTML($report_type, $aggregator, $start_date, $end_date);

if ($format === 'html') {
    // Output as HTML for browser printing
    echo $html;
} else if ($format === 'pdf') {
    // Try to use TCPDF or similar library if available
    try {
        generatePDFReport($html, $report_type);
    } catch (Exception $e) {
        echo "PDF generation requires additional libraries. Please use HTML export and print to PDF from your browser.";
    }
}

function generateReportHTML($report_type, $aggregator, $start_date, $end_date) {
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Library Report - ' . $report_type . '</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
            padding: 20px;
        }
        .pdf-container {
            background: white;
            max-width: 900px;
            margin: 0 auto;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #667eea;
            padding-bottom: 20px;
        }
        .header h1 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 28px;
            margin-bottom: 10px;
        }
        .header p {
            color: #666;
            font-size: 14px;
        }
        .report-info {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .info-box {
            background: #f9f8fb;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.08);
        }
        .info-box label {
            font-weight: 600;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: block;
            font-size: 12px;
            margin-bottom: 5px;
        }
        .info-box value {
            font-size: 16px;
            color: #333;
        }
        .section {
            margin-bottom: 40px;
            page-break-inside: avoid;
        }
        .section-title {
            font-size: 22px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 20px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
        }
        .summary-card .label {
            font-size: 12px;
            opacity: 0.9;
            text-transform: uppercase;
        }
        .summary-card .value {
            font-size: 32px;
            font-weight: bold;
            margin-top: 10px;
        }
        .summary-card.warning {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            box-shadow: 0 4px 15px rgba(255, 152, 0, 0.2);
        }
        .summary-card.danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.2);
        }
        .summary-card.success {
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.2);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: white;
            border-bottom: 2px solid #667eea;
        }
        table td {
            padding: 10px 12px;
            border-bottom: 1px solid #e0e0e0;
        }
        table tbody tr:hover {
            background: #f9f8fb;
        }
        table tbody tr:nth-child(even) {
            background: #fafbfc;
        }
        .chart-container {
            margin: 20px 0;
            padding: 20px;
            background: #f9f8fb;
            border-radius: 8px;
            border: 1px solid #e0d5f0;
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.08);
        }
        .no-data {
            text-align: center;
            padding: 30px;
            color: #999;
            font-style: italic;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #667eea;
            text-align: center;
            font-size: 12px;
            color: #666;
        }
        .export-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(102, 126, 234, 0.15);
            z-index: 1000;
        }
        .export-controls button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 8px;
            cursor: pointer;
            margin: 5px;
            font-size: 14px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .export-controls button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .pdf-container {
                max-width: 100%;
                box-shadow: none;
                padding: 0;
            }
            .export-controls {
                display: none;
            }
        }
        .highlight-danger {
            color: #dc3545;
            font-weight: 600;
        }
        .highlight-warning {
            color: #ffc107;
            font-weight: 600;
        }
        .highlight-success {
            color: #28a745;
            font-weight: 600;
        }
    </style>
</head>
<body>';

    // Export controls
    $html .= '
    <div class="export-controls">
        <button onclick="window.print()">🖨️ Print / Save PDF</button>
        <button onclick="exportAsCSV()">📥 Export CSV</button>
        <button onclick="window.history.back()">← Back</button>
    </div>';

    // Main container
    $html .= '<div class="pdf-container">';
    
    // Header
    $html .= '
    <div class="header">
        <h1>📊 Library Report: ' . ucfirst(str_replace('_', ' ', $report_type)) . '</h1>
        <p>Generated: ' . date('Y-m-d H:i:s') . '</p>
        <p>Period: ' . $start_date . ' to ' . $end_date . '</p>
    </div>';

    // Report content based on type
    switch ($report_type) {
        case 'borrowing_trends':
            $html .= generateBorrowingTrendsReport($aggregator, $start_date, $end_date);
            break;
        case 'overdue_books':
            $html .= generateOverdueReport($aggregator, $start_date, $end_date);
            break;
        case 'system_metrics':
            $html .= generateSystemMetricsReport($aggregator, $start_date, $end_date);
            break;
    }

    // Footer
    $html .= '
    <div class="footer">
        <p>Library Borrowing System © ' . date('Y') . ' | Confidential Report</p>
        <p>Generated for: ' . $_SESSION['full_name'] . '</p>
    </div>';

    $html .= '</div>'; // end pdf-container

    // JavaScript for CSV export
    $html .= '
    <script>
    function exportAsCSV() {
        const type = "' . $report_type . '";
        const startDate = "' . $start_date . '";
        const endDate = "' . $end_date . '";
        window.location.href = "export_csv.php?type=" + type + "&start_date=" + startDate + "&end_date=" + endDate;
    }
    </script>
    </body>
</html>';

    return $html;
}

function generateBorrowingTrendsReport($aggregator, $start_date, $end_date) {
    $data = $aggregator->getBorrowingTrends($start_date, $end_date);
    $html = '';

    // Summary Cards
    $html .= '
    <div class="section">
        <h2 class="section-title">📈 Summary</h2>
        <div class="summary-grid">
            <div class="summary-card">
                <div class="label">Total Borrows</div>
                <div class="value">' . $data['total_borrows'] . '</div>
            </div>
            <div class="summary-card success">
                <div class="label">Total Returns</div>
                <div class="value">' . $data['total_returns'] . '</div>
            </div>
            <div class="summary-card warning">
                <div class="label">Avg Return Rate</div>
                <div class="value">' . ($data['total_borrows'] > 0 ? 
                    round(($data['total_returns'] / $data['total_borrows']) * 100) . '%' : '0%') . '</div>
            </div>
        </div>
    </div>';

    // Most Borrowed Books
    $html .= '
    <div class="section">
        <h2 class="section-title">📚 Top 15 Most Borrowed Books</h2>';
    
    if (!empty($data['most_borrowed_books'])) {
        $html .= '<table>
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Title</th>
                    <th>Author</th>
                    <th>Borrows</th>
                    <th>Returns</th>
                </tr>
            </thead>
            <tbody>';
        
        $rank = 1;
        foreach ($data['most_borrowed_books'] as $book) {
            $html .= '<tr>
                <td><strong>' . $rank++ . '</strong></td>
                <td>' . htmlspecialchars($book['title']) . '</td>
                <td>' . htmlspecialchars($book['author']) . '</td>
                <td>' . $book['borrow_count'] . '</td>
                <td>' . $book['return_count'] . '</td>
            </tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<div class="no-data">No borrowing data available for this period.</div>';
    }
    $html .= '</div>';

    // Borrowing by Month
    if (!empty($data['borrowing_by_month'])) {
        $html .= '
        <div class="section">
            <h2 class="section-title">📅 Borrowing by Month</h2>
            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Number of Borrows</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($data['borrowing_by_month'] as $month) {
            $html .= '<tr>
                <td>' . htmlspecialchars($month['month']) . '</td>
                <td>' . $month['count'] . '</td>
            </tr>';
        }
        $html .= '</tbody></table></div>';
    }

    // Borrowing by Day of Week
    if (!empty($data['borrowing_by_day'])) {
        $html .= '
        <div class="section">
            <h2 class="section-title">🗓️ Borrowing Pattern by Day of Week</h2>
            <table>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Number of Borrows</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($data['borrowing_by_day'] as $day) {
            $html .= '<tr>
                <td>' . htmlspecialchars($day['day_name']) . '</td>
                <td>' . $day['count'] . '</td>
            </tr>';
        }
        $html .= '</tbody></table></div>';
    }

    // Top Borrowers
    if (!empty($data['borrowing_by_student'])) {
        $html .= '
        <div class="section">
            <h2 class="section-title">👥 Top Student Borrowers</h2>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Student Name</th>
                        <th>Total Borrows</th>
                        <th>Overdue Count</th>
                    </tr>
                </thead>
                <tbody>';
        
        $rank = 1;
        foreach ($data['borrowing_by_student'] as $student) {
            $html .= '<tr>
                <td><strong>' . $rank++ . '</strong></td>
                <td>' . htmlspecialchars($student['full_name']) . '</td>
                <td>' . $student['borrow_count'] . '</td>
                <td>' . ($student['overdue_count'] > 0 ? '<span class="highlight-danger">' . $student['overdue_count'] . '</span>' : $student['overdue_count']) . '</td>
            </tr>';
        }
        $html .= '</tbody></table></div>';
    }

    return $html;
}

function generateOverdueReport($aggregator, $start_date, $end_date) {
    $data = $aggregator->getOverdueData($start_date, $end_date);
    $html = '';

    // Summary Cards
    $html .= '
    <div class="section">
        <h2 class="section-title">⚠️ Summary</h2>
        <div class="summary-grid">
            <div class="summary-card danger">
                <div class="label">Total Overdue Items</div>
                <div class="value">' . $data['total_overdue_count'] . '</div>
            </div>
            <div class="summary-card warning">
                <div class="label">Total Penalty Amount</div>
                <div class="value">$' . number_format($data['total_penalty_amount'], 2) . '</div>
            </div>
        </div>
    </div>';

    // Current Overdue Items
    $html .= '
    <div class="section">
        <h2 class="section-title">📋 Current Overdue Items</h2>';
    
    if (!empty($data['overdues_list'])) {
        $html .= '<table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Book Title</th>
                    <th>Due Date</th>
                    <th>Days Overdue</th>
                    <th>Penalty</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($data['overdues_list'] as $item) {
            $htmlcolor = $item['days_overdue'] > 30 ? 'highlight-danger' : 'highlight-warning';
            $html .= '<tr>
                <td>' . htmlspecialchars($item['student_name']) . '</td>
                <td>' . htmlspecialchars($item['book_title']) . '</td>
                <td>' . $item['due_date'] . '</td>
                <td><span class="' . $htmlcolor . '">' . $item['days_overdue'] . '</span></td>
                <td>$' . number_format($item['penalty_amount'], 2) . '</td>
            </tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<div class="no-data">No overdue items at this time.</div>';
    }
    $html .= '</div>';

    // Overdue by User
    if (!empty($data['overdue_by_user'])) {
        $html .= '
        <div class="section">
            <h2 class="section-title">👤 Overdue Summary by Student</h2>
            <table>
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Overdue Count</th>
                        <th>Total Days Overdue</th>
                        <th>Total Penalty</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($data['overdue_by_user'] as $user) {
            $html .= '<tr>
                <td>' . htmlspecialchars($user['full_name']) . '</td>
                <td><span class="highlight-danger">' . $user['overdue_count'] . '</span></td>
                <td>' . ($user['total_days_overdue'] ?? 0) . '</td>
                <td>$' . number_format($user['total_penalty'] ?? 0, 2) . '</td>
            </tr>';
        }
        $html .= '</tbody></table></div>';
    }

    // Repeated Offenders
    if (!empty($data['repeated_offenders'])) {
        $html .= '
        <div class="section">
            <h2 class="section-title">🚨 Repeated Offenders (3+ Late Returns)</h2>
            <table>
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Total Transactions</th>
                        <th>Late Returns</th>
                        <th>Late Return %</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($data['repeated_offenders'] as $offender) {
            $html .= '<tr>
                <td>' . htmlspecialchars($offender['full_name']) . '</td>
                <td>' . $offender['total_transactions'] . '</td>
                <td><span class="highlight-danger">' . $offender['late_returns'] . '</span></td>
                <td>' . $offender['late_percent'] . '%</td>
            </tr>';
        }
        $html .= '</tbody></table></div>';
    }

    return $html;
}

function generateSystemMetricsReport($aggregator, $start_date, $end_date) {
    $metrics = $aggregator->getSystemMetrics($start_date, $end_date);
    $inventory = $aggregator->getInventoryStatus();
    $html = '';

    // User Metrics Cards
    $html .= '
    <div class="section">
        <h2 class="section-title">👥 User Statistics</h2>
        <div class="summary-grid">
            <div class="summary-card success">
                <div class="label">Active Students</div>
                <div class="value">' . $metrics['active_students'] . '</div>
            </div>
            <div class="summary-card">
                <div class="label">Total Students</div>
                <div class="value">' . $metrics['total_students'] . '</div>
            </div>
            <div class="summary-card success">
                <div class="label">Active Staff</div>
                <div class="value">' . $metrics['active_users'] . '</div>
            </div>
        </div>
    </div>';

    // Inventory Metrics
    $html .= '
    <div class="section">
        <h2 class="section-title">📚 Inventory Status</h2>
        <div class="summary-grid">
            <div class="summary-card">
                <div class="label">Total Books (Copies)</div>
                <div class="value">' . $metrics['total_books'] . '</div>
            </div>
            <div class="summary-card success">
                <div class="label">Available</div>
                <div class="value">' . $metrics['available_books'] . '</div>
            </div>
            <div class="summary-card warning">
                <div class="label">Borrowed</div>
                <div class="value">' . $metrics['borrowed_books'] . '</div>
            </div>
        </div>
    </div>';

    // Inventory Breakdown by Status
    if (!empty($inventory)) {
        $html .= '
        <div class="section">
            <h2 class="section-title">📊 Inventory Breakdown by Status</h2>
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Count</th>
                        <th>Total Copies</th>
                        <th>Available</th>
                        <th>Borrowed</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($inventory as $item) {
            $html .= '<tr>
                <td><strong>' . ucfirst(str_replace('_', ' ', $item['book_status'])) . '</strong></td>
                <td>' . $item['count'] . '</td>
                <td>' . ($item['total_copies'] ?? 0) . '</td>
                <td>' . ($item['available_copies'] ?? 0) . '</td>
                <td>' . ($item['borrowed_copies'] ?? 0) . '</td>
            </tr>';
        }
        $html .= '</tbody></table></div>';
    }

    // Activity Metrics
    $html .= '
    <div class="section">
        <h2 class="section-title">🎯 Activity Metrics</h2>
        <div class="summary-grid">
            <div class="summary-card success">
                <div class="label">Total Borrows</div>
                <div class="value">' . $metrics['most_used_features']['total_borrows'] . '</div>
            </div>
            <div class="summary-card success">
                <div class="label">Total Returns</div>
                <div class="value">' . $metrics['most_used_features']['total_returns'] . '</div>
            </div>
            <div class="summary-card">
                <div class="label">Active Transactions</div>
                <div class="value">' . $metrics['active_transactions'] . '</div>
            </div>
        </div>
    </div>';

    return $html;
}
?>
