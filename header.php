<?php
/**
 * Shared Header - Jose Abad Santos High School Library Management System
 */
require_once __DIR__ . '/includes/notification_helper.php';

$headerUserId = (int)($_SESSION['user_id'] ?? 0);
?>
<style>
    .jashs-header {
        position: sticky;
        top: 0;
        z-index: 900;
        width: calc(100vw - 260px);
        min-height: 78px;
        margin: 0 0 24px 0;
        padding: 12px 20px;
        box-sizing: border-box;
        background: #141F52;
        color: #FEFEF9;
        display: flex;
        align-items: center;
        gap: 14px;
        border-bottom: 3px solid #F4F916;
        box-shadow: 0 3px 10px rgba(20,31,82,.22);
    }

    .jashs-header-brand {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
        flex: 1;
    }

    .jashs-header-brand img {
        width: 48px;
        height: 48px;
        object-fit: contain;
        border-radius: 50%;
        background: #FEFEF9;
        border: 2px solid #91B0E0;
        padding: 3px;
        flex-shrink: 0;
    }

    .jashs-header-title-wrap {
        min-width: 0;
    }

    .jashs-header-title {
        margin: 0;
        font-size: clamp(17px, 1.7vw, 23px);
        line-height: 1.1;
        font-weight: 800;
        letter-spacing: .2px;
    }

    .jashs-header-subtitle {
        margin: 4px 0 0;
        font-size: 12px;
        line-height: 1.2;
        color: #D2E2F6;
        font-weight: 600;
    }

    .jashs-notification-wrap {
        position: relative;
        flex-shrink: 0;
    }

    .jashs-notification-bell {
        position: relative;
        width: 42px;
        height: 42px;
        border: 1px solid rgba(255,255,255,.3);
        border-radius: 10px;
        background: rgba(255,255,255,.08);
        color: #fff;
        cursor: pointer;
        font-size: 18px;
    }

    .jashs-notification-bell:hover {
        background: rgba(255,255,255,.16);
    }

    .jashs-notification-count {
        position: absolute;
        top: -4px;
        right: -4px;
        min-width: 17px;
        height: 17px;
        padding: 0 4px;
        border-radius: 999px;
        display: none;
        align-items: center;
        justify-content: center;
        background: #F4F916;
        color: #141F52;
        font-size: 10px;
        font-weight: 800;
    }

    .jashs-notification-panel {
        position: absolute;
        right: 0;
        top: 50px;
        width: 340px;
        max-width: calc(100vw - 24px);
        max-height: 420px;
        overflow-y: auto;
        background: #fff;
        color: #202A44;
        border: 1px solid #D2E2F6;
        border-radius: 12px;
        box-shadow: 0 18px 50px rgba(0,0,0,.22);
        display: none;
    }

    .jashs-notification-panel.show { display: block; }

    .jashs-notification-head {
        padding: 13px 14px;
        display: flex;
        justify-content: space-between;
        gap: 10px;
        align-items: center;
        border-bottom: 1px solid #E7EEF7;
        position: sticky;
        top: 0;
        background: #fff;
        z-index: 1;
    }

    .jashs-notification-head button {
        border: 0;
        background: none;
        color: #52618D;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
    }

    .jashs-notification-item {
        padding: 12px 14px;
        border-bottom: 1px solid #EEF2F7;
        cursor: pointer;
    }

    .jashs-notification-item { transition: background .15s ease; cursor:pointer; }
    .jashs-notification-item:hover { background:#EEF4FA; }
    .jashs-notification-item.unread { background: #F3F7FC; }

    .jashs-notification-item strong {
        display: block;
        font-size: 12px;
        margin-bottom: 3px;
    }

    .jashs-notification-item p {
        margin: 0;
        color: #52618D;
        font-size: 12px;
        line-height: 1.4;
    }

    .jashs-notification-time {
        margin-top: 5px;
        color: #8793A7;
        font-size: 10px;
    }

    .jashs-notification-empty {
        padding: 24px 14px;
        text-align: center;
        color: #8793A7;
        font-size: 12px;
    }

    @media (max-width: 768px) {
        .jashs-header {
            width: 100vw;
            min-height: 64px;
            padding: 10px 14px;
            margin-bottom: 16px;
        }

        .jashs-header-brand img {
            width: 40px;
            height: 40px;
        }

        .jashs-header-title {
            font-size: 17px;
        }

        .jashs-header-subtitle {
            font-size: 11px;
        }

        .jashs-notification-panel {
            right: -8px;
        }
    }
</style>

<header class="jashs-header">
    <div class="jashs-header-brand">
        <img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo">
        <div class="jashs-header-title-wrap">
            <h1 class="jashs-header-title">Jose Abad Santos High School</h1>
        </div>
    </div>

    <?php if ($headerUserId > 0): ?>
    <div class="jashs-notification-wrap">
        <button type="button" class="jashs-notification-bell" id="headerNotificationBell" aria-label="Notifications">
            🔔
            <span class="jashs-notification-count" id="headerNotificationCount">0</span>
        </button>

        <div class="jashs-notification-panel" id="headerNotificationPanel">
            <div class="jashs-notification-head">
                <strong>Notifications</strong>
                <button type="button" id="headerMarkAllNotifications">Mark all read</button>
            </div>
            <div id="headerNotificationList">
                <div class="jashs-notification-empty">Loading notifications...</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</header>

<script>
(function () {
    const bell = document.getElementById('headerNotificationBell');
    const panel = document.getElementById('headerNotificationPanel');
    const count = document.getElementById('headerNotificationCount');
    const list = document.getElementById('headerNotificationList');
    const markAll = document.getElementById('headerMarkAllNotifications');

    if (!bell || !panel || !count || !list) return;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function relativeTime(value) {
        const time = new Date(String(value).replace(' ', 'T')).getTime();
        if (Number.isNaN(time)) return value;
        const minutes = Math.floor(Math.max(0, Date.now() - time) / 60000);
        if (minutes < 1) return 'Just now';
        if (minutes < 60) return minutes + ' min ago';
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return hours + ' hr ago';
        return Math.floor(hours / 24) + ' day(s) ago';
    }

    function loadNotifications() {
        fetch('/LibraryBorrowingSystem/notifications.php?action=list', { credentials: 'same-origin' })
            .then(response => response.json())
            .then(data => {
                if (!data.ok) return;

                const unread = Number(data.unread || 0);
                count.textContent = unread > 99 ? '99+' : unread;
                count.style.display = unread > 0 ? 'flex' : 'none';

                if (!data.notifications || data.notifications.length === 0) {
                    list.innerHTML = '<div class="jashs-notification-empty">No notifications yet.</div>';
                    return;
                }

                list.innerHTML = data.notifications.map(item => `
                    <a href="${escapeHtml(item.target_url || '#')}"
                       class="jashs-notification-item ${Number(item.is_read) === 0 ? 'unread' : ''}"
                       data-id="${Number(item.notification_id)}"
                       style="display:block;text-decoration:none;color:inherit;">
                        <strong>${escapeHtml(item.title)}</strong>
                        <p>${escapeHtml(item.message)}</p>
                        <div class="jashs-notification-time">${relativeTime(item.created_at)}</div>
                    </a>
                `).join('');

                list.querySelectorAll('.jashs-notification-item').forEach(item => {
                    item.addEventListener('click', function (event) {
                        const target = this.getAttribute('href') || '#';
                        if (!target || target === '#') return;

                        event.preventDefault();
                        fetch('/LibraryBorrowingSystem/notifications.php?action=read', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'notification_id=' + encodeURIComponent(this.dataset.id)
                        }).finally(() => {
                            window.location.href = target;
                        });
                    });
                });
            })
            .catch(() => {});
    }

    bell.addEventListener('click', function (event) {
        event.stopPropagation();
        panel.classList.toggle('show');
        loadNotifications();
    });

    panel.addEventListener('click', event => event.stopPropagation());
    document.addEventListener('click', () => panel.classList.remove('show'));

    if (markAll) {
        markAll.addEventListener('click', function () {
            fetch('/LibraryBorrowingSystem/notifications.php?action=read_all', {
                method: 'POST',
                credentials: 'same-origin'
            }).then(loadNotifications);
        });
    }

    loadNotifications();
    setInterval(loadNotifications, 30000);
})();
</script>
