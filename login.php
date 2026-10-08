<?php
/**
 * Library Login - Jose Abad Santos High School Library Borrowing System
 * Automatically detects the Chief Librarian or Student account from the identifier.
 * Student and teacher identifiers accepted: ID Number, QR Code, Email, or Contact Number.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/audit_logger.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /LibraryBorrowingSystem/' . ($account['role'] === 'attendance_kiosk' ? 'attendance_kiosk.php' : 'admin/dashboard.php'));
    exit();
}
if (isset($_SESSION['student_id'])) {
    header('Location: /LibraryBorrowingSystem/student/dashboard.php');
    exit();
}
if (isset($_SESSION['teacher_id'])) {
    header('Location: /LibraryBorrowingSystem/teacher/dashboard.php');
    exit();
}

$error = '';
$success = '';
$lockSeconds = 0;

if (isset($_GET['logout'])) {
    $success = 'You have been logged out successfully.';
}
if (isset($_GET['expired'])) {
    $error = 'Your session has expired. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $accountType = 'unknown';
    $account = null;
    $loginMethod = 'unknown';

    try {
        requireValidCsrf($_POST['csrf_token'] ?? '');

        if ($identifier === '' || $password === '') {
            auditLogLoginEvent(
                $conn,
                'unknown',
                null,
                $identifier,
                'failure',
                'Login failed because required fields were not completed.',
                ['reason' => 'missing_fields']
            );
            throw new RuntimeException('Username, student number, email, or contact number and password are required.');
        }

        $lock = isLoginLocked($conn, 'login', $identifier);
        if ($lock['locked']) {
            auditLogLoginEvent(
                $conn,
                'unknown',
                null,
                $identifier,
                'failure',
                'Login attempt was blocked by rate limiting.',
                ['reason' => 'rate_limited']
            );
            $lockSeconds = max(1, (int)$lock['seconds']);
            $minutes = max(1, (int)ceil($lockSeconds / 60));
            throw new RuntimeException("Too many failed login attempts. Try again in about {$minutes} minute(s). If you forgot your password or continue having trouble, please use Forgot Password.");
        }

        // Chief Librarian account is identified by username.
        $adminStmt = $conn->prepare(
            'SELECT user_id, full_name, username, password, role, status
             FROM users WHERE username = ? LIMIT 1'
        );
        if ($adminStmt) {
            $adminStmt->bind_param('s', $identifier);
            $adminStmt->execute();
            $adminAccount = $adminStmt->get_result()->fetch_assoc();
            $adminStmt->close();

            if ($adminAccount) {
                $accountType = 'admin';
                $account = $adminAccount;
                $loginMethod = 'chief_librarian_username';
            }
        }

        // Try students first, then teachers. If identifiers overlap, password verification decides the account.
        if ($account === null) {
            $studentStmt = $conn->prepare(
                'SELECT student_id, student_no, full_name, email, contact_number,
                        qr_code, password, status
                 FROM students
                 WHERE student_no = ? OR email = ? OR contact_number = ? OR qr_code = ?'
            );
            if (!$studentStmt) {
                throw new RuntimeException('Unable to process the login right now.');
            }

            $studentStmt->bind_param('ssss', $identifier, $identifier, $identifier, $identifier);
            $studentStmt->execute();
            $studentAccounts = $studentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $studentStmt->close();

            if (!empty($studentAccounts)) {
                $accountType = 'student';
                $account = $studentAccounts[0];
            }
        }

        if ($account === null) {
            $teacherStmt = $conn->prepare(
                'SELECT teacher_id, teacher_no, full_name, email, contact_number,
                        qr_code, password, status, is_archived
                 FROM teachers
                 WHERE teacher_no = ? OR email = ? OR contact_number = ? OR qr_code = ?'
            );
            if (!$teacherStmt) throw new RuntimeException('Unable to process the login right now.');
            $teacherStmt->bind_param('ssss', $identifier, $identifier, $identifier, $identifier);
            $teacherStmt->execute();
            $teacherAccounts = $teacherStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $teacherStmt->close();
            if (!empty($teacherAccounts)) {
                $accountType = 'teacher';
                $account = $teacherAccounts[0];
            }
        }

        if ($accountType === 'admin') {
            $passwordOk = constantTimePasswordVerify($password, $account['password']);

            if (!$passwordOk || $account['status'] !== 'active' || !in_array($account['role'], ['admin','attendance_kiosk'], true)) {
                recordFailedLogin($conn, 'login', $identifier);

                auditLogLoginEvent(
                    $conn,
                    'admin',
                    $account,
                    $identifier,
                    'failure',
                    'Chief Librarian login failed.',
                    [
                        'login_method' => $loginMethod,
                        'reason' => $account['status'] !== 'active'
                            ? 'inactive_account'
                            : ($account['role'] !== 'admin' ? 'invalid_role' : 'invalid_credentials')
                    ]
                );

                throw new RuntimeException('Invalid login credentials or inactive account.');
            }

            clearFailedLogins($conn, 'login', $identifier);
            session_regenerate_id(true);

            $_SESSION['user_id'] = (int)$account['user_id'];
            $_SESSION['full_name'] = $account['full_name'];
            $_SESSION['username'] = $account['username'];
            $_SESSION['role'] = $account['role'];
            $_SESSION['login_time'] = time();
            $_SESSION['login_started_at'] = time();
            $_SESSION['session_fingerprint'] = createSessionFingerprint();

            auditLogLoginEvent(
                $conn,
                'admin',
                $account,
                $identifier,
                'success',
                'Chief Librarian login successful.',
                ['login_method' => $loginMethod]
            );

            header('Location: /LibraryBorrowingSystem/' . ($account['role'] === 'attendance_kiosk' ? 'attendance_kiosk.php' : 'admin/dashboard.php'));
            exit();
        }

        if ($accountType === 'student') {
            $passwordOk = constantTimePasswordVerify($password, $account['password']);

            if (!$passwordOk) {
                $teacherFallback = $conn->prepare('SELECT teacher_id, teacher_no, full_name, email, contact_number, qr_code, password, status, is_archived FROM teachers WHERE teacher_no = ? OR email = ? OR contact_number = ? OR qr_code = ?');
                $teacherFallback->bind_param('ssss', $identifier, $identifier, $identifier, $identifier);
                $teacherFallback->execute();
                $teacherMatches = $teacherFallback->get_result()->fetch_all(MYSQLI_ASSOC);
                $teacherFallback->close();
                foreach ($teacherMatches as $teacherCandidate) {
                    if (constantTimePasswordVerify($password, $teacherCandidate['password'])) {
                        $accountType = 'teacher';
                        $account = $teacherCandidate;
                        break;
                    }
                }
            }

            if ($accountType === 'teacher') {
                // Continue into the teacher session branch below.
            } elseif (!$passwordOk || $account['status'] !== 'active') {
                recordFailedLogin($conn, 'login', $identifier);

                auditLogLoginEvent(
                    $conn,
                    'student',
                    $account,
                    $identifier,
                    'failure',
                    'Student login failed for ' . (string)$account['full_name'] . '.',
                    [
                        'login_method' => $loginMethod,
                        'reason' => $account['status'] !== 'active'
                            ? 'inactive_account'
                            : 'invalid_credentials'
                    ]
                );

                throw new RuntimeException('Invalid student credentials or inactive account.');
            }

            clearFailedLogins($conn, 'login', $identifier);
            session_regenerate_id(true);

            $_SESSION['student_id'] = (int)$account['student_id'];
            $_SESSION['student_no'] = $account['student_no'];
            $_SESSION['student_name'] = $account['full_name'];
            $_SESSION['student_qr'] = $account['qr_code'];
            $_SESSION['student_login_time'] = time();
            $_SESSION['student_login_started_at'] = time();
            $_SESSION['student_session_fingerprint'] = createSessionFingerprint();

            auditLogLoginEvent(
                $conn,
                'student',
                $account,
                $identifier,
                'success',
                'Student login successful for ' . (string)$account['full_name'] . '.',
                ['login_method' => $loginMethod]
            );

            header('Location: /LibraryBorrowingSystem/student/dashboard.php');
            exit();
        }

        if ($accountType === 'teacher') {
            $passwordOk = constantTimePasswordVerify($password, $account['password']);
            if (!$passwordOk || $account['status'] !== 'active' || (int)($account['is_archived'] ?? 0) === 1) {
                recordFailedLogin($conn, 'login', $identifier);
                throw new RuntimeException('Invalid teacher credentials or inactive account.');
            }
            clearFailedLogins($conn, 'login', $identifier);
            session_regenerate_id(true);
            $_SESSION['teacher_id'] = (int)$account['teacher_id'];
            $_SESSION['teacher_no'] = $account['teacher_no'];
            $_SESSION['teacher_name'] = $account['full_name'];
            $_SESSION['teacher_qr'] = $account['qr_code'];
            $_SESSION['teacher_login_time'] = time();
            $_SESSION['teacher_session_fingerprint'] = createSessionFingerprint();
            header('Location: /LibraryBorrowingSystem/teacher/dashboard.php');
            exit();
        }

        recordFailedLogin($conn, 'login', $identifier);

        auditLogLoginEvent(
            $conn,
            'unknown',
            null,
            $identifier,
            'failure',
            'Login failed: no matching account was found.',
            ['reason' => 'account_not_found']
        );

        throw new RuntimeException('Invalid login credentials.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
        logError('Login attempt: ' . $e->getMessage());
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Login - Jose Abad Santos High School</title>
    <!-- Literata (a typeface made for reading books) + Public Sans. Falls back to Georgia / Segoe UI if offline. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Literata:opsz,wght@7..72,500..700&family=Public+Sans:wght@400..700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:#141F52; --blue:#52618D; --sky:#91B0E0; --light:#D2E2F6; --yellow:#F4F916; --white:#FEFEF9; --text:#202A44;
            --paper:#ECF2FA;
            --muted:#414D73;
            --field-border:#8090B0;

            /* Frosted-glass surfaces. One place to retune how see-through the card is. */
            --glass:rgba(254,254,249,.74);
            --glass-strong:rgba(255,255,255,.86);
            --glass-field:rgba(255,255,255,.62);
            --glass-field-focus:rgba(255,255,255,.92);
            --glass-border:rgba(255,255,255,.55);
            --glass-line:rgba(32,42,68,.16);
            --blur:blur(22px) saturate(140%);
            --serif:'Literata', Georgia, 'Times New Roman', serif;
            --sans:'Public Sans', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        html, body { height:100%; }
        body {
            font-family:var(--sans);
            color:var(--text);
            min-height:100vh;
            background:#0d1533;
            overflow-x:hidden;
            -webkit-font-smoothing:antialiased;
        }

        /* One slow settle on the photos when the page loads. Nothing else animates on its own. */
        @keyframes gentleZoom {
            from { transform:scale(1.05); }
            to   { transform:scale(1); }
        }
        @keyframes spin { to { transform:rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration:0.001ms !important; animation-iteration-count:1 !important; transition-duration:0.001ms !important; }
        }

        /* ---------- Full-page photo background ---------- */
        .bg {
            position:fixed;
            inset:0;
            z-index:0;
            overflow:hidden;
            background:#0d1533;
            animation:gentleZoom 14s ease-out forwards;
        }
        .bg-photo { position:absolute; top:0; height:100%; width:58%; overflow:hidden; }
        .bg-photo img { display:block; width:100%; height:100%; object-fit:cover; }
        /* Two photos meet on a slanted seam; the thin dark gap between them is the .bg colour. */
        .bg-a { left:0;  z-index:1; clip-path:polygon(0 0, 100% 0, 83% 100%, 0 100%); }
        .bg-b { right:0;            clip-path:polygon(28.6% 0, 100% 0, 100% 100%, 11.6% 100%); }
        .bg-a img { object-position:center 40%; }
        .bg-b img { object-position:60% 50%; }
        /* Dark only where text sits (top row, bottom copy); the right side stays light enough to see the room. */
        .bg::after {
            content:"";
            position:absolute;
            inset:0;
            z-index:2;
            background:
                linear-gradient(180deg, rgba(13,21,51,.62) 0%, rgba(13,21,51,.10) 20%, rgba(13,21,51,.10) 52%, rgba(13,21,51,.80) 100%),
                linear-gradient(90deg, rgba(13,21,51,0) 46%, rgba(13,21,51,.42) 100%);
        }

        /* ---------- Layout on top of the photos ---------- */
        .page-shell {
            position:relative;
            z-index:1;
            min-height:100vh;
            min-height:100dvh;
            display:grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(400px, 1fr);
        }
        @media(min-width:1600px){
            .page-shell { grid-template-columns: minmax(0, 1.35fr) minmax(500px, 1fr); }
        }
        @media(min-width:981px) and (max-width:1180px){
            .page-shell { grid-template-columns: 1fr 1fr; }
        }

        .brand-panel {
            display:flex;
            flex-direction:column;
            justify-content:space-between;
            min-height:100vh;
            min-height:100dvh;
            padding:clamp(28px, 4vw, 44px) clamp(28px, 5vw, 64px) clamp(32px, 5vw, 56px) clamp(28px, 4vw, 48px);
            color:var(--white);
            text-shadow:0 1px 14px rgba(8,14,40,.55);
        }
        .brand-top { display:flex; align-items:center; gap:14px; }
        .brand-logo {
            height:56px; width:56px; flex-shrink:0; object-fit:contain;
            background:var(--white);
            border-radius:50%;
            padding:4px;
            box-shadow:0 4px 14px rgba(0,0,0,.28);
        }
        .brand-name { font-size:13px; font-weight:500; line-height:1.4; }
        .brand-name strong { display:block; font-size:16px; font-weight:700; }

        .brand-middle { max-width:30rem; }
        .brand-middle h2 {
            font-family:var(--serif);
            font-size:clamp(28px, 3vw, 42px);
            line-height:1.16;
            font-weight:600;
            letter-spacing:-.015em;
            margin-bottom:14px;
            text-wrap:balance;
        }
        .brand-middle p { font-size:15.5px; line-height:1.6; color:var(--white); opacity:.94; max-width:28rem; }
        .brand-middle .brand-motto {
            margin-top:22px;
            font-family:var(--serif);
            font-style:italic;
            font-size:15px;
            color:var(--yellow);
            opacity:1;
        }

        /* ---------- Form ---------- */
        .form-panel {
            display:flex;
            align-items:center;
            justify-content:center;
            padding:40px 24px;
            padding-left: max(24px, env(safe-area-inset-left));
            padding-right: max(24px, env(safe-area-inset-right));
            padding-bottom: max(40px, env(safe-area-inset-bottom));
        }
        .login-container {
            position:relative;
            background:var(--glass);
            -webkit-backdrop-filter:var(--blur);
            backdrop-filter:var(--blur);
            padding:40px 38px 32px;
            border:1px solid var(--glass-border);
            border-radius:18px;
            box-shadow:
                0 28px 70px rgba(8,14,40,.42),
                0 2px 8px rgba(8,14,40,.14),
                inset 0 1px 0 rgba(255,255,255,.7);
            width:100%;
            max-width:420px;
        }
        /* A faint sheen along the top edge, so the panel reads as glass rather than a flat wash. */
        .login-container::before {
            content:"";
            position:absolute;
            inset:0;
            border-radius:inherit;
            pointer-events:none;
            background:linear-gradient(170deg, rgba(255,255,255,.34) 0%, rgba(255,255,255,0) 42%);
        }
        .login-container > * { position:relative; }

        /* Without blur support, lean opaque instead: legibility wins over the effect. */
        @supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
            .login-container { background:rgba(254,254,249,.95); }
            input { background:rgba(255,255,255,.96); }
        }
        /* Respect a reader who has asked the OS for less transparency. */
        @media (prefers-reduced-transparency: reduce) {
            .login-container { background:var(--white); backdrop-filter:none; -webkit-backdrop-filter:none; }
            .login-container::before { display:none; }
            input { background:#FBFDFF; }
        }
        .logo { display:none; justify-content:center; margin-bottom:12px; }
        .logo img { width:56px; height:56px; object-fit:cover; border-radius:50%; border:2px solid var(--navy); }

        .login-header { margin-bottom:26px; }
        .login-header h1 {
            font-family:var(--serif);
            color:var(--navy);
            font-size:30px;
            line-height:1.15;
            font-weight:700;
            letter-spacing:-.015em;
            margin-bottom:8px;
        }
        .login-header p { color:var(--muted); font-size:14px; line-height:1.55; text-wrap:pretty; }

        .form-group { margin-bottom:18px; }
        label { display:block; margin-bottom:7px; color:var(--text); font-weight:600; font-size:14px; }
        .label-row { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:7px; }
        .label-row label { margin-bottom:0; }
        .field-hint { margin-top:7px; color:var(--muted); font-size:12.5px; line-height:1.5; }
        .caps-hint { color:#8A4B00; font-weight:600; }

        .input-icon-wrap { position:relative; }
        .input-icon-wrap svg {
            position:absolute;
            left:14px; top:50%;
            transform:translateY(-50%);
            width:18px; height:18px;
            stroke:var(--muted);
            pointer-events:none;
            transition:stroke .15s;
        }
        .input-icon-wrap input { padding-left:42px; }
        .input-icon-wrap:focus-within svg { stroke:var(--navy); }

        input {
            width:100%;
            height:48px;
            padding:12px 14px;
            border:1.5px solid var(--field-border);
            border-radius:10px;
            font-family:inherit;
            font-size:16px;
            color:var(--text);
            background:var(--glass-field);
            transition:border-color .15s, box-shadow .15s, background .15s;
        }
        input::placeholder { color:#5E6B92; }
        input:hover { border-color:var(--blue); background:rgba(255,255,255,.76); }
        input:focus {
            outline:none;
            border-color:var(--navy);
            background:var(--glass-field-focus);
            box-shadow:0 0 0 4px rgba(20,31,82,.20);
        }

        button[type="submit"] {
            width:100%;
            height:50px;
            margin-top:6px;
            background:linear-gradient(180deg, #23316E 0%, var(--navy) 100%);
            color:#fff;
            border:0;
            border-radius:10px;
            font-family:inherit;
            font-size:16px;
            font-weight:700;
            letter-spacing:.01em;
            cursor:pointer;
            box-shadow:0 8px 20px rgba(20,31,82,.34), inset 0 1px 0 rgba(255,255,255,.16);
            transition:background .15s, box-shadow .15s, transform .12s;
            display:flex; align-items:center; justify-content:center; gap:10px;
        }
        button[type="submit"]:hover { background:linear-gradient(180deg, #2B3A80 0%, #1B2864 100%); box-shadow:0 12px 26px rgba(20,31,82,.42), inset 0 1px 0 rgba(255,255,255,.2); }
        button[type="submit"]:active { transform:translateY(1px); box-shadow:0 5px 14px rgba(20,31,82,.32); }
        button[type="submit"]:focus-visible { outline:3px solid var(--sky); outline-offset:3px; }
        button[type="submit"]:disabled { cursor:progress; background:var(--blue); box-shadow:none; transform:none; }
        .btn-spinner {
            display:none;
            width:16px; height:16px;
            border:2px solid rgba(255,255,255,.4);
            border-top-color:#fff;
            border-radius:50%;
            animation:spin .7s linear infinite;
        }
        button[type="submit"].is-loading .btn-spinner { display:inline-block; }

        .divider {
            display:flex; align-items:center; gap:12px;
            margin:24px 0 14px;
            color:var(--muted);
            font-size:13px;
        }
        .divider::before, .divider::after { content:""; flex:1; height:1px; background:var(--glass-line); }

        .links { display:grid; }
        .links a {
            display:block; padding:12px 16px;
            border:1.5px solid rgba(20,31,82,.55);
            border-radius:10px;
            background:rgba(255,255,255,.34);
            text-align:center; text-decoration:none;
            font-size:14.5px; font-weight:700;
            color:var(--navy);
            transition:background .15s, border-color .15s;
        }
        .links a:hover { background:rgba(255,255,255,.78); border-color:var(--navy); }
        .links a:focus-visible, .forgot-password-link:focus-visible { outline:3px solid var(--sky); outline-offset:2px; }

        .security-note {
            margin-top:20px;
            color:var(--muted); font-size:12px; line-height:1.5; text-align:center;
            text-wrap:pretty;
        }
        .security-note svg { display:inline-block; width:14px; height:14px; margin-right:6px; vertical-align:-2px; stroke:var(--muted); }

        .password-field { position:relative; width:100%; }
        .password-field > input[type="password"],
        .password-field > input[type="text"] { width:100%; padding-right:78px !important; }

        .show-password-btn {
            position:absolute;
            top:50%;
            right:8px;
            transform:translateY(-50%) !important;
            min-width:62px !important;
            width:auto !important;
            min-height:34px !important;
            height:34px !important;
            padding:5px 9px !important;
            border:1px solid rgba(20,31,82,.20) !important;
            border-radius:7px !important;
            background:rgba(255,255,255,.72) !important;
            color:var(--navy) !important;
            box-shadow:none !important;
            font-family:inherit !important;
            font-size:12px !important;
            font-weight:600 !important;
            line-height:1 !important;
            cursor:pointer;
            z-index:2;
        }
        .show-password-btn:hover { background:rgba(228,236,248,.95) !important; transform:translateY(-50%) !important; box-shadow:none !important; }
        .show-password-btn:focus-visible { outline:3px solid var(--sky); outline-offset:1px; }

        .forgot-password-link { color:var(--navy); font-size:13px; font-weight:600; text-decoration:none; }
        .forgot-password-link:hover { text-decoration:underline; }

        /* ---------- Tablets and phones (portrait): photos stack behind a headline banner, card below ---------- */
        @media(max-width:980px) and (min-height:561px){
            .page-shell { grid-template-columns:1fr; }
            .bg { animation:none; }
            .bg-photo { width:100%; }
            .bg-a { height:46%; clip-path:polygon(0 0, 100% 0, 100% 90%, 0 100%); }
            .bg-b { clip-path:none; height:100%; }
            .bg::after { background:linear-gradient(180deg, rgba(13,21,51,.70) 0%, rgba(13,21,51,.62) 38%, rgba(13,21,51,.42) 100%); }
            .brand-panel { min-height:clamp(230px, 36vh, 320px); padding:22px clamp(20px, 5vw, 32px) 26px; }
            .brand-motto { display:none; }
            .form-panel {
                align-items:flex-start;
                padding:8px 20px 40px;
                padding-left: max(20px, env(safe-area-inset-left));
                padding-right: max(20px, env(safe-area-inset-right));
            }
            .login-container { max-width:460px; --glass:rgba(254,254,249,.82); }
        }

        /* ---------- Small phones ---------- */
        @media(max-width:480px){
            .login-container { padding:28px 20px 24px; border-radius:16px; --glass:rgba(254,254,249,.86); --blur:blur(16px) saturate(130%); }
            .brand-panel { min-height:200px; padding:16px 20px 22px; }
            .brand-name { display:none; }
            .brand-logo { height:46px; width:46px; }
            .brand-middle p { display:none; }
            .brand-middle h2 { font-size:24px; margin-bottom:0; }
            .login-header { margin-bottom:20px; }
            .login-header h1 { font-size:26px; }
            .form-group { margin-bottom:16px; }
            .show-password-btn { min-width:58px !important; font-size:11px !important; right:6px; }
        }
        @media(max-width:360px){
            .login-container { padding:24px 16px 20px; }
            .brand-middle h2 { font-size:21px; }
            .input-icon-wrap input { padding-left:38px; }
            .input-icon-wrap svg { left:12px; width:16px; height:16px; }
            .password-field > input[type="password"],
            .password-field > input[type="text"] { padding-right:66px !important; }
            .show-password-btn { min-width:50px !important; font-size:10.5px !important; right:5px; padding:4px 7px !important; }
        }

        /* ---------- Short landscape phones: prioritize the form ---------- */
        @media(max-height:560px) and (orientation:landscape){
            .page-shell { grid-template-columns: 38vw 1fr; }
            .brand-panel { justify-content:flex-end; padding:18px 24px 18px 18px; }
            .brand-top, .brand-motto, .brand-middle p { display:none; }
            .brand-middle h2 { font-size:19px; margin-bottom:0; }
            .form-panel { padding:16px; align-items:flex-start;
                padding-left:max(16px, env(safe-area-inset-left)); padding-right:max(16px, env(safe-area-inset-right)); padding-bottom:max(16px, env(safe-area-inset-bottom)); }
            .login-container { padding:22px 24px; margin:auto 0; }
            .logo { display:flex; margin-bottom:8px; }
            .login-header { margin-bottom:14px; }
            .form-group { margin-bottom:12px; }
        }

        @media(min-width:1440px){
            .form-panel { padding:40px; }
        }

        /* ---------- Touch devices: comfortable tap targets ---------- */
        @media(hover:none) and (pointer:coarse){
            button[type="submit"], .links a { min-height:48px; }
            .forgot-password-link { display:inline-block; padding:8px 0; }
            .show-password-btn { min-height:38px !important; padding:8px 10px !important; }
        }
    </style>
    <?php require_once __DIR__ . '/includes/responsive.php'; ?>
</head>
<body class="admin-login-page">
<?php require_once __DIR__ . '/includes/ui_feedback.php'; ?>

<!-- Full-page photo background -->
<div class="bg">
    <div class="bg-photo bg-a">
        <img src="/LibraryBorrowingSystem/Img/Main_entrance_JASHS.png" alt="Jose Abad Santos High School main entrance" fetchpriority="high" decoding="async">
    </div>
    <div class="bg-photo bg-b">
        <img src="/LibraryBorrowingSystem/Img/library1.png" alt="Students studying at the long reading tables of the school library" decoding="async">
    </div>
</div>

<div class="page-shell">

    <div class="brand-panel">
        <div class="brand-top">
            <img class="brand-logo" src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo">
            <div class="brand-name">
                <strong>Jose Abad Santos High School</strong>
                Library Borrowing System
            </div>
        </div>

        <div class="brand-middle">
            <h2>Welcome back to the JASHS Library.</h2>
            <p>Log in to borrow books, check due dates, and manage your library account.</p>
            <p class="brand-motto">Proud to be Abadians</p>
        </div>
    </div>

    <div class="form-panel">
        <div class="login-container">
            <div class="logo"><img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School"></div>
            <div class="login-header">
                <h1>Library Login</h1>
                <p>Students, teachers, and the Chief Librarian all log in here.</p>
            </div>


            <?php if ($error): ?>
            <script>document.addEventListener('DOMContentLoaded',()=>showToast(<?php echo json_encode($error); ?>,'error',4500,'Login'));</script>
            <?php endif; ?>
            <?php if ($lockSeconds > 0): ?>
            <div id="login-lock-countdown" class="field-hint" style="color:#b42318;font-weight:700;margin-bottom:16px;"></div>
            <?php endif; ?>
            <?php if ($success): ?>
            <script>document.addEventListener('DOMContentLoaded',()=>showToast(<?php echo json_encode($success); ?>,'success',3500));</script>
            <?php endif; ?>

            <form id="login-form" method="POST" autocomplete="off" novalidate>
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label for="identifier">Username or ID number</label>
                    <div class="input-icon-wrap">
                        <input type="text" id="identifier" name="identifier" required maxlength="150" autocomplete="username" aria-describedby="identifier-hint" value="<?php echo htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Username or ID number">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    </div>
                    <p class="field-hint" id="identifier-hint">Students and teachers can also use their email, contact number, or QR code.</p>
                </div>
                <div class="form-group">
                    <div class="label-row">
                        <label for="password">Password</label>
                        <a class="forgot-password-link" href="/LibraryBorrowingSystem/forgot_password.php">Forgot password?</a>
                    </div>
                    <div class="password-field input-icon-wrap">
                        <input type="password" id="password" name="password" required maxlength="128" autocomplete="current-password" placeholder="Enter your password">
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <button type="button" class="show-password-btn" aria-pressed="false" aria-label="Show password">Show</button>
                    </div>
                    <p class="field-hint caps-hint" id="caps-hint" role="status" hidden>Caps Lock is on.</p>
                </div>
                <button type="submit">
                    <span class="btn-spinner" aria-hidden="true"></span>
                    <span class="btn-label">Log in</span>
                </button>
            </form>

            <div class="divider">Don't have an account?</div>
            <div class="links">
                <a class="register-link" href="/LibraryBorrowingSystem/student/register.php">Register</a>
            </div>
            <div class="security-note">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                After too many failed attempts, login is paused for a few minutes.
            </div>
        </div>
    </div>

</div>

<script>
(function () {
    function initPasswordToggles(root) {
        (root || document).querySelectorAll('.password-field').forEach(function (wrapper) {
            var input = wrapper.querySelector('input[type="password"], input[type="text"]');
            var button = wrapper.querySelector('.show-password-btn');
            if (!input || !button || button.dataset.ready === '1') return;

            button.dataset.ready = '1';
            button.addEventListener('click', function () {
                var showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                button.textContent = showing ? 'Show' : 'Hide';
                button.setAttribute('aria-pressed', showing ? 'false' : 'true');
                button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
                input.focus({preventScroll: true});
            });
        });
    }

    // Warn when Caps Lock is on while typing the password.
    function initCapsLockHint() {
        var pw = document.getElementById('password');
        var hint = document.getElementById('caps-hint');
        if (!pw || !hint) return;
        function check(e) {
            if (e.getModifierState) hint.hidden = !e.getModifierState('CapsLock');
        }
        pw.addEventListener('keydown', check);
        pw.addEventListener('keyup', check);
        pw.addEventListener('blur', function () { hint.hidden = true; });
    }

    // On desktop, start in the right field (password if the ID was kept after a failed attempt).
    // Skipped on touch devices so the keyboard doesn't cover the page on load.
    function initAutofocus() {
        if (!window.matchMedia || !window.matchMedia('(hover:hover) and (pointer:fine)').matches) return;
        var id = document.getElementById('identifier');
        var pw = document.getElementById('password');
        var target = (id && id.value) ? pw : id;
        if (target) target.focus({preventScroll: true});
    }

    // Show progress and block double-submits while the login request is running.
    function initSubmitState() {
        var form = document.getElementById('login-form');
        if (!form) return;
        var btn = form.querySelector('button[type="submit"]');
        var label = btn && btn.querySelector('.btn-label');
        if (!btn || !label) return;

        function reset() {
            btn.disabled = false;
            btn.classList.remove('is-loading');
            label.textContent = 'Log in';
        }
        form.addEventListener('submit', function () {
            // Deferred so the browser still sends the form before the button is disabled.
            setTimeout(function () {
                btn.disabled = true;
                btn.classList.add('is-loading');
                label.textContent = 'Logging in\u2026';
            }, 0);
        });
        // Coming back with the Back button should not leave the button stuck.
        window.addEventListener('pageshow', function (e) { if (e.persisted) reset(); });
    }

    function initLockCountdown() {
        var box = document.getElementById('login-lock-countdown');
        if (!box) return;
        var seconds = <?php echo (int)$lockSeconds; ?>;
        var form = document.getElementById('login-form');
        var button = form ? form.querySelector('button[type=\"submit\"]') : null;
        function update() {
            var m = Math.floor(seconds / 60);
            var s = seconds % 60;
            box.textContent = 'Login temporarily locked. Try again in ' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0') + '.';
            if (button) button.disabled = true;
            if (seconds <= 0) {
                box.textContent = 'You may try logging in again. If you continue having trouble, use Forgot Password.';
                if (button) button.disabled = false;
                return;
            }
            seconds--;
            setTimeout(update,1000);
        }
        update();
    }

    function init() { initPasswordToggles(); initCapsLockHint(); initAutofocus(); initSubmitState(); initLockCountdown(); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

</body>
</html>