<?php
require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

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
            $openStmt = $conn->prepare('SELECT attendance_id, time_in FROM library_attendance WHERE ((student_id = ? AND ? = 1) OR (teacher_id = ? AND ? = 0)) AND visit_date = ? AND time_out IS NULL ORDER BY time_in DESC LIMIT 1 FOR UPDATE');
            $isStudent = $borrowerType === 'student' ? 1 : 0;
            $openStmt->bind_param('iiiis', $borrowerId, $isStudent, $borrowerId, $isStudent, $today);
            $openStmt->execute();
            $openVisit = $openStmt->get_result()->fetch_assoc();
            $openStmt->close();

            if ($openVisit) {
                $attendanceId = (int)$openVisit['attendance_id'];
                $timeOut = date('Y-m-d H:i:s');
                $updateStmt = $conn->prepare('UPDATE library_attendance SET time_out = ? WHERE attendance_id = ?');
                $updateStmt->bind_param('si', $timeOut, $attendanceId);
                if (!$updateStmt->execute()) {
                    throw new Exception('Unable to record time out.');
                }
                $updateStmt->close();
                $actionLabel = 'timed out';
            } else {
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
                $actionLabel = 'timed in';
            }
            $conn->commit();
            $message = $borrower['full_name'] . ' (' . ucfirst($borrowerType) . ') ' . $actionLabel . ' successfully at ' . date('h:i A') . '.';
            $message_type = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $message_type = 'error';
        logError('Attendance error: ' . $e->getMessage());
    }
}

$attendance = [];
$reportDate = $_GET['date'] ?? date('Y-m-d');
$reportDateObject = DateTime::createFromFormat('Y-m-d', $reportDate);
if (!$reportDateObject || $reportDateObject->format('Y-m-d') !== $reportDate) {
    $reportDate = date('Y-m-d');
    $reportDateObject = new DateTime($reportDate);
}
$listStmt = $conn->prepare("SELECT a.time_in, a.time_out, COALESCE(s.student_no, t.teacher_no) COLLATE utf8mb4_unicode_ci AS borrower_no, COALESCE(s.full_name, t.full_name) COLLATE utf8mb4_unicode_ci AS full_name, CASE WHEN a.student_id IS NULL THEN 'Teacher' ELSE 'Student' END AS borrower_type FROM library_attendance a LEFT JOIN students s ON s.student_id = a.student_id LEFT JOIN teachers t ON t.teacher_id = a.teacher_id WHERE a.visit_date = ? ORDER BY a.time_in DESC");
$listStmt->bind_param('s', $reportDate);
$listStmt->execute();
$listResult = $listStmt->get_result();
while ($row = $listResult->fetch_assoc()) {
    $attendance[] = $row;
}
$listStmt->close();

$totalVisits = count($attendance);
$completedVisits = count(array_filter($attendance, static fn($row) => !empty($row['time_out'])));
$openVisits = $totalVisits - $completedVisits;

if (($_GET['report'] ?? '') === '1'):
    $reportTitleDate = $reportDateObject->format('F d, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Attendance Report - <?php echo h($reportTitleDate); ?></title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:28px;color:#202A44;font-family:Arial,sans-serif}.report{max-width:1050px;margin:0 auto}.report-header{text-align:center;border-bottom:3px solid #141F52;padding-bottom:16px;margin-bottom:22px}.report-header img{width:58px;height:58px;object-fit:contain;border-radius:50%;vertical-align:middle;margin-bottom:8px}.report-header h1{margin:0;color:#141F52;font-size:25px}.report-header p{margin:5px 0;color:#52618D;font-size:13px}.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px}.summary-card{border:1px solid #D2E2F6;border-left:4px solid #141F52;padding:14px;border-radius:6px}.summary-card strong{display:block;font-size:24px;color:#141F52}.summary-card span{font-size:12px;color:#52618D}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:9px;border:1px solid #D2E2F6;text-align:left}th{background:#141F52;color:#fff}.report-actions{margin-top:20px;display:flex;gap:10px}.report-actions button,.report-actions a{border:0;border-radius:5px;padding:10px 16px;background:#141F52;color:#fff;text-decoration:none;cursor:pointer;font-weight:700}.report-actions a{background:#52618D}@media print{body{padding:0}.report-actions{display:none}.report-header{margin-top:0}}@media(max-width:600px){.summary{grid-template-columns:1fr}}
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
        <div class="summary-card"><strong><?php echo $completedVisits; ?></strong><span>Completed Visits</span></div>
        <div class="summary-card"><strong><?php echo $openVisits; ?></strong><span>Currently Inside</span></div>
    </section>
    <table>
        <thead><tr><th>#</th><th>Borrower</th><th>Type</th><th>ID Number</th><th>Time In</th><th>Time Out</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($attendance as $index => $row): ?>
            <tr><td><?php echo $index + 1; ?></td><td><?php echo h($row['full_name']); ?></td><td><?php echo h($row['borrower_type']); ?></td><td><?php echo h($row['borrower_no']); ?></td><td><?php echo h(date('M d, Y h:i A', strtotime($row['time_in']))); ?></td><td><?php echo $row['time_out'] ? h(date('M d, Y h:i A', strtotime($row['time_out']))) : 'Still in library'; ?></td><td><?php echo $row['time_out'] ? 'Completed' : 'Inside'; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$attendance): ?><tr><td colspan="7" style="text-align:center">No attendance recorded for this date.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <div class="report-actions"><button type="button" onclick="window.print()">Print Report</button><a href="attendance.php?date=<?php echo h($reportDate); ?>">Back to Attendance</a></div>
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
        *{box-sizing:border-box}.attendance-page{background:#F3F7FC;color:#202A44;font-family:'Segoe UI',Tahoma,sans-serif}.attendance-container{max-width:1050px;margin:30px auto;padding:0 20px}.attendance-card{background:#fff;border-radius:10px;padding:25px;margin-bottom:22px;box-shadow:0 2px 10px rgba(0,0,0,.08)}.attendance-title{margin:0 0 8px;color:#141F52;font-size:26px}.muted{color:#52618D;font-size:14px}.alert{padding:13px 15px;border-radius:7px;margin:16px 0}.success{background:#EDF5DD;color:#344E15}.error{background:#F8D7DA;color:#721C24}.attendance-form{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end;margin-top:22px}.field label{display:block;font-weight:600;font-size:13px;margin-bottom:7px}.field input{width:100%;padding:13px;border:1px solid #D2E2F6;border-radius:6px;font-size:16px}.attendance-button{border:0;border-radius:6px;padding:13px 20px;background:#141F52;color:#fff;font:600 14px 'Segoe UI',Tahoma,sans-serif;cursor:pointer}.attendance-button:hover{background:#52618D}.scanner{margin-top:20px;background:#E7EEF7;border-radius:8px;padding:16px;text-align:center}.scanner-controls{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:12px}.attendance-button.secondary{background:#666}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:12px;text-align:left;border-bottom:1px solid #E7EEF7;font-size:13px}th{background:#141F52;color:#fff;font-size:12px}.open{color:#567D1F;font-weight:700}.closed{color:#52618D}.attendance-toolbar{display:flex;justify-content:space-between;gap:16px;align-items:end;flex-wrap:wrap;margin-bottom:18px}.date-filter{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.date-filter label{display:block;font-size:12px;font-weight:600;color:#52618D;margin-bottom:5px}.date-filter input{padding:10px;border:1px solid #D2E2F6;border-radius:6px}.report-link{color:#141F52;font-weight:600;text-decoration:none}.report-link:hover{text-decoration:underline}@media(max-width:700px){.attendance-form{grid-template-columns:1fr}.attendance-form .attendance-button{width:100%}}
        
    </style>
</head>
<body class="attendance-page">
<?php include __DIR__ . '/../navbar.php'; ?>
<?php include __DIR__ . '/../header.php'; ?>
<div class="attendance-container">
    <div class="attendance-card">
        <h1 class="attendance-title">Library Attendance</h1>
        <div class="muted">Enter a student or teacher QR code or ID number. The first scan records time in; the next scan records time out.</div>
        <?php if ($message): ?><div class="alert <?php echo h($message_type); ?>"><?php echo h($message); ?></div><?php endif; ?>
        <form method="POST" class="attendance-form" id="attendanceForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="toggle_attendance">
            <div class="field">
                <label for="identifier">Student or Teacher QR Code / ID Number</label>
                <input type="text" id="identifier" name="identifier" placeholder="e.g. STU-20260816-EE8626 or TCH-20260816-ABC123" autocomplete="off" required autofocus>
            </div>
            <button class="attendance-button" type="submit">Record Time In / Out</button>
        </form>
        <div class="scanner">
            <div id="attendance-reader"></div>
            <div class="scanner-controls">
                <button class="attendance-button" type="button" id="startScanner">Scan QR Code</button>
                <button class="attendance-button secondary" type="button" id="stopScanner" hidden>Stop Scanner</button>
            </div>
        </div>
    </div>

    <div class="attendance-card">
        <div class="attendance-toolbar">
            <div><h2>Attendance Records</h2><div class="muted"><?php echo h($reportDateObject->format('F d, Y')); ?></div></div>
            <form class="date-filter" method="GET">
                <div><label for="attendance-date">Report date</label><input id="attendance-date" type="date" name="date" value="<?php echo h($reportDate); ?>"></div>
                <button class="attendance-button" type="submit">View Date</button>
            </form>
            <a class="report-link" href="attendance.php?report=1&amp;date=<?php echo h($reportDate); ?>" target="_blank" rel="noopener">Print Attendance Report</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Borrower</th><th>Type</th><th>ID Number</th><th>Time In</th><th>Time Out</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($attendance as $row): ?>
                    <tr>
                        <td><?php echo h($row['full_name']); ?></td>
                        <td><?php echo h($row['borrower_type']); ?></td>
                        <td><?php echo h($row['borrower_no']); ?></td>
                        <td><?php echo h(date('M d, Y h:i A', strtotime($row['time_in']))); ?></td>
                        <td><?php echo $row['time_out'] ? h(date('M d, Y h:i A', strtotime($row['time_out']))) : 'Still in library'; ?></td>
                        <td class="<?php echo $row['time_out'] ? 'closed' : 'open'; ?>"><?php echo $row['time_out'] ? 'Completed' : 'Inside'; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$attendance): ?><tr><td colspan="6" style="text-align:center;color:#777;padding:25px">No attendance recorded for this date.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
let scanner = null;
const identifier = document.getElementById('identifier');
const form = document.getElementById('attendanceForm');
function normalizeScan(value) {
    const text = String(value || '').trim();
    try {
        const url = new URL(text);
        return (url.searchParams.get('code') || url.searchParams.get('qr') || text).trim();
    } catch (error) {
        return text;
    }
}
function stopAttendanceScanner() {
    if (scanner) {
        scanner.clear();
        scanner = null;
    }
    document.getElementById('startScanner').hidden = false;
    document.getElementById('stopScanner').hidden = true;
}
document.getElementById('startScanner').addEventListener('click', function () {
    this.hidden = true;
    document.getElementById('stopScanner').hidden = false;
    scanner = new Html5QrcodeScanner('attendance-reader', {facingMode: 'environment', qrbox: 250}, false);
    scanner.render(function (decodedText) {
        identifier.value = normalizeScan(decodedText);
        stopAttendanceScanner();
        form.submit();
    }, function () {});
});
document.getElementById('stopScanner').addEventListener('click', stopAttendanceScanner);
</script>
</body>
</html>
