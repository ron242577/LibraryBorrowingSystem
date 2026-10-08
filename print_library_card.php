<?php
require_once __DIR__ . '/db.php';

$type = strtolower(trim((string)($_GET['type'] ?? '')));
if (!in_array($type, ['student','teacher'], true)) { http_response_code(400); exit('Invalid account type.'); }

$fullName = $idNumber = $qrCode = '';
$reg = $_SESSION['registration_card_download'] ?? null;
if (is_array($reg) && (($reg['registration_role'] ?? '') === $type)) {
    $fullName = trim((string)($reg['full_name'] ?? ''));
    $idNumber = trim((string)($type === 'teacher' ? ($reg['teacher_no'] ?? '') : ($reg['student_id_number'] ?? '')));
    $qrCode = trim((string)($reg['qr_code'] ?? ''));
}

if ($fullName === '' || $idNumber === '' || $qrCode === '') {
    if ($type === 'student') {
        $uid = (int)($_SESSION['student_id'] ?? 0);
        if ($uid <= 0) { http_response_code(401); exit('Student login required.'); }
        $stmt = $conn->prepare("SELECT full_name, student_no, qr_code FROM students WHERE student_id = ? AND status = 'active' AND COALESCE(is_archived,0) = 0 LIMIT 1");
        $stmt->bind_param('i', $uid);
    } else {
        $uid = (int)($_SESSION['teacher_id'] ?? 0);
        if ($uid <= 0) { http_response_code(401); exit('Teacher login required.'); }
        $stmt = $conn->prepare("SELECT full_name, teacher_no, qr_code FROM teachers WHERE teacher_id = ? AND status = 'active' AND COALESCE(is_archived,0) = 0 LIMIT 1");
        $stmt->bind_param('i', $uid);
    }
    if (!$stmt) { http_response_code(503); exit('Unable to load your Library Access Card.'); }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) { http_response_code(404); exit('Your Library Access Card is not available.'); }
    $fullName = trim((string)$row['full_name']);
    $idNumber = trim((string)($type === 'teacher' ? $row['teacher_no'] : $row['student_no']));
    $qrCode = trim((string)$row['qr_code']);
}

if ($fullName === '' || $idNumber === '' || $qrCode === '') { http_response_code(404); exit('Your Library Access Card is not available.'); }
$qrPath = '/LibraryBorrowingSystem/qr_codes/' . rawurlencode($qrCode) . '.png';
$logoPath = '/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg';
$downloadJpeg = $type === 'teacher' ? '/LibraryBorrowingSystem/teacher/download_qr.php?format=jpg' : '/LibraryBorrowingSystem/student/download_qr.php?format=jpg';
$downloadPdf = $type === 'teacher' ? '/LibraryBorrowingSystem/teacher/download_qr.php?format=pdf' : '/LibraryBorrowingSystem/student/download_qr.php?format=pdf';
if (is_array($reg) && (($reg['registration_role'] ?? '') === $type)) {
    $downloadJpeg = '/LibraryBorrowingSystem/student/download_registration_card.php?format=jpg';
    $downloadPdf = '/LibraryBorrowingSystem/student/download_registration_card.php?format=pdf';
}
$closeUrl = ($type === 'teacher') ? '/LibraryBorrowingSystem/teacher/dashboard.php' : '/LibraryBorrowingSystem/student/dashboard.php';
if (is_array($reg) && (($reg['registration_role'] ?? '') === $type)) $closeUrl = '/LibraryBorrowingSystem/student/register.php?completed=1';

function cardH($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo cardH($fullName . ' - Library Access Card'); ?></title>
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0;background:#eef0f4;font-family:Arial,Helvetica,sans-serif;color:#141414}
.screen-bar{position:sticky;top:0;z-index:5;display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;padding:12px;background:#141F52;box-shadow:0 4px 18px rgba(0,0,0,.18)}
.screen-bar button,.screen-bar .btn{border:0;border-radius:999px;padding:10px 16px;font:700 13px/1 Arial,Helvetica,sans-serif;cursor:pointer;text-decoration:none;white-space:nowrap}
.screen-bar .primary{background:#F6D61B;color:#141414}.screen-bar .ghost{background:#fff;color:#141F52}.screen-bar .dark{background:#273764;color:#fff}
.stage{min-height:calc(100vh - 64px);display:grid;place-items:center;padding:24px}
.card-shell{width:min(590px,calc(100vw - 24px));aspect-ratio:590/372;position:relative;overflow:hidden;background:#fff;box-shadow:0 18px 55px rgba(0,0,0,.22)}
.card-canvas-wrap{position:absolute;left:0;top:0;width:590px;height:372px;transform-origin:top left}
#cardPreview{display:block;width:590px;height:372px}
.note{text-align:center;margin:10px auto 0;font-size:12px;color:#5B6890}
.loading{text-align:center;font-size:14px;color:#5B6890;margin-top:12px}
@page{size:auto;margin:10mm}
@media print{
    html,body{margin:0!important;padding:0!important;width:100%;height:auto;min-height:0;background:#fff;overflow:visible}
    .screen-bar,.note,.loading{display:none!important}
    .stage{display:flex;align-items:flex-start;justify-content:center;min-height:0;width:100%;padding:0}
    .stage > div{margin:0 auto;padding:0}
    .card-shell{width:3.375in;height:2.125in;aspect-ratio:auto;box-shadow:none;overflow:visible;position:relative;margin:0 auto}
    .card-canvas-wrap{position:static;width:3.375in;height:2.125in;transform:none!important;transform-origin:initial}
    #cardPreview{display:block;width:3.375in!important;height:2.125in!important;max-width:none!important;max-height:none!important}
}
</style>
</head>
<body>
<div class="screen-bar">
    <button class="primary" id="printCard" type="button">Print Card</button>
    <button class="ghost" id="downloadJpeg" type="button">Download JPEG</button>
    <button class="dark" id="downloadPdf" type="button">Download PDF</button>
    <button class="ghost" id="closeCard" type="button">Close</button>
</div>
<div class="stage">
    <div>
        <div class="card-shell" aria-label="Library Access Card preview">
            <div class="card-canvas-wrap" id="cardCanvasWrap"><img id="cardPreview" alt="Library Access Card"></div>
        </div>
        <div class="loading" id="cardLoading">Preparing Library Access Card…</div>
        <div class="note">Print at 100% / Actual Size for a standard CR80 card.</div>
    </div>
</div>

<script src="/LibraryBorrowingSystem/js/qrcode.js"></script>
<script src="/LibraryBorrowingSystem/includes/library_card_client.js"></script>
<script>
(function(){
    const data = {
        fullName: <?php echo json_encode($fullName, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        idNumber: <?php echo json_encode($idNumber, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        qrCode: <?php echo json_encode($qrCode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        qrSrc: <?php echo json_encode($qrPath, JSON_UNESCAPED_SLASHES); ?>,
        logoSrc: <?php echo json_encode($logoPath, JSON_UNESCAPED_SLASHES); ?>
    };
    const preview = document.getElementById('cardPreview');
    const loading = document.getElementById('cardLoading');
    const shell = document.querySelector('.card-shell');
    const wrap = document.getElementById('cardCanvasWrap');

    function fitPreview(){
        if (window.matchMedia && window.matchMedia('print').matches) return;
        if (!shell || !wrap) return;
        const scale = Math.min(shell.clientWidth / 590, shell.clientHeight / 372);
        wrap.style.transform = 'scale(' + Math.max(0.1, scale) + ')';
    }

    async function renderCard(){
        try {
            const canvas = await window.libraryAccessCardClient.draw(data);
            preview.src = canvas.toDataURL('image/jpeg', 0.95);
            loading.style.display = 'none';
            window.__libraryCardCanvas = canvas;
        } catch (e) {
            loading.textContent = (e && e.message) ? e.message : 'Unable to prepare the Library Access Card.';
        }
    }

    document.getElementById('printCard').addEventListener('click', function(){ window.print(); });
    document.getElementById('downloadJpeg').addEventListener('click', async function(){
        try {
            if (!window.libraryAccessCardClient) throw new Error('Card generator is unavailable.');
            await window.libraryAccessCardClient.download(data);
        } catch (e) {
            alert((e && e.message) ? e.message : 'Unable to download the Library Access Card.');
        }
    });
    document.getElementById('downloadPdf').addEventListener('click', async function(){
        try {
            if (!window.libraryAccessCardClient) throw new Error('Card generator is unavailable.');
            await window.libraryAccessCardClient.downloadPdf(data);
        } catch (e) {
            alert((e && e.message) ? e.message : 'Unable to download the Library Access Card PDF.');
        }
    });
    document.getElementById('closeCard').addEventListener('click', function(){ window.location.href = <?php echo json_encode($closeUrl); ?>; });
    window.addEventListener('resize', fitPreview);
    fitPreview();
    renderCard();
})();
</script>
</body>
</html>
