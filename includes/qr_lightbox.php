<?php
/**
 * Shared full-screen QR viewer.
 *
 * Any element with data-qr-zoom becomes tappable. For personal library cards,
 * desktop shows Print Card while mobile shows Download Card.
 */
if (defined('QR_LIGHTBOX_LOADED')) return;
define('QR_LIGHTBOX_LOADED', true);
?>
<style id="qr-lightbox-css">
.qr-zoomable{position:relative;display:inline-block;padding:0;margin:0;border:0;background:transparent;cursor:zoom-in;border-radius:14px;-webkit-tap-highlight-color:transparent;font:inherit;color:inherit;line-height:0}
.qr-zoomable img{display:block;transition:transform .15s,box-shadow .15s}
.qr-zoomable:hover img,.qr-zoomable:focus-visible img{transform:scale(1.03);box-shadow:0 8px 24px rgba(20,31,82,.25)}
.qr-zoomable:focus-visible{outline:3px solid #F4F916;outline-offset:3px}
.qr-zoomable .qr-zoom-hint{position:absolute;right:6px;bottom:6px;display:grid;place-items:center;width:28px;height:28px;border-radius:50%;background:#141F52;color:#fff;font-size:14px;line-height:1;box-shadow:0 2px 8px rgba(0,0,0,.3);pointer-events:none}
.qr-tap-note{display:block;margin-top:8px;font-size:12px;font-weight:600;color:var(--pu-muted,#5B6890);line-height:1.3}

#qrLightbox{position:fixed;inset:0;z-index:100050;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(8,13,36,.94);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);overflow-y:auto;overscroll-behavior:contain}
#qrLightbox.open{display:flex;animation:qrlbIn .18s ease-out}
#qrLightbox .qrlb-card{width:min(100%,600px);margin:auto;text-align:center;color:#fff}
#qrLightbox .qrlb-title{margin:0 0 4px;font-size:clamp(18px,4.6vw,24px);font-weight:800;letter-spacing:-.2px;overflow-wrap:anywhere}
#qrLightbox .qrlb-sub{margin:0 0 16px;font-size:13.5px;color:#c9d6f2;overflow-wrap:anywhere}
#qrLightbox .qrlb-frame{background:#fff;border-radius:22px;padding:clamp(12px,3.5vw,22px);margin:0 auto;width:min(94vw,68vh,560px);aspect-ratio:1/1;display:grid;place-items:center;box-shadow:0 20px 60px rgba(0,0,0,.5);position:relative}
#qrLightbox .qrlb-frame img{width:100%;height:100%;object-fit:contain;image-rendering:pixelated;image-rendering:crisp-edges;background:#fff;display:block}
#qrLightbox .qrlb-loading,#qrLightbox .qrlb-error{position:absolute;inset:0;display:grid;place-items:center;padding:18px;color:#141F52;font-weight:700;font-size:14px;background:#fff;border-radius:22px}
#qrLightbox .qrlb-error{display:none}
#qrLightbox .qrlb-code{margin:16px 0 0;font:700 clamp(13px,3.6vw,16px) 'Courier New',monospace;letter-spacing:.5px;color:#F4F916;overflow-wrap:anywhere}
#qrLightbox .qrlb-tip{margin:6px 0 0;font-size:12.5px;color:#aebbdb}
#qrLightbox .qrlb-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:18px}
#qrLightbox .qrlb-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:44px;padding:0 20px;border-radius:999px;border:0;cursor:pointer;font:700 14px/1 'Inter','Segoe UI',system-ui,-apple-system,Roboto,sans-serif;text-decoration:none;-webkit-tap-highlight-color:transparent}
#qrLightbox .qrlb-btn.primary{background:#F4F916;color:#141F52}
#qrLightbox .qrlb-btn.secondary{background:#fff;color:#141F52}
#qrLightbox .qrlb-btn.ghost{background:rgba(255,255,255,.14);color:#fff}
#qrLightbox .qrlb-btn:active{transform:scale(.97)}
#qrLightbox .qrlb-close{position:fixed;top:max(12px,env(safe-area-inset-top,0px));right:max(12px,env(safe-area-inset-right,0px));width:48px;height:48px;border-radius:50%;border:0;background:rgba(255,255,255,.16);color:#fff;font-size:30px;line-height:1;cursor:pointer;display:grid;place-items:center;z-index:2}
#qrLightbox .qrlb-close:hover{background:rgba(255,255,255,.28)}
#qrLightbox .card-action{display:none}
#qrLightbox .qrlb-status{min-height:18px;margin:8px 0 0;font-size:12px;color:#b8c5e4}
body.qr-lightbox-open{overflow:hidden}

@media (min-width:601px){
    #qrLightbox .card-action.desktop-action{display:inline-flex}
}
@media (max-width:600px){
    #qrLightbox{padding:14px 14px calc(14px + env(safe-area-inset-bottom,0px))}
    #qrLightbox .qrlb-btn{flex:0 1 auto;min-height:44px;padding:0 18px}
    #qrLightbox .card-action.mobile-action{display:inline-flex}
}
@media (max-height:520px) and (orientation:landscape){
    #qrLightbox .qrlb-frame{width:min(60vh,300px)}
    #qrLightbox .qrlb-card{display:grid;grid-template-columns:auto 1fr;gap:18px;text-align:left;align-items:center;width:min(100%,760px)}
}
@keyframes qrlbIn{from{opacity:0}to{opacity:1}}
@media (prefers-reduced-motion:reduce){#qrLightbox.open{animation:none}}
</style>

<div id="qrLightbox" role="dialog" aria-modal="true" aria-labelledby="qrlbTitle" aria-hidden="true">
    <button type="button" class="qrlb-close" id="qrlbClose" aria-label="Close QR viewer">&times;</button>
    <div class="qrlb-card">
        <div class="qrlb-frame">
            <div class="qrlb-loading" id="qrlbLoading">Loading QR…</div>
            <div class="qrlb-error" id="qrlbError">Couldn't load the QR image. Check your connection and try again.</div>
            <img id="qrlbImg" alt="Large QR code" decoding="async">
        </div>
        <div class="qrlb-info">
            <h3 class="qrlb-title" id="qrlbTitle">QR Code</h3>
            <p class="qrlb-sub" id="qrlbSub"></p>
            <p class="qrlb-code" id="qrlbCode"></p>
            <p class="qrlb-tip">Raise your screen brightness and hold it steady for the scanner.</p>
            <p class="qrlb-status" id="qrlbStatus" aria-live="polite"></p>
            <div class="qrlb-actions">
                <a class="qrlb-btn primary card-action desktop-action" id="qrlbPrint" href="#" target="_blank" rel="noopener">Print Card</a>
                <button type="button" class="qrlb-btn primary card-action mobile-action" id="qrlbDownload">⇩ Download Card</button>
                <button type="button" class="qrlb-btn ghost" id="qrlbDone">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="/LibraryBorrowingSystem/js/qrcode.js"></script>
<script src="/LibraryBorrowingSystem/includes/library_card_client.js"></script>
<script>
(function () {
    var box = document.getElementById('qrLightbox');
    if (!box) return;
    var img = document.getElementById('qrlbImg'),
        loading = document.getElementById('qrlbLoading'),
        errBox = document.getElementById('qrlbError'),
        title = document.getElementById('qrlbTitle'),
        sub = document.getElementById('qrlbSub'),
        codeEl = document.getElementById('qrlbCode'),
        printLink = document.getElementById('qrlbPrint'),
        downloadBtn = document.getElementById('qrlbDownload'),
        statusEl = document.getElementById('qrlbStatus'),
        lastFocus = null,
        current = null;

    function isCard() { return !!(current && current.card); }
    function fromEl(el) {
        var d = el.dataset;
        return {
            code: d.qrCode,
            title: d.qrTitle,
            sub: d.qrSub,
            download: d.qrDownload,
            print: d.qrPrint,
            card: d.qrCard === '1',
            cardName: d.qrCardName || '',
            cardId: d.qrCardId || '',
            cardImage: d.qrCardImage || '',
            cardLogo: d.qrCardLogo || ''
        };
    }

    function setActionVisibility(card) {
        printLink.style.display = card ? '' : 'none';
        downloadBtn.style.display = card ? '' : 'none';
        statusEl.textContent = '';
    }

    function openQr(o) {
        if (!o || !o.code) return;
        lastFocus = document.activeElement;
        current = o;
        title.textContent = o.title || 'QR Code';
        sub.textContent = o.sub || '';
        sub.style.display = o.sub ? '' : 'none';
        codeEl.textContent = o.code;
        setActionVisibility(!!o.card);
        if (o.print) printLink.href = o.print; else printLink.removeAttribute('href');
        loading.style.display = 'grid';
        errBox.style.display = 'none';
        img.style.visibility = 'hidden';
        img.onload = function () { loading.style.display = 'none'; img.style.visibility = 'visible'; };
        img.onerror = function () { loading.style.display = 'none'; errBox.style.display = 'grid'; };
        img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=700x700&margin=0&ecc=M&data=' + encodeURIComponent(o.code);
        box.classList.add('open');
        box.setAttribute('aria-hidden', 'false');
        document.body.classList.add('qr-lightbox-open');
        document.getElementById('qrlbClose').focus();
    }

    function closeQr() {
        if (!box.classList.contains('open')) return;
        box.classList.remove('open');
        box.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('qr-lightbox-open');
        img.removeAttribute('src');
        current = null;
        statusEl.textContent = '';
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }

    downloadBtn.addEventListener('click', function () {
        if (!current || !current.card || !window.libraryAccessCardClient) return;
        statusEl.textContent = 'Preparing your Library Access Card…';
        downloadBtn.disabled = true;
        window.libraryAccessCardClient.download({
            fullName: current.cardName,
            idNumber: current.cardId,
            qrCode: current.code,
            qrSrc: current.cardImage,
            logoSrc: current.cardLogo
        }).then(function () {
            statusEl.textContent = 'Library Access Card downloaded.';
        }).catch(function (e) {
            statusEl.textContent = (e && e.message) ? e.message : 'Unable to create the card. Please try again.';
        }).finally(function () {
            downloadBtn.disabled = false;
        });
    });

    window.openQrLightbox = openQr;
    window.closeQrLightbox = closeQr;

    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-qr-zoom]') : null;
        if (t) { e.preventDefault(); openQr(fromEl(t)); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeQr(); return; }
        if ((e.key === 'Enter' || e.key === ' ') && e.target && e.target.matches && e.target.matches('[data-qr-zoom]:not(button):not(a)')) {
            e.preventDefault(); openQr(fromEl(e.target));
        }
        if (e.key === 'Tab' && box.classList.contains('open')) {
            var f = box.querySelectorAll('button,a[href]'), vis = [];
            for (var i = 0; i < f.length; i++) if (f[i].offsetParent !== null || f[i].id === 'qrlbClose') vis.push(f[i]);
            if (!vis.length) return;
            var first = vis[0], last = vis[vis.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });
    box.addEventListener('click', function (e) { if (e.target === box) closeQr(); });
    document.getElementById('qrlbClose').addEventListener('click', closeQr);
    document.getElementById('qrlbDone').addEventListener('click', closeQr);
})();
</script>
