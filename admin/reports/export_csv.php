<?php
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/reports_aggregator.php';
require_once __DIR__ . '/../../session_check.php';

if (!isAdmin()) {
    header('HTTP/1.0 403 Forbidden');
    exit('Access Denied');
}

$type = $_GET['type'] ?? 'borrowing_trends';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
if (!in_array($type, ['borrowing_trends', 'system_metrics'], true)) $type = 'borrowing_trends';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="JASHS_' . $type . '_' . date('Y-m-d_H-i-s') . '.csv"');
$output = fopen('php://output', 'w');
$aggregator = new ReportsAggregator($conn);

try {
    fputcsv($output, ['JOSE ABAD SANTOS HIGH SCHOOL LIBRARY REPORT']);
    fputcsv($output, ['Period', $start_date . ' to ' . $end_date]);
    fputcsv($output, ['Process', 'Same-day borrowing and return']);
    fputcsv($output, []);

    if ($type === 'borrowing_trends') {
        $data = $aggregator->getBorrowingTrends($start_date, $end_date);
        fputcsv($output, ['SUMMARY']);
        fputcsv($output, ['Total Borrows', $data['total_borrows']]);
        fputcsv($output, ['Total Returns', $data['total_returns']]);
        fputcsv($output, []);

        fputcsv($output, ['MOST BORROWED BOOKS']);
        fputcsv($output, ['Title', 'Author', 'Borrow Count', 'Return Count']);
        foreach ($data['most_borrowed_books'] as $book) {
            fputcsv($output, [$book['title'], $book['author'], $book['borrow_count'], $book['return_count']]);
        }
        fputcsv($output, []);

        fputcsv($output, ['TOP STUDENT BORROWERS']);
        fputcsv($output, ['Student', 'Borrow Count', 'Return Count']);
        foreach ($data['borrowing_by_student'] as $student) {
            fputcsv($output, [$student['full_name'], $student['borrow_count'], $student['return_count']]);
        }
    } else {
        $m = $aggregator->getSystemMetrics($start_date, $end_date);
        fputcsv($output, ['SYSTEM METRICS']);
        fputcsv($output, ['Metric', 'Value']);
        fputcsv($output, ['Active Students', $m['active_students']]);
        fputcsv($output, ['Total Students', $m['total_students']]);
        fputcsv($output, ['Active Staff', $m['active_users']]);
        fputcsv($output, ['Total Staff', $m['total_users']]);
        fputcsv($output, ['Total Book Copies', $m['total_books']]);
        fputcsv($output, ['Available Copies', $m['available_books']]);
        fputcsv($output, ['Borrowed Copies', $m['borrowed_books']]);
        fputcsv($output, ['Active Borrow Transactions', $m['active_transactions']]);
        fputcsv($output, ['Borrows in Period', $m['most_used_features']['total_borrows']]);
        fputcsv($output, ['Returns in Period', $m['most_used_features']['total_returns']]);
    }
} catch (Exception $e) {
    fputcsv($output, ['Error', $e->getMessage()]);
}

fclose($output);
exit();
?>
