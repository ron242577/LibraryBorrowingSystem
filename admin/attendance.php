<?php
require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/print_charts.php';

if (!isAdmin()) {
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
        $idColumn = $borrowerType === 'student' ? 'student_id' : 'teacher_id';
        $today = date('Y-m-d');
        $nowStamp = date('Y-m-d H:i:s');
        $conn->begin_transaction();
        try {
            // Look at this person's latest record today: still timed in = this scan is a Time Out.
            $latestStmt = $conn->prepare("SELECT attendance_id, time_in, time_out FROM library_attendance WHERE $idColumn = ? AND visit_date = ? ORDER BY time_in DESC, attendance_id DESC LIMIT 1 FOR UPDATE");
            $latestStmt->bind_param('is', $borrowerId, $today);
            $latestStmt->execute();
            $latest = $latestStmt->get_result()->fetch_assoc();
            $latestStmt->close();

            // Guard against one QR held in front of the camera flipping In -> Out immediately.
            if ($latest) {
                $lastEvent = strtotime($latest['time_out'] ?: $latest['time_in']);
                if ($lastEvent && (time() - $lastEvent) < 10) {
                    throw new Exception($borrower['full_name'] . ' was just recorded. Please wait a few seconds before scanning again.');
                }
            }

            if ($latest && empty($latest['time_out'])) {
                $attendanceId = (int)$latest['attendance_id'];
                $outStmt = $conn->prepare('UPDATE library_attendance SET time_out = ? WHERE attendance_id = ? AND time_out IS NULL');
                $outStmt->bind_param('si', $nowStamp, $attendanceId);
                if (!$outStmt->execute() || $outStmt->affected_rows < 1) {
                    throw new Exception('Unable to record time out.');
                }
                $outStmt->close();
                $conn->commit();
                $visitAction = 'time_out';
                $timeIn = $latest['time_in'];
                $timeOut = $nowStamp;
                $message = $borrower['full_name'] . ' (' . ucfirst($borrowerType) . ') timed out successfully at ' . date('h:i A') . '.';
            } else {
                if ($borrowerType === 'student') {
                    $insertStmt = $conn->prepare('INSERT INTO library_attendance (student_id, teacher_id, visit_date, time_in) VALUES (?, NULL, ?, ?)');
                } else {
                    $insertStmt = $conn->prepare('INSERT INTO library_attendance (student_id, teacher_id, visit_date, time_in) VALUES (NULL, ?, ?, ?)');
                }
                $insertStmt->bind_param('iss', $borrowerId, $today, $nowStamp);
                if (!$insertStmt->execute()) {
                    throw new Exception('Unable to record time in.');
                }
                $attendanceId = (int)$insertStmt->insert_id;
                $insertStmt->close();
                $conn->commit();
                $visitAction = 'time_in';
                $timeIn = $nowStamp;
                $timeOut = null;
                $message = $borrower['full_name'] . ' (' . ucfirst($borrowerType) . ') timed in successfully at ' . date('h:i A') . '.';
            }
            $message_type = 'success';
            $lastVisit = [
                'attendance_id' => $attendanceId,
                'action' => $visitAction,
                'full_name' => $borrower['full_name'],
                'borrower_no' => $borrower['borrower_no'],
                'borrower_type' => ucfirst($borrowerType),
                'time_in' => date('h:i A', strtotime($timeIn)),
                'time_out' => $timeOut ? date('h:i A', strtotime($timeOut)) : null,
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
        echo json_encode(['ok' => $message_type === 'success', 'message' => $message, 'action' => $lastVisit['action'] ?? null, 'visit' => $lastVisit]);
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
$listStmt = $conn->prepare("SELECT a.attendance_id, a.time_in, a.time_out, COALESCE(s.student_no COLLATE utf8mb4_unicode_ci, t.teacher_no COLLATE utf8mb4_unicode_ci) AS borrower_no, COALESCE(s.full_name COLLATE utf8mb4_unicode_ci, t.full_name COLLATE utf8mb4_unicode_ci) AS full_name, CASE WHEN a.student_id IS NULL THEN 'Teacher' ELSE 'Student' END AS borrower_type FROM library_attendance a LEFT JOIN students s ON s.student_id = a.student_id LEFT JOIN teachers t ON t.teacher_id = a.teacher_id WHERE a.visit_date BETWEEN ? AND ? ORDER BY a.time_in DESC");
$listStmt->bind_param('ss', $start_date, $end_date);
$listStmt->execute();
$listResult = $listStmt->get_result();
while ($row = $listResult->fetch_assoc()) {
    $attendance[] = $row;
}
$listStmt->close();

$totalVisits = count($attendance);

$openVisits = count(array_filter($attendance, function ($r) { return empty($r['time_out']); }));

if (($_GET['report'] ?? '') === '1'):
    $reportTitleDate = $start_date . ' to ' . $end_date;
    $monthOptions = printMonthOptions($start_date, $end_date);
    $selectedMonths = printSelectedMonths($monthOptions);
    $attendance = array_values(array_filter($attendance, function ($r) use ($selectedMonths) {
        return in_array(date('Y-m', strtotime($r['time_in'])), $selectedMonths, true);
    }));
    $totalVisits = count($attendance);
    $openVisits = count(array_filter($attendance, function ($r) { return empty($r['time_out']); }));
    if (count($selectedMonths) < count($monthOptions)) {
        $reportTitleDate = $selectedMonths ? implode(', ', array_map(function ($m) use ($monthOptions) { return $monthOptions[$m]; }, $selectedMonths)) : 'No months selected';
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time In/Time Out Report - <?php echo h($reportTitleDate); ?></title>
    <style>
<?php echo printReportStyles(); ?>
    </style>
</head>
<body>
<main class="report">
    <?php echo printReportToolbar('?start_date=' . $start_date . '&end_date=' . $end_date, 'Back to Time In/Time Out'); ?>
    <?php echo printMonthPicker($monthOptions, $selectedMonths, $start_date, $end_date); ?>
    <?php echo printFrameOpen(); ?>
    <?php echo printReportHeader('Time In/Time Out Report', $reportTitleDate); ?>
    <section class="summary">
        <div class="summary-card"><strong><?php echo $totalVisits; ?></strong><span>Total Visits</span></div>
        <div class="summary-card"><strong><?php echo $openVisits; ?></strong><span>No Time Out Yet</span></div>
    </section>
    <?php
    $attTypes = ['Student' => 0, 'Teacher' => 0];
    $attByDate = [];
    foreach ($attendance as $row) {
        $dk = date('Y-m-d', strtotime($row['time_in']));
        $attByDate[$dk] = ($attByDate[$dk] ?? 0) + 1;
        $attTypes[$row['borrower_type']] = ($attTypes[$row['borrower_type']] ?? 0) + 1;
    }
    echo $selectedMonths
        ? renderVisitCharts($attByDate, $start_date, $end_date, $selectedMonths)
        : '<section class="print-chart"><div class="chart-empty">No months selected. Tick at least one month above.</div></section>';
    echo renderBarChart('Visits by Borrower Type', array_keys($attTypes), [['name' => 'Visits', 'color' => '#567D1F', 'values' => array_values($attTypes)]]);
    ?>
    <table>
        <thead><tr><th>#</th><th>Borrower</th><th>Type</th><th>ID Number</th><th>Time In</th><th>Time Out</th></tr></thead>
        <tbody>
        <?php foreach ($attendance as $index => $row): ?>
            <tr><td><?php echo $index + 1; ?></td><td><?php echo h($row['full_name']); ?></td><td><?php echo h($row['borrower_type']); ?></td><td><?php echo h($row['borrower_no']); ?></td><td><?php echo h(date('M d, Y h:i A', strtotime($row['time_in']))); ?></td><td><?php echo !empty($row['time_out']) ? h(date('M d, Y h:i A', strtotime($row['time_out']))) : '&mdash;'; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$attendance): ?><tr><td colspan="6" style="text-align:center">No time in/time out recorded for this date.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php echo printFrameClose(); ?>
</main>
</body>
</html>
<?php exit(); endif; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Time In/Time Out - Library Borrowing System</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.4/html5-qrcode.min.js"></script>
    <style>
        :root{--navy:#141F52;--slate:#52618D;--sky:#D2E2F6;--mist:#E7EEF7;--bg:#F3F7FC;--ink:#202A44;--green:#567D1F;--green-bg:#EDF5DD;--red:#9B2335;--red-bg:#FBE4E7;--yellow:#F4F916}
        *{box-sizing:border-box}
        body.attendance-page{background:var(--bg);color:var(--ink);font-family:'Segoe UI',Tahoma,sans-serif}
        .attendance-container{max-width:1200px;margin:0 auto 40px;padding:0 20px;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:22px;align-items:start}
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

        .scanner-wrapper{text-align:center;background:#E7EEF7;padding:18px;border-radius:8px;margin:18px 0}
        #attendance-reader{width:100%;max-width:500px;margin:0 auto;border-radius:8px;overflow:hidden}
        .scanner-controls{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:15px}
        .scanner-controls button{padding:8px 16px;font-size:12px;font-weight:700;font-family:inherit;color:#fff;background:#141F52;border:none;border-radius:6px;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;justify-content:center;gap:6px}
        .scanner-controls button:hover{background:#52618D;transform:translateY(-2px);box-shadow:0 5px 15px rgba(20,31,82,.32)}
        .scanner-controls button.btn-danger{background:#c0392b}

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

        .list-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
        .count{display:inline-block;background:var(--navy);color:#fff;border-radius:999px;padding:3px 12px;font-size:13px;font-weight:700;margin-left:8px;vertical-align:middle}
        .date-filter{display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:14px;padding:12px;background:var(--mist);border-radius:10px}
        .date-filter label{display:block;font-size:11px;font-weight:700;color:var(--slate);margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px}
        .date-filter input{padding:8px 10px;border:1px solid var(--sky);border-radius:8px;font-size:13px;background:#fff}
        .date-filter .btn{padding:9px 14px;font-size:13px}
        .report-link{color:var(--navy);font-weight:700;font-size:13px;text-decoration:none}
        .report-link:hover{text-decoration:underline}
        .table-wrap{max-height:560px;overflow:auto;border:1px solid var(--mist);border-radius:10px}
        .table-wrap table{width:100%;min-width:0;border-collapse:collapse}
        th,td{padding:11px 12px;text-align:left;border-bottom:1px solid var(--mist);font-size:13px}
        th{background:var(--navy);color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:.5px;position:sticky;top:0;z-index:1}
        tbody tr:nth-child(even){background:#FAFCFF}
        tr.new{animation:flash 2.4s ease-out}
        @keyframes flash{from{background:#E4F0C8}to{background:transparent}}
        .name{font-weight:700;color:var(--navy)}
        .badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700}
        .badge.Student{background:#DCE8FA;color:#1F3A7A}
        .badge.Teacher{background:#EFE3F8;color:#5B2E7E}
        .empty td{text-align:center;color:var(--slate);padding:34px 12px}

        .attendance-shell{container-type:inline-size;container-name:att;width:100%}
        .list-card{container-type:inline-size;container-name:lst}
        @container att (max-width:900px){.attendance-container{grid-template-columns:1fr;gap:18px}}
        @container att (max-width:560px){.attendance-container{padding:0 12px}.card{padding:16px;border-radius:12px}}
        @container lst (max-width:440px){.table-wrap{max-height:none;border:0;overflow:visible}table,tbody{display:block}thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}tbody tr{display:grid;grid-template-columns:1fr auto;grid-template-areas:'name name' 'type id' 'time out';gap:6px 12px;align-items:center;padding:12px 14px;margin-bottom:10px;border:1px solid var(--mist);border-radius:12px;background:#fff!important}tbody td{display:block;padding:0;border:0;font-size:13px}tbody td:nth-child(1){grid-area:name;font-size:15px}tbody td:nth-child(2){grid-area:type}tbody td:nth-child(3){grid-area:id;justify-self:end;color:var(--slate)}tbody td:nth-child(4){grid-area:time;justify-self:start;font-weight:700;color:var(--navy);font-variant-numeric:tabular-nums}tbody td:nth-child(4)::before{content:'In ';font-weight:400;color:var(--slate)}tbody td:nth-child(5){grid-area:out;justify-self:end;font-weight:700;color:var(--navy);font-variant-numeric:tabular-nums}tbody td:nth-child(5)::before{content:'Out ';font-weight:400;color:var(--slate)}tr.empty{display:block!important;text-align:center;border-style:dashed}tr.empty td{padding:22px 8px;display:block} .date-filter>div{flex:1 1 130px}.date-filter input{width:100%}.date-filter .btn{flex:1 1 100%}}
        @supports not (container-type:inline-size){
            @media(max-width:1180px){.attendance-container{grid-template-columns:1fr}}
            @media(max-width:480px){.table-wrap{max-height:none;border:0;overflow:visible}table,tbody{display:block}thead{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}tbody tr{display:grid;grid-template-columns:1fr auto;grid-template-areas:'name name' 'type id' 'time out';gap:6px 12px;align-items:center;padding:12px 14px;margin-bottom:10px;border:1px solid var(--mist);border-radius:12px;background:#fff!important}tbody td{display:block;padding:0;border:0;font-size:13px}tbody td:nth-child(1){grid-area:name;font-size:15px}tbody td:nth-child(2){grid-area:type}tbody td:nth-child(3){grid-area:id;justify-self:end;color:var(--slate)}tbody td:nth-child(4){grid-area:time;justify-self:start;font-weight:700;color:var(--navy);font-variant-numeric:tabular-nums}tbody td:nth-child(4)::before{content:'In ';font-weight:400;color:var(--slate)}tbody td:nth-child(5){grid-area:out;justify-self:end;font-weight:700;color:var(--navy);font-variant-numeric:tabular-nums}tbody td:nth-child(5)::before{content:'Out ';font-weight:400;color:var(--slate)}tr.empty{display:block!important;text-align:center;border-style:dashed}tr.empty td{padding:22px 8px;display:block}}
        }
        @media(max-width:520px){.card{padding:18px}.manual .row{flex-direction:column}.manual .btn{width:100%}.clock strong{font-size:21px}.attendance-title{font-size:22px}}
    </style>
</head>
<body class="attendance-page">
<?php include __DIR__ . '/../navbar.php'; ?>
<?php include __DIR__ . '/../header.php'; ?>

<div class="attendance-shell">
<div class="attendance-container">
    <section class="card" aria-label="Scan station">
        <div class="card-head">
            <div>
                <h1 class="attendance-title">Time In/Time Out</h1>
                <p class="muted">Scan your QR code to time in. Scan it again when you leave to time out. You can also type your ID number below.</p>
            </div>
            <div class="clock" aria-live="off"><strong id="clockTime">--:--</strong><span id="clockDate"></span></div>
        </div>

        <div class="status<?php echo $message ? ' ' . h($message_type) : ''; ?>" id="status" role="status" aria-live="polite">
            <div class="icon" id="statusIcon"><?php echo $message_type === 'success' ? '&#10003;' : ($message_type === 'error' ? '!' : '&#9641;'); ?></div>
            <div>
                <div class="title" id="statusTitle"><?php echo $message_type === 'success' ? (($lastVisit['action'] ?? '') === 'time_out' ? 'Time out recorded' : 'Time in recorded') : ($message_type === 'error' ? 'Could not record' : 'Ready to scan'); ?></div>
                <div class="sub" id="statusSub"><?php echo $message ? h($message) : 'Waiting for the next QR code.'; ?></div>
            </div>
        </div>

        <div class="scanner-wrapper">
            <div id="attendance-reader"></div>
        </div>

        <div class="scanner-controls">
            <button type="button" class="btn-small" id="startCamBtn">Start Camera</button>
            <button type="button" class="btn-small btn-danger" id="stopCamBtn" style="display:none;">Stop Camera</button>
        </div>

        <form method="POST" class="manual" id="attendanceForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="toggle_attendance">
            <label for="identifier">No QR code? Enter ID number</label>
            <div class="row">
                <input type="text" id="identifier" name="identifier" placeholder="e.g. STU-20260816-EE8626" autocomplete="off" required autofocus>
                <button class="btn" type="submit" id="submitBtn">Time In / Time Out</button>
            </div>
        </form>
    </section>

    <section class="card list-card" aria-label="Attendance list">
        <div class="list-head">
            <h2 class="attendance-title" style="font-size:20px">Visits<span class="count" id="visitCount"><?php echo $totalVisits; ?></span></h2>
            <a class="report-link" href="?report=1&amp;start_date=<?php echo h($start_date); ?>&amp;end_date=<?php echo h($end_date); ?>" target="_blank" rel="noopener">Print report &rarr;</a>
        </div>
        <form method="GET" class="date-filter">
            <div><label for="start_date">From</label><input type="date" id="start_date" name="start_date" value="<?php echo h($start_date); ?>"></div>
            <div><label for="end_date">To</label><input type="date" id="end_date" name="end_date" value="<?php echo h($end_date); ?>"></div>
            <button class="btn" type="submit">Filter</button>
        </form>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Type</th><th>ID No.</th><th>Time In</th><th>Time Out</th></tr></thead>
                <tbody id="visitBody">
                <?php foreach ($attendance as $row): ?>
                    <tr data-id="<?php echo (int)$row['attendance_id']; ?>">
                        <td class="name"><?php echo h($row['full_name']); ?></td>
                        <td><span class="badge <?php echo h($row['borrower_type']); ?>"><?php echo h($row['borrower_type']); ?></span></td>
                        <td><?php echo h($row['borrower_no']); ?></td>
                        <td><?php echo h(date(($start_date === $end_date) ? 'h:i A' : 'M d, h:i A', strtotime($row['time_in']))); ?></td>
                        <td><?php echo !empty($row['time_out']) ? h(date(($start_date === $end_date) ? 'h:i A' : 'M d, h:i A', strtotime($row['time_out']))) : '&mdash;'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$attendance): ?><tr class="empty"><td colspan="5">No visits recorded for this period yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
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
    const visitBody = document.getElementById('visitBody');
    const visitCount = document.getElementById('visitCount');
    const startCamBtn = document.getElementById('startCamBtn');
    const stopCamBtn = document.getElementById('stopCamBtn');
    const showsToday = <?php echo json_encode($start_date === date('Y-m-d') && $end_date === date('Y-m-d')); ?>;

    let busy = false, resetTimer = null, lastCode = '', lastCodeAt = 0, scanner = null;

    function esc(v) { const d = document.createElement('div'); d.textContent = v == null ? '' : String(v); return d.innerHTML; }

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

    function rowHtml(v) {
        return '<td class="name">' + esc(v.full_name) + '</td><td><span class="badge ' + esc(v.borrower_type) + '">' + esc(v.borrower_type) + '</span></td><td>' + esc(v.borrower_no) + '</td><td>' + esc(v.time_in) + '</td><td>' + (v.time_out ? esc(v.time_out) : '&mdash;') + '</td>';
    }

    // Time In adds a new row; Time Out fills in the Time Out cell of the existing row.
    function upsertRow(v) {
        if (!showsToday) return;
        const existing = visitBody.querySelector('tr[data-id="' + String(v.attendance_id) + '"]');
        if (existing) {
            existing.innerHTML = rowHtml(v);
            existing.classList.remove('new');
            void existing.offsetWidth;
            existing.classList.add('new');
            return;
        }
        const empty = visitBody.querySelector('.empty');
        if (empty) empty.remove();
        const tr = document.createElement('tr');
        tr.className = 'new';
        tr.setAttribute('data-id', String(v.attendance_id));
        tr.innerHTML = rowHtml(v);
        visitBody.insertBefore(tr, visitBody.firstChild);
        visitCount.textContent = String(parseInt(visitCount.textContent, 10) + 1);
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
                const v = data.visit;
                if (v.action === 'time_out') {
                    setStatus('success', v.full_name, v.borrower_type + ' \u2022 timed out at ' + v.time_out, '\u2713');
                } else {
                    setStatus('success', v.full_name, v.borrower_type + ' \u2022 timed in at ' + v.time_in, '\u2713');
                }
                upsertRow(v);
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
        // ignore the same code seen again within 10s so one scan = one record
        if (busy || (code === lastCode && now - lastCodeAt < 10000)) return;
        lastCode = code; lastCodeAt = now;
        submitCode(code);
    }

    function startScanner() {
        if (typeof Html5QrcodeScanner === 'undefined') {
            document.getElementById('attendance-reader').textContent = 'Camera scanner could not load. Use the ID number field below.';
            return;
        }
        startCamBtn.style.display = 'none';
        stopCamBtn.style.display = 'inline-flex';
        scanner = new Html5QrcodeScanner('attendance-reader', { facingMode: 'environment', qrbox: undefined }, false);
        scanner.render(onScan, function () {});
    }

    function stopScanner() {
        if (scanner) {
            scanner.clear();
            scanner = null;
        }
        startCamBtn.style.display = 'inline-flex';
        stopCamBtn.style.display = 'none';
    }

    startCamBtn.addEventListener('click', startScanner);
    stopCamBtn.addEventListener('click', stopScanner);

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
