<?php
/**
 * Student Profile Page - Jose Abad Santos High School
 * Requires a verified student portal session.
 */

require_once __DIR__ . '/../includes/student_session.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/notification_helper.php';

$student = null;
$borrowed_books = [];
$error = null;
$student_id = (int)$_SESSION['student_id'];
$student_qr = $_SESSION['student_qr'] ?? '';

try {
    $student_stmt = $conn->prepare("SELECT student_id, student_no, full_name, student_group, department, year_level, contact_number, qr_code, status, created_at FROM students WHERE student_id = ? AND status = 'active' AND COALESCE(is_archived,0) = 0 LIMIT 1");
    $student_stmt->bind_param('i', $student_id);
    $student_stmt->execute();
    $student = $student_stmt->get_result()->fetch_assoc();
    $student_stmt->close();

    $reservations = [];
    try {
        $reservation_stmt = $conn->prepare("
            SELECT r.reservation_id, r.status, r.reserved_at, r.ready_at,
                   b.title, b.book_number
            FROM book_reservations r
            INNER JOIN books b ON r.book_id = b.book_id
            WHERE r.student_id = ? AND r.status IN ('pending','ready')
            ORDER BY r.reserved_at DESC
        ");
        $reservation_stmt->bind_param('i', $student_id);
        $reservation_stmt->execute();
        $reservation_result = $reservation_stmt->get_result();
        while ($row = $reservation_result->fetch_assoc()) {
            $reservations[] = $row;
        }
        $reservation_stmt->close();
    } catch (Throwable $reservation_error) {
        $reservations = [];
        logError('Student reservations load error: ' . $reservation_error->getMessage());
    }

    if (!$student) {
        unset($_SESSION['student_id'], $_SESSION['student_no'], $_SESSION['student_name'], $_SESSION['student_qr']);
        header('Location: /LibraryBorrowingSystem/student/portal.php');
        exit();
    }

    $books_stmt = $conn->prepare("\n        SELECT\n            t.transaction_id,\n            t.date_borrowed,\n            t.due_date,\n            t.return_date,\n            t.status,\n            b.title,\n            b.author\n        FROM transactions t\n        INNER JOIN books b ON t.book_id = b.book_id\n        WHERE t.student_id = ?\n        ORDER BY t.date_borrowed DESC\n    ");
    $books_stmt->bind_param('i', $student_id);
    $books_stmt->execute();
    $books_result = $books_stmt->get_result();

    while ($row = $books_result->fetch_assoc()) {
        $borrowed_books[] = $row;
    }
    $books_stmt->close();
} catch (Exception $e) {
    logError('Student profile error: ' . $e->getMessage());
    $error = 'Unable to load your profile right now. Please try again.';
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
        .qr-download-btn {
            display: inline-block;
            margin-top: 14px;
            padding: 10px 18px;
            background: #141F52;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
        }
        .qr-download-btn:hover { background: #52618D; }
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
    
        .student-notification-wrap{position:relative;display:flex;align-items:center;margin-right:8px}
        .student-notification-bell{position:relative;width:40px;height:40px;border:1px solid #D2E2F6;border-radius:9px;background:#fff;color:#141F52;cursor:pointer}
        .student-notification-count{position:absolute;top:-4px;right:-4px;min-width:17px;height:17px;padding:0 4px;border-radius:999px;background:#F4F916;color:#141F52;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center}
        .student-notification-panel{position:absolute;right:0;top:48px;width:330px;max-width:calc(100vw - 30px);background:#fff;border:1px solid #D2E2F6;border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.18);display:none;z-index:1300;overflow:hidden;color:#202A44}
        .student-notification-panel.show{display:block}
        .student-notification-header{display:flex;justify-content:space-between;align-items:center;padding:12px 13px;border-bottom:1px solid #E7EEF7}
        .student-notification-header button{border:0;background:none;color:#52618D;font-size:11px;font-weight:700;cursor:pointer}
        .student-notification-item{padding:12px 13px;border-bottom:1px solid #EEF2F7}
        .student-notification-item.unread{background:#F3F7FC}
        .student-notification-title{font-size:12px;font-weight:800}
        .student-notification-message{font-size:12px;color:#52618D;line-height:1.4;margin-top:3px}
        .student-notification-time{font-size:10px;color:#8793A7;margin-top:5px}
        .student-notification-empty{padding:22px;text-align:center;color:#8793A7;font-size:12px}
        @media(max-width:700px){.student-notification-wrap{margin-right:4px}.student-notification-panel{right:-60px}}


        .header-brand-text{display:flex;flex-direction:column;line-height:1.1;}
        .header-brand-subtitle{display:block;margin-top:4px;font-size:11px;font-weight:600;color:#52618D;}
        .page-header{gap:10px;}
        @media(max-width:700px){.header-brand-text{font-size:16px;}.header-brand-subtitle{font-size:10px;}}
        
        .student-header-actions{display:flex;align-items:center;gap:4px;flex-shrink:0}
        .student-notification-wrap{margin:0!important}
        @media(max-width:700px){.student-header-actions{gap:4px}.student-notification-bell{width:38px;height:38px}.student-menu-name{max-width:120px}}


        .page-header{height:78px;padding:0 40px;box-sizing:border-box;position:sticky;top:0;z-index:900;background:#fff;}
        .student-header-actions{display:flex;align-items:center;gap:6px;flex-shrink:0;}
        .student-notification-wrap{margin:0!important;}
        @media(max-width:700px){.page-header{height:60px;padding:0 16px;}.student-header-actions{gap:4px;}}
        </style>
    <?php require_once __DIR__ . '/../includes/responsive.php'; ?>
</head>
<body class="student-app student-profile-page">
    <?php require_once __DIR__ . '/../includes/ui_feedback.php'; ?>
    <header class="page-header">
        <a href="/LibraryBorrowingSystem/student/borrow.php" class="header-brand">
            <img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo">
            <span class="header-brand-text">Jose Abad Santos High School<span class="header-brand-subtitle">Library Management System</span></span>
        </a>
        <div class="student-header-actions">
        <div class="student-notification-wrap">
    <button type="button" class="student-notification-bell" id="studentNotificationBell" aria-label="Notifications">
        <span>🔔</span><span class="student-notification-count" id="studentNotificationCount" style="display:none;">0</span>
    </button>
    <div class="student-notification-panel" id="studentNotificationPanel">
        <div class="student-notification-header"><strong>Notifications</strong><button type="button" id="studentMarkAllNotifications">Mark all read</button></div>
        <div id="studentNotificationList"><div class="student-notification-empty">Loading notifications...</div></div>
    </div>
</div>

<div class="student-menu">
            <button type="button" class="student-menu-toggle" id="studentMenuToggle" aria-haspopup="true" aria-expanded="false">
                <span class="student-menu-name"><?php echo $student ? htmlspecialchars($student['full_name']) : 'Student'; ?></span>
                <span class="student-menu-caret">▼</span>
            </button>
            <div class="student-dropdown" id="studentDropdown">
                <a href="/LibraryBorrowingSystem/student/profile.php" class="active">Profile</a>
                <?php if ($student_qr): ?>
                    <a href="/LibraryBorrowingSystem/student/borrow.php">Search Books</a>
                <?php endif; ?>
                <div class="dropdown-divider"></div>
                <a href="/LibraryBorrowingSystem/student/portal.php?logout=1">Logout</a>
            </div>
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
                            <div class="info-label">Section</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['student_group'] ?: 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Grade Level</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['year_level'] ?: 'N/A'); ?></div>
                        </div>
                        <?php if (in_array($student['year_level'], ['Grade 11', 'Grade 12'], true)): ?>
                            <div class="info-item">
                                <div class="info-label">Department / Strand</div>
                                <div class="info-value"><?php echo htmlspecialchars($student['department'] ?: 'N/A'); ?></div>
                            </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <div class="info-label">Contact</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Total Records</div>
                            <div class="info-value"><?php echo count($borrowed_books); ?></div>
                        </div>
                    </div>

                    <div class="qr-box">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($student['qr_code']); ?>" alt="Student QR Code">
                        <div class="qr-text"><?php echo htmlspecialchars($student['qr_code']); ?></div>
                        <a class="qr-download-btn" href="/LibraryBorrowingSystem/student/download_qr.php">Download QR Code</a>
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
                                    $badge_class = strtolower($book['status']);
                                    $badge_text = ucfirst($book['status']);
                                ?>
                                <div class="book-row">
                                    <div>
                                        <div class="book-title"><?php echo htmlspecialchars($book['title']); ?></div>
                                        <div class="book-meta">
                                            Author: <?php echo htmlspecialchars($book['author']); ?><br>
                                            Borrowed: <?php echo date('M d, Y', strtotime($book['date_borrowed'])); ?> •
                                            Return By: <?php echo date('M d, Y', strtotime($book['due_date'])); ?> (same day)
                                            <?php if (!empty($book['return_date'])): ?> • Returned: <?php echo date('M d, Y', strtotime($book['return_date'])); ?><?php endif; ?>
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
        <section class="card" id="reservations">
            <div class="card-header">My Reservations</div>
            <div class="card-body">
                <?php if (empty($reservations)): ?>
                    <div class="empty">You do not have any active reservations.</div>
                <?php else: ?>
                    <div style="display:grid;gap:10px;">
                        <?php foreach ($reservations as $reservation): ?>
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:13px;background:#F7F9FC;border:1px solid #D2E2F6;border-radius:9px;">
                                <div>
                                    <strong style="color:#202A44;"><?php echo htmlspecialchars($reservation['title']); ?></strong>
                                    <div style="font-size:12px;color:#52618D;margin-top:4px;">
                                        Book No: <?php echo htmlspecialchars($reservation['book_number']); ?> ·
                                        Reserved: <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($reservation['reserved_at']))); ?>
                                    </div>
                                </div>
                                <span class="badge <?php echo $reservation['status']==='ready' ? 'returned' : 'borrowed'; ?>">
                                    <?php echo htmlspecialchars(ucfirst($reservation['status'])); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

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

<script>
(function(){
  const bell=document.getElementById('studentNotificationBell');
  const panel=document.getElementById('studentNotificationPanel');
  const count=document.getElementById('studentNotificationCount');
  const list=document.getElementById('studentNotificationList');
  const markAll=document.getElementById('studentMarkAllNotifications');
  if(!bell||!panel||!list)return;
  function esc(v){const d=document.createElement('div');d.textContent=v??'';return d.innerHTML;}
  function relativeTime(v){const t=new Date(v.replace(' ','T')).getTime(),m=Math.floor(Math.max(0,Date.now()-t)/60000);if(m<1)return'Just now';if(m<60)return m+' min ago';const h=Math.floor(m/60);if(h<24)return h+' hr ago';return Math.floor(h/24)+' day(s) ago';}
  function load(){fetch('/LibraryBorrowingSystem/notifications.php?action=list',{credentials:'same-origin'}).then(r=>r.json()).then(d=>{if(!d.ok)return;const u=Number(d.unread||0);count.textContent=u>99?'99+':u;count.style.display=u?'flex':'none';if(!d.notifications.length){list.innerHTML='<div class="student-notification-empty">No notifications yet.</div>';return;}list.innerHTML=d.notifications.map(n=>`<div class="student-notification-item ${Number(n.is_read)===0?'unread':''}" data-id="${Number(n.notification_id)}"><div class="student-notification-title">${esc(n.title)}</div><div class="student-notification-message">${esc(n.message)}</div><div class="student-notification-time">${relativeTime(n.created_at)}</div></div>`).join('');list.querySelectorAll('.student-notification-item').forEach(el=>el.onclick=function(){fetch('/LibraryBorrowingSystem/notifications.php?action=read',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'notification_id='+encodeURIComponent(this.dataset.id)}).then(load);});}).catch(()=>{});}
  bell.addEventListener('click',e=>{e.stopPropagation();panel.classList.toggle('show');load();});
  panel.addEventListener('click',e=>e.stopPropagation());document.addEventListener('click',()=>panel.classList.remove('show'));
  markAll.addEventListener('click',()=>fetch('/LibraryBorrowingSystem/notifications.php?action=read_all',{method:'POST',credentials:'same-origin'}).then(load));
  load();setInterval(load,30000);
})();
</script>

</body>
</html>
