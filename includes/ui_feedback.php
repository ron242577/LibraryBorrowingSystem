<?php
/**
 * Shared UI Feedback
 * - Bottom-right auto-dismiss notifications (toasts)
 * - Custom confirmation modal (replaces native browser confirm dialogs)
 */
if (defined('JASHS_UI_FEEDBACK_INCLUDED')) {
    return;
}
define('JASHS_UI_FEEDBACK_INCLUDED', true);
?>
<style>
    .jashs-toast-container {
        position: fixed;
        right: 22px;
        bottom: 22px;
        z-index: 99999;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 10px;
        width: min(390px, calc(100vw - 32px));
        pointer-events: none;
    }

    .jashs-toast {
        width: 100%;
        background: #ffffff;
        color: #202A44;
        border: 1px solid #D2E2F6;
        border-left: 5px solid #141F52;
        border-radius: 10px;
        box-shadow: 0 12px 30px rgba(20, 31, 82, 0.20);
        padding: 14px 16px;
        display: flex;
        align-items: flex-start;
        gap: 11px;
        opacity: 0;
        transform: translateY(18px);
        transition: opacity .22s ease, transform .22s ease;
        pointer-events: auto;
    }

    .jashs-toast.show {
        opacity: 1;
        transform: translateY(0);
    }

    .jashs-toast.success { border-left-color: #567D1F; }
    .jashs-toast.error { border-left-color: #BB5716; }
    .jashs-toast.warning { border-left-color: #C7A500; }
    .jashs-toast.info { border-left-color: #141F52; }

    .jashs-toast-icon {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 28px;
        font-size: 14px;
        font-weight: 900;
        background: #EDF3FA;
        color: #141F52;
    }

    .jashs-toast.success .jashs-toast-icon { background: #EDF5DD; color: #344E15; }
    .jashs-toast.error .jashs-toast-icon { background: #FBE8DC; color: #7A3A0E; }
    .jashs-toast.warning .jashs-toast-icon { background: #FBFDCB; color: #5C5F05; }

    .jashs-toast-content { flex: 1; min-width: 0; }
    .jashs-toast-title { font-size: 13px; font-weight: 800; margin-bottom: 2px; }
    .jashs-toast-message { font-size: 13px; line-height: 1.45; color: #52618D; overflow-wrap: anywhere; }
    .jashs-toast-close {
        border: 0 !important;
        background: transparent !important;
        color: #52618D !important;
        box-shadow: none !important;
        padding: 0 !important;
        width: 24px !important;
        min-width: 24px !important;
        height: 24px !important;
        line-height: 24px !important;
        font-size: 19px !important;
        font-weight: 500 !important;
        cursor: pointer;
        transform: none !important;
    }

    .jashs-confirm-overlay {
        position: fixed;
        inset: 0;
        z-index: 100000;
        background: rgba(9, 16, 45, .58);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .jashs-confirm-overlay.show { display: flex; }

    .jashs-confirm-dialog {
        width: min(430px, 100%);
        background: #fff;
        border-radius: 14px;
        overflow: hidden;
        box-shadow: 0 22px 60px rgba(0, 0, 0, .28);
        animation: jashsModalIn .16s ease-out;
    }

    @keyframes jashsModalIn {
        from { opacity: 0; transform: translateY(12px) scale(.985); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .jashs-confirm-header {
        background: #141F52;
        color: #fff;
        border-bottom: 4px solid #F4F916;
        padding: 19px 22px;
    }

    .jashs-confirm-header h3 { margin: 0; font-size: 19px; line-height: 1.3; }
    .jashs-confirm-body { padding: 22px; }
    .jashs-confirm-message { margin: 0; color: #52618D; font-size: 14px; line-height: 1.6; }
    .jashs-confirm-actions {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 0 22px 22px;
    }

    .jashs-confirm-btn {
        border: 0;
        border-radius: 8px;
        padding: 10px 18px;
        font-size: 13px;
        font-weight: 800;
        cursor: pointer;
    }

    .jashs-confirm-cancel { background: #E7EEF7; color: #202A44; }
    .jashs-confirm-primary { background: #141F52; color: #fff; }
    .jashs-confirm-danger { background: #B42318; color: #fff; }

    @media (max-width: 600px) {
        .jashs-toast-container { right: 16px; bottom: 16px; width: calc(100vw - 32px); }
        .jashs-confirm-actions { flex-direction: column-reverse; }
        .jashs-confirm-btn { width: 100%; }
    }
</style>

<div id="jashsToastContainer" class="jashs-toast-container" aria-live="polite" aria-atomic="true"></div>

<div id="jashsConfirmOverlay" class="jashs-confirm-overlay" role="dialog" aria-modal="true" aria-labelledby="jashsConfirmTitle">
    <div class="jashs-confirm-dialog">
        <div class="jashs-confirm-header">
            <h3 id="jashsConfirmTitle">Please Confirm</h3>
        </div>
        <div class="jashs-confirm-body">
            <p id="jashsConfirmMessage" class="jashs-confirm-message"></p>
        </div>
        <div class="jashs-confirm-actions">
            <button type="button" id="jashsConfirmCancel" class="jashs-confirm-btn jashs-confirm-cancel">Cancel</button>
            <button type="button" id="jashsConfirmOk" class="jashs-confirm-btn jashs-confirm-primary">Confirm</button>
        </div>
    </div>
</div>

<script>
(function () {
    if (window.JASHSFeedbackReady) return;
    window.JASHSFeedbackReady = true;

    function toastIcon(type) {
        if (type === 'success') return '✓';
        if (type === 'error') return '!';
        if (type === 'warning') return '!';
        return 'i';
    }

    function toastTitle(type) {
        if (type === 'success') return 'Success';
        if (type === 'error') return 'Error';
        if (type === 'warning') return 'Notice';
        return 'Information';
    }

    window.showToast = function (message, type, duration, title) {
        const container = document.getElementById('jashsToastContainer');
        if (!container || !message) return;

        type = ['success', 'error', 'warning', 'info'].includes(type) ? type : 'info';
        duration = Number(duration) > 0 ? Number(duration) : 3600;

        const toast = document.createElement('div');
        toast.className = 'jashs-toast ' + type;

        const icon = document.createElement('div');
        icon.className = 'jashs-toast-icon';
        icon.textContent = toastIcon(type);

        const content = document.createElement('div');
        content.className = 'jashs-toast-content';

        const heading = document.createElement('div');
        heading.className = 'jashs-toast-title';
        heading.textContent = title || toastTitle(type);

        const text = document.createElement('div');
        text.className = 'jashs-toast-message';
        text.textContent = String(message);

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'jashs-toast-close';
        close.setAttribute('aria-label', 'Close notification');
        close.textContent = '×';

        content.appendChild(heading);
        content.appendChild(text);
        toast.appendChild(icon);
        toast.appendChild(content);
        toast.appendChild(close);
        container.appendChild(toast);

        let timer = null;
        const remove = function () {
            if (!toast.isConnected) return;
            toast.classList.remove('show');
            setTimeout(function () { if (toast.isConnected) toast.remove(); }, 240);
        };

        close.addEventListener('click', remove);
        requestAnimationFrame(function () { toast.classList.add('show'); });
        timer = setTimeout(remove, duration);

        toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
        toast.addEventListener('mouseleave', function () { timer = setTimeout(remove, 1800); });
        return toast;
    };

    let activeResolver = null;
    const overlay = document.getElementById('jashsConfirmOverlay');
    const titleEl = document.getElementById('jashsConfirmTitle');
    const messageEl = document.getElementById('jashsConfirmMessage');
    const cancelBtn = document.getElementById('jashsConfirmCancel');
    const okBtn = document.getElementById('jashsConfirmOk');

    function finishConfirm(value) {
        if (!overlay || !activeResolver) return;
        overlay.classList.remove('show');
        const resolver = activeResolver;
        activeResolver = null;
        resolver(value);
    }

    window.showConfirmModal = function (options) {
        options = options || {};
        if (!overlay) return Promise.resolve(false);

        titleEl.textContent = options.title || 'Please Confirm';
        messageEl.textContent = options.message || 'Are you sure you want to continue?';
        cancelBtn.textContent = options.cancelText || 'Cancel';
        okBtn.textContent = options.confirmText || 'Confirm';
        okBtn.className = 'jashs-confirm-btn ' + (options.danger ? 'jashs-confirm-danger' : 'jashs-confirm-primary');
        overlay.classList.add('show');

        return new Promise(function (resolve) {
            activeResolver = resolve;
            setTimeout(function () { okBtn.focus(); }, 80);
        });
    };

    cancelBtn.addEventListener('click', function () { finishConfirm(false); });
    okBtn.addEventListener('click', function () { finishConfirm(true); });
    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) finishConfirm(false);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && overlay.classList.contains('show')) finishConfirm(false);
    });

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const message = form.getAttribute('data-confirm-message');
        if (!message || form.dataset.confirmBypass === '1') return;

        event.preventDefault();
        showConfirmModal({
            title: form.getAttribute('data-confirm-title') || 'Please Confirm',
            message: message,
            confirmText: form.getAttribute('data-confirm-text') || 'Confirm',
            cancelText: form.getAttribute('data-cancel-text') || 'Cancel',
            danger: form.getAttribute('data-confirm-danger') === '1'
        }).then(function (confirmed) {
            if (!confirmed) return;
            form.dataset.confirmBypass = '1';
            HTMLFormElement.prototype.submit.call(form);
        });
    }, true);
})();
</script>
