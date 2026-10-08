<?php
/**
 * Global responsive helpers.
 * Loaded by admin shell, student/teacher portals, login and registration pages.
 * Screen-only rules keep tables usable, forms comfortable and overlays within the viewport.
 */
?>
<style id="global-responsive-layer">
html, body { max-width: 100%; }
html { overflow-x: hidden; }
body { overflow-x: clip; }
*, *::before, *::after { box-sizing: border-box; }
img, canvas, video, svg { max-width: 100%; }
img { height: auto; }
button, input, select, textarea { font: inherit; }
button, a, input, select, textarea { -webkit-tap-highlight-color: transparent; }

/* Keep tables readable without forcing the whole page wider than the viewport. */
.table-responsive,
.table-wrapper,
.table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto !important;
    overflow-y: visible;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}
.table-responsive > table,
.table-wrapper > table,
.table-wrap > table,
.table-responsive table,
.table-wrapper table,
.table-wrap table {
    min-width: 640px;
}

/* Shared overlays */
.modal-box,
.modal-content,
.jashs-confirm-dialog {
    max-width: min(720px, calc(100vw - 32px));
    max-height: calc(100dvh - 32px);
    overflow-y: auto;
}

/* ------------------------------------------------------------------------- */
/* Librarian/admin shell                                                     */
/* ------------------------------------------------------------------------- */
body:has(#sidebar) {
    min-width: 0;
}
body:has(#sidebar) .container,
body:has(#sidebar) .attendance-container,
body:has(#sidebar) main.container,
body:has(#sidebar) main.report {
    width: min(100%, 1400px);
    max-width: 100%;
    margin-left: auto;
    margin-right: auto;
    padding-left: clamp(14px, 2.2vw, 24px);
    padding-right: clamp(14px, 2.2vw, 24px);
}
body:has(#sidebar) .page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
}
body:has(#sidebar) .page-header > * { min-width: 0; }
body:has(#sidebar) .page-header h1,
body:has(#sidebar) .page-header h2 {
    font-size: clamp(22px, 3vw, 28px);
    line-height: 1.2;
    overflow-wrap: anywhere;
}
body:has(#sidebar) .page-header p { line-height: 1.5; }
body:has(#sidebar) .stats-grid,
body:has(#sidebar) .metrics-grid,
body:has(#sidebar) .summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 14px;
}
body:has(#sidebar) .filter-grid,
body:has(#sidebar) .form-row,
body:has(#sidebar) .form-grid {
    min-width: 0;
}
body:has(#sidebar) .filter-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
body:has(#sidebar) .form-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}
body:has(#sidebar) .form-row.three {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
body:has(#sidebar) .form-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
}
body:has(#sidebar) .form-grid > button,
body:has(#sidebar) .form-grid > .btn {
    width: auto;
    min-width: 160px;
    white-space: nowrap;
}
body:has(#sidebar) .form-group,
body:has(#sidebar) .filter-group { min-width: 0; }
body:has(#sidebar) .filter-actions,
body:has(#sidebar) .actions,
body:has(#sidebar) .rules-actions,
body:has(#sidebar) .modal-buttons,
body:has(#sidebar) .scanner-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}
body:has(#sidebar) .filter-actions > *,
body:has(#sidebar) .actions > *,
body:has(#sidebar) .rules-actions > *,
body:has(#sidebar) .modal-buttons > * { max-width: 100%; }
body:has(#sidebar) .section-title-row,
body:has(#sidebar) .table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
body:has(#sidebar) .table-section,
body:has(#sidebar) .table-card,
body:has(#sidebar) .section,
body:has(#sidebar) .card {
    min-width: 0;
}
body:has(#sidebar) .table-wrapper,
body:has(#sidebar) .table-wrap,
body:has(#sidebar) .table-responsive {
    overflow-x: auto !important;
}
body:has(#sidebar) .modal-overlay,
body:has(#sidebar) .qr-modal-overlay {
    overflow-y: auto;
    padding: 16px;
}
body:has(#sidebar) .modal-content,
body:has(#sidebar) .qr-modal-content,
body:has(#sidebar) .modal-box {
    width: min(100%, 720px);
    max-width: calc(100vw - 32px);
}

/* ------------------------------------------------------------------------- */
/* Attendance kiosk                                                         */
/* ------------------------------------------------------------------------- */
body.attendance-page .attendance-container,
body.attendance-page .kiosk-nav {
    width: min(100%, 760px);
    max-width: 100%;
}
body.attendance-page .attendance-container { padding-left: 16px; padding-right: 16px; }
body.attendance-page .card,
body.attendance-page .scanner-wrapper,
body.attendance-page .status { min-width: 0; }
body.attendance-page #attendance-reader,
body.attendance-page #attendance-reader > div { max-width: 100%; }

/* ------------------------------------------------------------------------- */
/* Student / teacher portal                                                  */
/* ------------------------------------------------------------------------- */
body.student-app .container,
body.teacher-app .container {
    width: 100%;
    max-width: 1200px;
    min-width: 0;
}
body.student-app .account-grid,
body.teacher-app .account-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
body.student-app .account-field,
body.teacher-app .account-field,
body.student-app .password-form,
body.teacher-app .password-form { min-width: 0; }

/* ------------------------------------------------------------------------- */
/* Tablet                                                                    */
/* ------------------------------------------------------------------------- */
@media (max-width: 1024px) {
    body:has(#sidebar) .container,
    body:has(#sidebar) .attendance-container,
    body:has(#sidebar) main.container,
    body:has(#sidebar) main.report {
        padding-left: 16px;
        padding-right: 16px;
    }
    body:has(#sidebar) .filter-grid,
    body:has(#sidebar) .form-row.three {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    body.student-app .account-grid,
    body.teacher-app .account-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

/* ------------------------------------------------------------------------- */
/* Mobile                                                                    */
/* ------------------------------------------------------------------------- */
@media (max-width: 768px) {
    .jashs-header { width: 100%; }

    body:has(#sidebar) .sidebar {
        width: min(280px, 86vw);
    }
    body:has(#sidebar) .hamburger-btn {
        width: 40px;
        height: 40px;
        top: 10px;
        left: 10px;
        font-size: 20px;
    }
    body.sidebar-open { overflow: hidden; }
    body:has(#sidebar) .sidebar-brand {
        padding: 12px 14px;
        min-height: 70px;
    }
    body:has(#sidebar) .sidebar-brand-text {
        font-size: 14px;
        line-height: 1.25;
    }
    body:has(#sidebar) .sidebar-link {
        min-height: 44px;
        padding: 11px 14px;
    }
    body:has(#sidebar) .dropdown-link {
        padding-left: 48px !important;
    }

    body:has(#sidebar) .container,
    body:has(#sidebar) .attendance-container,
    body:has(#sidebar) main.container,
    body:has(#sidebar) main.report {
        width: 100%;
        margin-top: 16px;
        padding-left: 12px;
        padding-right: 12px;
    }
    body:has(#sidebar) .page-header {
        margin-bottom: 16px;
        padding: 18px;
    }
    body:has(#sidebar) .page-header h1,
    body:has(#sidebar) .page-header h2 { font-size: 22px; }
    body:has(#sidebar) .stats-grid,
    body:has(#sidebar) .metrics-grid,
    body:has(#sidebar) .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    body:has(#sidebar) .filter-grid,
    body:has(#sidebar) .form-row,
    body:has(#sidebar) .form-row.three,
    body:has(#sidebar) .form-grid {
        grid-template-columns: 1fr;
    }
    body:has(#sidebar) .filter-actions,
    body:has(#sidebar) .actions,
    body:has(#sidebar) .rules-actions,
    body:has(#sidebar) .modal-buttons {
        align-items: stretch;
    }
    body:has(#sidebar) .filter-actions > *,
    body:has(#sidebar) .actions > *,
    body:has(#sidebar) .rules-actions > * {
        flex: 1 1 auto;
    }
    body:has(#sidebar) .section,
    body:has(#sidebar) .card,
    body:has(#sidebar) .table-section,
    body:has(#sidebar) .table-card {
        border-radius: 12px;
    }
    body:has(#sidebar) .section,
    body:has(#sidebar) .card,
    body:has(#sidebar) .table-card,
    body:has(#sidebar) .filter-section,
    body:has(#sidebar) .page-header {
        padding: 16px;
    }
    body:has(#sidebar) .table-wrapper,
    body:has(#sidebar) .table-wrap,
    body:has(#sidebar) .table-responsive {
        margin-left: -1px;
        margin-right: -1px;
    }
    body:has(#sidebar) .modal-content,
    body:has(#sidebar) .qr-modal-content,
    body:has(#sidebar) .modal-box {
        width: 100%;
        max-width: none;
        border-radius: 18px;
    }

    body.attendance-page .kiosk-nav {
        padding-left: 12px;
        padding-right: 12px;
        margin-top: 4px;
    }
    body.attendance-page .attendance-container {
        margin-top: 12px;
        padding-left: 12px;
        padding-right: 12px;
    }
    body.attendance-page .card { padding: 16px; border-radius: 14px; }
    body.attendance-page .card-head { flex-direction: column; align-items: stretch; }
    body.attendance-page .clock { text-align: left; }
    body.attendance-page .status { padding: 14px; gap: 10px; }
    body.attendance-page .scanner-wrapper { padding: 12px; }

    body.student-app .account-grid,
    body.teacher-app .account-grid { grid-template-columns: 1fr; }
    body.student-app .account-profile-preview,
    body.teacher-app .account-profile-preview { align-items: flex-start; }
    body.student-app .account-actions,
    body.teacher-app .account-actions { width: 100%; }
    body.student-app .account-btn,
    body.teacher-app .account-btn { width: 100%; }
}

@media (max-width: 600px) {
    .table-responsive > table,
    .table-wrapper > table,
    .table-wrap > table,
    .table-responsive table,
    .table-wrapper table,
    .table-wrap table { min-width: 590px; }

    body:has(#sidebar) .stats-grid,
    body:has(#sidebar) .metrics-grid,
    body:has(#sidebar) .summary-grid { gap: 8px; }
    body:has(#sidebar) .page-header { padding: 14px; }
    body:has(#sidebar) .filter-actions > *,
    body:has(#sidebar) .actions > *,
    body:has(#sidebar) .rules-actions > * { width: 100%; }

    body.student-app .container,
    body.teacher-app .container { padding-left: 12px; padding-right: 12px; }
}

@media (max-width: 420px) {
    body:has(#sidebar) .stats-grid,
    body:has(#sidebar) .metrics-grid,
    body:has(#sidebar) .summary-grid { grid-template-columns: 1fr; }

    body.student-app .pd-stats,
    body.teacher-app .pd-stats { grid-template-columns: 1fr; }
    body.student-app .search-form,
    body.teacher-app .search-form { grid-template-columns: 1fr !important; }
    body.student-app .info-grid,
    body.teacher-app .info-grid,
    body.student-app .book-details-grid,
    body.teacher-app .book-details-grid { grid-template-columns: 1fr !important; }
    body.student-app .pd-actions .pd-btn,
    body.teacher-app .pd-actions .pd-btn { flex-basis: 100%; }
}


/* ------------------------------------------------------------------------- */
/* Dashboard mobile centering                                                */
/* Keep the welcome / QR hero balanced on narrow screens.                   */
/* ------------------------------------------------------------------------- */
@media (max-width: 768px) {
    body.student-app .pd-wrap,
    body.teacher-app .pd-wrap {
        width: 100%;
        margin-left: auto;
        margin-right: auto;
    }

    body.student-app .pd-wrap .pd-hero,
    body.teacher-app .pd-wrap .pd-hero {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 16px;
        text-align: center;
        padding: 22px 16px;
    }

    body.student-app .pd-wrap .pd-hero > div:first-child,
    body.teacher-app .pd-wrap .pd-hero > div:first-child {
        width: 100%;
        min-width: 0;
    }

    body.student-app .pd-wrap .pd-hero h1,
    body.teacher-app .pd-wrap .pd-hero h1 {
        font-size: clamp(24px, 8vw, 32px);
        line-height: 1.15;
        margin-bottom: 8px;
    }

    body.student-app .pd-wrap .pd-chips,
    body.teacher-app .pd-wrap .pd-chips {
        justify-content: center;
        gap: 2px 12px;
        margin-bottom: 14px;
    }

    body.student-app .pd-wrap .pd-actions,
    body.teacher-app .pd-wrap .pd-actions {
        width: 100%;
        justify-content: center;
    }

    body.student-app .pd-wrap .pd-actions .pd-btn,
    body.teacher-app .pd-wrap .pd-actions .pd-btn {
        min-width: 0;
        flex: 1 1 0;
    }

    body.student-app .pd-wrap .pd-idcard,
    body.teacher-app .pd-wrap .pd-idcard {
        width: min(100%, 340px);
        margin: 0 auto;
    }

    body.student-app .pd-wrap .pd-idcard img,
    body.teacher-app .pd-wrap .pd-idcard img {
        width: 100%;
        height: auto;
    }

    body:has(#sidebar) .ld {
        width: 100%;
        margin-left: auto;
        margin-right: auto;
    }

    body:has(#sidebar) .ld .ld-hero {
        text-align: center;
        align-items: center;
        justify-content: center;
    }

    body:has(#sidebar) .ld .ld-hero > div:first-child {
        width: 100%;
    }

    body:has(#sidebar) .ld .ld-actions {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 420px) {
    body.student-app .pd-wrap .pd-hero,
    body.teacher-app .pd-wrap .pd-hero {
        padding: 18px 12px;
        gap: 14px;
    }

    body.student-app .pd-wrap .pd-idcard,
    body.teacher-app .pd-wrap .pd-idcard {
        grid-template-columns: 92px minmax(0, 1fr);
        gap: 10px;
        padding: 9px;
    }

    body.student-app .pd-wrap .pd-idcard .qr-tap-note,
    body.teacher-app .pd-wrap .pd-idcard .qr-tap-note {
        font-size: 11px;
    }

    body.student-app .pd-wrap .pd-idcard .pd-idcode,
    body.teacher-app .pd-wrap .pd-idcard .pd-idcode {
        font-size: 10px;
    }

    body:has(#sidebar) .ld .ld-hero {
        padding-left: 14px;
        padding-right: 14px;
    }

    body:has(#sidebar) .ld .ld-actions .ld-btn {
        flex-basis: 100%;
    }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: .001ms !important;
        transition-duration: .001ms !important;
        scroll-behavior: auto !important;
    }
}
</style>
