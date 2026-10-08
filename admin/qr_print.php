<?php
/**
 * Compact physical book QR print sheet.
 * Printable area contains compact QR labels (Book Title + QR image + Book Number + QR ID).
 */
require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/book_copies.php';

if (!isAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

function qrpH($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function qrpDataUri(string $qrCode): string {
    $safe = basename($qrCode) . '.png';
    $path = __DIR__ . '/../qr_codes/' . $safe;
    if (is_file($path) && is_readable($path)) {
        $data = file_get_contents($path);
        if ($data !== false && $data !== '') {
            return 'data:image/png;base64,' . base64_encode($data);
        }
    }
    return '';
}

$bookId = (int)($_GET['book_id'] ?? 0);
$requestedQrSize = (float)($_GET['qr_size'] ?? 22);
$requestedQrSize = max(10, min(60, $requestedQrSize));
$requestedBookFont = (float)($_GET['book_font'] ?? 11);
$requestedBookFont = max(7, min(18, $requestedBookFont));
$requestedQrIdFont = (float)($_GET['qrid_font'] ?? 7);
$requestedQrIdFont = max(4, min(12, $requestedQrIdFont));
$requestedTitleFont = (float)($_GET['title_font'] ?? 8);
$requestedTitleFont = max(6, min(18, $requestedTitleFont));
$copies = [];

if ($bookId > 0) {
    $stmt = $conn->prepare("SELECT c.copy_id, c.book_id, c.book_number, c.qr_code, c.copy_status, b.title
        FROM book_copies c
        INNER JOIN books b ON b.book_id = c.book_id
        WHERE c.book_id = ? AND c.copy_status <> 'removed' AND b.is_archived = 0
        ORDER BY CAST(SUBSTRING(c.book_number, 4) AS UNSIGNED), c.copy_id");
    $stmt->bind_param('i', $bookId);
} else {
    $stmt = $conn->prepare("SELECT c.copy_id, c.book_id, c.book_number, c.qr_code, c.copy_status, b.title
        FROM book_copies c
        INNER JOIN books b ON b.book_id = c.book_id
        WHERE c.copy_status <> 'removed' AND b.is_archived = 0
        ORDER BY b.title ASC, CAST(SUBSTRING(c.book_number, 4) AS UNSIGNED), c.copy_id");
}

if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['qr_image'] = qrpDataUri((string)$row['qr_code']);
        $copies[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Book QR Codes</title>
<style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
        font-family: Arial, Helvetica, sans-serif;
        background: #fff;
        color: #14213d;
    }

    .print-controls {
        position: sticky;
        top: 0;
        z-index: 20;
        display: flex;
        justify-content: flex-end;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px 14px;
        padding: 24px 32px 10px;
        background: #fff;
    }
    .qr-size-control {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-right: auto;
        color: #14213d;
        font-size: 13px;
        font-weight: 700;
    }
    .qr-size-control input {
        width: 92px;
        border: 1px solid #b9c7de;
        border-radius: 6px;
        padding: 8px 10px;
        font: inherit;
        font-weight: 600;
        color: #14213d;
        background: #fff;
    }
    .qr-size-control .unit {
        font-size: 12px;
        color: #64748b;
        font-weight: 600;
    }
    .qr-size-control + .text-size-control { margin-left: 18px; }
    .text-size-control {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #14213d;
        font-size: 13px;
        font-weight: 700;
    }
    .text-size-control label { white-space: nowrap; }
    .text-size-control input {
        width: 72px;
        border: 1px solid #b9c7de;
        border-radius: 6px;
        padding: 8px 10px;
        font: inherit;
        font-weight: 600;
        color: #14213d;
        background: #fff;
    }
    .text-size-control .unit {
        font-size: 12px;
        color: #64748b;
        font-weight: 600;
    }
    .qr-size-control .hint {
        font-size: 11px;
        color: #64748b;
        font-weight: 500;
    }
    .actions { display: flex; gap: 8px; }
    .actions button {
        border: 0;
        border-radius: 6px;
        padding: 9px 15px;
        font: inherit;
        font-weight: 700;
        cursor: pointer;
    }
    .print-btn { background: #14213d; color: #fff; }
    .close-btn { background: #586b9d; color: #fff; }

    .preview {
        width: 1024px;
        max-width: calc(100% - 40px);
        margin: 0 auto 40px;
        padding: 0 0 30px;
    }
    .school-header {
        text-align: center;
        padding-top: 2px;
    }
    .school-logo {
        width: 52px;
        height: 52px;
        object-fit: contain;
        display: block;
        margin: 0 auto 8px;
    }
    .school-title {
        margin: 0;
        color: #14213d;
        font-size: 24px;
        font-weight: 800;
        line-height: 1.15;
    }
    .school-subtitle {
        margin-top: 4px;
        color: #52618d;
        font-size: 12px;
        line-height: 1.35;
    }
    .rule {
        border: 0;
        border-top: 3px solid #14213d;
        margin: 22px 0 14px;
    }
    .sheet-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        margin-bottom: 8px;
        color: #52618d;
        font-size: 11px;
    }
    .sheet-meta strong {
        color: #14213d;
        font-size: 12px;
    }

    .sheet {
        width: 100%;
        margin: 0;
        display: grid;
        grid-template-columns: repeat(var(--sheet-cols, 6), minmax(0, 1fr));
        grid-auto-rows: max-content;
        align-items: start;
        gap: 8px 10px;
    }

    .label {
        min-width: 0;
        height: auto;
        min-height: 0;
        padding: 5px 5px 6px;
        border: 1px solid #d2e2f6;
        border-radius: 4px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: flex-start;
        text-align: center;
        overflow: visible;
        break-inside: avoid;
        page-break-inside: avoid;
        background: #fff;
    }
    .label img {
        display: block;
        width: calc(var(--qr-size-mm, 22) * 1mm);
        height: calc(var(--qr-size-mm, 22) * 1mm);
        object-fit: contain;
        image-rendering: pixelated;
    }
    .book-title-label {
        width: 100%;
        flex: 0 0 auto;
        min-height: calc(var(--title-font-pt, 8) * 1pt * 1.05);
        margin-bottom: 4px;
        font-size: calc(var(--title-font-pt, 8) * 1pt);
        line-height: 1.05;
        font-weight: 800;
        color: #14213d;
        overflow-wrap: anywhere;
        word-break: break-word;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
        overflow: hidden;
        color: #14213d !important;
        opacity: 1 !important;
        visibility: visible !important;
    }
    .book-number {
        margin-top: 0;

        font-size: calc(var(--book-font-pt, 11) * 1pt);
        line-height: 1.05;
        font-weight: 800;
        white-space: nowrap;
        color: #14213d;
    }
    .qr-id {
        margin-top: 3px;
        width: 100%;
        max-width: 100%;
        font-size: calc(var(--qrid-font-pt, 7) * 1pt);
        line-height: 1.08;
        color: #52618d;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
        letter-spacing: -.05px;
    }
    .missing {
        font-size: 8px;
        color: #b91c1c;
    }
    .empty {
        width: 100%;
        margin: 30px auto;
        text-align: center;
        color: #64748b;
    }

    @page {
        size: A4 portrait;
        margin: 7mm;
    }

    @media print {
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
        }
        .print-controls,
        .school-header,
        .rule,
        .sheet-meta,
        .empty {
            display: none !important;
        }
        .preview {
            width: 100% !important;
            max-width: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .sheet {
            width: 100% !important;
            margin: 0 !important;
            grid-template-columns: repeat(var(--print-cols, 6), minmax(0, 1fr));
            grid-auto-rows: max-content;
            align-items: start;
            gap: 2.5mm 2mm;
        }
        .label {
            height: auto;
            min-height: 0;
            padding: 1.5mm 1mm 1.6mm;
            border: 0.35pt solid #cfd6df;
            border-radius: 1.2mm;
            background: #fff;
            overflow: visible;
        }
        .label img {
            width: calc(var(--qr-size-mm, 22) * 1mm);
            height: calc(var(--qr-size-mm, 22) * 1mm);
        }
        .book-title-label {
            margin-bottom: .7mm;
            min-height: calc(var(--title-font-pt, 8) * 1pt * 1.05);
            font-size: calc(var(--title-font-pt, 8) * 1pt);
        }
        .book-number {
            margin-top: .8mm;
            font-size: calc(var(--book-font-pt, 8.5) * 1pt);
        }
        .qr-id {
            margin-top: .5mm;
            font-size: calc(var(--qrid-font-pt, 5.2) * 1pt);
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        * {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }

    @media screen and (max-width: 900px) {
        .preview {
            max-width: calc(100% - 24px);
        }
        .sheet {
            grid-template-columns: repeat(var(--screen-cols, 3), 1fr);
        }
        .label { height: auto; min-height: 0; }
    }

    @media screen and (max-width: 560px) {
        .print-controls {
            position: sticky;
            justify-content: stretch;
            align-items: stretch;
            padding: 12px;
            gap: 8px;
        }
        .qr-size-control,
        .text-size-control {
            width: 100%;
            margin: 0 !important;
            justify-content: space-between;
        }
        .qr-size-control input,
        .text-size-control input { width: 72px; }
        .actions {
            width: 100%;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .actions button { width: 100%; min-height: 42px; }
        .preview {
            max-width: calc(100% - 16px);
            margin-bottom: 24px;
        }
        .school-title { font-size: 20px; }
        .sheet-meta {
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
        }
        .sheet {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px;
        }
        .label { height: auto; min-height: 0; padding: 5px 4px 6px; }
        .label img {
            width: min(calc(var(--qr-size-mm, 22) * 1mm), 100%);
            height: auto;
            aspect-ratio: 1 / 1;
        }
        .book-title-label { line-height: 1.08; }
    }
</style>
</head>
<body>
<div class="print-controls">
    <div class="qr-size-control">
        <label for="qrSize">QR size</label>
        <input id="qrSize" type="number" min="10" max="60" step="1" value="<?php echo qrpH($requestedQrSize); ?>" inputmode="decimal" list="qrSizeOptions" aria-describedby="qrSizeHint">
        <datalist id="qrSizeOptions">
            <option value="10"></option>
            <option value="15"></option>
            <option value="20"></option>
            <option value="22"></option>
            <option value="25"></option>
            <option value="30"></option>
            <option value="35"></option>
            <option value="40"></option>
            <option value="45"></option>
            <option value="50"></option>
            <option value="60"></option>
        </datalist>
        <span class="unit">mm</span>
        <span class="hint" id="qrSizeHint">Square QR size; 10–60 mm.</span>
    </div>
    <div class="text-size-control" aria-label="QR label text sizing">
        <label for="bookFontSize">Book #</label>
        <input id="bookFontSize" type="number" min="7" max="18" step="0.5" value="<?php echo qrpH($requestedBookFont); ?>" inputmode="decimal" list="bookFontOptions" aria-describedby="bookFontHint">
        <datalist id="bookFontOptions">
            <option value="8"></option>
            <option value="9"></option>
            <option value="10"></option>
            <option value="11"></option>
            <option value="12"></option>
            <option value="14"></option>
            <option value="16"></option>
            <option value="18"></option>
        </datalist>
        <span class="unit">pt</span>
        <label for="qrIdFontSize">QR ID</label>
        <input id="qrIdFontSize" type="number" min="4" max="12" step="0.2" value="<?php echo qrpH($requestedQrIdFont); ?>" inputmode="decimal" list="qrIdFontOptions" aria-describedby="qrIdFontHint">
        <span class="unit">pt</span>
        <label for="titleFontSize">Title</label>
        <input id="titleFontSize" type="number" min="6" max="18" step="0.5" value="<?php echo qrpH($requestedTitleFont); ?>" inputmode="decimal" list="titleFontOptions">
        <datalist id="titleFontOptions"><option value="7"></option><option value="8"></option><option value="9"></option><option value="10"></option><option value="12"></option><option value="14"></option><option value="16"></option><option value="18"></option></datalist>
        <span class="unit">pt</span>
        <span class="hint" id="titleFontHint">Title 6–18 pt · shown above QR</span>
        <datalist id="qrIdFontOptions">
            <option value="4"></option>
            <option value="5"></option>
            <option value="6"></option>
            <option value="7"></option>
            <option value="8"></option>
            <option value="10"></option>
            <option value="12"></option>
        </datalist>
        <span class="unit">pt</span>
        <span class="hint" id="bookFontHint">Book # 7–18 pt</span>
        <span class="hint" id="qrIdFontHint">QR ID 4–12 pt</span>
    </div>
    <div class="actions">
        <button type="button" class="print-btn" onclick="window.print()">Print / Save PDF</button>
        <button type="button" class="close-btn" onclick="window.close()">Back to Catalogue</button>
    </div>
</div>

<main class="preview">
    <section class="school-header">
        <img class="school-logo" src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo">
        <h1 class="school-title">Jose Abad Santos High School</h1>
        <div class="school-subtitle">Book QR Code Sheet<br>Physical QR Codes — <?php echo $bookId > 0 ? 'Selected Book' : 'Active Catalogue'; ?></div>
    </section>

    <hr class="rule">

    <div class="sheet-meta">
        <strong><?php echo count($copies); ?> physical book QR code<?php echo count($copies) === 1 ? '' : 's'; ?></strong>
        <span>Each label includes the Book Title, Book Number and the QR ID.</span>
    </div>

    <?php if ($copies): ?>
    <div class="sheet">
    <?php foreach ($copies as $copy): ?>
        <div class="label">
            <div class="book-title-label" title="<?php echo qrpH($copy['title']); ?>"><?php echo qrpH($copy['title']); ?></div>
            <?php if ($copy['qr_image'] !== ''): ?>
                <img src="<?php echo qrpH($copy['qr_image']); ?>" alt="QR <?php echo qrpH($copy['book_number']); ?>">
            <?php else: ?>
                <div class="missing">QR image unavailable</div>
            <?php endif; ?>
            <div class="book-number"><?php echo qrpH($copy['book_number']); ?></div>
            <div class="qr-id">QR ID: <?php echo qrpH($copy['qr_code']); ?></div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty">No active physical book copies are available to print.</div>
    <?php endif; ?>
</main>

<script>
(function () {
    const root = document.documentElement;
    const qrInput = document.getElementById('qrSize');
    const bookFontInput = document.getElementById('bookFontSize');
    const qrIdFontInput = document.getElementById('qrIdFontSize');
    const titleFontInput = document.getElementById('titleFontSize');
    const printWidthMm = 210 - 14; // A4 width minus @page left/right margins.

    function clamp(value, min, max, fallback) {
        const number = Number(value);
        if (!Number.isFinite(number)) return fallback;
        return Math.min(max, Math.max(min, number));
    }

    function updatePrintSettings() {
        const qrSize = clamp(qrInput.value, 10, 60, 22);
        const bookFont = clamp(bookFontInput.value, 7, 18, 11);
        const qrIdFont = clamp(qrIdFontInput.value, 4, 12, 7);
        const titleFont = clamp(titleFontInput.value, 6, 18, 8);

        qrInput.value = String(qrSize);
        bookFontInput.value = String(bookFont);
        qrIdFontInput.value = String(qrIdFont);
        titleFontInput.value = String(titleFont);

        root.style.setProperty('--qr-size-mm', qrSize);
        root.style.setProperty('--book-font-pt', bookFont);
        root.style.setProperty('--qrid-font-pt', qrIdFont);
        root.style.setProperty('--title-font-pt', titleFont);
        // Cards size naturally from their contents. Keep this legacy variable at zero
        // for compatibility with older stylesheets; it no longer controls label height.
        root.style.setProperty('--label-extra-mm', '0mm');

        // Keep the cards compact while automatically reducing columns as the QR gets larger.
        const pitch = qrSize + 5;
        const cols = Math.max(1, Math.floor(printWidthMm / pitch));
        root.style.setProperty('--print-cols', cols);
        root.style.setProperty('--sheet-cols', Math.min(cols, 6));
        root.style.setProperty('--screen-cols', Math.min(cols, 3));

        try {
            const url = new URL(window.location.href);
            url.searchParams.set('qr_size', String(qrSize));
            url.searchParams.set('book_font', String(bookFont));
            url.searchParams.set('qrid_font', String(qrIdFont));
            url.searchParams.set('title_font', String(titleFont));
            window.history.replaceState(null, '', url.toString());
        } catch (error) {
            // URL persistence is optional; printing still works without it.
        }
    }

    qrInput.addEventListener('input', updatePrintSettings);
    qrInput.addEventListener('change', updatePrintSettings);
    bookFontInput.addEventListener('input', updatePrintSettings);
    bookFontInput.addEventListener('change', updatePrintSettings);
    qrIdFontInput.addEventListener('input', updatePrintSettings);
    qrIdFontInput.addEventListener('change', updatePrintSettings);
    titleFontInput.addEventListener('input', updatePrintSettings);
    titleFontInput.addEventListener('change', updatePrintSettings);
    updatePrintSettings();

    window.addEventListener('beforeprint', function () {
        updatePrintSettings();
        document.title = 'Book QR Codes';
    });
})();
</script>
</body>
</html>
