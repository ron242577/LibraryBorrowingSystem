<?php
/**
 * Library Login - Jose Abad Santos High School Library Borrowing System
 * Automatically detects the Chief Librarian or Student account from the identifier.
 * Student identifiers accepted: Student Number, Email, or Contact Number.
 * Both account types require the same security CAPTCHA.
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

$captchaQuestion = currentMathCaptchaQuestion('login');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['api'] ?? '') === 'refresh_captcha') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        requireValidCsrf($_POST['csrf_token'] ?? '');
        echo json_encode(['success' => true, 'captcha_question' => refreshMathCaptcha('login')]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unable to refresh CAPTCHA.']);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $captchaAnswer = trim((string)($_POST['captcha_answer'] ?? ''));

    $accountType = 'unknown';
    $account = null;
    $loginMethod = 'unknown';

    try {
        requireValidCsrf($_POST['csrf_token'] ?? '');

        if ($identifier === '' || $password === '' || $captchaAnswer === '') {
            auditLogLoginEvent(
                $conn,
                'unknown',
                null,
                $identifier,
                'failure',
                'Login failed because required fields were not completed.',
                ['reason' => 'missing_fields']
            );
            throw new RuntimeException('Username/email/contact number and password are required, including CAPTCHA verification.');
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

        if (!verifyMathCaptcha($captchaAnswer, 'login')) {
            recordFailedLogin($conn, 'login', $identifier);
            auditLogLoginEvent(
                $conn,
                'unknown',
                null,
                $identifier,
                'failure',
                'Login failed because the CAPTCHA was incorrect.',
                ['reason' => 'captcha_failed']
            );
            throw new RuntimeException('Incorrect CAPTCHA. Solve the new challenge and try again.');
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

        // Student account accepts student number, email, or contact number.
        if ($account === null) {
            $studentStmt = $conn->prepare(
                'SELECT student_id, student_no, full_name, email, contact_number,
                        qr_code, password, status
                 FROM students
                 WHERE student_no = ? OR email = ? OR contact_number = ?
                 LIMIT 1'
            );
            if (!$studentStmt) {
                throw new RuntimeException('Unable to process the login right now.');
            }

            $studentStmt->bind_param('sss', $identifier, $identifier, $identifier);
            $studentStmt->execute();
            $studentAccount = $studentStmt->get_result()->fetch_assoc();
            $studentStmt->close();

            if ($studentAccount) {
                $accountType = 'student';
                $account = $studentAccount;

                if (strcasecmp($identifier, (string)$studentAccount['email']) === 0) {
                    $loginMethod = 'student_email';
                } elseif ($identifier === (string)$studentAccount['contact_number']) {
                    $loginMethod = 'student_contact';
                } else {
                    $loginMethod = 'student_number';
                }
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
            clearMathCaptcha('login');
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

            if (!$passwordOk || $account['status'] !== 'active') {
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
            clearMathCaptcha('login');
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
        $captchaQuestion = refreshMathCaptcha('login');
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
        body { font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:var(--light); display:flex; justify-content:center; align-items:center; min-height:100vh; padding:20px; color:var(--text); }
        .login-container { background:var(--white); padding:38px; border:1px solid var(--sky); border-top:6px solid var(--navy); border-radius:10px; box-shadow:0 12px 30px rgba(20,31,82,.18); width:100%; max-width:430px; }
        .logo-container { text-align:center; margin-bottom:18px; }
        .logo-container img { height:96px; width:auto; max-width:100%; object-fit:contain; }
        .login-header { text-align:center; margin-bottom:28px; }
        .login-header h1 { color:var(--navy); margin-bottom:8px; font-size:27px; }
        .login-header p { color:var(--blue); font-size:14px; line-height:1.5; }
        .account-note { margin:0 0 20px; padding:12px; border-radius:7px; background:#EDF3FA; color:var(--blue); font-size:12px; line-height:1.5; text-align:center; }
        .form-group { margin-bottom:18px; }
        label { display:block; margin-bottom:8px; color:var(--text); font-weight:700; font-size:13px; }
        input { width:100%; padding:12px 13px; border:1px solid var(--sky); border-radius:6px; font-size:14px; }
        input:focus { outline:none; border-color:var(--navy); box-shadow:0 0 0 3px rgba(244,249,22,.35); }
        .captcha-box { background:#F7F9FC; border:1px solid var(--light); border-radius:7px; padding:13px; }
        .captcha-question { font-size:21px; font-weight:900; color:var(--navy); letter-spacing:.5px; margin-bottom:10px; }
        .captcha-row { display:grid; grid-template-columns:1fr auto; gap:8px; }
        .captcha-refresh { width:auto; padding:0 14px; background:#E7EEF7; color:var(--text); border:0; border-radius:6px; font-weight:700; cursor:pointer; }
        .captcha-refresh:hover { background:#D2E2F6; }
        button[type="submit"] { width:100%; padding:13px; background:var(--navy); color:white; border:0; border-radius:6px; font-size:15px; font-weight:800; cursor:pointer; transition:.2s; }
        button[type="submit"]:hover { background:var(--blue); transform:translateY(-1px); }
        .links { display:grid; gap:10px; margin-top:18px; }
        .links a { display:block; padding:11px 16px; border-radius:7px; text-align:center; text-decoration:none; font-size:13px; font-weight:700; }
        .register-link { background:#E7EEF7; color:var(--navy); }
        .register-link:hover { background:#D2E2F6; }
        .security-note { margin-top:18px; padding:12px; border-radius:7px; background:#F7F9FC; color:var(--blue); font-size:11px; line-height:1.5; text-align:center; }
        @media(max-width:480px){ .login-container{padding:28px 22px;} .captcha-row{grid-template-columns:1fr;} .captcha-refresh{min-height:44px;} }
    
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

        @media (max-width: 480px) {
            .show-password-btn {
                min-width: 58px !important;
                font-size: 10px !important;
                right: 6px;
            }
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

        .forgot-password-link {
            color: #141F52;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .forgot-password-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .forgot-password-row {
                justify-content: center;
            }

            .forgot-password-link {
                font-size: 12px;
            }
        }

</style>
    <?php require_once __DIR__ . '/includes/responsive.php'; ?>
</head>
<body class="admin-login-page">
<?php require_once __DIR__ . '/includes/ui_feedback.php'; ?>
<div class="login-container">
    <div class="logo-container"><img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo"></div>
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
            <label for="identifier">Username / Student Number / Email / Contact Number</label>
            <input type="text" id="identifier" name="identifier" required maxlength="150" autocomplete="username" value="<?php echo htmlspecialchars($_POST['identifier'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter your login identifier">
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <div class="password-field">
                <input type="password" id="password" name="password" required maxlength="128" autocomplete="current-password" placeholder="Enter your password">
                <button type="button" class="show-password-btn" aria-pressed="false" aria-label="Show password">Show</button>
            </div>
            <div class="forgot-password-row">
                <a class="forgot-password-link" href="/LibraryBorrowingSystem/student/forgot_password.php">Forgot Student Password?</a>
            </div>

        </div>
        <div class="form-group captcha-box">
            <label for="captcha_answer">Security CAPTCHA</label>
            <div id="captchaQuestion" class="captcha-question"><?php echo htmlspecialchars($captchaQuestion, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="captcha-row">
                <input type="number" id="captcha_answer" name="captcha_answer" inputmode="numeric" autocomplete="off" required placeholder="Enter the answer">
                <button type="button" class="captcha-refresh" id="refreshCaptcha">New CAPTCHA</button>
            </div>
        </div>
        <button type="submit">Secure Login</button>
    </form>

    <div class="links">
        <a class="register-link" href="/LibraryBorrowingSystem/student/register.php">Register as Student</a>
    </div>
    <div class="security-note">Your account type is detected automatically as Chief Librarian or Student. Login attempts are rate-limited and protected with CAPTCHA and secure sessions.</div>
</div>
<script>
const CSRF_TOKEN = <?php echo json_encode($csrf); ?>;
const captchaQuestion = document.getElementById('captchaQuestion');
const captchaAnswer = document.getElementById('captcha_answer');

async function refreshCaptcha() {
    try {
        const body = new URLSearchParams();
        body.set('api', 'refresh_captcha');
        body.set('csrf_token', CSRF_TOKEN);
        const response = await fetch('/LibraryBorrowingSystem/login.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        });
        const data = await response.json();
        if (data.captcha_question) captchaQuestion.textContent = data.captcha_question;
        captchaAnswer.value = '';
        captchaAnswer.focus();
    } catch (error) {
        showToast('Unable to refresh CAPTCHA. Please refresh the page.', 'error', 3500);
    }
}

document.getElementById('refreshCaptcha').addEventListener('click', refreshCaptcha);
</script>

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
