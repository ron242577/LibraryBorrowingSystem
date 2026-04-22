<?php
/**
 * Vertical Sidebar Navigation - Library Borrowing System
 * Include in <body>: <?php include __DIR__ . '/navbar.php'; ?>
 */

$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? '';
$full_name = $_SESSION['full_name'] ?? 'User';
?>
<style>
    /* Root CSS Variables */
    :root {
        --sidebar-width: 260px;
        --sidebar-width-collapsed: 80px;
        --primary-color: #003366;
        --secondary-color: #8B0000;
        --transition-speed: 0.3s;
        --shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    /* Sidebar Styles */
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

    /* Sidebar Brand */
    .sidebar-brand {
        padding: 20px 15px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 18px;
        font-weight: bold;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        transition: opacity var(--transition-speed);
        min-height: 70px;
        justify-content: center;
    }

    .sidebar.collapsed .sidebar-brand {
        padding: 15px;
        font-size: 24px;
    }

    .sidebar-brand-text {
        transition: opacity var(--transition-speed);
    }

    .sidebar.collapsed .sidebar-brand-text {
        display: none;
    }

    /* Hamburger Button */
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

    /* Sidebar Menu */
    .sidebar-menu {
        flex: 1;
        padding: 20px 0;
        overflow-y: auto;
        list-style: none;
    }

    .sidebar-item {
        display: flex;
        align-items: center;
    }

    .sidebar-link {
        flex: 1;
        color: white;
        text-decoration: none;
        padding: 14px 18px;
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

    .sidebar-link:hover::before {
        transform: scaleY(1);
    }

    .sidebar-link.active {
        background-color: rgba(255, 255, 255, 0.2);
        font-weight: 600;
    }

    .sidebar-link.active::before {
        transform: scaleY(1);
    }

    .sidebar-link-icon {
        font-size: 18px;
        min-width: 24px;
        transition: transform var(--transition-speed);
    }

    .sidebar-link-text {
        transition: opacity var(--transition-speed);
        white-space: nowrap;
    }

    .sidebar.collapsed .sidebar-link-text {
        display: none;
    }

    /* Sidebar User Section */
    .sidebar-user {
        padding: 15px 18px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        display: flex;
        flex-direction: column;
        gap: 10px;
        transition: all var(--transition-speed);
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
        transition: opacity var(--transition-speed);
    }

    .sidebar.collapsed .sidebar-user-name {
        display: none;
    }

    .sidebar-user-role {
        font-size: 11px;
        background: rgba(255, 255, 255, 0.2);
        padding: 4px 8px;
        border-radius: 12px;
        font-weight: 500;
        transition: opacity var(--transition-speed);
    }

    .sidebar.collapsed .sidebar-user-role {
        display: none;
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

    .sidebar.collapsed .sidebar-logout span {
        display: none;
    }

    /* Overlay for mobile */
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

    /* Main content adjustment */
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

    /* Default for non-mobile */
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
    }

    /* Responsive Design - Tablet/Mobile */
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

        .sidebar-overlay {
            display: none;
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

    /* Utility: Hide toggle on desktop when sidebar is collapsed */
    @media (min-width: 769px) {
        .sidebar-toggle-mobile {
            display: none;
        }
    }
</style>

<!-- Sidebar Overlay -->
<div id="sidebarOverlay" class="sidebar-overlay"></div>

<!-- Hamburger Menu Button -->
<button id="hamburgerBtn" class="hamburger-btn" aria-label="Toggle sidebar">
    <span class="hamburger-line"></span>
    <span class="hamburger-line"></span>
    <span class="hamburger-line"></span>
</button>

<!-- Sidebar Navigation -->
<aside class="sidebar" id="sidebar">
    <!-- Brand -->
    <div class="sidebar-brand">
        <span style="font-size: 28px;">📚</span>
        <span class="sidebar-brand-text">Library</span>
    </div>

    <!-- Menu Items -->
    <ul class="sidebar-menu">
        <?php if ($role === 'super_admin'): ?>
            <!-- Super Admin Menu -->
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/superadmin/dashboard.php" 
                   class="sidebar-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">📊</span>
                    <span class="sidebar-link-text">Dashboard</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/superadmin/librarian_management.php" 
                   class="sidebar-link <?php echo $current_page === 'librarian_management.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">👥</span>
                    <span class="sidebar-link-text">Manage Librarians</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/superadmin/reports.php" 
                   class="sidebar-link <?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">📊</span>
                    <span class="sidebar-link-text">Reports & Analytics</span>
                </a>
            </li>
            
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/superadmin/student_records.php" 
                   class="sidebar-link <?php echo $current_page === 'student_records.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">👨‍🎓</span>
                    <span class="sidebar-link-text">Student Records</span>
                </a>
            </li>

        <?php elseif ($role === 'librarian'): ?>
            <!-- Librarian Menu -->
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/dashboard.php" 
                   class="sidebar-link <?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">📊</span>
                    <span class="sidebar-link-text">Dashboard</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/add_student.php" 
                   class="sidebar-link <?php echo $current_page === 'add_student.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">➕</span>
                    <span class="sidebar-link-text">Add Student</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/add_book.php" 
                   class="sidebar-link <?php echo $current_page === 'add_book.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">📖</span>
                    <span class="sidebar-link-text">Add Book</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/search.php" 
                   class="sidebar-link <?php echo $current_page === 'search.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">🔍</span>
                    <span class="sidebar-link-text">Search Students</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/qr_borrow.php" 
                   class="sidebar-link <?php echo $current_page === 'qr_borrow.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">📤</span>
                    <span class="sidebar-link-text">Borrow Book</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/overdue.php" 
                   class="sidebar-link <?php echo $current_page === 'overdue.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">⏰</span>
                    <span class="sidebar-link-text">Overdue Books</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/qr_return.php" 
                   class="sidebar-link <?php echo $current_page === 'qr_return.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">📥</span>
                    <span class="sidebar-link-text">Return Book</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/inventory.php" 
                   class="sidebar-link <?php echo $current_page === 'inventory.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">📦</span>
                    <span class="sidebar-link-text">Inventory</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/transactions.php" 
                   class="sidebar-link <?php echo $current_page === 'transactions.php' ? 'active' : ''; ?>">
                    <span class="sidebar-link-icon">📋</span>
                    <span class="sidebar-link-text">Transactions</span>
                </a>
            </li>

        <?php endif; ?>
    </ul>

    <!-- User Section -->
    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <div class="sidebar-user-name" title="<?php echo htmlspecialchars($full_name); ?>">
                <?php echo htmlspecialchars($full_name); ?>
            </div>
            <div class="sidebar-user-role">
                <?php echo $role === 'super_admin' ? '🔐 Admin' : '📚 Librarian'; ?>
            </div>
        </div>
        <a href="/LibraryBorrowingSystem/logout.php" class="sidebar-logout">
            <span>🚪</span>
            <span>Logout</span>
        </a>
    </div>
</aside>

<script>
    (function() {
        'use strict';

        // DOM Elements
        const hamburgerBtn = document.getElementById('hamburgerBtn');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const body = document.body;
        let isCollapsed = false;
        let isMobile = false;

        // Initialize body class on desktop
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

        // Check if device is mobile
        function checkMobile() {
            isMobile = window.innerWidth <= 768;
        }

        // Update body class based on sidebar state
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

        // Initialize on page load
        window.addEventListener('DOMContentLoaded', initializeBodyClass);

        // Toggle sidebar on hamburger click
        hamburgerBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (isMobile) {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
                hamburgerBtn.classList.toggle('active');
            } else {
                sidebar.classList.toggle('collapsed');
                isCollapsed = sidebar.classList.contains('collapsed');
                hamburgerBtn.classList.toggle('active');
                updateBodyClass();
            }
        });

        // Close sidebar when overlay is clicked (mobile)
        overlay.addEventListener('click', function() {
            if (isMobile) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                hamburgerBtn.classList.remove('active');
            }
        });

        // Close sidebar when a link is clicked (mobile)
        const sidebarLinks = document.querySelectorAll('.sidebar-link');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', function() {
                if (isMobile) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    hamburgerBtn.classList.remove('active');
                }
            });
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            const wasMobile = isMobile;
            checkMobile();

            // Reset sidebar state when switching between mobile and desktop
            if (wasMobile !== isMobile) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                hamburgerBtn.classList.remove('active');
                if (isMobile) {
                    sidebar.classList.remove('collapsed');
                    isCollapsed = false;
                }
                updateBodyClass();
            }
        });

        // Close sidebar when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isMobile && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                hamburgerBtn.classList.remove('active');
            }
        });

        // Prevent scrolling when sidebar is open on mobile
        function toggleBodyScroll(disable) {
            if (disable) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }

        overlay.addEventListener('click', () => toggleBodyScroll(false));
        hamburgerBtn.addEventListener('click', function() {
            if (isMobile) {
                const isActive = sidebar.classList.contains('active');
                toggleBodyScroll(isActive);
            }
        });
    })();
</script>
