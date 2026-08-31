<?php
/**
 * Shared Sidebar Navigation - Jose Abad Santos High School Library Borrowing System
 */

$full_name = $_SESSION['full_name'] ?? 'User';

function isActiveNav($path) {
    $current_path = $_SERVER['REQUEST_URI'] ?? '';
    return strpos($current_path, $path) !== false ? 'active' : '';
}

function isActiveNavAny($paths) {
    foreach ($paths as $path) {
        if (strpos($_SERVER['REQUEST_URI'] ?? '', $path) !== false) {
            return 'active';
        }
    }
    return '';
}

$books_nav_paths = ['/admin/qr_transaction.php', '/admin/inventory.php'];
$books_open = isActiveNavAny($books_nav_paths) === 'active';
?>
<style>
    :root {
        --sidebar-width: 260px;
        --jas-navy: #141F52;
        --jas-blue: #52618D;
        --jas-sky: #91B0E0;
        --jas-light: #D2E2F6;
        --jas-yellow: #F4F916;
        --jas-white: #FEFEF9;
    }

    body {
        margin: 0;
        padding: 0;
    }

    .sidebar {
        position: fixed;
        inset: 0 auto 0 0;
        width: var(--sidebar-width);
        height: 100vh;
        background: var(--jas-navy);
        color: var(--jas-white);
        display: flex;
        flex-direction: column;
        z-index: 999;
        overflow-y: auto;
        box-shadow: 0 4px 14px rgba(20, 31, 82, .22);
        transition: transform .25s ease;
    }

    .sidebar-brand {
        min-height: 78px;
        padding: 14px 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 3px solid var(--jas-yellow);
        box-sizing: border-box;
    }

    .sidebar-brand img {
        width: 42px;
        height: 42px;
        object-fit: contain;
        border-radius: 50%;
        background: white;
        padding: 2px;
        flex-shrink: 0;
    }

    .sidebar-brand-text {
        font-size: 15px;
        line-height: 1.3;
        font-weight: 800;
    }

    .sidebar-menu {
        list-style: none;
        margin: 0;
        padding: 14px 0;
        flex: 1;
    }

    .sidebar-item { width: 100%; }

    .sidebar-link,
    .dropdown-link {
        color: var(--jas-white);
        text-decoration: none;
        display: flex;
        align-items: center;
        width: 100%;
        box-sizing: border-box;
        border: 0;
        font-family: inherit;
        cursor: pointer;
    }

    .sidebar-link {
        padding: 13px 18px;
        background: transparent;
        font-size: 14px;
        font-weight: 600;
        border-left: 4px solid transparent;
    }

    .sidebar-link:hover,
    .sidebar-link.active,
    .dropdown.open > .sidebar-link {
        background: rgba(145, 176, 224, .18);
        border-left-color: var(--jas-yellow);
    }

    .dropdown-toggle {
        justify-content: space-between;
        text-align: left;
    }

    .dropdown-icon {
        font-size: 11px;
        transition: transform .2s ease;
    }

    .dropdown.open .dropdown-icon { transform: rotate(180deg); }

    .dropdown-menu {
        list-style: none;
        margin: 0;
        padding: 0;
        display: none;
    }

    .dropdown.open .dropdown-menu { display: block; }

    .dropdown-link {
        padding: 10px 18px 10px 42px;
        color: var(--jas-light);
        font-size: 13px;
        border-left: 4px solid transparent;
    }

    .dropdown-link:hover,
    .dropdown-link.active {
        background: rgba(145, 176, 224, .14);
        color: white;
        border-left-color: var(--jas-yellow);
    }

    .sidebar-user {
        padding: 15px 18px;
        border-top: 1px solid rgba(255, 255, 255, .14);
    }

    .sidebar-user-label {
        font-size: 10px;
        color: var(--jas-light);
        text-transform: uppercase;
        font-weight: 700;
    }

    .sidebar-user-name {
        margin: 5px 0 8px;
        font-size: 13px;
        font-weight: 700;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sidebar-user-role {
        display: inline-block;
        padding: 4px 9px;
        border-radius: 999px;
        background: var(--jas-yellow);
        color: var(--jas-navy);
        font-size: 11px;
        font-weight: 800;
        margin-bottom: 10px;
    }

    .sidebar-logout {
        display: block;
        padding: 8px 10px;
        border: 1px solid var(--jas-sky);
        border-radius: 5px;
        color: white;
        text-decoration: none;
        text-align: center;
        font-size: 12px;
        font-weight: 700;
    }

    .sidebar-logout:hover {
        background: var(--jas-blue);
        border-color: var(--jas-yellow);
    }

    .hamburger-btn {
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1001;
        width: 42px;
        height: 42px;
        display: none;
        align-items: center;
        justify-content: center;
        background: white;
        color: var(--jas-navy);
        border: 0;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .16);
        cursor: pointer;
        font-size: 22px;
        font-weight: 800;
    }

    .sidebar-overlay {
        position: fixed;
        inset: 0;
        display: none;
        background: rgba(0, 0, 0, .55);
        z-index: 998;
    }

    @media (min-width: 769px) {
        body { margin-left: var(--sidebar-width); }
    }

    @media (max-width: 768px) {
        .hamburger-btn { display: flex; }
        .sidebar { transform: translateX(-100%); }
        .sidebar.active { transform: translateX(0); }
        .sidebar-overlay.active { display: block; }
        body { margin-left: 0 !important; }
    }

</style>

<div id="sidebarOverlay" class="sidebar-overlay"></div>
<button type="button" id="hamburgerBtn" class="hamburger-btn" aria-label="Open navigation">☰</button>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo">
        <span class="sidebar-brand-text">Library Management System</span>
    </div>

    <ul class="sidebar-menu">
        <li class="sidebar-item">
            <a href="/LibraryBorrowingSystem/admin/dashboard.php" class="sidebar-link <?php echo isActiveNav('/admin/dashboard.php'); ?>">Dashboard</a>
        </li>
        <li class="sidebar-item">
            <a href="/LibraryBorrowingSystem/admin/staff_management.php" class="sidebar-link <?php echo isActiveNav('/admin/staff_management.php'); ?>">Staff Management</a>
        </li>
        <li class="sidebar-item">
            <a href="/LibraryBorrowingSystem/admin/reports.php" class="sidebar-link <?php echo isActiveNav('/admin/reports.php'); ?>">Reports &amp; Analytics</a>
        </li>
        <li class="sidebar-item">
            <a href="/LibraryBorrowingSystem/admin/student_records.php" class="sidebar-link <?php echo isActiveNav('/admin/student_records.php'); ?>">Student Records</a>
        </li>
        <li class="sidebar-item dropdown <?php echo $books_open ? 'open' : ''; ?>">
            <button type="button" class="sidebar-link dropdown-toggle" aria-expanded="<?php echo $books_open ? 'true' : 'false'; ?>">
                <span>Circulation</span><span class="dropdown-icon">▼</span>
            </button>
            <ul class="dropdown-menu">
                <li><a href="/LibraryBorrowingSystem/admin/qr_transaction.php" class="dropdown-link <?php echo isActiveNav('/admin/qr_transaction.php'); ?>">QR Transactions</a></li>
                <li><a href="/LibraryBorrowingSystem/admin/inventory.php" class="dropdown-link <?php echo isActiveNav('/admin/inventory.php'); ?>">Cataloging</a></li>
            </ul>
        </li>
        <li class="sidebar-item">
            <a href="/LibraryBorrowingSystem/admin/transactions.php" class="sidebar-link <?php echo isActiveNav('/admin/transactions.php'); ?>">Transaction Records</a>
        </li>
        <li class="sidebar-item">
            <a href="/LibraryBorrowingSystem/admin/reservations.php" class="sidebar-link <?php echo isActiveNav('/admin/reservations.php'); ?>">Reservations</a>
        </li>
        <li class="sidebar-item">
            <a href="/LibraryBorrowingSystem/admin/backup_management.php" class="sidebar-link <?php echo isActiveNav('/admin/backup_management.php'); ?>">Backup &amp; Restore</a>
        </li>
    </ul>

    <div class="sidebar-user">
        <div class="sidebar-user-label">Signed in as</div>
        <div class="sidebar-user-role" title="<?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <a href="/LibraryBorrowingSystem/logout.php" class="sidebar-logout">Logout</a>
    </div>
</aside>

<script>
(function () {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const button = document.getElementById('hamburgerBtn');

    function setSidebarState(open) {
        sidebar.classList.toggle('active', open);
        overlay.classList.toggle('active', open);
        document.body.classList.toggle('sidebar-open', open && window.innerWidth <= 768);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    }

    function closeSidebar() {
        setSidebarState(false);
    }

    button.setAttribute('aria-expanded', 'false');
    button.addEventListener('click', function () {
        setSidebarState(!sidebar.classList.contains('active'));
    });

    overlay.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && sidebar.classList.contains('active')) {
            closeSidebar();
            button.focus();
        }
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) {
            closeSidebar();
        }
    });

    document.querySelectorAll('.dropdown-toggle').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            const dropdown = toggle.closest('.dropdown');
            dropdown.classList.toggle('open');
            toggle.setAttribute('aria-expanded', dropdown.classList.contains('open') ? 'true' : 'false');
        });
    });

    document.querySelectorAll('.sidebar a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 768) closeSidebar();
        });
    });
})();
</script>
