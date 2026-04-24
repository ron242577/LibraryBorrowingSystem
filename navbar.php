<?php
/**
 * Vertical Sidebar Navigation - Library Borrowing System
 * Include in <body>: <?php include __DIR__ . '/navbar.php'; ?>
 */

$current_path = $_SERVER['REQUEST_URI'] ?? '';
$role = $_SESSION['role'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'User';

function isActiveNav($path) {
    $current_path = $_SERVER['REQUEST_URI'] ?? '';
    return strpos($current_path, $path) !== false ? 'active' : '';
}
?>
<style>
    :root {
        --sidebar-width: 260px;
        --sidebar-width-collapsed: 80px;
        --primary-color: #003366;
        --secondary-color: #8B0000;
        --transition-speed: 0.3s;
        --shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: var(--sidebar-width);
        height: 100vh;
        background: var(--primary-color);
        color: white;
        display: flex;
        flex-direction: column;
        box-shadow: var(--shadow);
        z-index: 999;
        overflow-y: auto;
        transition: width var(--transition-speed) ease;
    }

    .sidebar.collapsed {
        width: var(--sidebar-width-collapsed);
    }

    .sidebar-brand {
        padding: 20px 15px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 18px;
        font-weight: bold;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        transition: opacity var(--transition-speed);
        min-height: 64px;
        justify-content: center;
        background: var(--primary-color);
        box-sizing: border-box;
    }

    .sidebar.collapsed .sidebar-brand {
        padding: 15px;
        font-size: 24px;
    }

    .sidebar-brand-icon {
        font-size: 24px;
        line-height: 1;
        flex-shrink: 0;
    }

    .sidebar-brand-text {
        transition: opacity var(--transition-speed);
    }

    .sidebar.collapsed .sidebar-brand-text {
        display: none;
    }

    .hamburger-btn {
        position: fixed;
        top: 20px;
        left: 20px;
        width: 45px;
        height: 45px;
        background: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        display: none;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 6px;
        z-index: 1001;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        transition: all var(--transition-speed);
    }

    .hamburger-btn:hover {
        background: #f0f0f0;
        transform: scale(1.05);
    }

    .hamburger-line {
        width: 25px;
        height: 3px;
        background: #333;
        border-radius: 2px;
        transition: all var(--transition-speed);
    }

    .hamburger-btn.active .hamburger-line:nth-child(1) {
        transform: rotate(45deg) translateY(11px);
    }

    .hamburger-btn.active .hamburger-line:nth-child(2) {
        opacity: 0;
    }

    .hamburger-btn.active .hamburger-line:nth-child(3) {
        transform: rotate(-45deg) translateY(-11px);
    }

    .sidebar-menu {
        flex: 1;
        padding: 14px 0;
        overflow-y: auto;
        list-style: none;
        margin: 0;
    }

    .sidebar-item {
        display: flex;
        align-items: center;
    }

    .sidebar-link {
        flex: 1;
        color: white;
        text-decoration: none;
        padding: 13px 18px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 14px;
        font-weight: 500;
        transition: background-color var(--transition-speed), padding-left var(--transition-speed);
        position: relative;
        overflow: hidden;
    }

    .sidebar-link::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        width: 4px;
        background: white;
        transform: scaleY(0);
        transition: transform var(--transition-speed);
    }

    .sidebar-link:hover {
        background-color: rgba(255, 255, 255, 0.15);
        padding-left: 22px;
    }

    .sidebar-link:hover::before,
    .sidebar-link.active::before {
        transform: scaleY(1);
    }

    .sidebar-link.active {
        background-color: rgba(255, 255, 255, 0.22);
        font-weight: 700;
    }

    .sidebar-link-text {
        transition: opacity var(--transition-speed);
        white-space: nowrap;
    }

    .sidebar.collapsed .sidebar-link-text {
        display: none;
    }

    .sidebar-user {
        padding: 15px 18px;
        border-top: 1px solid rgba(255, 255, 255, 0.14);
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .sidebar-user-info {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .sidebar-user-name {
        font-size: 13px;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sidebar.collapsed .sidebar-user-name,
    .sidebar.collapsed .sidebar-user-role {
        display: none;
    }

    .sidebar-user-role {
        font-size: 11px;
        background: rgba(255, 255, 255, 0.2);
        padding: 4px 8px;
        border-radius: 12px;
        font-weight: 500;
    }

    .sidebar-logout {
        color: white;
        text-decoration: none;
        padding: 8px 10px;
        background-color: rgba(255, 255, 255, 0.2);
        border-radius: 5px;
        font-size: 12px;
        font-weight: 600;
        transition: background-color var(--transition-speed);
        border: 1px solid rgba(255, 255, 255, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        cursor: pointer;
    }

    .sidebar-logout:hover {
        background-color: rgba(255, 255, 255, 0.3);
        border-color: rgba(255, 255, 255, 0.5);
    }

    .sidebar.collapsed .sidebar-logout {
        padding: 8px;
    }

    .sidebar.collapsed .sidebar-logout span:last-child {
        display: none;
    }

    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        z-index: 998;
        animation: fadeIn var(--transition-speed);
    }

    .sidebar-overlay.active {
        display: block;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    body {
        margin: 0;
        padding: 0;
    }

    body.sidebar-normal main,
    body.sidebar-normal .main-content,
    body.sidebar-normal .content,
    body.sidebar-normal .container {
        transition: margin-left var(--transition-speed) ease;
    }

    body.sidebar-collapsed main,
    body.sidebar-collapsed .main-content,
    body.sidebar-collapsed .content,
    body.sidebar-collapsed .container {
        margin-left: var(--sidebar-width-collapsed);
        transition: margin-left var(--transition-speed) ease;
    }

    @media (min-width: 769px) {
        body {
            margin-left: var(--sidebar-width);
            transition: margin-left var(--transition-speed) ease;
        }

        body.sidebar-collapsed {
            margin-left: var(--sidebar-width-collapsed);
        }

        body.sidebar-mobile {
            margin-left: 0;
        }

        .sidebar-toggle-mobile {
            display: none;
        }
    }

    @media (max-width: 768px) {
        :root {
            --sidebar-width: 260px;
        }

        .hamburger-btn {
            display: flex;
        }

        .sidebar {
            transform: translateX(-100%);
            transition: transform var(--transition-speed) ease;
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .sidebar.collapsed {
            display: none;
        }

        main, .main-content, .content {
            margin-left: 0;
        }

        .sidebar-overlay.active {
            display: block;
        }
    }

    @media (max-width: 600px) {
        .hamburger-btn {
            width: 40px;
            height: 40px;
            top: 15px;
            left: 15px;
        }

        .hamburger-line {
            width: 20px;
        }

        .sidebar {
            width: 85vw;
            max-width: 260px;
        }

        .sidebar-link {
            padding: 12px 14px;
            font-size: 13px;
        }
    }
</style>

<div id="sidebarOverlay" class="sidebar-overlay"></div>

<button id="hamburgerBtn" class="hamburger-btn" aria-label="Toggle sidebar">
    <span class="hamburger-line"></span>
    <span class="hamburger-line"></span>
    <span class="hamburger-line"></span>
</button>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <span class="sidebar-brand-text">Arellano University Library</span>
    </div>

    <ul class="sidebar-menu">
        <?php if ($role === 'super_admin'): ?>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/superadmin/dashboard.php" class="sidebar-link <?php echo isActiveNav('/superadmin/dashboard.php'); ?>">
                    <span class="sidebar-link-text">Dashboard</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/superadmin/librarian_management.php" class="sidebar-link <?php echo isActiveNav('/superadmin/librarian_management.php'); ?>">
                    <span class="sidebar-link-text">Manage Librarians</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/superadmin/reports.php" class="sidebar-link <?php echo isActiveNav('/superadmin/reports.php'); ?>">
                    <span class="sidebar-link-text">Reports & Analytics</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/superadmin/student_records.php" class="sidebar-link <?php echo isActiveNav('/superadmin/student_records.php'); ?>">
                    <span class="sidebar-link-text">Student Records</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/add_student.php" class="sidebar-link <?php echo isActiveNav('/librarian/add_student.php'); ?>">
                    <span class="sidebar-link-text">Add Student</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/add_book.php" class="sidebar-link <?php echo isActiveNav('/librarian/add_book.php'); ?>">
                    <span class="sidebar-link-text">Add Book</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/search.php" class="sidebar-link <?php echo isActiveNav('/librarian/search.php'); ?>">
                    <span class="sidebar-link-text">Search Students</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/qr_borrow.php" class="sidebar-link <?php echo isActiveNav('/librarian/qr_borrow.php'); ?>">
                    <span class="sidebar-link-text">Borrow Book</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/overdue.php" class="sidebar-link <?php echo isActiveNav('/librarian/overdue.php'); ?>">
                    <span class="sidebar-link-text">Overdue Books</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/qr_return.php" class="sidebar-link <?php echo isActiveNav('/librarian/qr_return.php'); ?>">
                    <span class="sidebar-link-text">Return Book</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/inventory.php" class="sidebar-link <?php echo isActiveNav('/librarian/inventory.php'); ?>">
                    <span class="sidebar-link-text">Inventory</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/transactions.php" class="sidebar-link <?php echo isActiveNav('/librarian/transactions.php'); ?>">
                    <span class="sidebar-link-text">Transactions</span>
                </a>
            </li>

        <?php elseif ($role === 'librarian'): ?>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/dashboard.php" class="sidebar-link <?php echo isActiveNav('/librarian/dashboard.php'); ?>">
                    <span class="sidebar-link-text">Dashboard</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/add_student.php" class="sidebar-link <?php echo isActiveNav('/librarian/add_student.php'); ?>">
                    <span class="sidebar-link-text">Add Student</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/add_book.php" class="sidebar-link <?php echo isActiveNav('/librarian/add_book.php'); ?>">
                    <span class="sidebar-link-text">Add Book</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/search.php" class="sidebar-link <?php echo isActiveNav('/librarian/search.php'); ?>">
                    <span class="sidebar-link-text">Search Students</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/qr_borrow.php" class="sidebar-link <?php echo isActiveNav('/librarian/qr_borrow.php'); ?>">
                    <span class="sidebar-link-text">Borrow Book</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/overdue.php" class="sidebar-link <?php echo isActiveNav('/librarian/overdue.php'); ?>">
                    <span class="sidebar-link-text">Overdue Books</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/qr_return.php" class="sidebar-link <?php echo isActiveNav('/librarian/qr_return.php'); ?>">
                    <span class="sidebar-link-text">Return Book</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/inventory.php" class="sidebar-link <?php echo isActiveNav('/librarian/inventory.php'); ?>">
                    <span class="sidebar-link-text">Inventory</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/transactions.php" class="sidebar-link <?php echo isActiveNav('/librarian/transactions.php'); ?>">
                    <span class="sidebar-link-text">Transactions</span>
                </a>
            </li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <div class="sidebar-user-name" title="<?php echo htmlspecialchars($full_name); ?>">
                <?php echo htmlspecialchars($full_name); ?>
            </div>
            <div class="sidebar-user-role">
                <?php echo $role === 'super_admin' ? 'Admin' : 'Librarian'; ?>
            </div>
        </div>
        <a href="/LibraryBorrowingSystem/logout.php" class="sidebar-logout">
            <span>Logout</span>
        </a>
    </div>
</aside>

<script>
    (function() {
        'use strict';

        const hamburgerBtn = document.getElementById('hamburgerBtn');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const body = document.body;
        let isCollapsed = false;
        let isMobile = false;

        function initializeBodyClass() {
            checkMobile();
            if (isMobile) {
                body.classList.add('sidebar-mobile');
                body.classList.remove('sidebar-normal', 'sidebar-collapsed');
            } else {
                body.classList.remove('sidebar-mobile');
                body.classList.add('sidebar-normal');
                body.classList.remove('sidebar-collapsed');
            }
        }

        function checkMobile() {
            isMobile = window.innerWidth <= 768;
        }

        function updateBodyClass() {
            if (isMobile) {
                body.classList.add('sidebar-mobile');
                body.classList.remove('sidebar-normal', 'sidebar-collapsed');
            } else {
                body.classList.remove('sidebar-mobile');
                if (isCollapsed) {
                    body.classList.add('sidebar-collapsed');
                    body.classList.remove('sidebar-normal');
                } else {
                    body.classList.add('sidebar-normal');
                    body.classList.remove('sidebar-collapsed');
                }
            }
        }

        window.addEventListener('DOMContentLoaded', initializeBodyClass);

        hamburgerBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (isMobile) {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
                hamburgerBtn.classList.toggle('active');
                document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
            } else {
                sidebar.classList.toggle('collapsed');
                isCollapsed = sidebar.classList.contains('collapsed');
                hamburgerBtn.classList.toggle('active');
                updateBodyClass();
            }
        });

        overlay.addEventListener('click', function() {
            if (isMobile) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                hamburgerBtn.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        document.querySelectorAll('.sidebar-link').forEach(function(link) {
            link.addEventListener('click', function() {
                if (isMobile) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    hamburgerBtn.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });

        window.addEventListener('resize', function() {
            const wasMobile = isMobile;
            checkMobile();
            if (wasMobile !== isMobile) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                hamburgerBtn.classList.remove('active');
                document.body.style.overflow = '';
                if (isMobile) {
                    sidebar.classList.remove('collapsed');
                    isCollapsed = false;
                }
                updateBodyClass();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isMobile && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                hamburgerBtn.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    })();
</script>
