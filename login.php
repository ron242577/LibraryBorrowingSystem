<?php
/**
 * Library Login - Jose Abad Santos High School Library Borrowing System
 * Automatically detects the Chief Librarian or Student account from the identifier.
 * Student and teacher identifiers accepted: ID Number, QR Code, Email, or Contact Number.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/audit_logger.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /LibraryBorrowingSystem/admin/dashboard.php');
    exit();
}
if (isset($_SESSION['student_id'])) {
    header('Location: /LibraryBorrowingSystem/student/profile.php');
    exit();
}

$error = '';
$success = '';

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
            $minutes = max(1, (int)ceil($lock['seconds'] / 60));
            throw new RuntimeException("Too many failed login attempts. Try again in about {$minutes} minute(s).");
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

            if (!$passwordOk || $account['status'] !== 'active' || $account['role'] !== 'admin') {
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
            $_SESSION['role'] = 'admin';
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

            header('Location: /LibraryBorrowingSystem/admin/dashboard.php');
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

            header('Location: /LibraryBorrowingSystem/student/profile.php');
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
            header('Location: /LibraryBorrowingSystem/teacher/profile.php');
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
    <style>
        :root { --navy:#141F52; --blue:#52618D; --sky:#91B0E0; --light:#D2E2F6; --yellow:#F4F916; --white:#FEFEF9; --text:#202A44; }
        * { margin:0; padding:0; box-sizing:border-box; }
        html, body { height:100%; }
        body {
            font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
            color:var(--text);
            min-height:100vh;
            background:
                radial-gradient(1100px 700px at 12% -10%, rgba(145,176,224,.35), transparent 60%),
                radial-gradient(900px 600px at 100% 100%, rgba(20,31,82,.10), transparent 55%),
                var(--light);
            overflow-x:hidden;
        }

        @keyframes fadeSlideUp {
            from { opacity:0; transform:translateY(18px); }
            to   { opacity:1; transform:translateY(0); }
        }
        @keyframes fadeSlideLeft {
            from { opacity:0; transform:translateX(-16px); }
            to   { opacity:1; transform:translateX(0); }
        }
        @keyframes shimmer {
            0%   { background-position:0% 50%; }
            100% { background-position:200% 50%; }
        }
        @keyframes gentleZoom {
            from { transform:scale(1.06); }
            to   { transform:scale(1); }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration:0.001ms !important; animation-iteration-count:1 !important; }
        }

        .page-shell {
            min-height:100vh;
            min-height:100dvh;
            display:grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(320px, 1fr);
        }

        @media(min-width:1600px){
            .page-shell { grid-template-columns: minmax(0, 1.35fr) minmax(460px, 1fr); }
        }
        @media(min-width:981px) and (max-width:1180px){
            .page-shell { grid-template-columns: 1fr 1fr; }
        }

        /* ---------- Left: campus photo panel ---------- */
        .brand-panel {
            position:relative;
            min-height:100vh;
            overflow:hidden;
            background:#0d1533;
            clip-path: polygon(0 0, 100% 0, 92% 100%, 0% 100%);
        }
        .brand-panel img {
            position:absolute;
            inset:0;
            width:100%;
            height:100%;
            object-fit:cover;
            object-position:center 35%;
            filter:saturate(1.05) contrast(1.02);
            animation:gentleZoom 16s ease-out forwards;
        }
        .brand-panel::before {
            content:"";
            position:absolute;
            inset:0;
            background:
                linear-gradient(180deg, rgba(20,31,82,.62) 0%, rgba(20,31,82,.32) 38%, rgba(13,21,51,.85) 100%),
                linear-gradient(90deg, rgba(20,31,82,.25) 0%, rgba(20,31,82,0) 42%);
        }
        .brand-panel::after {
            content:"";
            position:absolute;
            right:-90px; top:-90px;
            width:280px; height:280px;
            border-radius:50%;
            background:rgba(244,249,22,.14);
        }
        .brand-texture {
            position:absolute;
            inset:0;
            z-index:1;
            opacity:.5;
            background-image: radial-gradient(rgba(254,254,249,.14) 1px, transparent 1px);
            background-size:22px 22px;
            mix-blend-mode:overlay;
            pointer-events:none;
        }
        .brand-content {
            position:relative;
            z-index:2;
            height:100%;
            display:flex;
            flex-direction:column;
            justify-content:space-between;
            padding:clamp(28px, 4vw, 44px) clamp(32px, 6vw, 66px) clamp(28px, 4vw, 44px) clamp(24px, 4vw, 46px);
            color:var(--white);
        }
        .brand-top {
            display:flex; align-items:center; gap:14px;
            animation:fadeSlideLeft .6s ease-out both;
        }
        .brand-top img.brand-logo {
            height:54px; width:54px; object-fit:contain;
            background:var(--white);
            border-radius:10px;
            padding:6px;
            box-shadow:0 6px 16px rgba(0,0,0,.25);
        }
        .brand-top .brand-name { margin-left: 70px; margin-top: 9px; font-size:13px; font-weight:700; letter-spacing:.04em; line-height:1.4; opacity:.92; }
        .brand-top .brand-name strong { display:block; font-size:15px; letter-spacing:.02em; }
        .logo{display:flex;justify-content:center;margin-bottom:18px}.logo img{width:78px;height:78px;object-fit:cover;border-radius:50%;border:3px solid var(--yellow)}
        .brand-middle { max-width:420px; animation:fadeSlideUp .7s ease-out .1s both; }
        .brand-pill {
            display:inline-flex; align-items:center; gap:8px;
            background:rgba(244,249,22,.16);
            border:1px solid rgba(244,249,22,.45);
            color:var(--yellow);
            padding:6px 13px;
            border-radius:999px;
            font-size:11.5px;
            font-weight:800;
            letter-spacing:.06em;
            text-transform:uppercase;
            margin-bottom:18px;
        }
        .brand-pill .dot {
            width:6px; height:6px; border-radius:50%;
            background:var(--yellow);
            box-shadow:0 0 0 3px rgba(244,249,22,.25);
        }
        .brand-middle h2 { font-size:clamp(21px, 2.4vw, 33px); line-height:1.26; margin-bottom:14px; font-weight:800; letter-spacing:-.01em; }
        .brand-middle h2 em {
            font-style:normal;
            background:linear-gradient(90deg, var(--yellow), #fff8a8, var(--yellow));
            background-size:220% auto;
            -webkit-background-clip:text;
            background-clip:text;
            color:transparent;
            animation:shimmer 5s linear infinite;
        }
        .brand-middle p { font-size:14.5px; line-height:1.65; color:var(--white); opacity:.95; }

        .brand-bottom { display:flex; flex-wrap:wrap; gap:clamp(10px, 2vw, 26px); animation:fadeSlideUp .7s ease-out .2s both; }
        .brand-stat {
            padding:14px 16px;
            border-radius:12px;
            background:rgba(254,254,249,.07);
            border:1px solid rgba(254,254,249,.14);
            backdrop-filter:blur(6px);
            flex:1 1 120px;
        }
        .brand-stat strong { display:block; font-size:19px; color:var(--yellow); font-weight:800; }
        .brand-stat span { font-size:11px; color:var(--light); opacity:.9; }


        /* ---------- Right: form panel ---------- */
        .form-panel {
            display:flex;
            align-items:center;
            justify-content:center;
            padding:40px 24px;
            padding-left: max(24px, env(safe-area-inset-left));
            padding-right: max(24px, env(safe-area-inset-right));
            padding-bottom: max(40px, env(safe-area-inset-bottom));
            background:var(--light);
            position:relative;
        }
        .login-container {
            background:var(--white);
            padding:40px 38px;
            border:1px solid var(--sky);
            border-radius:18px;
            box-shadow:0 24px 48px rgba(20,31,82,.18), 0 2px 8px rgba(20,31,82,.06);
            width:100%;
            max-width:410px;
            position:relative;
            animation:fadeSlideUp .55s ease-out both;
            transition:box-shadow .25s ease, transform .25s ease;
        }
        .login-container:hover {
            box-shadow:0 28px 56px rgba(20,31,82,.22), 0 2px 10px rgba(20,31,82,.08);
        }
        .login-container::before {
            content:"";
            position:absolute;
            top:0; left:24px; right:24px;
            height:4px;
            border-radius:0 0 6px 6px;
            background:linear-gradient(90deg, var(--navy), var(--blue), var(--yellow), var(--blue), var(--navy));
            background-size:220% auto;
            animation:shimmer 6s linear infinite;
        }
        .logo-container { text-align:center; margin-bottom:14px; }
        .logo-container img {
            height:78px; width:auto; max-width:100%; object-fit:contain;
            filter:drop-shadow(0 6px 12px rgba(20,31,82,.18));
        }
        .login-header { text-align:center; margin-bottom:26px; }
        .login-header h1 { color:var(--navy); margin-bottom:6px; font-size:25px; font-weight:800; letter-spacing:-.01em; }
        .login-header p { color:var(--blue); font-size:13.5px; line-height:1.5; }
        .account-note { margin:0 0 20px; padding:12px; border-radius:7px; background:#EDF3FA; color:var(--blue); font-size:12px; line-height:1.5; text-align:center; }

        .form-group { margin-bottom:18px; }
        label { display:block; margin-bottom:7px; color:var(--text); font-weight:700; font-size:13px; }

        .input-icon-wrap { position:relative; }
        .input-icon-wrap svg {
            position:absolute;
            left:13px; top:50%;
            transform:translateY(-50%);
            width:17px; height:17px;
            stroke:var(--blue);
            pointer-events:none;
            transition:stroke .15s;
        }
        .input-icon-wrap input { padding-left:40px; }
        .input-icon-wrap input:focus + svg,
        .input-icon-wrap:focus-within svg { stroke:var(--navy); }

        input {
            width:100%;
            padding:12px 14px;
            border:1.5px solid var(--sky);
            border-radius:9px;
            font-size:16px;
            background:#FBFDFF;
            transition:border-color .15s, box-shadow .15s, background .15s;
        }
        input::placeholder { color:#9BAAC7; }
        input:hover { border-color:var(--blue); }
        input:focus { outline:none; border-color:var(--navy); background:var(--white); box-shadow:0 0 0 4px rgba(20,31,82,.10); }

        button[type="submit"] {
            width:100%;
            padding:13px;
            background:var(--navy);
            color:white;
            border:0;
            border-radius:9px;
            font-size:15px;
            font-weight:800;
            letter-spacing:.01em;
            cursor:pointer;
            transition:.2s;
            box-shadow:0 8px 18px rgba(20,31,82,.28);
            display:flex; align-items:center; justify-content:center; gap:8px;
        }
        button[type="submit"] svg { width:16px; height:16px; transition:transform .2s; }
        button[type="submit"]:hover { background:var(--blue); transform:translateY(-1px); box-shadow:0 10px 22px rgba(20,31,82,.32); }
        button[type="submit"]:hover svg { transform:translateX(3px); }
        button[type="submit"]:active { transform:translateY(0); }

        .links { display:grid; gap:10px; margin-top:18px; }
        .links a { display:block; padding:11px 16px; border-radius:9px; text-align:center; text-decoration:none; font-size:13px; font-weight:700; transition:.15s; }
        .register-link { background:#E7EEF7; color:var(--navy); }
        .register-link:hover { background:#D2E2F6; transform:translateY(-1px); }

        .divider {
            display:flex; align-items:center; gap:12px;
            margin:22px 0 6px;
            color:var(--blue);
            font-size:11px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:.06em;
        }
        .divider::before, .divider::after { content:""; flex:1; height:1px; background:var(--sky); opacity:.6; }

        .security-note {
            margin-top:14px; padding:12px; border-radius:9px; background:#F7F9FC;
            color:var(--blue); font-size:11px; line-height:1.5; text-align:center;
            display:flex; align-items:center; justify-content:center; gap:7px;
        }
        .security-note svg { width:13px; height:13px; flex-shrink:0; stroke:var(--blue); }

        .password-field {
            position: relative;
            width: 100%;
        }

        .password-field > input[type="password"],
        .password-field > input[type="text"] {
            width: 100%;
            padding-right: 78px !important;
        }

        .show-password-btn {
            position: absolute;
            top: 50%;
            right: 8px;
            transform: translateY(-50%) !important;
            min-width: 62px !important;
            width: auto !important;
            min-height: 34px !important;
            height: 34px !important;
            padding: 5px 9px !important;
            border: 1px solid #D2E2F6 !important;
            border-radius: 6px !important;
            background: #F7F9FC !important;
            color: #52618D !important;
            box-shadow: none !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            line-height: 1 !important;
            cursor: pointer;
            z-index: 2;
        }

        .show-password-btn:hover {
            background: #E7EEF7 !important;
            color: #141F52 !important;
            transform: translateY(-50%) !important;
            box-shadow: none !important;
        }

        .show-password-btn:focus-visible {
            outline: 2px solid #141F52;
            outline-offset: 2px;
        }

        .forgot-password-link {
            color: #141F52;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }
        .forgot-password-link:hover { text-decoration: underline; }

        .forgot-password-row {
            display: flex;
            justify-content: flex-end;
            margin-top: 7px;
            margin-bottom: 4px;
        }

        /* ---------- Responsive: tablets & below (stacked layout) ---------- */
        @media(max-width:980px){
            .page-shell { grid-template-columns:1fr; }
            .brand-panel {
                min-height:clamp(200px, 34vh, 320px);
                clip-path: polygon(0 0, 100% 0, 100% 92%, 0 100%);
            }
            .brand-panel img { animation:none; }
            .brand-content { padding:24px clamp(20px, 5vw, 32px); }
            .brand-bottom { display:none; }
            .form-panel {
                padding:clamp(24px, 5vw, 32px) 20px 40px;
                padding-left: max(20px, env(safe-area-inset-left));
                padding-right: max(20px, env(safe-area-inset-right));
            }
            .login-container { max-width:460px; }
        }

        /* ---------- Small phones ---------- */
        @media(max-width:480px){
            .login-container { padding:28px 20px; border-radius:16px; }
            .brand-panel { min-height:190px; clip-path:polygon(0 0, 100% 0, 100% 90%, 0 100%); }
            .brand-content { padding:18px 20px; }
            .brand-top .brand-name { display:none; }
            .brand-top img.brand-logo { height:46px; width:46px; }
            .brand-middle p { display:none; }
            .brand-pill { margin-bottom:8px; font-size:10.5px; padding:5px 11px; }
            .brand-middle h2 { margin-bottom:0; }
            .logo-container img { height:60px; }
            .login-header { margin-bottom:20px; }
            .login-header h1 { font-size:21px; }
            .form-group { margin-bottom:15px; }
            .show-password-btn { min-width:58px !important; font-size:10px !important; right:6px; }
            .forgot-password-row { justify-content:center; }
            .forgot-password-link { font-size:12px; }
        }

        /* ---------- Very small phones ---------- */
        @media(max-width:360px){
            .login-container { padding:24px 16px; }
            .brand-panel { min-height:150px; }
            .brand-content { padding:14px 16px; }
            .brand-middle h2 { font-size:19px; }
            .input-icon-wrap input { padding-left:36px; }
            .input-icon-wrap svg { left:11px; width:15px; height:15px; }
            .password-field > input[type="password"],
            .password-field > input[type="text"] { padding-right:66px !important; }
            .show-password-btn { min-width:50px !important; font-size:9.5px !important; right:5px; padding:4px 7px !important; }
        }

        /* ---------- Short viewports / landscape phones: prioritize the form ---------- */
        @media(max-height:560px) and (orientation:landscape){
            .page-shell { grid-template-columns: 38vw 1fr; }
            .brand-panel {
                min-height:100vh;
                clip-path: polygon(0 0, 100% 0, 88% 100%, 0 100%);
            }
            .brand-content { padding:18px 24px 18px 18px; justify-content:flex-end; }
            .brand-top, .brand-bottom { display:none; }
            .brand-middle p { display:none; }
            .brand-middle h2 { font-size:17px; margin-bottom:0; }
            .brand-pill { display:none; }
            .form-panel {
                padding:16px 16px;
                padding-left: max(16px, env(safe-area-inset-left));
                padding-right: max(16px, env(safe-area-inset-right));
                padding-bottom: max(16px, env(safe-area-inset-bottom));
                align-items:flex-start;
            }
            .login-container { padding:22px 24px; margin:auto 0; }
            .logo-container { margin-bottom:8px; }
            .logo-container img { height:48px; }
            .login-header { margin-bottom:14px; }
            .form-group { margin-bottom:12px; }
        }

        /* ---------- Large desktops: keep the form column from stretching too wide ---------- */
        @media(min-width:1440px){
            .form-panel { padding:40px; }
        }

        /* ---------- Touch devices: comfortable tap targets ---------- */
        @media(hover:none) and (pointer:coarse){
            button[type="submit"], .links a, .forgot-password-link {
                min-height:44px;
            }
            .links a, button[type="submit"] { padding-top:13px; padding-bottom:13px; }
            .show-password-btn { min-height:38px !important; padding:8px 10px !important; }
        }
</style>
    <?php require_once __DIR__ . '/includes/responsive.php'; ?>
</head>
<body class="admin-login-page">
<?php require_once __DIR__ . '/includes/ui_feedback.php'; ?>
<div class="page-shell">

    <div class="brand-panel">
        <img src="/LibraryBorrowingSystem/Img/Main_entrance_JASHS.png" alt="Jose Abad Santos High School main entrance">
        <div class="brand-texture"></div>
        <div class="brand-content">
            <div class="brand-top">
                <img class="brand-logo" src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo">
                <div class="brand-name">
                    <strong>Jose Abad Santos High School</strong>
                    Library Borrowing System
                </div>
            </div>

            <div class="brand-middle">
                <span class="brand-pill"><span class="dot"></span>Proud to be Abadians</span>
                <h2>Welcome back to the <em>JASHS Library</em>.</h2>
                <p>Sign in to borrow books, track due dates, and manage your library account anytime, anywhere on campus.</p>
            </div>

        </div>
    </div>

    <div class="form-panel">
        <div class="login-container">
            <div class="logo"><img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School"></div>
            <div class="login-header">
                <h1>Library Login</h1>
                <p>Jose Abad Santos High School Library Borrowing System</p>
            </div>


            <?php if ($error): ?>
            <script>document.addEventListener('DOMContentLoaded',()=>showToast(<?php echo json_encode($error); ?>,'error',4500,'Login'));</script>
            <?php endif; ?>
            <?php if ($success): ?>
            <script>document.addEventListener('DOMContentLoaded',()=>showToast(<?php echo json_encode($success); ?>,'success',3500));</script>
            <?php endif; ?>

            <form method="POST" autocomplete="off" novalidate>
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label for="identifier">Username</label>
                    <div class="input-icon-wrap">
                        <input type="text" id="identifier" name="identifier" required maxlength="150" autocomplete="username" value="<?php echo htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter your login identifier">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-field input-icon-wrap">
                        <input type="password" id="password" name="password" required maxlength="128" autocomplete="current-password" placeholder="Enter your password">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="10" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <button type="button" class="show-password-btn" aria-pressed="false" aria-label="Show password">Show</button>
                    </div>
                    <div class="forgot-password-row">
                        <a class="forgot-password-link" href="/LibraryBorrowingSystem/forgot_password.php">Forgot Password?</a>
                    </div>
                </div>
                <button type="submit">
                    Login
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="M13 6l6 6-6 6"></path></svg>
                </button>
            </form>

            <div class="divider">New here?</div>
            <div class="links">
                <a class="register-link" href="/LibraryBorrowingSystem/student/register.php">Register</a>
            </div>
            <div class="security-note">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                Secure sessions and login rate-limiting help protect access to the library system.
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initPasswordToggles();
        });
    } else {
        initPasswordToggles();
    }
})();
</script>

</body>
</html>