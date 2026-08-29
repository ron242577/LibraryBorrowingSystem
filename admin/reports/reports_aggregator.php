<?php
/**
 * Reports Data Aggregator - Jose Abad Santos High School Library Borrowing System
 * Same-day borrowing and return reporting.
 */

require_once __DIR__ . '/../../db.php';

class ReportsAggregator {
    private $conn;

    public function __construct($connection) {
        $this->conn = $connection;
    }

    public function getBorrowingTrends($start_date, $end_date) {
        return [
            'most_borrowed_books' => $this->getMostBorrowedBooks($start_date, $end_date),
            'borrowing_by_month' => $this->getBorrowingByMonth($start_date, $end_date),
            'borrowing_by_day' => $this->getBorrowingByDay($start_date, $end_date),
            'borrowing_by_student' => $this->getBorrowingByStudent($start_date, $end_date),
            'total_borrows' => $this->getTotalBorrows($start_date, $end_date),
            'total_returns' => $this->getTotalReturns($start_date, $end_date),
        ];
    }

    private function dateRangeEnd($end_date) {
        return date('Y-m-d', strtotime($end_date . ' +1 day'));
    }

    private function getMostBorrowedBooks($start_date, $end_date) {
        $end_exclusive = $this->dateRangeEnd($end_date);
        $query = "SELECT b.book_id, b.title, b.author,
                         COUNT(t.transaction_id) AS borrow_count,
                         SUM(CASE WHEN t.status = 'returned' THEN 1 ELSE 0 END) AS return_count
                  FROM books b
                  LEFT JOIN transactions t ON b.book_id = t.book_id
                    AND t.date_borrowed >= ? AND t.date_borrowed < ?
                  GROUP BY b.book_id, b.title, b.author
                  ORDER BY borrow_count DESC, b.title ASC
                  LIMIT 15";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ss', $start_date, $end_exclusive);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    private function getBorrowingByMonth($start_date, $end_date) {
        $end_exclusive = $this->dateRangeEnd($end_date);
        $stmt = $this->conn->prepare("SELECT DATE_FORMAT(date_borrowed, '%Y-%m') AS month, COUNT(*) AS count
                                      FROM transactions
                                      WHERE date_borrowed >= ? AND date_borrowed < ?
                                      GROUP BY DATE_FORMAT(date_borrowed, '%Y-%m')
                                      ORDER BY month ASC");
        $stmt->bind_param('ss', $start_date, $end_exclusive);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    private function getBorrowingByDay($start_date, $end_date) {
        $end_exclusive = $this->dateRangeEnd($end_date);
        $stmt = $this->conn->prepare("SELECT DAYNAME(date_borrowed) AS day_name, DAYOFWEEK(date_borrowed) AS day_num, COUNT(*) AS count
                                      FROM transactions
                                      WHERE date_borrowed >= ? AND date_borrowed < ?
                                      GROUP BY DAYOFWEEK(date_borrowed), DAYNAME(date_borrowed)
                                      ORDER BY day_num ASC");
        $stmt->bind_param('ss', $start_date, $end_exclusive);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    private function getBorrowingByStudent($start_date, $end_date) {
        $end_exclusive = $this->dateRangeEnd($end_date);
        $stmt = $this->conn->prepare("SELECT s.student_id, s.full_name,
                                             COUNT(t.transaction_id) AS borrow_count,
                                             SUM(CASE WHEN t.status = 'returned' THEN 1 ELSE 0 END) AS return_count
                                      FROM students s
                                      LEFT JOIN transactions t ON s.student_id = t.student_id
                                        AND t.date_borrowed >= ? AND t.date_borrowed < ?
                                      WHERE s.status = 'active'
                                      GROUP BY s.student_id, s.full_name
                                      HAVING borrow_count > 0
                                      ORDER BY borrow_count DESC, s.full_name ASC
                                      LIMIT 10");
        $stmt->bind_param('ss', $start_date, $end_exclusive);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    }

    private function getTotalBorrows($start_date, $end_date) {
        $end_exclusive = $this->dateRangeEnd($end_date);
        $stmt = $this->conn->prepare('SELECT COUNT(*) AS count FROM transactions WHERE date_borrowed >= ? AND date_borrowed < ?');
        $stmt->bind_param('ss', $start_date, $end_exclusive);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['count'] ?? 0);
    }

    private function getTotalReturns($start_date, $end_date) {
        $end_exclusive = $this->dateRangeEnd($end_date);
        $stmt = $this->conn->prepare("SELECT COUNT(*) AS count FROM transactions WHERE return_date >= ? AND return_date < ? AND status = 'returned'");
        $stmt->bind_param('ss', $start_date, $end_exclusive);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['count'] ?? 0);
    }

    public function getSystemMetrics($start_date, $end_date) {
        return [
            'active_students' => $this->scalar("SELECT COUNT(*) FROM students WHERE status = 'active' AND COALESCE(is_archived,0)=0"),
            'total_students' => $this->scalar('SELECT COUNT(*) FROM students WHERE COALESCE(is_archived,0)=0'),
            'active_users' => $this->scalar("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'total_users' => $this->scalar('SELECT COUNT(*) FROM users'),
            'total_books' => $this->scalar('SELECT COALESCE(SUM(total_copies),0) FROM books WHERE COALESCE(is_archived,0)=0'),
            'available_books' => $this->scalar('SELECT COALESCE(SUM(available_copies),0) FROM books WHERE COALESCE(is_archived,0)=0'),
            'borrowed_books' => $this->scalar('SELECT COALESCE(SUM(borrowed_copies),0) FROM books WHERE COALESCE(is_archived,0)=0'),
            'active_transactions' => $this->scalar("SELECT COUNT(*) FROM transactions WHERE status = 'borrowed'"),
            'most_used_features' => [
                'total_borrows' => $this->getTotalBorrows($start_date, $end_date),
                'total_returns' => $this->getTotalReturns($start_date, $end_date),
                'active_users' => $this->scalar("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            ],
        ];
    }

    public function getInventoryStatus() {
        $result = $this->conn->query("SELECT book_status, COUNT(*) AS count,
                                             COALESCE(SUM(total_copies),0) AS total_copies,
                                             COALESCE(SUM(available_copies),0) AS available_copies,
                                             COALESCE(SUM(borrowed_copies),0) AS borrowed_copies
                                      FROM books
                                      WHERE COALESCE(is_archived,0)=0
                                      GROUP BY book_status");
        $rows = [];
        if ($result) while ($row = $result->fetch_assoc()) $rows[] = $row;
        return $rows;
    }

    private function scalar($query) {
        $result = $this->conn->query($query);
        if (!$result) return 0;
        $row = $result->fetch_row();
        return (int)($row[0] ?? 0);
    }
}
?>
