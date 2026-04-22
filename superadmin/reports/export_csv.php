<?php
/**
 * CSV Export Handler - Reports Module
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

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="report_' . $report_type . '_' . date('Y-m-d_H-i-s') . '.csv"');

$output = fopen('php://output', 'w');

$aggregator = new ReportsAggregator($conn);

try {
    switch ($report_type) {
        case 'borrowing_trends':
            exportBorrowingTrends($aggregator, $output, $start_date, $end_date);
            break;
            
        case 'overdue_books':
            exportOverdueBooks($aggregator, $output, $start_date, $end_date);
            break;
            
        case 'system_metrics':
            exportSystemMetrics($aggregator, $output, $start_date, $end_date);
            break;
            
        default:
            fputcsv($output, ['Error: Invalid report type']);
    }
} catch (Exception $e) {
    fputcsv($output, ['Error: ' . $e->getMessage()]);
}

fclose($output);
exit();

/**
 * Export Borrowing Trends to CSV
 */
function exportBorrowingTrends($aggregator, $output, $start_date, $end_date) {
    $data = $aggregator->getBorrowingTrends($start_date, $end_date);
    
    // Header
    fputcsv($output, ['BORROWING TRENDS REPORT', date('Y-m-d H:i:s')]);
    fputcsv($output, ['Report Period: ' . $start_date . ' to ' . $end_date]);
    fputcsv($output, []);
    
    // Summary
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Borrows', 'Total Returns']);
    fputcsv($output, [$data['total_borrows'], $data['total_returns']]);
    fputcsv($output, []);
    
    // Most Borrowed Books
    fputcsv($output, ['MOST BORROWED BOOKS']);
    fputcsv($output, ['Rank', 'Title', 'Author', 'Borrow Count', 'Return Count']);
    $rank = 1;
    foreach ($data['most_borrowed_books'] as $book) {
        fputcsv($output, [
            $rank++,
            $book['title'],
            $book['author'],
            $book['borrow_count'],
            $book['return_count']
        ]);
    }
    fputcsv($output, []);
    
    // Borrowing by Month
    fputcsv($output, ['BORROWING BY MONTH']);
    fputcsv($output, ['Month', 'Number of Borrows']);
    foreach ($data['borrowing_by_month'] as $month) {
        fputcsv($output, [$month['month'], $month['count']]);
    }
    fputcsv($output, []);
    
    // Borrowing by Day
    fputcsv($output, ['BORROWING BY DAY OF WEEK']);
    fputcsv($output, ['Day', 'Number of Borrows']);
    foreach ($data['borrowing_by_day'] as $day) {
        fputcsv($output, [$day['day_name'], $day['count']]);
    }
    fputcsv($output, []);
    
    // Top Borrowers
    fputcsv($output, ['TOP STUDENT BORROWERS']);
    fputcsv($output, ['Rank', 'Student Name', 'Borrow Count', 'Overdue Count']);
    $rank = 1;
    foreach ($data['borrowing_by_student'] as $student) {
        fputcsv($output, [
            $rank++,
            $student['full_name'],
            $student['borrow_count'],
            $student['overdue_count']
        ]);
    }
}

/**
 * Export Overdue Books to CSV
 */
function exportOverdueBooks($aggregator, $output, $start_date, $end_date) {
    $data = $aggregator->getOverdueData($start_date, $end_date);
    
    // Header
    fputcsv($output, ['OVERDUE BOOKS REPORT', date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    // Summary
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Overdue Items', 'Total Penalty Amount']);
    fputcsv($output, [
        $data['total_overdue_count'],
        '$' . number_format($data['total_penalty_amount'], 2)
    ]);
    fputcsv($output, []);
    
    // Current Overdue Items
    fputcsv($output, ['CURRENT OVERDUE ITEMS']);
    fputcsv($output, ['Student', 'Book Title', 'Due Date', 'Days Overdue', 'Penalty']);
    foreach ($data['overdues_list'] as $item) {
        fputcsv($output, [
            $item['student_name'],
            $item['book_title'],
            $item['due_date'],
            $item['days_overdue'],
            '$' . number_format($item['penalty_amount'], 2)
        ]);
    }
    fputcsv($output, []);
    
    // Overdue by User
    fputcsv($output, ['OVERDUE SUMMARY BY STUDENT']);
    fputcsv($output, ['Student Name', 'Overdue Count', 'Total Days Overdue', 'Total Penalty']);
    foreach ($data['overdue_by_user'] as $user) {
        fputcsv($output, [
            $user['full_name'],
            $user['overdue_count'],
            $user['total_days_overdue'],
            '$' . number_format($user['total_penalty'], 2)
        ]);
    }
    fputcsv($output, []);
    
    // Repeated Offenders
    fputcsv($output, ['REPEATED OFFENDERS (3+ Late Returns)']);
    fputcsv($output, ['Student Name', 'Total Transactions', 'Late Returns', 'Late Return %']);
    foreach ($data['repeated_offenders'] as $offender) {
        fputcsv($output, [
            $offender['full_name'],
            $offender['total_transactions'],
            $offender['late_returns'],
            $offender['late_percent'] . '%'
        ]);
    }
}

/**
 * Export System Metrics to CSV
 */
function exportSystemMetrics($aggregator, $output, $start_date, $end_date) {
    $metrics = $aggregator->getSystemMetrics($start_date, $end_date);
    
    // Header
    fputcsv($output, ['SYSTEM USAGE METRICS REPORT', date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    // User Statistics
    fputcsv($output, ['USER STATISTICS']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Active Students', $metrics['active_students']]);
    fputcsv($output, ['Total Students', $metrics['total_students']]);
    fputcsv($output, ['Active Users (Staff)', $metrics['active_users']]);
    fputcsv($output, ['Total Users (Staff)', $metrics['total_users']]);
    fputcsv($output, []);
    
    // Inventory Statistics
    fputcsv($output, ['INVENTORY STATISTICS']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Books (Copies)', $metrics['total_books']]);
    fputcsv($output, ['Available Books', $metrics['available_books']]);
    fputcsv($output, ['Borrowed Books', $metrics['borrowed_books']]);
    fputcsv($output, ['Active Transactions', $metrics['active_transactions']]);
    fputcsv($output, []);
    
    // Feature Usage
    fputcsv($output, ['FEATURE USAGE']);
    fputcsv($output, ['Feature', 'Count']);
    fputcsv($output, ['Total Borrows', $metrics['most_used_features']['total_borrows']]);
    fputcsv($output, ['Total Returns', $metrics['most_used_features']['total_returns']]);
    fputcsv($output, ['Active Users', $metrics['most_used_features']['active_users']]);
}
?>
