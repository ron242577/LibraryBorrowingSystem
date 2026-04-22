<?php
/**
 * Reports Data Aggregator - Library Borrowing System
 * Handles all data collection and aggregation for reports
 */

require_once __DIR__ . '/../../db.php';

class ReportsAggregator {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Get borrowing trends data
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public function getBorrowingTrends($start_date, $end_date) {
        $trends = [
            'most_borrowed_books' => $this->getMostBorrowedBooks($start_date, $end_date),
            'borrowing_by_month' => $this->getBorrowingByMonth($start_date, $end_date),
            'borrowing_by_day' => $this->getBorrowingByDay($start_date, $end_date),
            'borrowing_by_student' => $this->getBorrowingByStudent($start_date, $end_date),
            'total_borrows' => $this->getTotalBorrows($start_date, $end_date),
            'total_returns' => $this->getTotalReturns($start_date, $end_date),
        ];
        
        return $trends;
    }
    
    /**
     * Get most borrowed books
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    private function getMostBorrowedBooks($start_date, $end_date) {
        $query = "SELECT 
                    b.book_id,
                    b.title, 
                    b.author,
                    COUNT(t.transaction_id) as borrow_count,
                    COUNT(CASE WHEN t.status IN ('returned', 'overdue') THEN 1 END) as return_count
                  FROM books b
                  LEFT JOIN transactions t ON b.book_id = t.book_id 
                    AND t.date_borrowed BETWEEN ? AND ?
                  GROUP BY b.book_id, b.title, b.author
                  ORDER BY borrow_count DESC
                  LIMIT 15";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $books = [];
        while ($row = $result->fetch_assoc()) {
            $books[] = $row;
        }
        $stmt->close();
        
        return $books;
    }
    
    /**
     * Get borrowing by month
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    private function getBorrowingByMonth($start_date, $end_date) {
        $query = "SELECT 
                    DATE_FORMAT(date_borrowed, '%Y-%m') as month,
                    COUNT(*) as count
                  FROM transactions
                  WHERE date_borrowed BETWEEN ? AND ?
                  GROUP BY DATE_FORMAT(date_borrowed, '%Y-%m')
                  ORDER BY month ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();
        
        return $data;
    }
    
    /**
     * Get borrowing by day of week
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    private function getBorrowingByDay($start_date, $end_date) {
        $query = "SELECT 
                    DAYNAME(date_borrowed) as day_name,
                    DAYOFWEEK(date_borrowed) as day_num,
                    COUNT(*) as count
                  FROM transactions
                  WHERE date_borrowed BETWEEN ? AND ?
                  GROUP BY DAYOFWEEK(date_borrowed), DAYNAME(date_borrowed)
                  ORDER BY day_num ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();
        
        return $data;
    }
    
    /**
     * Get borrowing by top students
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    private function getBorrowingByStudent($start_date, $end_date) {
        $query = "SELECT 
                    s.student_id,
                    s.full_name,
                    COUNT(t.transaction_id) as borrow_count,
                    COUNT(CASE WHEN t.status = 'overdue' THEN 1 END) as overdue_count
                  FROM students s
                  LEFT JOIN transactions t ON s.student_id = t.student_id 
                    AND t.date_borrowed BETWEEN ? AND ?
                  WHERE s.status = 'active'
                  GROUP BY s.student_id, s.full_name
                  ORDER BY borrow_count DESC
                  LIMIT 10";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();
        
        return $data;
    }
    
    /**
     * Get total borrows in period
     */
    private function getTotalBorrows($start_date, $end_date) {
        $query = "SELECT COUNT(*) as count FROM transactions WHERE date_borrowed BETWEEN ? AND ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Get total returns in period
     */
    private function getTotalReturns($start_date, $end_date) {
        $query = "SELECT COUNT(*) as count FROM transactions 
                  WHERE return_date BETWEEN ? AND ? AND status = 'returned'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ss', $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Get overdue books data
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public function getOverdueData($start_date, $end_date) {
        $overdueData = [
            'overdues_list' => $this->getOverduesList(),
            'overdue_by_user' => $this->getOverdueByUser(),
            'overdue_by_book' => $this->getOverdueByBook(),
            'repeated_offenders' => $this->getRepeatedOffenders(),
            'total_overdue_count' => $this->getTotalOverdueCount(),
            'total_penalty_amount' => $this->getTotalPenaltyAmount(),
        ];
        
        return $overdueData;
    }
    
    /**
     * Get current overdue items
     */
    private function getOverduesList() {
        $query = "SELECT 
                    t.transaction_id,
                    s.full_name as student_name,
                    b.title as book_title,
                    t.date_borrowed,
                    t.due_date,
                    DATEDIFF(NOW(), t.due_date) as days_overdue,
                    t.penalty_amount,
                    t.status
                  FROM transactions t
                  JOIN students s ON t.student_id = s.student_id
                  JOIN books b ON t.book_id = b.book_id
                  WHERE t.status IN ('overdue', 'borrowed') 
                    AND t.due_date < NOW()
                  ORDER BY t.due_date ASC";
        
        $result = $this->conn->query($query);
        $overdues = [];
        while ($row = $result->fetch_assoc()) {
            $overdues[] = $row;
        }
        
        return $overdues;
    }
    
    /**
     * Get overdue count by user
     */
    private function getOverdueByUser() {
        $query = "SELECT 
                    s.student_id,
                    s.full_name,
                    COUNT(t.transaction_id) as overdue_count,
                    SUM(CASE WHEN t.status = 'overdue' THEN DATEDIFF(NOW(), t.due_date) ELSE 0 END) as total_days_overdue,
                    SUM(t.penalty_amount) as total_penalty
                  FROM students s
                  LEFT JOIN transactions t ON s.student_id = t.student_id
                    AND t.status IN ('overdue', 'borrowed') 
                    AND t.due_date < NOW()
                  WHERE s.status = 'active'
                  GROUP BY s.student_id, s.full_name
                  HAVING overdue_count > 0
                  ORDER BY overdue_count DESC
                  LIMIT 15";
        
        $result = $this->conn->query($query);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        return $data;
    }
    
    /**
     * Get overdue count by book
     */
    private function getOverdueByBook() {
        $query = "SELECT 
                    b.book_id,
                    b.title,
                    COUNT(t.transaction_id) as overdue_count
                  FROM books b
                  LEFT JOIN transactions t ON b.book_id = t.book_id
                    AND t.status IN ('overdue', 'borrowed')
                    AND t.due_date < NOW()
                  WHERE b.book_status != 'lost'
                  GROUP BY b.book_id, b.title
                  HAVING overdue_count > 0
                  ORDER BY overdue_count DESC
                  LIMIT 10";
        
        $result = $this->conn->query($query);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        return $data;
    }
    
    /**
     * Get repeated offenders (students with multiple overdues)
     */
    private function getRepeatedOffenders() {
        $query = "SELECT 
                    s.student_id,
                    s.full_name,
                    COUNT(t.transaction_id) as total_transactions,
                    COUNT(CASE WHEN t.return_date > t.due_date THEN 1 END) as late_returns,
                    ROUND(COUNT(CASE WHEN t.return_date > t.due_date THEN 1 END) * 100.0 / COUNT(t.transaction_id), 2) as late_percent
                  FROM students s
                  JOIN transactions t ON s.student_id = t.student_id
                  WHERE t.status IN ('returned', 'overdue')
                  GROUP BY s.student_id, s.full_name
                  HAVING late_returns >= 3
                  ORDER BY late_returns DESC
                  LIMIT 10";
        
        $result = $this->conn->query($query);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        return $data;
    }
    
    /**
     * Get total overdue count
     */
    private function getTotalOverdueCount() {
        $query = "SELECT COUNT(*) as count FROM transactions 
                  WHERE status IN ('overdue', 'borrowed') AND due_date < NOW()";
        $result = $this->conn->query($query);
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Get total penalty amount
     */
    private function getTotalPenaltyAmount() {
        $query = "SELECT SUM(penalty_amount) as total FROM transactions 
                  WHERE status IN ('overdue', 'borrowed') AND due_date < NOW()";
        $result = $this->conn->query($query);
        $row = $result->fetch_assoc();
        
        return $row['total'] ?? 0;
    }
    
    /**
     * Get system usage metrics
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public function getSystemMetrics($start_date, $end_date) {
        $metrics = [
            'active_students' => $this->getActiveStudents(),
            'total_students' => $this->getTotalStudents(),
            'active_users' => $this->getActiveUsers(),
            'total_users' => $this->getTotalUsers(),
            'total_books' => $this->getTotalBooks(),
            'available_books' => $this->getAvailableBooks(),
            'borrowed_books' => $this->getBorrowedBooks(),
            'active_transactions' => $this->getActiveTransactions(),
            'most_used_features' => $this->getMostUsedFeatures($start_date, $end_date),
        ];
        
        return $metrics;
    }
    
    /**
     * Get active students
     */
    private function getActiveStudents() {
        $query = "SELECT COUNT(DISTINCT s.student_id) as count 
                  FROM students s
                  JOIN transactions t ON s.student_id = t.student_id
                  WHERE s.status = 'active' AND t.status IN ('borrowed', 'overdue')";
        $result = $this->conn->query($query);
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Get total active students
     */
    private function getTotalStudents() {
        $query = "SELECT COUNT(*) as count FROM students WHERE status = 'active'";
        $result = $this->conn->query($query);
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Get active users (librarian & admin)
     */
    private function getActiveUsers() {
        $query = "SELECT COUNT(*) as count FROM users WHERE status = 'active'";
        $result = $this->conn->query($query);
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Get total users
     */
    private function getTotalUsers() {
        $query = "SELECT COUNT(*) as count FROM users";
        $result = $this->conn->query($query);
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Get total books
     */
    private function getTotalBooks() {
        $query = "SELECT SUM(total_copies) as count FROM books";
        $result = $this->conn->query($query);
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Get available books
     */
    private function getAvailableBooks() {
        $query = "SELECT SUM(available_copies) as count FROM books";
        $result = $this->conn->query($query);
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Get borrowed books
     */
    private function getBorrowedBooks() {
        $query = "SELECT SUM(borrowed_copies) as count FROM books";
        $result = $this->conn->query($query);
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Get active transactions
     */
    private function getActiveTransactions() {
        $query = "SELECT COUNT(*) as count FROM transactions WHERE status IN ('borrowed', 'overdue')";
        $result = $this->conn->query($query);
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    }
    
    /**
     * Get most used features
     */
    private function getMostUsedFeatures($start_date, $end_date) {
        $features = [
            'total_borrows' => $this->getTotalBorrows($start_date, $end_date),
            'total_returns' => $this->getTotalReturns($start_date, $end_date),
            'total_searches' => 0, // Would need logging system
            'active_users' => $this->getActiveUsers(),
        ];
        
        return $features;
    }
    
    /**
     * Get popularity by category or time period
     * @return array
     */
    public function getInventoryStatus() {
        $query = "SELECT 
                    book_status,
                    COUNT(*) as count,
                    SUM(total_copies) as total_copies,
                    SUM(available_copies) as available_copies,
                    SUM(borrowed_copies) as borrowed_copies
                  FROM books
                  GROUP BY book_status";
        
        $result = $this->conn->query($query);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        
        return $data;
    }
}
?>
