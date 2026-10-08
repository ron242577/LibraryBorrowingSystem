<?php
/**
 * Teacher Password Recovery - Jose Abad Santos High School
 * Email verification is used only to reset an existing teacher's password.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/gmail_smtp.php';

$step = $_SESSION['password_reset_step'] ?? 'request';
$message = '';
$message_type = '';
$masked_email = '';
$cooldown = 0;

if (isset($_GET['cancel'])) {
    unset(
        $_SESSION['password_reset_teacher_id'],
        $_SESSION['password_reset_email'],
        $_SESSION['password_reset_name'],
        $_SESSION['password_reset_code_hash'],
        $_SESSION['password_reset_code_expires'],
        $_SESSION['password_reset_attempts'],
        $_SESSION['password_reset_last_sent'],
        $_SESSION['password_reset_step']
    );
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireValidCsrf($_POST['csrf_token'] ?? '');
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'request_code') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Enter a valid registered teacher email.');
            }

            $lock = isLoginLocked($conn, 'teacher_password_reset', $email);
            if ($lock['locked']) {
                $minutes = max(1, (int)ceil($lock['seconds'] / 60));
                throw new RuntimeException("Too many requests. Try again in about {$minutes} minute(s).");
            }

            $stmt = $conn->prepare("SELECT teacher_id, full_name, email, status FROM teachers WHERE LOWER(email) = ? AND status = 'active' AND COALESCE(is_archived,0) = 0 LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $teacher = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Do not reveal whether the email exists to unauthenticated users.
            if (!$teacher) {
                recordFailedLogin($conn, 'teacher_password_reset', $email);
                throw new RuntimeException('If that email is registered, a verification code will be sent.');
            }

            $lastSent = (int)($_SESSION['password_reset_last_sent'] ?? 0);
            $wait = 60 - (time() - $lastSent);
            if ($wait > 0 && ($_SESSION['password_reset_email'] ?? '') === $email) {
                throw new RuntimeException("Please wait {$wait} second(s) before requesting another code.");
            }

            $code = generateOtpCode();
            sendTeacherPasswordResetEmail($teacher['email'], $teacher['full_name'], $code);

            $_SESSION['password_reset_teacher_id'] = (int)$teacher['teacher_id'];
            $_SESSION['password_reset_email'] = $teacher['email'];
            $_SESSION['password_reset_name'] = $teacher['full_name'];
            $_SESSION['password_reset_code_hash'] = password_hash($code, PASSWORD_DEFAULT);
            $_SESSION['password_reset_code_expires'] = time() + 300;
            $_SESSION['password_reset_attempts'] = 0;
            $_SESSION['password_reset_last_sent'] = time();
            $_SESSION['password_reset_step'] = 'verify';

            clearFailedLogins($conn, 'teacher_password_reset', $email);
            $message = 'A 6-digit verification code has been sent to ' . maskEmail($teacher['email']) . '.';
            $message_type = 'success';
            $masked_email = maskEmail($teacher['email']);
            $step = 'verify';
        }

        if ($action === 'verify_code') {
            $teacherId = (int)($_SESSION['password_reset_teacher_id'] ?? 0);
            $email = (string)($_SESSION['password_reset_email'] ?? '');
            $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));

            if ($teacherId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($_SESSION['password_reset_code_hash'])) {
                throw new RuntimeException('Your password recovery session has expired. Start again.');
            }
            if (time() > (int)($_SESSION['password_reset_code_expires'])) {
                throw new RuntimeException('The verification code expired. Request a new code.');
            }
            if ((int)($_SESSION['password_reset_attempts'] ?? 0) >= 5) {
                unset($_SESSION['password_reset_code_hash']);
                throw new RuntimeException('Too many incorrect verification attempts. Request a new code.');
            }
            if (!preg_match('/^\d{6}$/', $code)) {
                throw new RuntimeException('Enter the 6-digit verification code.');
            }

            $_SESSION['password_reset_attempts'] = (int)$_SESSION['password_reset_attempts'] + 1;
            if (!password_verify($code, $_SESSION['password_reset_code_hash'])) {
                throw new RuntimeException('Incorrect verification code.');
            }

            $_SESSION['password_reset_verified_until'] = time() + 600;
            $_SESSION['password_reset_step'] = 'reset';
            $step = 'reset';
        }

        if ($action === 'reset_password') {
            $teacherId = (int)($_SESSION['password_reset_teacher_id'] ?? 0);
            $verifiedUntil = (int)($_SESSION['password_reset_verified_until'] ?? 0);
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($teacherId <= 0 || $verifiedUntil < time()) {
                throw new RuntimeException('Your password reset authorization expired. Start again.');
            }

            $policyErrors = passwordPolicyErrors($newPassword, false);
            if ($newPassword === '' || !empty($policyErrors)) {
                throw new RuntimeException($newPassword === '' ? 'Password is required.' : strongPasswordMessage($policyErrors));
            }
            if (!hash_equals($newPassword, $confirmPassword)) {
                throw new RuntimeException('Password confirmation does not match.');
            }

            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE teachers SET password = ? WHERE teacher_id = ? AND status = 'active' AND COALESCE(is_archived,0) = 0");
            $stmt->bind_param('si', $hash, $teacherId);
            if (!$stmt->execute() || $stmt->affected_rows < 1) {
                $stmt->close();
                throw new RuntimeException('Unable to update your password. Please try again.');
            }
            $stmt->close();

            $email = (string)($_SESSION['password_reset_email'] ?? '');
            clearFailedLogins($conn, 'teacher_password_reset', $email);
            session_regenerate_id(true);

            unset(
                $_SESSION['password_reset_teacher_id'],
                $_SESSION['password_reset_email'],
                $_SESSION['password_reset_name'],
                $_SESSION['password_reset_code_hash'],
                $_SESSION['password_reset_code_expires'],
                $_SESSION['password_reset_attempts'],
                $_SESSION['password_reset_last_sent'],
                $_SESSION['password_reset_verified_until'],
                $_SESSION['password_reset_step']
            );

            header('Location: /LibraryBorrowingSystem/login.php?reset=1');
            exit();
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $message_type = 'error';
        if (isset($_SESSION['password_reset_email'])) {
            $masked_email = maskEmail((string)$_SESSION['password_reset_email']);
        }
        $step = $_SESSION['password_reset_step'] ?? 'request';
    }
}

$csrf = csrfToken();
if (!$masked_email && !empty($_SESSION['password_reset_email'])) {
    $masked_email = maskEmail((string)$_SESSION['password_reset_email']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Teacher Password Recovery - JASHS Library</title>
<?php require_once __DIR__ . '/../includes/auth_ui.php'; authUiHead(); ?>
<?php require_once __DIR__ . '/../includes/responsive.php'; ?>
</head>
<body class="auth-page">
<?php authUiBackdrop(); ?>
<div class="auth-wrap">
<?php authUiBrand(); ?>
<div class="auth-card">
<div class="auth-icon"><svg viewBox="0 0 24 24"><circle cx="8" cy="15" r="4"/><path d="M10.8 12.2 20 3m-4 4 3 3m-5-1 2 2"/></svg></div>
<h1 class="auth-title">Reset your password</h1>
<?php if ($step === 'request'): ?>
<p class="auth-sub">Enter the email address registered to your teacher account and we will send you a verification code.</p>
<?php elseif ($step === 'verify'): ?>
<p class="auth-sub">Enter the 6-digit code we sent to <strong><?php echo htmlspecialchars($masked_email); ?></strong>. It expires in 5 minutes.</p>
<?php else: ?>
<p class="auth-sub">Code verified. Choose a new password for your account.</p>
<?php endif; ?>
<?php authUiSteps($step); ?>

<?php if ($message !== ''): ?>
<div class="alert <?php echo $message_type === 'success' ? 'success' : 'error'; ?>" role="<?php echo $message_type === 'success' ? 'status' : 'alert'; ?>">
    <?php echo $message_type === 'success' ? '<svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/></svg>' : '<svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>'; ?>
    <div><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
</div>
<?php endif; ?>

<?php if ($step === 'request'): ?>
<form method="POST">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="request_code">
    <div class="field">
        <label for="email">Registered email</label>
        <div class="input-wrap has-icon">
            <input type="email" id="email" name="email" required autocomplete="email" placeholder="teacher@gmail.com">
            <svg class="lead" aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
        </div>
    </div>
    <button class="btn primary" type="submit">Send verification code</button>
    <a class="btn ghost" href="/LibraryBorrowingSystem/login.php"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M19 12H5m6-6-6 6 6 6"/></svg> Back to login</a>
</form>
<?php elseif ($step === 'verify'): ?>
<form method="POST">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="verify_code">
    <div class="field">
        <label for="code">Verification code</label>
        <input class="code" type="text" id="code" name="code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="000000" required autofocus>
    </div>
    <button class="btn primary" type="submit">Verify code</button>
    <a class="btn ghost" href="?cancel=1">Cancel</a>
</form>
<?php else: ?>
<form method="POST" id="resetForm">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="reset_password">
    <div class="field">
        <label for="new_password">New password</label>
        <div class="password-field has-icon">
            <svg class="lead" aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="new_password" name="new_password" maxlength="128" autocomplete="new-password" required>
            <button type="button" class="show-password-btn" aria-pressed="false" aria-label="Show password">Show</button>
        </div>
        <div class="rules" id="rules" aria-live="polite">
            <div data-rule="length">10+ characters</div>
            <div data-rule="upper">Uppercase letter</div>
            <div data-rule="lower">Lowercase letter</div>
            <div data-rule="number">Number</div>
        </div>
    </div>
    <div class="field">
        <label for="confirm_password">Confirm new password</label>
        <div class="password-field has-icon">
            <svg class="lead" aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="confirm_password" name="confirm_password" maxlength="128" autocomplete="new-password" required>
            <button type="button" class="show-password-btn" aria-pressed="false" aria-label="Show password">Show</button>
        </div>
        <div class="hint" id="matchHint" aria-live="polite"></div>
    </div>
    <button class="btn primary" type="submit">Save new password</button>
    <a class="btn ghost" href="?cancel=1">Cancel</a>
</form>
<?php endif; ?>

<div class="sec-note"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg><span>For your security, codes expire quickly and only work once.</span></div>
</div>
</div>
<?php authUiToggleScript(); ?>
<script>
(function(){
    var p=document.getElementById('new_password'),c=document.getElementById('confirm_password'),m=document.getElementById('matchHint');
    if(!p)return;
    var tests={length:function(v){return v.length>=10},upper:function(v){return /[A-Z]/.test(v)},lower:function(v){return /[a-z]/.test(v)},number:function(v){return /[0-9]/.test(v)}};
    function check(){
        Object.keys(tests).forEach(function(k){
            var el=document.querySelector('#rules [data-rule="'+k+'"]');
            if(el)el.className=tests[k](p.value)?'valid':'';
        });
        if(c&&c.value){
            var ok=c.value===p.value;
            m.textContent=ok?'Passwords match.':'Passwords do not match yet.';
            m.style.color=ok?'#2F6B1F':'#7A3A0E';
        }else if(m){m.textContent='';}
    }
    p.addEventListener('input',check);
    if(c)c.addEventListener('input',check);
})();
</script>
</body>
</html>
