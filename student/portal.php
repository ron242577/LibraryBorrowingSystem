<?php
/**
 * Student Portal Login - Jose Abad Santos High School Library Borrowing System
 * Flow: QR/manual identification -> password + CAPTCHA -> student session.
 */
require_once __DIR__ . '/../db.php';

if (isset($_GET['logout'])) {
    unset(
        $_SESSION['student_id'],
        $_SESSION['student_no'],
        $_SESSION['student_name'],
        $_SESSION['student_qr'],
        $_SESSION['student_login_time'],
        $_SESSION['student_login_started_at'],
        $_SESSION['student_session_fingerprint']
    );
    clearMathCaptcha('student');
    session_regenerate_id(true);
}

if (isset($_SESSION['student_id']) && !isset($_GET['logout'])) {
    header('Location: /LibraryBorrowingSystem/student/profile.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = (string)($_POST['api'] ?? '');

    try {
        requireValidCsrf($_POST['csrf_token'] ?? '');

        if ($api === 'verify_student_password') {
            $studentQr = trim($_POST['qr'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $captchaAnswer = trim((string)($_POST['captcha_answer'] ?? ''));

            if ($studentQr === '' || $password === '' || $captchaAnswer === '') {
                throw new RuntimeException('QR code, password, and CAPTCHA are required.');
            }

            $lock = isLoginLocked($conn, 'student', $studentQr);
            if ($lock['locked']) {
                $minutes = max(1, (int)ceil($lock['seconds'] / 60));
                throw new RuntimeException("Too many failed attempts. Try again in about {$minutes} minute(s).");
            }

            if (!verifyMathCaptcha($captchaAnswer, 'student')) {
                recordFailedLogin($conn, 'student', $studentQr);
                throw new RuntimeException('Incorrect CAPTCHA. Solve the new challenge and try again.');
            }

            $stmt = $conn->prepare('SELECT student_id, student_no, full_name, qr_code, password, status FROM students WHERE qr_code = ? LIMIT 1');
            if (!$stmt) {
                throw new RuntimeException('Unable to process student login right now.');
            }
            $stmt->bind_param('s', $studentQr);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $passwordOk = $student ? constantTimePasswordVerify($password, $student['password']) : constantTimePasswordVerify($password, null);
            if (!$student || !$passwordOk || $student['status'] !== 'active') {
                recordFailedLogin($conn, 'student', $studentQr);
                throw new RuntimeException('Invalid student QR code or password.');
            }

            clearFailedLogins($conn, 'student', $studentQr);
            clearMathCaptcha('student');
            session_regenerate_id(true);

            $_SESSION['student_id'] = (int)$student['student_id'];
            $_SESSION['student_no'] = $student['student_no'];
            $_SESSION['student_name'] = $student['full_name'];
            $_SESSION['student_qr'] = $student['qr_code'];
            $_SESSION['student_login_time'] = time();
            $_SESSION['student_login_started_at'] = time();
            $_SESSION['student_session_fingerprint'] = createSessionFingerprint();

            echo json_encode([
                'success' => true,
                'redirect' => '/LibraryBorrowingSystem/student/profile.php',
                'message' => 'Login successful.'
            ]);
            exit();
        }

        if ($api === 'refresh_student_captcha') {
            echo json_encode([
                'success' => true,
                'captcha_question' => refreshMathCaptcha('student')
            ]);
            exit();
        }

        throw new RuntimeException('Invalid request.');
    } catch (Throwable $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
        if ($api === 'verify_student_password' || $api === 'refresh_student_captcha') {
            $response['captcha_question'] = refreshMathCaptcha('student');
        }
        echo json_encode($response);
        exit();
    }
}

$csrf = csrfToken();
$captchaQuestion = currentMathCaptchaQuestion('student');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - Jose Abad Santos High School</title>
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box} body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#141F52;color:#202A44;min-height:100vh;padding:20px}
        .login-page{min-height:calc(100vh - 40px);display:flex;flex-direction:column;align-items:center;justify-content:center}.welcome-section{text-align:center;color:white;margin-bottom:32px}.welcome-icon{width:115px;height:115px;object-fit:contain;border-radius:50%;background:white;margin-bottom:14px}.welcome-section h1{font-size:36px;margin-bottom:8px}.welcome-section p{opacity:.92;font-size:15px}
        .login-form-container{width:100%;max-width:470px;background:white;border-radius:18px;overflow:hidden;box-shadow:0 18px 50px rgba(0,0,0,.24)}.form-header{background:#141F52;color:white;padding:22px 26px;text-align:center;border-bottom:4px solid #F4F916}.form-header h2{font-size:21px}.form-content{padding:30px}.form-group{margin-bottom:20px}label{display:block;margin-bottom:8px;color:#52618D;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.5px}
        input[type=text],input[type=password],input[type=number]{width:100%;padding:13px 15px;border:2px solid #D2E2F6;border-radius:9px;font-size:14px;outline:none}input:focus{border-color:#141F52;box-shadow:0 0 0 3px rgba(244,249,22,.35)}.helper-text{margin-top:7px;color:#52618D;font-size:12px;line-height:1.5}.scanner-box{background:#F3F7FC;border:1px solid #D2E2F6;border-radius:10px;padding:12px;margin-bottom:20px}#qr-reader{width:100%;min-height:230px;background:white;border-radius:8px;overflow:hidden}
        .btn{width:100%;padding:13px 20px;border:0;border-radius:9px;background:#141F52;color:white;font-size:14px;font-weight:800;cursor:pointer}.btn:hover{background:#52618D}.btn:disabled{opacity:.6;cursor:not-allowed}.btn-secondary{background:#E7EEF7;color:#202A44}.btn-secondary:hover{background:#D2E2F6}.links{display:grid;gap:10px;margin-top:24px;padding-top:20px;border-top:1px solid #E7EEF7}.links a{display:block;padding:11px 16px;border-radius:9px;text-align:center;text-decoration:none;font-size:13px;font-weight:700}.register-link{background:#141F52;color:white}.staff-link{background:#E7EEF7;color:#202A44}
        .modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.58);z-index:2000}.modal.show{display:flex}.modal-card{width:100%;max-width:405px;background:white;border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.28);overflow:hidden}.modal-header{padding:20px 22px;background:#141F52;color:white;border-bottom:4px solid #F4F916}.modal-header h3{font-size:19px}.modal-body{padding:22px}.selected-qr{margin-bottom:16px;padding:10px 12px;background:#F3F7FC;border-radius:7px;color:#52618D;font-size:12px;word-break:break-all}.modal-error{display:none;margin-bottom:13px;padding:10px 12px;border-radius:7px;background:#FBE8DC;color:#7A3A0E;font-size:13px}.modal-error.show{display:block}.modal-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px}.security-note{margin-top:14px;color:#52618D;font-size:11px;line-height:1.5;text-align:center}
        .captcha-box{background:#F3F7FC;border:1px solid #D2E2F6;border-radius:9px;padding:14px}.captcha-question{font-size:20px;font-weight:900;color:#141F52;margin-bottom:10px;letter-spacing:.5px}.captcha-row{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center}.captcha-refresh{width:auto;padding:12px 14px;background:#E7EEF7;color:#202A44}.captcha-refresh:hover{background:#D2E2F6}
        @media(max-width:520px){.welcome-section h1{font-size:28px}.form-content{padding:24px 18px}.modal-actions{grid-template-columns:1fr}.captcha-row{grid-template-columns:1fr}}
    </style>
    <?php require_once __DIR__ . '/../includes/responsive.php'; ?>
</head>
<body class="student-portal-page">
<?php require_once __DIR__ . '/../includes/ui_feedback.php'; ?>
<div class="login-page">
    <div class="welcome-section">
        <img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo" class="welcome-icon">
        <h1>Student Portal</h1>
        <p>Jose Abad Santos High School Library Borrowing System</p>
    </div>
    <div class="login-form-container">
        <div class="form-header"><h2>Secure Student Access</h2></div>
        <div class="form-content">
            <div class="form-group">
                <label>QR Code Scanner</label>
                <div class="scanner-box"><div id="qr-reader"></div></div>
                <div class="helper-text">Scan your student QR code. You will be asked for your password and a security CAPTCHA.</div>
            </div>
            <form id="manualForm">
                <div class="form-group">
                    <label for="studentQr">Manual QR Entry</label>
                    <input type="text" id="studentQr" placeholder="Enter or paste your student QR code" maxlength="255" autocomplete="off" required>
                </div>
                <button type="submit" class="btn">Continue</button>
            </form>
            <div class="security-note">Student login uses your QR code, password, and CAPTCHA. Email verification is only required when creating a new student account.</div>
            <div class="links"><a class="register-link" href="/LibraryBorrowingSystem/student/register.php">Register as Student</a><a class="staff-link" href="/LibraryBorrowingSystem/login.php">Login as Admin</a></div>
        </div>
    </div>
</div>

<div id="passwordModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="passwordModalTitle">
    <div class="modal-card">
        <div class="modal-header"><h3 id="passwordModalTitle">Student Login Verification</h3></div>
        <div class="modal-body">
            <div id="selectedQrText" class="selected-qr"></div>
            <div id="passwordError" class="modal-error"></div>
            <form id="passwordForm">
                <div class="form-group">
                    <label for="studentPassword">Password</label>
                    <input type="password" id="studentPassword" maxlength="128" autocomplete="current-password" required>
                </div>
                <div class="form-group captcha-box">
                    <label for="captchaAnswer">Security CAPTCHA</label>
                    <div id="captchaQuestion" class="captcha-question"><?php echo htmlspecialchars($captchaQuestion, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="captcha-row">
                        <input type="number" id="captchaAnswer" inputmode="numeric" autocomplete="off" placeholder="Enter the answer" required>
                        <button type="button" class="btn captcha-refresh" id="refreshCaptcha">New CAPTCHA</button>
                    </div>
                    <div class="helper-text">Solve the math challenge to continue. A new CAPTCHA is generated after a failed attempt.</div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="cancelPassword">Cancel</button>
                    <button type="submit" class="btn" id="passwordButton">Login</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode($csrf); ?>;
let scanner = null, pendingQr = '';
const passwordModal = document.getElementById('passwordModal');
const passwordInput = document.getElementById('studentPassword');
const passwordError = document.getElementById('passwordError');
const captchaInput = document.getElementById('captchaAnswer');
const captchaQuestion = document.getElementById('captchaQuestion');

function apiBody(action, extra={}) {
    const body = new URLSearchParams();
    body.set('api', action);
    body.set('csrf_token', CSRF_TOKEN);
    Object.entries(extra).forEach(([key, value]) => body.set(key, value));
    return body.toString();
}

async function postApi(action, extra={}) {
    const response = await fetch('/LibraryBorrowingSystem/student/portal.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
        body: apiBody(action, extra)
    });
    return await response.json();
}

function openPasswordModal(qr) {
    pendingQr = String(qr || '').trim();
    if (!pendingQr) return;
    document.getElementById('selectedQrText').textContent = 'QR Code: ' + pendingQr;
    passwordInput.value = '';
    captchaInput.value = '';
    passwordError.classList.remove('show');
    passwordModal.classList.add('show');
    setTimeout(() => passwordInput.focus(), 100);
}

function closePasswordModal() {
    passwordModal.classList.remove('show');
    passwordInput.value = '';
    captchaInput.value = '';
    passwordError.classList.remove('show');
}

async function refreshCaptcha() {
    try {
        const data = await postApi('refresh_student_captcha');
        if (data.captcha_question) captchaQuestion.textContent = data.captcha_question;
        captchaInput.value = '';
    } catch (error) {
        showToast('Unable to refresh the CAPTCHA. Refresh the page and try again.', 'error', 3500);
    }
}

document.getElementById('manualForm').addEventListener('submit', event => {
    event.preventDefault();
    openPasswordModal(document.getElementById('studentQr').value);
});

document.getElementById('cancelPassword').addEventListener('click', () => {
    closePasswordModal();
    pendingQr = '';
});

document.getElementById('refreshCaptcha').addEventListener('click', refreshCaptcha);
passwordModal.addEventListener('click', event => {
    if (event.target === passwordModal) {
        closePasswordModal();
        pendingQr = '';
    }
});

document.getElementById('passwordForm').addEventListener('submit', async event => {
    event.preventDefault();
    const button = document.getElementById('passwordButton');
    if (!pendingQr || !passwordInput.value || !captchaInput.value) return;

    button.disabled = true;
    button.textContent = 'Checking...';
    passwordError.classList.remove('show');

    try {
        const data = await postApi('verify_student_password', {
            qr: pendingQr,
            password: passwordInput.value,
            captcha_answer: captchaInput.value
        });

        if (data.captcha_question) captchaQuestion.textContent = data.captcha_question;
        if (!data.success) throw new Error(data.message || 'Login failed.');

        showToast('Login successful. Opening your profile...', 'success', 2200, 'Welcome');
        setTimeout(() => window.location.href = data.redirect, 500);
    } catch (error) {
        passwordError.textContent = error.message;
        passwordError.classList.add('show');
        captchaInput.value = '';
        if (captchaQuestion.textContent) setTimeout(() => captchaInput.focus(), 100);
    } finally {
        button.disabled = false;
        button.textContent = 'Login';
    }
});

async function startScanner() {
    if (typeof Html5Qrcode === 'undefined') {
        showToast('QR scanner failed to load. Use manual entry instead.', 'error', 4000);
        return;
    }

    scanner = new Html5Qrcode('qr-reader');
    try {
        await scanner.start(
            {facingMode:'environment'},
            {fps:10, qrbox:{width:230,height:230}},
            async decoded => {
                try { await scanner.stop(); } catch (error) {}
                showToast('QR code scanned. Enter your password and CAPTCHA to continue.', 'success', 2600);
                openPasswordModal(decoded);
            },
            () => {}
        );
    } catch (error) {
        showToast('Unable to access the camera. Use manual entry below.', 'error', 4000);
    }
}

document.addEventListener('DOMContentLoaded', () => setTimeout(startScanner, 300));
</script>
</body>
</html>
