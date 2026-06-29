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

function isActiveNavAny($paths) {
    $current_path = $_SERVER['REQUEST_URI'] ?? '';
    foreach ($paths as $path) {
        if (strpos($current_path, $path) !== false) {
            return 'active';
        }
    }
    return '';
}

function isDropdownOpen($paths) {
    return isActiveNavAny($paths) === 'active' ? 'open' : '';
}

$books_nav_paths = [
    '/librarian/qr_borrow.php',
    '/librarian/qr_return.php',
    '/librarian/inventory.php'
];
?>
<style>
    :root {
        --sidebar-width: 260px;
        --sidebar-width-collapsed: 80px;
        --primary-color: #141F52;
        --secondary-color: #52618D;
        --accent-color: #F4F916;
        --sky-color: #91B0E0;
        --light-blue-color: #D2E2F6;
        --success-color: #B5D27A;
        --off-white-color: #FEFEF9;
        --transition-speed: 0.3s;
        --shadow: 0 4px 14px rgba(20, 31, 82, 0.22);
    }

    body {
        margin: 0;
        padding: 0;
    }

    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: var(--sidebar-width);
        height: 100vh;
        background: var(--primary-color);
        color: var(--off-white-color);
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
        border-bottom: 3px solid var(--accent-color);
        min-height: 64px;
        justify-content: center;
        background: var(--primary-color);
        box-sizing: border-box;
    }

    .sidebar-brand-logo {
        width: 42px;
        height: 42px;
        padding: 2px;
        object-fit: contain;
        flex-shrink: 0;
        border-radius: 50%;
        background: var(--off-white-color);
        border: 2px solid var(--sky-color);
    }

    .sidebar-brand-text {
        transition: opacity var(--transition-speed);
    }

    .sidebar.collapsed .sidebar-brand-text,
    .sidebar.collapsed .sidebar-link-text,
    .sidebar.collapsed .sidebar-user-name,
    .sidebar.collapsed .sidebar-user-role,
    .sidebar.collapsed .dropdown-icon,
    .sidebar.collapsed .dropdown-menu {
        display: none;
    }

    .sidebar.collapsed .sidebar-brand {
        padding-inline: 10px;
    }

    .hamburger-btn {
        position: fixed;
        top: 20px;
        left: 20px;
        width: 45px;
        height: 45px;
        background: var(--off-white-color);
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
        background: var(--light-blue-color);
        transform: scale(1.05);
    }

    .hamburger-line {
        width: 25px;
        height: 3px;
        background: var(--primary-color);
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
        color: var(--off-white-color);
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
        background: var(--accent-color);
        transform: scaleY(0);
        transition: transform var(--transition-speed);
    }

    .sidebar-link:hover {
        background-color: rgba(145, 176, 224, 0.18);
        padding-left: 22px;
    }

    .sidebar-link:hover::before,
    .sidebar-link.active::before {
        transform: scaleY(1);
    }

    .sidebar-link.active {
        background-color: var(--secondary-color);
        font-weight: 700;
    }

    .sidebar-link-text {
        transition: opacity var(--transition-speed);
        white-space: nowrap;
    }

    .dropdown {
        flex-direction: column;
        width: 100%;
    }

    .dropdown-toggle {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border: 0;
        background: transparent;
        text-align: left;
        cursor: pointer;
        font-family: inherit;
    }

    .dropdown.open > .dropdown-toggle,
    .dropdown-toggle:hover {
        background-color: rgba(145, 176, 224, 0.18);
    }

    .dropdown-menu {
        list-style: none;
        margin: 0;
        padding: 0;
        display: none;
        width: 100%;
    }

    .dropdown.open .dropdown-menu {
        display: block;
    }

    .dropdown-menu li {
        width: 100%;
    }

    .dropdown-link {
        display: block;
        width: 100%;
        padding: 10px 18px 10px 40px;
        font-size: 13px;
        color: var(--light-blue-color);
        text-decoration: none;
        transition: 0.2s;
        border-left: 2px solid transparent;
        box-sizing: border-box;
    }

    .dropdown-link:hover,
    .dropdown-link.active {
        background: rgba(145, 176, 224, 0.18);
        color: var(--off-white-color);
        border-left: 2px solid var(--accent-color);
        font-weight: 700;
    }

    .dropdown-icon {
        font-size: 12px;
        transition: transform 0.3s;
    }

    .dropdown.open .dropdown-icon {
        transform: rotate(180deg);
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

    .sidebar-user-label {
        font-size: 10px;
        font-weight: 700;
        color: var(--light-blue-color);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .sidebar-user-name {
        font-size: 13px;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sidebar-user-role {
        font-size: 11px;
        background: var(--accent-color);
        color: var(--primary-color);
        padding: 4px 8px;
        border-radius: 12px;
        font-weight: 500;
    }

    .sidebar-logout {
        color: var(--off-white-color);
        text-decoration: none;
        padding: 8px 10px;
        background-color: transparent;
        border-radius: 5px;
        font-size: 12px;
        font-weight: 600;
        transition: background-color var(--transition-speed);
        border: 1px solid var(--sky-color);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        cursor: pointer;
    }

    .sidebar-logout:hover {
        background-color: var(--secondary-color);
        border-color: var(--accent-color);
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

        main, .main-content, .content, .container {
            margin-left: 0 !important;
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
        <span class="sidebar-brand-text">Claro M. Recto High School</span>
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
                    <span class="sidebar-link-text">Report &amp; Analytics</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/superadmin/student_records.php" class="sidebar-link <?php echo isActiveNav('/superadmin/student_records.php'); ?>">
                    <span class="sidebar-link-text">Student Records</span>
                </a>
            </li>
            <li class="sidebar-item dropdown <?php echo isDropdownOpen($books_nav_paths); ?>">
                <button type="button" class="sidebar-link dropdown-toggle" aria-expanded="<?php echo isDropdownOpen($books_nav_paths) === 'open' ? 'true' : 'false'; ?>">
                    <span class="sidebar-link-text">Books</span>
                    <span class="dropdown-icon">▼</span>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a href="/LibraryBorrowingSystem/librarian/qr_borrow.php"
                           class="dropdown-link <?php echo isActiveNavAny(['/librarian/qr_borrow.php', '/librarian/qr_return.php']); ?>">
                            Transactions
                        </a>
                    </li>
                    <li>
                        <a href="/LibraryBorrowingSystem/librarian/inventory.php"
                           class="dropdown-link <?php echo isActiveNav('/librarian/inventory.php'); ?>">
                            Inventory
                        </a>
                    </li>
                </ul>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/transactions.php" class="sidebar-link <?php echo isActiveNav('/librarian/transactions.php'); ?>">
                    <span class="sidebar-link-text">Transaction Records</span>
                </a>
            </li>
        <?php elseif ($role === 'librarian'): ?>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/dashboard.php" class="sidebar-link <?php echo isActiveNav('/librarian/dashboard.php'); ?>">
                    <span class="sidebar-link-text">Dashboard</span>
                </a>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/superadmin/student_records.php" class="sidebar-link <?php echo isActiveNav('/superadmin/student_records.php'); ?>">
                    <span class="sidebar-link-text">Student Records</span>
                </a>
            </li>
            <li class="sidebar-item dropdown <?php echo isDropdownOpen($books_nav_paths); ?>">
                <button type="button" class="sidebar-link dropdown-toggle" aria-expanded="<?php echo isDropdownOpen($books_nav_paths) === 'open' ? 'true' : 'false'; ?>">
                    <span class="sidebar-link-text">Books</span>
                    <span class="dropdown-icon">▼</span>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a href="/LibraryBorrowingSystem/librarian/qr_borrow.php"
                           class="dropdown-link <?php echo isActiveNavAny(['/librarian/qr_borrow.php', '/librarian/qr_return.php']); ?>">
                            Transactions
                        </a>
                    </li>
                    <li>
                        <a href="/LibraryBorrowingSystem/librarian/inventory.php"
                           class="dropdown-link <?php echo isActiveNav('/librarian/inventory.php'); ?>">
                            Inventory
                        </a>
                    </li>
                </ul>
            </li>
            <li class="sidebar-item">
                <a href="/LibraryBorrowingSystem/librarian/transactions.php" class="sidebar-link <?php echo isActiveNav('/librarian/transactions.php'); ?>">
                    <span class="sidebar-link-text">Transaction Records</span>
                </a>
            </li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-user">
        <div class="sidebar-user-info">
            <div class="sidebar-user-label">Username:</div>
            <div class="sidebar-user-name" title="<?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?>
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

        function initializeBodyClass() {
            checkMobile();
            updateBodyClass();
        }

        function closeMobileSidebar() {
            if (isMobile) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                hamburgerBtn.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        window.addEventListener('DOMContentLoaded', initializeBodyClass);

        if (hamburgerBtn && sidebar && overlay) {
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

            overlay.addEventListener('click', closeMobileSidebar);
        }

        document.querySelectorAll('.sidebar-link').forEach(function(link) {
            link.addEventListener('click', function() {
                if (link.classList.contains('dropdown-toggle')) {
                    return;
                }
                closeMobileSidebar();
            });
        });

        window.addEventListener('resize', function() {
            const wasMobile = isMobile;
            checkMobile();
            if (wasMobile !== isMobile) {
                if (sidebar && overlay && hamburgerBtn) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    hamburgerBtn.classList.remove('active');
                }
                document.body.style.overflow = '';
                if (isMobile && sidebar) {
                    sidebar.classList.remove('collapsed');
                    isCollapsed = false;
                }
                updateBodyClass();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isMobile && sidebar && sidebar.classList.contains('active')) {
                closeMobileSidebar();
            }
        });
    })();

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.dropdown-toggle').forEach(function(toggle) {
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const parent = this.closest('.dropdown');
                if (!parent) return;

                document.querySelectorAll('.dropdown').forEach(function(dropdown) {
                    if (dropdown !== parent) {
                        dropdown.classList.remove('open');
                        const otherToggle = dropdown.querySelector('.dropdown-toggle');
                        if (otherToggle) {
                            otherToggle.setAttribute('aria-expanded', 'false');
                        }
                    }
                });

                parent.classList.toggle('open');
                this.setAttribute('aria-expanded', parent.classList.contains('open') ? 'true' : 'false');
            });
        });

        document.querySelectorAll('.dropdown-link.active').forEach(function(activeLink) {
            const dropdown = activeLink.closest('.dropdown');
            if (dropdown) {
                dropdown.classList.add('open');
                const toggle = dropdown.querySelector('.dropdown-toggle');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'true');
                }
            }
        });
    });
</script>
