<?php
/**
 * Student Profile Page
 * Shows student information and borrowed book records separate from the search dashboard.
 */

require_once __DIR__ . '/../db.php';

$student = null;
$borrowed_books = [];
$total_penalty = 0;
$error = null;
$student_qr = isset($_GET['qr']) ? trim($_GET['qr']) : null;

if (!$student_qr) {
    $error = 'No student QR code provided. Please scan your student ID again.';
} else {
    try {
        $student_stmt = $conn->prepare("SELECT student_no, full_name, contact_number, qr_code, status, created_at FROM students WHERE qr_code = ? AND status = 'active'");
        $student_stmt->bind_param('s', $student_qr);
        $student_stmt->execute();
        $student_result = $student_stmt->get_result();

        if ($student_result->num_rows > 0) {
            $student = $student_result->fetch_assoc();
            $student_id = (int)$student['student_no'];

            $books_stmt = $conn->prepare("\n                SELECT\n                    t.transaction_id,\n                    t.date_borrowed,\n                    t.due_date,\n                    t.return_date,\n                    t.penalty_amount,\n                    t.status,\n                    b.title,\n                    b.author\n                FROM transactions t\n                INNER JOIN books b ON t.book_id = b.book_id\n                WHERE t.student_id = ?\n                ORDER BY t.date_borrowed DESC\n            ");
            $books_stmt->bind_param('i', $student_id);
            $books_stmt->execute();
            $books_result = $books_stmt->get_result();

            while ($row = $books_result->fetch_assoc()) {
                if ($row['status'] === 'borrowed' && strtotime($row['due_date']) < time()) {
                    $days_late = max(1, floor((time() - strtotime($row['due_date'])) / 86400));
                    $row['computed_penalty'] = $days_late * 5;
                    $total_penalty += $row['computed_penalty'];
                } else {
                    $row['computed_penalty'] = (float)($row['penalty_amount'] ?? 0);
                    $total_penalty += $row['computed_penalty'];
                }
                $borrowed_books[] = $row;
            }
            $books_stmt->close();
        } else {
            $error = 'Student not found or inactive. Please scan your student ID again.';
        }
        $student_stmt->close();
    } catch (Exception $e) {
        logError('Student profile error: ' . $e->getMessage());
        $error = 'Unable to load your profile right now. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile - Library Borrowing System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: #F3F7FC;
            color: #202A44;
            padding-top: 70px;
            padding-bottom: 40px;
        }
        .page-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 70px;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,.1);
            z-index: 998;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 40px;
            border-bottom: 3px solid #F4F916;
        }
        .header-brand {
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            gap: 12px;
        }
        .header-brand img { height: 50px; width: auto; object-fit: contain; }
        .header-brand-text { font-size: 18px; font-weight: 700; color: #141F52; }
        .student-menu {
            position: relative;
            display: flex;
            align-items: center;
        }
        .student-menu-toggle {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: #141F52;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
        }
        .student-menu-toggle:hover { background: #0D153B; }
        .student-menu-name {
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .student-menu-caret { font-size: 11px; line-height: 1; }
        .student-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            min-width: 190px;
            background: #fff;
            border: 1px solid #e2e6ea;
            border-radius: 8px;
            box-shadow: 0 12px 30px rgba(0,0,0,.14);
            padding: 8px 0;
            display: none;
            z-index: 1200;
        }
        .student-dropdown.show { display: block; }
        .student-dropdown a {
            display: block;
            padding: 12px 18px;
            color: #202A44;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        .student-dropdown a:hover,
        .student-dropdown a.active {
            background: #EDF3FA;
            color: #141F52;
            border-left: 3px solid #F4F916;
        }
        .student-dropdown .dropdown-divider { height: 1px; background: #D2E2F6; margin: 6px 0; }
        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }
        .page-title { margin-bottom: 24px; }
        .page-title h1 { font-size: 28px; color: #202A44; margin-bottom: 8px; }
        .page-title p { color: #52618D; font-size: 14px; }
        .card {
            background: white;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            border: 1px solid #D2E2F6;
            margin-bottom: 24px;
            overflow: hidden;
        }
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #eef0f4;
            font-size: 18px;
            font-weight: 800;
            color: #202A44;
        }
        .card-body { padding: 24px; }
        .profile-top {
            display: flex;
            gap: 18px;
            align-items: center;
            margin-bottom: 24px;
        }
        .avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: #141F52;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
        }
        .profile-name h2 { font-size: 24px; margin-bottom: 6px; }
        .profile-name p { color: #52618D; font-size: 14px; }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 14px;
        }
        .info-item {
            background: #F7F9FC;
            padding: 16px;
            border-radius: 10px;
            border-left: 4px solid #141F52;
        }
        .info-label {
            font-size: 11px;
            color: #52618D;
            text-transform: uppercase;
            letter-spacing: .5px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .info-value { font-size: 16px; font-weight: 800; color: #202A44; }
        .qr-box {
            background: #F7F9FC;
            border: 2px dashed #141F52;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-top: 20px;
        }
        .qr-box img { width: 150px; height: 150px; background: white; padding: 8px; border-radius: 8px; }
        .qr-text { margin-top: 10px; color: #141F52; font: 700 12px 'Courier New', monospace; }
        .penalty-box {
            background: #FBE8DC;
            border-left: 4px solid #BB5716;
            padding: 16px;
            border-radius: 10px;
            margin-top: 18px;
            color: #7A3A0E;
            font-weight: 700;
        }
        .books-list { display: flex; flex-direction: column; gap: 12px; }
        .book-row {
            background: #F7F9FC;
            border: 1px solid #D2E2F6;
            border-radius: 10px;
            padding: 16px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
            align-items: center;
        }
        .book-title { font-size: 16px; font-weight: 800; margin-bottom: 4px; }
        .book-meta { color: #52618D; font-size: 13px; line-height: 1.6; }
        .badge {
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .badge.borrowed { background: #FBFDCB; color: #5C5F05; }
        .badge.returned { background: #EDF5DD; color: #344E15; }
        .badge.overdue { background: #f8d7da; color: #721c24; }
        .empty, .error-box { text-align: center; padding: 40px 20px; color: #52618D; }
        .error-box { color: #c62828; background: #ffebee; border: 2px solid #ef5350; border-radius: 10px; }
        @media (max-width: 700px) {
            body { padding-top: 60px; }
            .page-header { height: 60px; padding: 0 16px; }
            .header-brand img { height: 40px; }
            .header-brand-text { font-size: 13px; }
            .student-menu-toggle { padding: 8px 10px; font-size: 12px; }
            .student-menu-name { max-width: 130px; }
            .book-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="page-header">
        <a href="/LibraryBorrowingSystem/student/borrow.php<?php echo $student_qr ? '?qr=' . urlencode($student_qr) : ''; ?>" class="header-brand">
            <img src="/LibraryBorrowingSystem/Img/Claro_M_Recto_Logo.png" alt="Claro M. Recto High School Logo">
            <span class="header-brand-text">Claro M. Recto Book Borrowing</span>
        </a>
        <div class="student-menu">
            <button type="button" class="student-menu-toggle" id="studentMenuToggle" aria-haspopup="true" aria-expanded="false">
                <span class="student-menu-name"><?php echo $student ? htmlspecialchars($student['full_name']) : 'Student'; ?></span>
                <span class="student-menu-caret">▼</span>
            </button>
            <div class="student-dropdown" id="studentDropdown">
                <a href="/LibraryBorrowingSystem/student/profile.php<?php echo $student_qr ? '?qr=' . urlencode($student_qr) : ''; ?>" class="active">Profile</a>
                <?php if ($student_qr): ?>
                    <a href="/LibraryBorrowingSystem/student/borrow.php?qr=<?php echo urlencode($student_qr); ?>">Search Books</a>
                <?php endif; ?>
                <div class="dropdown-divider"></div>
                <a href="/LibraryBorrowingSystem/student/portal.php">Logout</a>
            </div>
        </div>
    </header>

    <main class="container">
        <div class="page-title">
            <h1>Profile</h1>
            <p>Your student information and borrowing history are shown here.</p>
        </div>

        <?php if ($error): ?>
            <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif ($student): ?>
            <section class="card">
                <div class="card-header">Student Information</div>
                <div class="card-body">
                    <div class="profile-top">
                        <div class="avatar">🎓</div>
                        <div class="profile-name">
                            <h2><?php echo htmlspecialchars($student['full_name']); ?></h2>
                            <p>Member since <?php echo date('M d, Y', strtotime($student['created_at'])); ?></p>
                        </div>
                    </div>

                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">Student ID</div>
                            <div class="info-value"><?php echo str_pad($student['student_no'], 4, '0', STR_PAD_LEFT); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['full_name']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Contact</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Total Records</div>
                            <div class="info-value"><?php echo count($borrowed_books); ?></div>
                        </div>
                    </div>

                    <?php if ($total_penalty > 0): ?>
                        <div class="penalty-box">Outstanding / recorded penalty: ₱<?php echo number_format($total_penalty, 2); ?></div>
                    <?php endif; ?>

                    <div class="qr-box">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($student['qr_code']); ?>" alt="Student QR Code">
                        <div class="qr-text"><?php echo htmlspecialchars($student['qr_code']); ?></div>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-header">Borrowing History</div>
                <div class="card-body">
                    <?php if (empty($borrowed_books)): ?>
                        <div class="empty">No borrowing records yet.</div>
                    <?php else: ?>
                        <div class="books-list">
                            <?php foreach ($borrowed_books as $book): ?>
                                <?php
                                    $is_overdue = $book['status'] === 'borrowed' && strtotime($book['due_date']) < time();
                                    $badge_class = $is_overdue ? 'overdue' : strtolower($book['status']);
                                    $badge_text = $is_overdue ? 'Overdue' : ucfirst($book['status']);
                                ?>
                                <div class="book-row">
                                    <div>
                                        <div class="book-title"><?php echo htmlspecialchars($book['title']); ?></div>
                                        <div class="book-meta">
                                            Author: <?php echo htmlspecialchars($book['author']); ?><br>
                                            Borrowed: <?php echo date('M d, Y', strtotime($book['date_borrowed'])); ?> •
                                            Due: <?php echo date('M d, Y', strtotime($book['due_date'])); ?>
                                            <?php if (!empty($book['return_date'])): ?> • Returned: <?php echo date('M d, Y', strtotime($book['return_date'])); ?><?php endif; ?>
                                            <?php if ($book['computed_penalty'] > 0): ?> • Penalty: ₱<?php echo number_format($book['computed_penalty'], 2); ?><?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="badge <?php echo htmlspecialchars($badge_class); ?>"><?php echo htmlspecialchars($badge_text); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <script>
        const studentMenuToggle = document.getElementById('studentMenuToggle');
        const studentDropdown = document.getElementById('studentDropdown');

        if (studentMenuToggle && studentDropdown) {
            studentMenuToggle.addEventListener('click', function (event) {
                event.stopPropagation();
                const isOpen = studentDropdown.classList.toggle('show');
                studentMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            document.addEventListener('click', function () {
                studentDropdown.classList.remove('show');
                studentMenuToggle.setAttribute('aria-expanded', 'false');
            });
        }
    </script>
</body>
</html>
