<?php
require_once(__DIR__ . '/session_check.php');
require_once(__DIR__ . "/db.php");

if (!isAdmin() && !hasRole('attendance_kiosk')) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function normalizeAttendanceInput($value) {
    $value = trim((string)$value);
    if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL)) {
        $query = parse_url($value, PHP_URL_QUERY);
        parse_str((string)$query, $params);
        $value = trim((string)($params['code'] ?? $params['qr'] ?? $value));
    }
    return $value;
}

$message = '';
$message_type = '';
$lastVisit = null;
$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_attendance') {
    try {
        requireValidCsrf($_POST['csrf_token'] ?? '');
        $identifier = normalizeAttendanceInput($_POST['identifier'] ?? '');
        if ($identifier === '') {
            throw new Exception('Enter a student or teacher QR code or ID number.');
        }

        $studentStmt = $conn->prepare("SELECT student_id, student_no AS borrower_no, full_name FROM students WHERE (qr_code = ? OR student_no = ?) AND status = 'active' AND COALESCE(is_archived,0) = 0 LIMIT 1");
        $studentStmt->bind_param('ss', $identifier, $identifier);
        $studentStmt->execute();
        $borrower = $studentStmt->get_result()->fetch_assoc();
        $studentStmt->close();
        $borrowerType = 'student';

        if (!$borrower) {
            $teacherStmt = $conn->prepare("SELECT teacher_id, teacher_no AS borrower_no, full_name FROM teachers WHERE (qr_code = ? OR teacher_no = ?) AND status = 'active' AND COALESCE(is_archived,0) = 0 LIMIT 1");
            $teacherStmt->bind_param('ss', $identifier, $identifier);
            $teacherStmt->execute();
            $borrower = $teacherStmt->get_result()->fetch_assoc();
            $teacherStmt->close();
            $borrowerType = 'teacher';
        }

        if (!$borrower) {
            throw new Exception('Active student or teacher not found. Check the QR code or ID number.');
        }

        $borrowerId = (int)($borrowerType === 'student' ? $borrower['student_id'] : $borrower['teacher_id']);
        $today = date('Y-m-d');
        $conn->begin_transaction();
        try {
            $timeIn = date('Y-m-d H:i:s');
            if ($borrowerType === 'student') {
                $insertStmt = $conn->prepare('INSERT INTO library_attendance (student_id, teacher_id, visit_date, time_in) VALUES (?, NULL, ?, ?)');
            } else {
                $insertStmt = $conn->prepare('INSERT INTO library_attendance (student_id, teacher_id, visit_date, time_in) VALUES (NULL, ?, ?, ?)');
            }
            $insertStmt->bind_param('iss', $borrowerId, $today, $timeIn);
            if (!$insertStmt->execute()) {
                throw new Exception('Unable to record time in.');
            }
            $insertStmt->close();
            $conn->commit();
            $message = $borrower['full_name'] . ' (' . ucfirst($borrowerType) . ') timed in successfully at ' . date('h:i A') . '.';
            $message_type = 'success';
            $lastVisit = [
                'full_name' => $borrower['full_name'],
                'borrower_no' => $borrower['borrower_no'],
                'borrower_type' => ucfirst($borrowerType),
                'time_in' => date('h:i A', strtotime($timeIn)),
            ];
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $message_type = 'error';
        logError('Attendance error: ' . $e->getMessage());
    }
    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => $message_type === 'success', 'message' => $message, 'visit' => $lastVisit]);
        exit();
    }
}

$attendance = [];
$start_date = $_GET['start_date'] ?? ($_GET['date'] ?? date('Y-m-d'));
$end_date = $_GET['end_date'] ?? $start_date;
foreach (['start_date','end_date'] as $d) {
    if (!DateTime::createFromFormat('Y-m-d', ${$d}) || DateTime::createFromFormat('Y-m-d', ${$d})->format('Y-m-d') !== ${$d}) {
        ${$d} = date('Y-m-d');
    }
}
$reportDateObject = new DateTime($start_date);
if (($_GET['report'] ?? '') === '1') {
$listStmt = $conn->prepare("SELECT a.time_in, COALESCE(s.student_no COLLATE utf8mb4_unicode_ci, t.teacher_no COLLATE utf8mb4_unicode_ci) AS borrower_no, COALESCE(s.full_name COLLATE utf8mb4_unicode_ci, t.full_name COLLATE utf8mb4_unicode_ci) AS full_name, CASE WHEN a.student_id IS NULL THEN 'Teacher' ELSE 'Student' END AS borrower_type FROM library_attendance a LEFT JOIN students s ON s.student_id = a.student_id LEFT JOIN teachers t ON t.teacher_id = a.teacher_id WHERE a.visit_date BETWEEN ? AND ? ORDER BY a.time_in DESC");
$listStmt->bind_param('ss', $start_date, $end_date);
$listStmt->execute();
$listResult = $listStmt->get_result();
while ($row = $listResult->fetch_assoc()) {
    $attendance[] = $row;
}
$listStmt->close();
}

$totalVisits = count($attendance);

$openVisits = $totalVisits;

if (($_GET['report'] ?? '') === '1'):
    $reportTitleDate = $start_date . ' to ' . $end_date;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Attendance Report - <?php echo h($reportTitleDate); ?></title>
    <style>
        *{box-sizing:border-box}.kiosk-nav{background:#141F52;color:white;padding:15px 25px}.kiosk-nav h2{margin:0;font-size:20px}body{margin:0;padding:28px;color:#202A44;font-family:Arial,sans-serif}.report{max-width:1050px;margin:0 auto}.report-header{text-align:center;border-bottom:3px solid #141F52;padding-bottom:16px;margin-bottom:22px}.report-header img{width:58px;height:58px;object-fit:contain;border-radius:50%;vertical-align:middle;margin-bottom:8px}.report-header h1{margin:0;color:#141F52;font-size:25px}.report-header p{margin:5px 0;color:#52618D;font-size:13px}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px}.summary-card{border:1px solid #D2E2F6;border-left:4px solid #141F52;padding:14px;border-radius:6px}.summary-card strong{display:block;font-size:24px;color:#141F52}.summary-card span{font-size:12px;color:#52618D}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:9px;border:1px solid #D2E2F6;text-align:left}th{background:#141F52;color:#fff}.report-actions{margin-top:20px;display:flex;gap:10px}.report-actions button,.report-actions a{border:0;border-radius:5px;padding:10px 16px;background:#141F52;color:#fff;text-decoration:none;cursor:pointer;font-weight:700}.report-actions a{background:#52618D}@media print{body{padding:0}.report-actions{display:none}.report-header{margin-top:0}}@media(max-width:600px){.summary{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main class="report">
    <header class="report-header">
        <img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo">
        <h1>Jose Abad Santos High School</h1>
        <p>Library Attendance Report</p>
        <p><?php echo h($reportTitleDate); ?></p>
    </header>
    <section class="summary">
        <div class="summary-card"><strong><?php echo $totalVisits; ?></strong><span>Total Visits</span></div>
        <div class="summary-card"><strong><?php echo $openVisits; ?></strong><span>Total Time In Records</span></div>
    </section>
    <table>
        <thead><tr><th>#</th><th>Borrower</th><th>Type</th><th>ID Number</th><th>Time In</th></tr></thead>
        <tbody>
        <?php foreach ($attendance as $index => $row): ?>
            <tr><td><?php echo $index + 1; ?></td><td><?php echo h($row['full_name']); ?></td><td><?php echo h($row['borrower_type']); ?></td><td><?php echo h($row['borrower_no']); ?></td><td><?php echo h(date('M d, Y h:i A', strtotime($row['time_in']))); ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$attendance): ?><tr><td colspan="5" style="text-align:center">No attendance recorded for this date.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <div class="report-actions"><button type="button" onclick="window.print()">Print Report</button><a href="?start_date=<?php echo h($start_date); ?>&end_date=<?php echo h($end_date); ?>">Back to Attendance</a></div>
</main>
</body>
</html>
<?php exit(); endif; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Attendance - Library Borrowing System</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.4/html5-qrcode.min.js"></script>
    <style>
        :root{--navy:#141F52;--slate:#52618D;--sky:#D2E2F6;--mist:#E7EEF7;--bg:#F3F7FC;--ink:#202A44;--green:#567D1F;--green-bg:#EDF5DD;--red:#9B2335;--red-bg:#FBE4E7;--yellow:#F4F916}
        *{box-sizing:border-box}
        body.attendance-page{margin:0;background:var(--bg);color:var(--ink);font-family:'Segoe UI',Tahoma,sans-serif}
        .kiosk-nav{display:flex;justify-content:space-between;align-items:center;gap:12px;max-width:760px;margin:-8px auto 0;padding:0 20px}
        .kiosk-nav .crumb{font-size:13px;font-weight:600;color:var(--slate)}
        .kiosk-nav a.logout{color:var(--navy);font-weight:700;font-size:13px;text-decoration:none;padding:8px 14px;border:1px solid var(--sky);border-radius:8px;background:#fff}
        .kiosk-nav a.logout:hover{background:var(--navy);color:#fff}
        .attendance-container{max-width:760px;margin:18px auto 40px;padding:0 20px}
        .card{background:#fff;border:1px solid var(--sky);border-radius:14px;padding:24px;box-shadow:0 4px 18px rgba(20,31,82,.07)}
        .card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:6px}
        .attendance-title{margin:0;color:var(--navy);font-size:26px;line-height:1.15}
        .muted{color:var(--slate);font-size:14px;line-height:1.5;margin:6px 0 0}
        .card-head>div:first-child{flex:1 1 240px;min-width:0}.clock{text-align:right;flex-shrink:0}
        .clock strong{display:block;font-size:26px;color:var(--navy);font-variant-numeric:tabular-nums;line-height:1}
        .clock span{font-size:12px;color:var(--slate)}

        .status{display:flex;align-items:center;gap:14px;margin:18px 0 0;padding:16px 18px;border-radius:12px;border:1px solid var(--sky);background:var(--mist);min-height:78px;transition:background .2s,border-color .2s}
        .status .icon{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;font-size:22px;font-weight:800;background:#fff;color:var(--slate);flex-shrink:0}
        .status .title{font-weight:800;font-size:17px;color:var(--navy)}
        .status .sub{font-size:13px;color:var(--slate);margin-top:2px;word-break:break-word}
        .status.success{background:var(--green-bg);border-color:#C5DD9A}
        .status.success .icon{background:var(--green);color:#fff}
        .status.success .title{color:#344E15}
        .status.error{background:var(--red-bg);border-color:#F0B8C0}
        .status.error .icon{background:var(--red);color:#fff}
        .status.error .title{color:var(--red)}
        .status.busy .icon{animation:pulse 1s infinite}
        @keyframes pulse{50%{opacity:.35}}

        .scanner-wrap{margin-top:18px;position:relative;border-radius:14px;overflow:hidden;background:#0c1333;aspect-ratio:16/10;max-height:360px;width:100%}
        #attendance-reader{width:100%;height:100%}
        #attendance-reader video{width:100%!important;height:100%!important;object-fit:cover;display:block}
        #attendance-reader img{display:none}
        .reticle{position:absolute;inset:0;pointer-events:none;display:grid;place-items:center}
        .reticle i{width:58%;aspect-ratio:1;border-radius:18px;box-shadow:0 0 0 999px rgba(12,19,51,.45);border:2px solid rgba(255,255,255,.9)}
        .scanner-msg{position:absolute;inset:0;display:none;flex-direction:column;align-items:center;justify-content:center;gap:12px;text-align:center;color:#fff;padding:24px;font-size:14px;background:#0c1333}
        .scanner-msg.show{display:flex}
        .scanner-msg button{margin-top:4px}

        .btn{border:0;border-radius:10px;padding:13px 20px;background:var(--navy);color:#fff;font:700 14px 'Segoe UI',Tahoma,sans-serif;cursor:pointer;transition:background .15s}
        .btn:hover{background:var(--slate)}
        .btn:disabled{opacity:.6;cursor:wait}
        .btn.light{background:#fff;color:var(--navy);border:1px solid var(--sky)}
        .btn.light:hover{background:var(--mist)}

        .manual{margin-top:20px;padding-top:20px;border-top:1px dashed var(--sky)}
        .manual label{display:block;font-weight:700;font-size:13px;margin-bottom:8px;color:var(--navy)}
        .manual .row{display:flex;gap:10px}
        .manual input{flex:1;min-width:0;padding:14px;border:2px solid var(--sky);border-radius:10px;font-size:16px;color:var(--ink)}
        .manual input:focus{outline:0;border-color:var(--navy);box-shadow:0 0 0 3px rgba(20,31,82,.12)}

        .attendance-shell{container-type:inline-size;container-name:att;width:100%}
        @container att (max-width:560px){.attendance-container{padding:0 12px}.card{padding:16px;border-radius:12px}.scanner-wrap{aspect-ratio:1/1;max-height:360px}}
        @media(max-height:520px) and (orientation:landscape){.scanner-wrap{aspect-ratio:16/9;max-height:300px}}
        @media(max-width:520px){.card{padding:18px}.manual .row{flex-direction:column}.manual .btn{width:100%}.clock strong{font-size:21px}.attendance-title{font-size:22px}}
    </style>
</head>
<body class="attendance-page">
<?php include __DIR__ . '/header.php'; ?>
<nav class="kiosk-nav">
    <span class="crumb">Library Attendance Kiosk</span>
    <a class="logout" href="/LibraryBorrowingSystem/logout.php">Logout</a>
</nav>

<div class="attendance-shell">
<div class="attendance-container">
    <section class="card" aria-label="Scan station">
        <div class="card-head">
            <div>
                <h1 class="attendance-title">Library Attendance</h1>
                <p class="muted">Hold your QR code up to the camera, or type your ID number below. The camera stays on for the next person.</p>
            </div>
            <div class="clock" aria-live="off"><strong id="clockTime">--:--</strong><span id="clockDate"></span></div>
        </div>

        <div class="status<?php echo $message ? ' ' . h($message_type) : ''; ?>" id="status" role="status" aria-live="polite">
            <div class="icon" id="statusIcon"><?php echo $message_type === 'success' ? '&#10003;' : ($message_type === 'error' ? '!' : '&#9641;'); ?></div>
            <div>
                <div class="title" id="statusTitle"><?php echo $message_type === 'success' ? 'Time in recorded' : ($message_type === 'error' ? 'Could not record' : 'Ready to scan'); ?></div>
                <div class="sub" id="statusSub"><?php echo $message ? h($message) : 'Waiting for the next QR code.'; ?></div>
            </div>
        </div>

        <div class="scanner-wrap">
            <div id="attendance-reader"></div>
            <div class="reticle"><i></i></div>
            <div class="scanner-msg" id="scannerMsg">
                <div id="scannerMsgText">Starting camera...</div>
                <button type="button" class="btn light" id="retryCamera" style="display:none">Try camera again</button>
            </div>
        </div>

        <form method="POST" class="manual" id="attendanceForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="toggle_attendance">
            <label for="identifier">No QR code? Enter ID number</label>
            <div class="row">
                <input type="text" id="identifier" name="identifier" placeholder="e.g. STU-20260816-EE8626" autocomplete="off" required autofocus>
                <button class="btn" type="submit" id="submitBtn">Record Time In</button>
            </div>
        </form>
    </section>

</div>
</div>

<script>
(function () {
    const form = document.getElementById('attendanceForm');
    const input = document.getElementById('identifier');
    const submitBtn = document.getElementById('submitBtn');
    const status = document.getElementById('status');
    const statusIcon = document.getElementById('statusIcon');
    const statusTitle = document.getElementById('statusTitle');
    const statusSub = document.getElementById('statusSub');
    const scannerMsg = document.getElementById('scannerMsg');
    const scannerMsgText = document.getElementById('scannerMsgText');
    const retryBtn = document.getElementById('retryCamera');

    let busy = false, resetTimer = null, lastCode = '', lastCodeAt = 0, scanner = null;

    function setStatus(kind, title, sub, icon) {
        status.className = 'status ' + kind;
        statusIcon.textContent = icon;
        statusTitle.textContent = title;
        statusSub.textContent = sub;
        clearTimeout(resetTimer);
        if (kind === 'success' || kind === 'error') {
            resetTimer = setTimeout(function () {
                setStatus('', 'Ready to scan', 'Waiting for the next QR code.', '\u25A1');
            }, kind === 'success' ? 5000 : 7000);
        }
    }

    function normalizeScan(value) {
        const text = String(value || '').trim();
        try {
            const url = new URL(text);
            return (url.searchParams.get('code') || url.searchParams.get('qr') || text).trim();
        } catch (e) { return text; }
    }

    async function submitCode(code) {
        if (busy || !code) return;
        busy = true;
        submitBtn.disabled = true;
        setStatus('busy', 'Checking...', code, '\u2026');
        try {
            const body = new FormData(form);
            body.set('identifier', code);
            const res = await fetch(window.location.pathname, {
                method: 'POST',
                body: body,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.ok && data.visit) {
                setStatus('success', data.visit.full_name, data.visit.borrower_type + ' \u2022 timed in at ' + data.visit.time_in, '\u2713');
                input.value = '';
            } else {
                setStatus('error', 'Could not record', data.message || 'Something went wrong.', '!');
            }
        } catch (e) {
            setStatus('error', 'Connection problem', 'Could not reach the server. Please try again or refresh the page.', '!');
        } finally {
            busy = false;
            submitBtn.disabled = false;
            if (!input.matches(':focus')) input.focus({ preventScroll: true });
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        submitCode(normalizeScan(input.value));
    });

    function onScan(decoded) {
        const code = normalizeScan(decoded);
        const now = Date.now();
        // ignore the same code seen again within 4s so one scan = one record
        if (busy || (code === lastCode && now - lastCodeAt < 4000)) return;
        lastCode = code; lastCodeAt = now;
        submitCode(code);
    }

    function showCameraMsg(text, canRetry) {
        scannerMsgText.textContent = text;
        retryBtn.style.display = canRetry ? '' : 'none';
        scannerMsg.classList.add('show');
    }

    async function startScanner() {
        if (typeof Html5Qrcode === 'undefined') {
            showCameraMsg('Camera scanner could not load. Use the ID number field below.', false);
            return;
        }
        showCameraMsg('Starting camera...', false);
        try {
            if (!scanner) scanner = new Html5Qrcode('attendance-reader');
            await scanner.start(
                { facingMode: 'environment' },
                { fps: 15, qrbox: function (w, h) { const s = Math.floor(Math.min(w, h) * 0.6); return { width: s, height: s }; } },
                onScan,
                function () {}
            );
            scannerMsg.classList.remove('show');
        } catch (err) {
            showCameraMsg('Camera unavailable. Allow camera access in your browser, or use the ID number field below.', true);
        }
    }
    retryBtn.addEventListener('click', startScanner);
    window.addEventListener('load', startScanner);

    function tick() {
        const d = new Date();
        document.getElementById('clockTime').textContent = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        document.getElementById('clockDate').textContent = d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
    }
    tick(); setInterval(tick, 15000);
})();
</script>
</body>
</html>
