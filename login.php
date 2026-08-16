<?php
/**
 * Admin Login - Jose Abad Santos High School Library Borrowing System
 * Includes CSRF protection, rate limiting, and secure sessions.
 */
require_once __DIR__ . '/db.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /LibraryBorrowingSystem/admin/dashboard.php');
    exit();
}

$error = '';
$success = '';

if (isset($_GET['logout'])) {
    $success = 'You have been logged out successfully.';
}
if (isset($_GET['expired'])) {
    $error = 'Your session has expired. Please login again.';
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    try {
        requireValidCsrf($_POST['csrf_token'] ?? '');

        if ($username === '' || $password === '') {
            throw new RuntimeException('Username and password are required.');
        }

        $lock = isLoginLocked($conn, 'admin', $username);
        if ($lock['locked']) {
            $minutes = max(1, (int)ceil($lock['seconds'] / 60));
            throw new RuntimeException("Too many failed login attempts. Try again in about {$minutes} minute(s).");
        }


        $stmt = $conn->prepare('SELECT user_id, full_name, username, password, role, status FROM users WHERE username = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to process the login right now.');
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $password_ok = $user ? constantTimePasswordVerify($password, $user['password']) : constantTimePasswordVerify($password, null);

        if (!$user || !$password_ok || $user['status'] !== 'active' || $user['role'] !== 'admin') {
            recordFailedLogin($conn, 'admin', $username);
            throw new RuntimeException('Invalid login credentials or inactive account.');
        }

        clearFailedLogins($conn, 'admin', $username);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = 'admin';
        $_SESSION['login_time'] = time();
        $_SESSION['login_started_at'] = time();
        $_SESSION['session_fingerprint'] = createSessionFingerprint();

        header('Location: /LibraryBorrowingSystem/admin/dashboard.php');
        exit();
    } catch (Throwable $e) {
        $error = $e->getMessage();
        logError('Admin login attempt: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Jose Abad Santos High School Library</title>
    <style>
        :root { --navy:#141F52; --blue:#52618D; --sky:#91B0E0; --light:#D2E2F6; --yellow:#F4F916; --white:#FEFEF9; --text:#202A44; }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:var(--light); display:flex; justify-content:center; align-items:center; min-height:100vh; padding:20px; color:var(--text); }
        .login-container { background:var(--white); padding:38px; border:1px solid var(--sky); border-top:6px solid var(--navy); border-radius:10px; box-shadow:0 12px 30px rgba(20,31,82,.18); width:100%; max-width:430px; }
        .logo-container { text-align:center; margin-bottom:18px; }
        .logo-container img { height:96px; width:auto; }
        .login-header { text-align:center; margin-bottom:28px; }
        .login-header h1 { color:var(--navy); margin-bottom:8px; font-size:27px; }
        .login-header p { color:var(--blue); font-size:14px; line-height:1.5; }
        .form-group { margin-bottom:18px; }
        label { display:block; margin-bottom:8px; color:var(--text); font-weight:700; font-size:13px; }
        input { width:100%; padding:12px 13px; border:1px solid var(--sky); border-radius:6px; font-size:14px; }
        input:focus { outline:none; border-color:var(--navy); box-shadow:0 0 0 3px rgba(244,249,22,.35); }
        button { width:100%; padding:13px; background:var(--navy); color:white; border:0; border-radius:6px; font-size:15px; font-weight:800; cursor:pointer; transition:.2s; }
        button:hover { background:var(--blue); transform:translateY(-1px); }
        .student-login-link { display:block; margin-top:18px; text-align:center; color:var(--navy); font-size:13px; font-weight:700; text-decoration:none; }
        .student-login-link:hover { text-decoration:underline; }
        .security-note { margin-top:20px; padding:12px; border-radius:7px; background:#F7F9FC; color:var(--blue); font-size:11px; line-height:1.5; text-align:center; }
        @media(max-width:480px){ .login-container{padding:28px 22px;} }
    </style>
    <?php require_once __DIR__ . '/includes/responsive.php'; ?>
</head>
<body class="admin-login-page">
<?php require_once __DIR__ . '/includes/ui_feedback.php'; ?>
<div class="login-container">
    <div class="logo-container"><img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo"></div>
    <div class="login-header">
        <h1>Library Admin Login</h1>
        <p>Jose Abad Santos High School Library Borrowing System</p>
    </div>

    <?php if ($error): ?>
    <script>document.addEventListener('DOMContentLoaded',()=>showToast(<?php echo json_encode($error); ?>,'error',4500,'Login')); </script>
    <?php endif; ?>
    <?php if ($success): ?>
    <script>document.addEventListener('DOMContentLoaded',()=>showToast(<?php echo json_encode($success); ?>,'success',3500));</script>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <?php echo csrfField(); ?>
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required maxlength="80" autocomplete="username" value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required maxlength="128" autocomplete="current-password">
        </div>
        <button type="submit">Secure Login</button>
    </form>
    <a href="/LibraryBorrowingSystem/student/portal.php" class="student-login-link">Login as Student</a>
    <div class="security-note">Login attempts are rate-limited. Sessions use secure cookies and automatically expire after inactivity.</div>
</div>
</body>
</html>
