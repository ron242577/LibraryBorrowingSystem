<?php
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['teacher_id'])) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$teacherId = (int)$_SESSION['teacher_id'];
$stmt = $conn->prepare("SELECT teacher_no, full_name, teaching_grades, teaching_strands, contact_number, email, qr_code FROM teachers WHERE teacher_id=? AND status='active' AND COALESCE(is_archived,0)=0 LIMIT 1");
$stmt->bind_param('i', $teacherId);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$teacher) {
    $_SESSION = [];
    header('Location: /LibraryBorrowingSystem/login.php?expired=1');
    exit();
}
$grades = implode(', ', (array)json_decode($teacher['teaching_grades'], true));
$strands = implode(', ', (array)json_decode($teacher['teaching_strands'] ?: '[]', true));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Profile - Library Borrowing System</title>
    <style>
        :root{color-scheme:light;--page:#141F52;--surface:#fff;--soft:#F3F7FC;--text:#202A44;--muted:#52618D;--accent:#F4F916}body.dark{color-scheme:dark;--page:#0d132d;--surface:#18213f;--soft:#222d4d;--text:#f4f7ff;--muted:#b7c5e2;--accent:#f4f916}body{margin:0;background:var(--page);color:var(--text);font-family:Segoe UI,Tahoma,sans-serif;padding:30px;transition:background .2s,color .2s}.card{max-width:700px;margin:30px auto;background:var(--surface);border-radius:14px;padding:30px;box-shadow:0 10px 30px #0b1238}.header{display:flex;justify-content:space-between;gap:20px;align-items:start;border-bottom:3px solid var(--accent);padding-bottom:18px}.header h1{margin:0;color:var(--text)}.header p{color:var(--muted)}.details{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:24px 0}.item{padding:14px;background:var(--soft);border-radius:8px}.label{font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:700}.value{margin-top:5px;font-weight:600}.qr{text-align:center;margin:20px 0}.qr img{width:180px;height:180px;border:8px solid #fff;box-shadow:0 2px 10px #ccd5e8}.actions{text-align:center}.button{display:inline-block;background:#141F52;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:700;border:0;cursor:pointer}.settings{margin:18px 0;text-align:right}.settings button{padding:9px 14px;border:1px solid var(--muted);border-radius:7px;background:var(--surface);color:var(--text);cursor:pointer}@media(max-width:600px){.details{grid-template-columns:1fr}body{padding:15px}}
    </style>
</head>
<body>
    <main class="card">
        <div class="header"><div><h1>Teacher Profile</h1><p>Library account information</p></div><div><a class="button" href="/LibraryBorrowingSystem/teacher/dashboard.php">Dashboard</a> <a class="button" href="/LibraryBorrowingSystem/logout.php">Log out</a></div></div>
        <div class="settings"><button type="button" id="themeToggle">Switch to dark theme</button></div>
        <div class="details">
            <div class="item"><div class="label">Full Name</div><div class="value"><?php echo htmlspecialchars($teacher['full_name']); ?></div></div>
            <div class="item"><div class="label">Teacher ID Number</div><div class="value"><?php echo htmlspecialchars($teacher['teacher_no']); ?></div></div>
            <div class="item"><div class="label">Contact Number</div><div class="value"><?php echo htmlspecialchars($teacher['contact_number'] ?: 'N/A'); ?></div></div>
            <div class="item"><div class="label">Email</div><div class="value"><?php echo htmlspecialchars($teacher['email']); ?></div></div>
            <div class="item"><div class="label">Grade Levels</div><div class="value"><?php echo htmlspecialchars($grades ?: 'N/A'); ?></div></div>
            <div class="item"><div class="label">Senior High Strands</div><div class="value"><?php echo htmlspecialchars($strands ?: 'N/A'); ?></div></div>
        </div>
        <div class="qr"><img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?php echo urlencode($teacher['qr_code']); ?>" alt="Teacher QR Code"><p><strong><?php echo htmlspecialchars($teacher['qr_code']); ?></strong></p><a class="button" href="/LibraryBorrowingSystem/download_qr.php?code=<?php echo urlencode($teacher['qr_code']); ?>&type=teacher">Download QR Code</a></div>
    </main>
    <script>
        const darkTheme = localStorage.getItem('jas-theme') === 'dark';
        document.body.classList.toggle('dark', darkTheme);
        const themeToggle = document.getElementById('themeToggle');
        function updateThemeLabel() { themeToggle.textContent = document.body.classList.contains('dark') ? 'Switch to light theme' : 'Switch to dark theme'; }
        updateThemeLabel();
        themeToggle.addEventListener('click', function () { document.body.classList.toggle('dark'); localStorage.setItem('jas-theme', document.body.classList.contains('dark') ? 'dark' : 'light'); updateThemeLabel(); });
    </script>
</body>
</html>
