<?php
/**
 * Shared UI polish + responsive layer for the Student and Teacher portals.
 * Loaded AFTER each page's own <style> so it refines (not replaces) their styles.
 * Scoped to body.student-app / body.teacher-app so admin pages are not affected.
 */
?>
<style id="portal-ui">
body.student-app, body.teacher-app {
    --pu-navy:#141F52; --pu-blue:#52618D; --pu-sky:#D2E2F6; --pu-mist:#EEF3FA; --pu-bg:#F3F7FC;
    --pu-card:#fff; --pu-ink:#202A44; --pu-muted:#5B6890; --pu-line:#E2EAF5; --pu-yellow:#F4F916;
    --pu-green-bg:#E7F4D4; --pu-green:#2F4B12; --pu-red-bg:#FBE4E7; --pu-red:#8E1F2E;
    --pu-shadow:0 1px 2px rgba(20,31,82,.05),0 6px 20px rgba(20,31,82,.07);
    --pu-radius:16px;
    font-family:'Inter','Segoe UI',system-ui,-apple-system,Roboto,sans-serif;
    background:var(--pu-bg); color:var(--pu-ink);
    -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;
    padding-top:0 !important;               /* header is sticky, no extra offset */
    padding-bottom:48px;
}
body.dark.student-app, body.dark.teacher-app {
    --pu-bg:#0d132d; --pu-card:#18213f; --pu-ink:#f4f7ff; --pu-muted:#aebbdb; --pu-line:#2d3a62; --pu-mist:#222d4d;
    --pu-sky:#3c4b72; --pu-green-bg:#233a1c; --pu-green:#c9efa0; --pu-red-bg:#4a1f29; --pu-red:#ffc2cb;
    --pu-shadow:0 1px 2px rgba(0,0,0,.3),0 8px 24px rgba(0,0,0,.28);
}
:where(body.student-app, body.teacher-app) *:focus-visible{outline:3px solid #F4F916;outline-offset:2px;border-radius:8px}

/* ---------- Header ---------- */
body.student-app .page-header, body.teacher-app .page-header{
    position:-webkit-sticky;position:sticky;top:0;left:auto;right:auto;z-index:1000;height:72px;padding:0 clamp(14px,3vw,40px);
    background:rgba(255,255,255,.94);-webkit-backdrop-filter:saturate(1.4) blur(10px);backdrop-filter:saturate(1.4) blur(10px);
    border-bottom:3px solid var(--pu-yellow);box-shadow:0 4px 18px rgba(20,31,82,.08);gap:12px;
}
body.dark .page-header{background:rgba(24,33,63,.94)}
.page-header .header-brand{min-width:0;flex:1 1 auto}
.page-header .header-brand img{height:44px;width:44px;border-radius:50%;flex-shrink:0}
.page-header .header-brand-text{min-width:0;font-size:clamp(14px,1.6vw,18px);line-height:1.15}
.page-header .header-brand-text,.page-header .header-brand-subtitle{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
body.dark .student-menu-toggle,body.dark .teacher-menu-toggle{background:#2a3a6e !important;border:1px solid #3c4b72 !important}
.results-section > div[style*="color"]{color:var(--pu-muted) !important}
body.dark .header-brand-text{color:#f4f7ff}body.dark .header-brand-subtitle{color:#aebbdb}
.student-menu-toggle,.teacher-menu-toggle{min-height:42px;border-radius:999px !important;padding:6px 14px !important}
.student-menu-toggle:hover,.teacher-menu-toggle:hover{background:var(--pu-blue) !important}
.student-notification-bell,.teacher-notification-bell{width:42px !important;height:42px !important;border-radius:50% !important;display:grid;place-items:center;transition:background .15s}
.student-notification-bell:hover,.teacher-notification-bell:hover{background:var(--pu-mist) !important}
.student-dropdown,.teacher-dropdown{border-radius:14px !important;border:1px solid var(--pu-line) !important;box-shadow:0 18px 50px rgba(20,31,82,.2) !important;padding:8px !important;overflow:hidden}
.student-dropdown a,.teacher-dropdown a{border-radius:10px !important;padding:11px 14px !important;border-left:0 !important}
.student-dropdown a:hover,.student-dropdown a.active,.teacher-dropdown a:hover,.teacher-dropdown a.active{background:var(--pu-mist) !important;box-shadow:inset 3px 0 0 var(--pu-yellow)}
.student-dropdown .dropdown-divider,.teacher-dropdown .dropdown-divider{margin:6px 4px !important}
.theme-switch{font-size:14px !important;border-radius:10px !important;margin:6px 0 !important;width:100% !important}
body.dark .student-dropdown,body.dark .teacher-dropdown,body.dark .student-notification-panel,body.dark .teacher-notification-panel{background:#18213f !important;color:#f4f7ff;border-color:#2d3a62 !important}
body.dark .student-dropdown a,body.dark .teacher-dropdown a{color:#f4f7ff}
body.dark .theme-switch{background:#222d4d !important;color:#f4f7ff !important;border-color:#3c4b72 !important}
body.dark .student-notification-bell,body.dark .teacher-notification-bell{background:#222d4d !important;border-color:#3c4b72 !important;color:#fff !important}
body.dark .student-notification-item.unread,body.dark .teacher-notification-item.unread{background:#222d4d}
body.dark .student-notification-message,body.dark .teacher-notification-message{color:#aebbdb}

/* ---------- Page container + titles ---------- */
.container{max-width:1200px;margin:clamp(18px,3vw,32px) auto;padding:0 clamp(14px,3vw,24px)}
.header h1,.page-title{font-size:clamp(24px,3.2vw,32px);letter-spacing:-.4px;color:var(--pu-ink) !important;margin:0 0 6px}
.header p,.page-subtitle{color:var(--pu-muted) !important;font-size:14.5px;line-height:1.5}
.header{margin-bottom:22px !important}

/* ---------- Cards ---------- */
.search-section,.results-section,.student-info-section,.teacher-info-section,.book-details,.card{
    background:var(--pu-card);border:1px solid var(--pu-line);border-radius:var(--pu-radius) !important;box-shadow:var(--pu-shadow) !important;color:var(--pu-ink)}
.search-section{padding:18px !important;margin-bottom:20px !important}
.results-section{padding:clamp(16px,2.4vw,26px) !important}
.results-section h2,.student-info-section h2,.teacher-info-section h2{font-size:19px !important;color:var(--pu-ink) !important;border-bottom:1px solid var(--pu-line) !important;padding-bottom:14px !important}
.card-header{background:transparent !important;border-bottom:1px solid var(--pu-line) !important;color:var(--pu-ink) !important}

/* ---------- Search + filters ---------- */
.search-form{display:grid !important;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px !important}
.search-form input{grid-column:1 / -1;min-width:0 !important;width:100%}
.search-form input,.search-form select{
    height:48px;padding:0 16px !important;border:1.5px solid var(--pu-line) !important;border-radius:12px !important;font-size:15px !important;
    background-color:var(--pu-card) !important;color:var(--pu-ink) !important;transition:border-color .15s,box-shadow .15s}
.search-form input,.search-form input:focus{padding-left:44px !important;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%2352618D' stroke-width='2.2' stroke-linecap='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='M20 20l-3.5-3.5'/%3E%3C/svg%3E") !important;background-repeat:no-repeat !important;background-position:15px center !important;background-color:var(--pu-card) !important}
.search-form select{width:100%;min-width:0 !important;appearance:none;-webkit-appearance:none;padding-right:38px !important;cursor:pointer;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2352618D' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 14px center}
.search-form input:focus,.search-form select:focus{border-color:var(--pu-navy) !important;box-shadow:0 0 0 4px rgba(244,249,22,.45) !important;outline:0}
body.dark .search-form input:focus,body.dark .search-form select:focus{border-color:#91B0E0 !important}
body.dark .search-form select option{background:#18213f;color:#f4f7ff}

/* ---------- Book list -> responsive card grid ---------- */
.results-list{display:grid !important;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr));gap:12px !important;max-height:min(70vh,720px) !important;padding:2px 4px 4px 2px;scrollbar-width:thin;scrollbar-color:var(--pu-sky) transparent}
.book-item{position:relative;padding:14px !important;background:var(--pu-card) !important;border:1.5px solid var(--pu-line) !important;border-radius:14px !important;min-height:76px;
    transition:border-color .15s,box-shadow .15s,transform .15s !important;gap:12px !important}
.book-item:hover,.book-item.active{transform:translateY(-2px) !important;border-color:var(--pu-navy) !important;background:var(--pu-card) !important;box-shadow:0 8px 22px rgba(20,31,82,.14)}
.book-item.active{box-shadow:0 0 0 3px rgba(244,249,22,.7),0 8px 22px rgba(20,31,82,.14)}
.book-item.unavailable{opacity:.62}
.book-item.unavailable:hover{transform:none !important;box-shadow:none;border-color:var(--pu-line) !important}
.book-icon{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:var(--pu-mist);font-size:22px !important}
.book-info{min-width:0}
.book-title{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.3;font-size:14.5px !important;color:var(--pu-ink) !important}
.book-author{margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--pu-muted) !important}
.book-availability{border-radius:999px !important;padding:4px 10px !important;white-space:nowrap}
.book-availability.available{background:var(--pu-green-bg) !important;color:var(--pu-green) !important}
.book-availability.unavailable{background:var(--pu-red-bg) !important;color:var(--pu-red) !important}
.empty-results,.no-selection{padding:42px 16px;text-align:center;color:var(--pu-muted);min-height:0 !important}

/* ---------- Book details panel ---------- */
.book-details{padding:clamp(16px,2.4vw,26px) !important;margin-top:20px}
.book-details-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr)) !important;gap:12px !important}
.detail-item,.info-item{background:var(--pu-mist) !important;border:1px solid var(--pu-line);border-radius:12px !important;padding:12px 14px !important}
.detail-label,.info-label{color:var(--pu-muted) !important}
.detail-value,.info-value{color:var(--pu-ink) !important;overflow-wrap:anywhere}
body.dark .detail-item,body.dark .info-item{border-color:#3c4b72}

/* ---------- Info / profile ---------- */
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,190px),1fr)) !important;gap:12px !important}
.profile-top,.student-header,.teacher-header{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.avatar,.student-avatar,.teacher-avatar{flex-shrink:0}
.profile-name{font-size:clamp(20px,2.6vw,26px);letter-spacing:-.3px;color:var(--pu-ink) !important;overflow-wrap:anywhere}
.qr-box,.qr-section{background:var(--pu-mist) !important;border:2px dashed var(--pu-sky) !important;border-radius:16px !important;padding:22px !important;text-align:center}
.qr-box img,.qr-section img{width:min(100%,170px);height:auto;background:#fff;border-radius:12px;padding:10px;box-shadow:var(--pu-shadow)}
.qr-text{overflow-wrap:anywhere;color:var(--pu-ink) !important}
.qr-download-btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 20px !important;border-radius:999px !important;background:var(--pu-navy) !important;color:#fff !important;text-decoration:none;font-weight:700;transition:background .15s,transform .15s}
.qr-download-btn:hover{background:var(--pu-blue) !important;transform:translateY(-1px)}
.books-list{display:grid;gap:10px}
.book-row{display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--pu-mist) !important;border:1px solid var(--pu-line) !important;border-radius:14px !important;padding:14px 16px !important}
.book-row .book-title{-webkit-line-clamp:unset;display:block}
.book-meta{color:var(--pu-muted) !important;line-height:1.55;overflow-wrap:anywhere}
.badge{border-radius:999px !important;white-space:nowrap}
.error-box{border-radius:14px !important}
body.dark .card-body,body.dark .book-row .book-title,body.dark .profile-name{color:#f4f7ff}

/* ---------- Modals ---------- */
.modal-overlay{-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);padding:16px;overflow-y:auto}
.modal-content{border-radius:20px !important;max-height:calc(100dvh - 32px);overflow-y:auto !important;background:var(--pu-card) !important;color:var(--pu-ink)}
.modal-title{color:var(--pu-ink) !important}.modal-message{color:var(--pu-muted) !important}
.modal-btn{min-height:46px;border-radius:12px !important}
body.dark .modal-body{background:transparent}

/* ---------- Tablet ---------- */
@media (max-width:900px){
    .search-form{grid-template-columns:repeat(2,minmax(0,1fr))}
    .results-list{max-height:none !important}
}
/* ---------- Phone ---------- */
@media (max-width:600px){
    body.student-app .page-header,body.teacher-app .page-header{height:60px;padding:0 12px;gap:8px}
    .page-header .header-brand{gap:8px}
    .page-header .header-brand img{height:36px;width:36px}
    .page-header .header-brand-text{font-size:14px}
    .page-header .header-brand-subtitle{display:none}
    .student-menu-name,.teacher-menu-name{max-width:84px !important}
    .student-menu-toggle,.teacher-menu-toggle{padding:6px 10px !important;font-size:12.5px !important;min-height:38px}
    .student-notification-bell,.teacher-notification-bell{width:38px !important;height:38px !important}
    /* notification panel becomes a full-width sheet under the header */
    .student-notification-panel,.teacher-notification-panel{position:fixed !important;left:10px !important;right:10px !important;top:68px !important;width:auto !important;max-width:none !important;max-height:calc(100dvh - 90px);overflow-y:auto}
    .student-dropdown,.teacher-dropdown{position:fixed !important;right:10px;top:68px !important;min-width:min(240px,calc(100vw - 20px))}
    .container{margin-top:16px}
    .search-section{padding:14px !important}
    .search-form{grid-template-columns:1fr 1fr;gap:10px !important}
    .search-form select{font-size:14px !important;padding-right:30px !important;padding-left:12px !important}
    .results-list{grid-template-columns:1fr}
    .book-item{padding:12px !important;min-height:68px}
    .book-icon{width:40px;height:40px}
    .profile-top,.student-header,.teacher-header{flex-direction:column;align-items:flex-start;gap:12px}
    .info-grid,.book-details-grid{grid-template-columns:repeat(2,minmax(0,1fr)) !important;gap:10px !important}
    .info-item{padding:10px 12px !important}.info-value{font-size:14px !important}
    .card-body{padding:16px !important}.card-header{padding:14px 16px !important}
    .book-row{flex-direction:column;align-items:flex-start}
    .book-row .badge{align-self:flex-start}
    .qr-download-btn{width:100%}
    .modal-overlay.show{align-items:flex-end !important;padding:0}
    .modal-content{width:100% !important;max-width:none !important;border-radius:22px 22px 0 0 !important;max-height:92dvh;animation:puSheet .28s ease !important}
    .modal-header{padding:28px 20px 18px !important}
    .modal-footer{flex-direction:column-reverse}
    .modal-footer .modal-btn{width:100%}
}
@media (max-width:360px){.search-form{grid-template-columns:1fr}.student-menu-name,.teacher-menu-name{max-width:64px !important}}
@keyframes puSheet{from{transform:translateY(40px);opacity:0}to{transform:none;opacity:1}}
@media (prefers-reduced-motion:reduce){*{animation-duration:.01ms !important;transition-duration:.01ms !important}}
</style>
