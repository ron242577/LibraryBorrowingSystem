<?php
/**
 * Shared Header - Jose Abad Santos High School Book Borrowing
 * Include immediately after navbar.php in pages that use the sidebar.
 */
?>
<style>
    :root {
        --jashs-navy: #141F52;
        --jashs-blue: #52618D;
        --jashs-sky: #91B0E0;
        --jashs-light-blue: #D2E2F6;
        --jashs-yellow: #F4F916;
        --jashs-green: #B5D27A;
        --jashs-white: #FEFEF9;
    }

    .au-system-header {
        position: sticky;
        top: 0;
        z-index: 900;
        width: 100%;
        min-height: 64px;
        margin: 0 0 24px 0;
        background: var(--jashs-navy);
        color: var(--jashs-white);
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 10px;
        padding: 10px 18px;
        box-sizing: border-box;
        border-radius: 0;
        border-bottom: 4px solid var(--jashs-yellow);
        box-shadow: 0 3px 10px rgba(20, 31, 82, 0.22);
        transition: none !important;
        animation: none !important;
        transform: none !important;
    }

    .au-system-header img {
        width: 38px;
        height: 38px;
        object-fit: contain;
        background: var(--jashs-white);
        border: 2px solid var(--jashs-sky);
        border-radius: 50%;
        padding: 3px;
        box-shadow: 0 1px 5px rgba(0, 0, 0, 0.18);
        flex-shrink: 0;
        transition: none !important;
        animation: none !important;
    }

    .au-system-header-title {
        margin: 0;
        font-size: clamp(17px, 1.8vw, 22px);
        font-weight: 800;
        letter-spacing: 0.2px;
        line-height: 1.15;
        text-align: left;
        text-shadow: none;
        transition: none !important;
        animation: none !important;
    }

    @media (max-width: 768px) {
        .au-system-header {
            min-height: 60px;
            margin-top: 0;
            padding: 9px 14px 9px 72px;
            gap: 9px;
        }

        .au-system-header img {
            width: 34px;
            height: 34px;
        }
    }

    @media (max-width: 480px) {
        .au-system-header-title {
            font-size: 16px;
        }
    }
</style>

<?php require_once __DIR__ . '/includes/responsive.php'; ?>

<header class="au-system-header">
    <img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo">
    <h1 class="au-system-header-title">Jose Abad Santos High School Book Borrowing</h1>
</header>

<?php require_once __DIR__ . '/includes/ui_feedback.php'; ?>
