(function (w) {
    'use strict';

    var CARD = {
        width: 590,
        height: 372,
        yellow: '#F6D61B',
        yellowSoft: '#FFF29F',
        black: '#141414',
        white: '#FFFFFF',
        gray: '#F2F3F5',
        grayText: '#6C6C6C',
        border: '#D7D8DB'
    };

    function safeText(v) {
        return String(v == null ? '' : v).replace(/[\r\n]+/g, ' ').trim();
    }

    function upper(v) {
        return safeText(v).toLocaleUpperCase();
    }

    function fileSafe(v) {
        var s = safeText(v).replace(/[^A-Za-z0-9_-]+/g, '_').replace(/^_+|_+$/g, '');
        return s || 'Library_Access_Card';
    }

    function loadImage(src, crossOrigin) {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            if (crossOrigin) img.crossOrigin = crossOrigin;
            img.onload = function () { resolve(img); };
            img.onerror = function () { reject(new Error('Unable to load card image.')); };
            img.src = src;
        });
    }

    function roundedRect(ctx, x, y, w, h, r) {
        var rr = Math.min(r, w / 2, h / 2);
        ctx.beginPath();
        ctx.moveTo(x + rr, y);
        ctx.arcTo(x + w, y, x + w, y + h, rr);
        ctx.arcTo(x + w, y + h, x, y + h, rr);
        ctx.arcTo(x, y + h, x, y, rr);
        ctx.arcTo(x, y, x + w, y, rr);
        ctx.closePath();
    }

    function fitFont(ctx, text, maxWidth, start, min, family, weight) {
        var size = start;
        var prefix = weight ? weight + ' ' : '';
        while (size > min) {
            ctx.font = prefix + size + 'px ' + family;
            if (ctx.measureText(text).width <= maxWidth) break;
            size -= 1;
        }
        return size;
    }

    function drawCenter(ctx, text, y, maxWidth, start, min, family, weight, color) {
        text = safeText(text);
        var size = fitFont(ctx, text, maxWidth, start, min, family, weight);
        ctx.font = (weight ? weight + ' ' : '') + size + 'px ' + family;
        ctx.fillStyle = color || CARD.black;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'alphabetic';
        ctx.fillText(text, CARD.width / 2, y);
        return size;
    }

    function getQrDataUrl(code) {
        if (typeof w.qrcode !== 'function') return null;
        var qr = w.qrcode(0, 'M');
        qr.addData(String(code));
        qr.make();
        return qr.createDataURL(3, 0);
    }

    function ensureQrLibrary() {
        if (typeof w.qrcode === 'function') return Promise.resolve();
        return new Promise(function (resolve, reject) {
            var existing = document.querySelector('script[data-library-card-qrcode]');
            if (existing) {
                existing.addEventListener('load', resolve, { once: true });
                existing.addEventListener('error', function () { reject(new Error('QR library could not be loaded.')); }, { once: true });
                return;
            }
            var script = document.createElement('script');
            script.src = '/LibraryBorrowingSystem/js/qrcode.js';
            script.async = true;
            script.dataset.libraryCardQrcode = '1';
            script.onload = resolve;
            script.onerror = function () { reject(new Error('QR library could not be loaded.')); };
            document.head.appendChild(script);
        });
    }

    function drawCard(opts) {
        opts = opts || {};
        var code = safeText(opts.qrCode);
        var fullName = upper(opts.fullName);
        var idNumber = safeText(opts.idNumber);
        if (!code || !fullName || !idNumber) return Promise.reject(new Error('Library Access Card details are incomplete.'));

        return ensureQrLibrary().then(function () {
            var logoSrc = opts.logoSrc || '/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg';
            var qrData = getQrDataUrl(code);
            if (!qrData) throw new Error('Unable to create the QR image.');

            return Promise.all([
                loadImage(logoSrc),
                loadImage(qrData)
            ]).then(function (images) {
                var logo = images[0], qr = images[1];
                var canvas = document.createElement('canvas');
                canvas.width = CARD.width;
                canvas.height = CARD.height;
                var ctx = canvas.getContext('2d');
                ctx.imageSmoothingEnabled = false;

                // Base + yellow header.
                ctx.fillStyle = CARD.white;
                ctx.fillRect(0, 0, CARD.width, CARD.height);
                ctx.fillStyle = CARD.yellow;
                ctx.fillRect(0, 0, CARD.width, 46);
                ctx.fillStyle = CARD.black;
                ctx.fillRect(0, 45, CARD.width, 5);

                // Bottom-right color accents.
                ctx.fillStyle = CARD.yellowSoft;
                ctx.beginPath();
                ctx.moveTo(CARD.width - 115, CARD.height);
                ctx.lineTo(CARD.width, CARD.height - 62);
                ctx.lineTo(CARD.width, CARD.height);
                ctx.closePath();
                ctx.fill();
                ctx.fillStyle = CARD.black;
                ctx.beginPath();
                ctx.moveTo(CARD.width - 54, CARD.height);
                ctx.lineTo(CARD.width, CARD.height - 30);
                ctx.lineTo(CARD.width, CARD.height);
                ctx.closePath();
                ctx.fill();

                // Double border.
                ctx.strokeStyle = CARD.black;
                ctx.lineWidth = 1.5;
                roundedRect(ctx, 4, 4, CARD.width - 9, CARD.height - 9, 20);
                ctx.stroke();
                ctx.strokeStyle = CARD.border;
                ctx.lineWidth = 1;
                roundedRect(ctx, 9, 9, CARD.width - 19, CARD.height - 19, 17);
                ctx.stroke();

                // Header text (kept clear of the logo).
                ctx.fillStyle = CARD.black;
                ctx.textAlign = 'left';
                ctx.textBaseline = 'alphabetic';
                ctx.font = '700 18px Arial, Helvetica, sans-serif';
                ctx.fillText('Jose Abad Santos High School', 28, 29);
                ctx.font = '400 9px Arial, Helvetica, sans-serif';
                ctx.fillText('SCHOOL LIBRARY', 29, 42);

                // Single school logo.
                var logoBox = 72;
                var scale = Math.min(logoBox / logo.naturalWidth, logoBox / logo.naturalHeight);
                var logoW = Math.max(1, Math.round(logo.naturalWidth * scale));
                var logoH = Math.max(1, Math.round(logo.naturalHeight * scale));
                ctx.drawImage(logo, 497 + Math.round((logoBox - logoW) / 2), 14 + Math.round((logoBox - logoH) / 2), logoW, logoH);

                // Name, QR block, ID and card label.
                drawCenter(ctx, fullName, 80, 490, 20, 14, 'Arial, Helvetica, sans-serif', '700', CARD.black);

                var qx = 215, qy = 100, qsize = 160;
                ctx.fillStyle = CARD.gray;
                roundedRect(ctx, qx - 10, qy - 10, qsize + 20, qsize + 20, 6);
                ctx.fill();
                ctx.drawImage(qr, qx, qy, qsize, qsize);
                ctx.fillStyle = CARD.yellow;
                ctx.fillRect(qx - 10, qy + qsize + 10, qsize + 20, 4);

                drawCenter(ctx, idNumber, 316, 420, 21, 14, 'Arial, Helvetica, sans-serif', '700', CARD.black);
                drawCenter(ctx, 'LIBRARY ACCESS CARD', 342, 390, 16, 12, 'Arial, Helvetica, sans-serif', '700', CARD.black);
                drawCenter(ctx, 'QR ID: ' + code, 362, 500, 8, 7, 'Arial, Helvetica, sans-serif', '400', CARD.grayText);

                return canvas;
            });
        });
    }

    function canvasToBlob(canvas) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (blob) resolve(blob); else reject(new Error('Unable to create the JPG file.'));
            }, 'image/jpeg', 0.95);
        });
    }

    function triggerDownload(blob, filename) {
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
    }

    function downloadCard(opts) {
        return drawCard(opts).then(canvasToBlob).then(function (blob) {
            triggerDownload(blob, fileSafe(opts.fullName) + '_Library_Access_Card.jpg');
            return true;
        });
    }

    function base64ToBytes(base64) {
        var raw = atob(base64);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    function textBytes(text) { return new TextEncoder().encode(text); }

    function buildPdfFromJpeg(jpegBase64, widthPx, heightPx) {
        var jpg = base64ToBytes(jpegBase64);
        var pageW = 243, pageH = 153;
        var content = 'q\n' + pageW + ' 0 0 ' + pageH + ' 0 0 cm\n/Im0 Do\nQ\n';
        var bodies = [];
        bodies[1] = textBytes('<< /Type /Catalog /Pages 2 0 R >>');
        bodies[2] = textBytes('<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
        bodies[3] = textBytes('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + pageW + ' ' + pageH + '] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>');
        bodies[4] = textBytes('<< /Length ' + textBytes(content).length + ' >>\nstream\n' + content + 'endstream');

        var imgHeader = textBytes('<< /Type /XObject /Subtype /Image /Width ' + widthPx + ' /Height ' + heightPx + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + jpg.length + ' >>\nstream\n');
        var imgFooter = textBytes('\nendstream');
        bodies[5] = [imgHeader, jpg, imgFooter];

        var parts = [textBytes('%PDF-1.4\n%\xFF\xFF\xFF\xFF\n')];
        var offsets = [0,0,0,0,0,0];
        var total = parts[0].length;
        for (var n = 1; n <= 5; n++) {
            offsets[n] = total;
            parts.push(textBytes(n + ' 0 obj\n')); total += parts[parts.length - 1].length;
            var body = bodies[n];
            if (Array.isArray(body)) {
                for (var j = 0; j < body.length; j++) { parts.push(body[j]); total += body[j].length; }
            } else { parts.push(body); total += body.length; }
            parts.push(textBytes('\nendobj\n')); total += parts[parts.length - 1].length;
        }
        var xrefOffset = total;
        var xref = 'xref\n0 6\n0000000000 65535 f \n';
        for (var k = 1; k <= 5; k++) xref += String(offsets[k]).padStart(10,'0') + ' 00000 n \n';
        xref += 'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' + xrefOffset + '\n%%EOF\n';
        parts.push(textBytes(xref));

        var blobParts = [];
        for (var q = 0; q < parts.length; q++) blobParts.push(parts[q]);
        return new Blob(blobParts, { type: 'application/pdf' });
    }

    function downloadPdf(opts) {
        return drawCard(opts).then(function (canvas) {
            return new Promise(function (resolve, reject) {
                canvas.toDataURL('image/jpeg', 0.95).split(',');
                try {
                    var dataUrl = canvas.toDataURL('image/jpeg', 0.95);
                    var base64 = dataUrl.split(',')[1];
                    var pdf = buildPdfFromJpeg(base64, CARD.width, CARD.height);
                    triggerDownload(pdf, fileSafe(opts.fullName) + '_Library_Access_Card.pdf');
                    resolve(true);
                } catch (e) { reject(e); }
            });
        });
    }

    w.libraryAccessCardClient = {
        draw: drawCard,
        download: downloadCard,
        downloadPdf: downloadPdf,
        constants: CARD
    };
})(window);
