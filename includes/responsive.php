<?php
/**
 * Global responsive overrides for the JASHS Library Borrowing System.
 * Loaded after each page's own CSS so these rules can safely normalize
 * layouts across desktop, tablet, and small-phone widths.
 */
if (defined('JASHS_RESPONSIVE_INCLUDED')) {
    return;
}
define('JASHS_RESPONSIVE_INCLUDED', true);
?>
<style id="jashs-responsive-css">
    html {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
        -webkit-text-size-adjust: 100%;
        text-size-adjust: 100%;
    }

    body {
        width: 100%;
        max-width: 100%;
        overflow-x: hidden;
    }

    *, *::before, *::after {
        box-sizing: border-box;
    }

    img, svg, video, canvas, iframe {
        max-width: 100%;
    }

    img, video {
        height: auto;
    }

    input, select, textarea, button {
        max-width: 100%;
        font-family: inherit;
    }

    textarea {
        resize: vertical;
    }

    .container,
    .content,
    .main-content,
    .page-content,
    .section,
    .card,
    .panel,
    .filter-section,
    .table-container,
    .table-wrapper,
    .table-responsive {
        min-width: 0;
    }

    .table-wrapper,
    .table-responsive,
    .table-container,
    .stu-tx-table {
        width: 100%;
        max-width: 100%;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior-inline: contain;
    }

    .table-wrapper table,
    .table-responsive table,
    .table-container table,
    .stu-tx-table table {
        max-width: none;
    }

    .modal,
    .modal-overlay,
    .qr-modal,
    .qr-modal-overlay,
    .verification-modal,
    .jashs-confirm-overlay {
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    .modal-content,
    .modal-box,
    .modal-card,
    .qr-modal-content,
    .verification-card,
    .verification-modal-content,
    .jashs-confirm-dialog {
        max-width: calc(100vw - 24px) !important;
        max-height: calc(100dvh - 24px) !important;
        overflow-y: auto;
    }

    .form-row > *,
    .form-grid > *,
    .grid > *,
    .stats-grid > *,
    .metrics-grid > *,
    .dashboard-grid > *,
    .info-grid > *,
    .book-details-grid > *,
    .summary-grid > * {
        min-width: 0;
    }

    #qr-reader,
    #qr-reader-student,
    #qr-reader-book,
    .scanner-wrapper video,
    .scanner-box video {
        width: 100% !important;
        max-width: 100% !important;
    }

    #qr-reader__scan_region,
    #qr-reader-student__scan_region,
    #qr-reader-book__scan_region {
        max-width: 100% !important;
    }

    #qr-reader img,
    #qr-reader-student img,
    #qr-reader-book img {
        max-width: 100% !important;
    }

    .sidebar-open {
        overflow: hidden !important;
        touch-action: none;
    }

    /* Tablet and below */
    @media (max-width: 992px) {
        .container {
            width: 100% !important;
            max-width: 100% !important;
        }

        .charts-grid,
        .grid-2 {
            grid-template-columns: 1fr !important;
        }

        .chart-wrapper,
        .chart-container canvas {
            max-width: 100% !important;
        }
    }

    /* Mobile */
    @media (max-width: 768px) {
        body {
            margin-left: 0 !important;
        }

        .container {
            margin-left: auto !important;
            margin-right: auto !important;
            padding-left: 14px !important;
            padding-right: 14px !important;
        }

        .sidebar {
            width: min(86vw, 300px) !important;
            max-width: 300px;
        }

        .hamburger-btn {
            top: max(12px, env(safe-area-inset-top)) !important;
            left: max(12px, env(safe-area-inset-left)) !important;
            width: 44px !important;
            height: 44px !important;
        }

        .au-system-header {
            min-height: 64px !important;
            padding-left: 70px !important;
            padding-right: 12px !important;
        }

        .au-system-header-title {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .dashboard-grid,
        .stats-grid,
        .metrics-grid,
        .filter-grid,
        .form-row,
        .form-row.three,
        .form-grid,
        .info-grid,
        .book-details-grid,
        .summary-grid,
        .step-indicator,
        .remove-choice-grid {
            grid-template-columns: 1fr !important;
        }

        .toolbar,
        .inventory-toolbar,
        .filter-actions,
        .button-group,
        .form-actions,
        .success-actions,
        .action-buttons,
        .modal-buttons,
        .modal-actions,
        .book-modal-actions,
        .scanner-controls,
        .controls-row,
        .scanner-header,
        .page-actions,
        .modal-mode-header {
            flex-wrap: wrap !important;
        }

        .toolbar form,
        .filter-actions form {
            min-width: 0 !important;
            width: 100%;
        }

        .modal-content,
        .modal-box,
        .modal-card,
        .qr-modal-content,
        .verification-card,
        .verification-modal-content {
            width: calc(100vw - 24px) !important;
            margin: 12px auto !important;
            padding-left: 18px !important;
            padding-right: 18px !important;
        }

        .book-modal-content,
        .book-details-modal-content {
            max-width: calc(100vw - 24px) !important;
            max-height: calc(100dvh - 24px) !important;
        }

        .details-qr-row {
            flex-direction: column !important;
            align-items: flex-start !important;
        }

        .details-qr-row img,
        .qr-modal-img,
        .qr-box img,
        .qr-display img {
            max-width: min(220px, 100%) !important;
            height: auto !important;
        }

        .scanner-wrapper,
        .scanner-box {
            padding-left: 10px !important;
            padding-right: 10px !important;
        }

        input,
        select,
        textarea {
            font-size: 16px !important;
        }

        button,
        .btn,
        a.btn,
        .backup-btn,
        .btn-submit,
        .btn-back,
        .btn-login {
            min-height: 44px;
        }

        .table-wrapper,
        .table-responsive,
        .table-container,
        .stu-tx-table {
            border-radius: 8px;
        }

        .table-wrapper table,
        .table-responsive table,
        .table-container table,
        .stu-tx-table table {
            min-width: 680px;
        }

        .student-app .page-header {
            padding-left: 12px !important;
            padding-right: 12px !important;
            gap: 8px;
        }

        .student-app .header-brand {
            min-width: 0;
            flex: 1 1 auto;
            overflow: hidden;
        }

        .student-app .header-brand img {
            flex: 0 0 auto;
        }

        .student-app .header-brand-text {
            display: block;
            min-width: 0;
            max-width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .student-app .student-menu {
            flex: 0 0 auto;
            min-width: 0;
        }

        .student-app .student-dropdown {
            min-width: 0 !important;
            width: min(220px, calc(100vw - 24px));
            max-width: calc(100vw - 24px);
        }

        .student-app .book-row,
        .student-app .book-item-header {
            min-width: 0;
        }
    }

    /* Small phones */
    @media (max-width: 480px) {
        .container {
            padding-left: 10px !important;
            padding-right: 10px !important;
            margin-top: 16px !important;
        }

        .au-system-header {
            padding-left: 64px !important;
            gap: 7px !important;
        }

        .au-system-header img {
            width: 32px !important;
            height: 32px !important;
        }

        .au-system-header-title {
            font-size: 14px !important;
            line-height: 1.25 !important;
        }

        .card,
        .section,
        .filter-section,
        .welcome-section,
        .backup-section {
            border-radius: 8px !important;
        }

        .card-body,
        .section,
        .filter-section,
        .welcome-section,
        .backup-section {
            padding-left: 14px !important;
            padding-right: 14px !important;
        }

        .modal,
        .modal-overlay,
        .qr-modal,
        .qr-modal-overlay,
        .verification-modal,
        .jashs-confirm-overlay {
            padding: 8px !important;
        }

        .modal-content,
        .modal-box,
        .modal-card,
        .qr-modal-content,
        .verification-card,
        .verification-modal-content,
        .jashs-confirm-dialog {
            width: calc(100vw - 16px) !important;
            max-width: calc(100vw - 16px) !important;
            max-height: calc(100dvh - 16px) !important;
            margin: 8px auto !important;
        }

        .modal-header,
        .modal-body,
        .modal-footer,
        .add-modal-body,
        .verification-body,
        .verification-actions {
            padding-left: 14px !important;
            padding-right: 14px !important;
        }

        .modal-buttons,
        .modal-actions,
        .form-actions,
        .button-group,
        .success-actions,
        .filter-actions,
        .scanner-controls,
        .controls-row {
            flex-direction: column !important;
            align-items: stretch !important;
        }

        .modal-buttons > *,
        .modal-actions > *,
        .form-actions > *,
        .button-group > *,
        .success-actions > *,
        .filter-actions > *,
        .scanner-controls > *,
        .controls-row > * {
            width: 100% !important;
            max-width: 100% !important;
        }

        .student-app .page-header {
            height: 58px !important;
        }

        .student-app {
            padding-top: 58px !important;
        }

        .student-app .header-brand img {
            width: 34px !important;
            height: 34px !important;
            object-fit: contain;
        }

        .student-app .header-brand-text {
            font-size: 11.5px !important;
        }

        .student-app .student-menu-toggle {
            min-height: 40px;
            padding: 7px 8px !important;
            gap: 5px !important;
        }

        .student-app .student-menu-name {
            max-width: 72px !important;
            font-size: 11px !important;
        }

        .student-portal-page {
            padding: 12px !important;
        }

        .student-portal-page .login-page {
            min-height: calc(100dvh - 24px) !important;
        }

        .student-portal-page .welcome-section {
            margin-bottom: 20px !important;
        }

        .student-portal-page .welcome-icon {
            width: 82px !important;
            height: 82px !important;
        }

        .student-portal-page .welcome-section h1 {
            font-size: 26px !important;
        }

        .student-portal-page .form-content,
        .admin-login-page .login-container {
            padding-left: 16px !important;
            padding-right: 16px !important;
        }

        .student-register-page {
            padding: 12px !important;
        }

        .student-register-page .card-body {
            padding: 18px 14px !important;
        }

        .student-profile-page .profile-top {
            align-items: flex-start !important;
        }

        .student-profile-page .avatar {
            width: 56px !important;
            height: 56px !important;
            font-size: 28px !important;
            flex: 0 0 56px;
        }

        .student-borrow-page .search-form {
            flex-direction: column !important;
        }

        .student-borrow-page .search-form input,
        .student-borrow-page .search-form button {
            width: 100% !important;
            min-width: 0 !important;
        }

        .student-borrow-page .book-item {
            padding: 12px !important;
        }

        .student-borrow-page .book-item,
        .student-borrow-page .book-info,
        .student-borrow-page .book-meta,
        .student-borrow-page .book-title {
            min-width: 0;
            overflow-wrap: anywhere;
        }
    }

    /* Very narrow devices */
    @media (max-width: 360px) {
        .sidebar {
            width: 90vw !important;
        }

        .au-system-header-title {
            font-size: 13px !important;
        }

        .student-app .header-brand-text {
            max-width: 120px;
        }

        .student-app .student-menu-name {
            max-width: 55px !important;
        }

        .jashs-toast-container {
            left: 8px !important;
            right: 8px !important;
            bottom: max(8px, env(safe-area-inset-bottom)) !important;
            width: auto !important;
        }
    }

    /* Landscape phones: keep dialogs and scanners usable */
    @media (max-height: 520px) and (orientation: landscape) {
        .modal,
        .modal-overlay,
        .qr-modal,
        .qr-modal-overlay,
        .verification-modal,
        .jashs-confirm-overlay {
            align-items: flex-start !important;
            padding-top: 8px !important;
            padding-bottom: 8px !important;
        }

        .modal-content,
        .modal-box,
        .modal-card,
        .qr-modal-content,
        .verification-card,
        .verification-modal-content,
        .jashs-confirm-dialog {
            max-height: calc(100dvh - 16px) !important;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            scroll-behavior: auto !important;
            animation-duration: .01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: .01ms !important;
        }
    }
</style>
