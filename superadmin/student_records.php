<?php
/**
 * Student Records - Library Borrowing System
 * Super Admin Only - View all student information and records
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

// Check if user is super admin
if (!isSuperAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

// Initialize variables
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get filter parameters
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'full_name';
$sort_order = $_GET['order'] ?? 'ASC';

// Validate sort parameters for security
$allowed_sorts = ['full_name', 'student_id', 'created_at', 'contact_number'];
$allowed_orders = ['ASC', 'DESC'];

if (!in_array($sort_by, $allowed_sorts)) $sort_by = 'full_name';
if (!in_array($sort_order, $allowed_orders)) $sort_order = 'ASC';

// Build query
$where_clause = "WHERE 1=1";
$search_param = '';

if (!empty($search)) {
    $search_param = '%' . $conn->real_escape_string($search) . '%';
    $where_clause .= " AND (full_name LIKE '$search_param' OR contact_number LIKE '$search_param' OR qr_code LIKE '$search_param')";
}

if (!empty($status_filter)) {
    $status_filter = $conn->real_escape_string($status_filter);
    $where_clause .= " AND status = '$status_filter'";
}

// Get total count
$count_query = "SELECT COUNT(*) as total FROM students $where_clause";
$count_result = $conn->query($count_query);
$total_students = $count_result ? $count_result->fetch_assoc()['total'] : 0;
$total_pages = ceil($total_students / $per_page);

// Get student records
$query = "SELECT 
            s.student_id,
            s.full_name,
            s.contact_number,
            s.qr_code,
            s.status,
            s.created_at,
            s.updated_at,
            COUNT(t.transaction_id) as total_borrows,
            SUM(CASE WHEN t.status = 'borrowed' THEN 1 ELSE 0 END) as currently_borrowed
        FROM students s
        LEFT JOIN transactions t ON s.student_id = t.student_id
        $where_clause
        GROUP BY s.student_id, s.full_name, s.contact_number, s.qr_code, s.status, s.created_at, s.updated_at
        ORDER BY $sort_by $sort_order
        LIMIT $offset, $per_page";

$result = $conn->query($query);
$students = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Records - Library System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            color: #333;
            margin-left: 260px;
        }

        @media (max-width: 992px) {
            body {
                margin-left: 0;
            }
        }

        .container {
            max-width: 1400px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-header h1 {
            font-size: 28px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .filter-section h3 {
            margin-bottom: 15px;
            color: #333;
            font-size: 16px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 5px;
            color: #555;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #003366;
            box-shadow: 0 0 3px rgba(0, 51, 102, 0.2);
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
        }

        .btn-search {
            background: #003366;
            color: white;
        }

        .btn-search:hover {
            background: #002244;
        }

        .btn-reset {
            background: #6c757d;
            color: white;
        }

        .btn-reset:hover {
            background: #5a6268;
        }

        .btn-export {
            background: #28a745;
            color: white;
        }

        .btn-export:hover {
            background: #218838;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-card h4 {
            color: #666;
            font-size: 12px;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card .value {
            font-size: 24px;
            font-weight: bold;
            color: #003366;
        }

        .table-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .table-header {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            margin: 0;
            color: #333;
            font-size: 16px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }

        th {
            padding: 15px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #495057;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            user-select: none;
        }

        th:hover {
            background: #e9ecef;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 13px;
        }

        tbody tr:hover {
            background: #f8f9fa;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-active {
            background: #d4edda;
            color: #155724;
        }

        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            padding: 20px;
            background: #f8f9fa;
        }

        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            text-decoration: none;
            font-size: 13px;
            color: #003366;
        }

        .pagination a:hover {
            background: #003366;
            color: white;
            border-color: #003366;
        }

        .pagination .active {
            background: #003366;
            color: white;
            border-color: #003366;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .sort-indicator {
            font-size: 11px;
            opacity: 0.6;
        }

        .sort-asc::after {
            content: " ↑";
        }

        .sort-desc::after {
            content: " ↓";
        }

        .action-links {
            display: flex;
            gap: 8px;
        }

        .action-links a {
            padding: 6px 12px;
            background: #003366;
            color: white;
            text-decoration: none;
            border-radius: 3px;
            font-size: 12px;
            transition: background 0.3s;
        }

        .action-links a:hover {
            background: #002244;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>

    <div class="container">
        <div class="page-header">
            <h1>Student Records</h1>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <h3>Search & Filter</h3>
            <form method="GET" id="filterForm">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label for="search">Search by Name, Contact, or QR Code</label>
                        <input type="text" id="search" name="search" 
                               placeholder="Enter name, phone, or QR code..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="sort">Sort By</label>
                        <select id="sort" name="sort">
                            <option value="full_name" <?php echo $sort_by === 'full_name' ? 'selected' : ''; ?>>Name</option>
                            <option value="student_id" <?php echo $sort_by === 'student_id' ? 'selected' : ''; ?>>ID</option>
                            <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Date Added</option>
                            <option value="contact_number" <?php echo $sort_by === 'contact_number' ? 'selected' : ''; ?>>Contact</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="order">Order</label>
                        <select id="order" name="order">
                            <option value="ASC" <?php echo $sort_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                            <option value="DESC" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                        </select>
                    </div>
                </div>

                <div class="filter-actions" style="margin-top: 15px;">
                    <button type="submit" class="btn btn-search">Search</button>
                    <a href="student_records.php" class="btn btn-reset">↺ Reset</a>
                </div>
            </form>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h4>Total Students</h4>
                <div class="value"><?php echo number_format($total_students); ?></div>
            </div>
            <div class="stat-card">
                <h4>Active Students</h4>
                <div class="value">
                    <?php
                    $active_result = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'active'");
                    $active_count = $active_result ? $active_result->fetch_assoc()['count'] : 0;
                    echo number_format($active_count);
                    ?>
                </div>
            </div>
            <div class="stat-card">
                <h4>Inactive Students</h4>
                <div class="value">
                    <?php
                    $inactive_result = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'inactive'");
                    $inactive_count = $inactive_result ? $inactive_result->fetch_assoc()['count'] : 0;
                    echo number_format($inactive_count);
                    ?>
                </div>
            </div>
        </div>

        <!-- Student Records Table -->
        <div class="table-section">
            <div class="table-header">
                <h3>Student Information</h3>
                <span><?php echo number_format($total_students); ?> students found</span>
            </div>

            <?php if (empty($students)): ?>
                <div class="no-data">
                    No student records found. <?php echo !empty($search) ? 'Try adjusting your search.' : 'No students registered yet.'; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Full Name</th>
                                <th>Contact Number</th>
                                <th>QR Code</th>
                                <th>Status</th>
                                <th>Books Borrowed</th>
                                <th>Currently Borrowed</th>
                                <th>Date Added</th>
                                <th>Last Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['student_id']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($student['full_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?></td>
                                    <td>
                                        <code style="background: #f0f0f0; padding: 4px 8px; border-radius: 3px;">
                                            <?php echo htmlspecialchars($student['qr_code']); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <?php 
                                        $status_class = $student['status'] === 'active' ? 'badge-active' : 'badge-inactive';
                                        ?>
                                        <span class="badge <?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars($student['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $student['total_borrows'] ?? 0; ?></td>
                                    <td>
                                        <strong style="color: <?php echo ($student['currently_borrowed'] ?? 0) > 0 ? '#ff6b6b' : '#28a745'; ?>">
                                            <?php echo $student['currently_borrowed'] ?? 0; ?>
                                        </strong>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($student['created_at'])); ?></td>
                                    <td><?php echo date('M d, Y H:i', strtotime($student['updated_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">« First</a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">‹ Previous</a>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next ›</a>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">Last »</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
