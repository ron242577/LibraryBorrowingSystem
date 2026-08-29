<?php
/**
 * Student Password Recovery - Jose Abad Santos High School
 * Email verification is used only to reset an existing student's password.
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
        $_SESSION['password_reset_student_id'],
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
                throw new RuntimeException('Enter a valid registered student email.');
            }

            $lock = isLoginLocked($conn, 'student_password_reset', $email);
            if ($lock['locked']) {
                $minutes = max(1, (int)ceil($lock['seconds'] / 60));
                throw new RuntimeException("Too many requests. Try again in about {$minutes} minute(s).");
            }

            $stmt = $conn->prepare("SELECT student_id, full_name, email, status FROM students WHERE LOWER(email) = ? AND status = 'active' AND COALESCE(is_archived,0) = 0 LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Do not reveal whether the email exists to unauthenticated users.
            if (!$student) {
                recordFailedLogin($conn, 'student_password_reset', $email);
                throw new RuntimeException('If that email is registered, a verification code will be sent.');
            }

            $lastSent = (int)($_SESSION['password_reset_last_sent'] ?? 0);
            $wait = 60 - (time() - $lastSent);
            if ($wait > 0 && ($_SESSION['password_reset_email'] ?? '') === $email) {
                throw new RuntimeException("Please wait {$wait} second(s) before requesting another code.");
            }

            $code = generateOtpCode();
            sendStudentPasswordResetEmail($student['email'], $student['full_name'], $code);

            $_SESSION['password_reset_student_id'] = (int)$student['student_id'];
            $_SESSION['password_reset_email'] = $student['email'];
            $_SESSION['password_reset_name'] = $student['full_name'];
            $_SESSION['password_reset_code_hash'] = password_hash($code, PASSWORD_DEFAULT);
            $_SESSION['password_reset_code_expires'] = time() + 300;
            $_SESSION['password_reset_attempts'] = 0;
            $_SESSION['password_reset_last_sent'] = time();
            $_SESSION['password_reset_step'] = 'verify';

            clearFailedLogins($conn, 'student_password_reset', $email);
            $message = 'A 6-digit verification code has been sent to ' . maskEmail($student['email']) . '.';
            $message_type = 'success';
            $masked_email = maskEmail($student['email']);
            $step = 'verify';
        }

        if ($action === 'verify_code') {
            $studentId = (int)($_SESSION['password_reset_student_id'] ?? 0);
            $email = (string)($_SESSION['password_reset_email'] ?? '');
            $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));

            if ($studentId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($_SESSION['password_reset_code_hash'])) {
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
            $studentId = (int)($_SESSION['password_reset_student_id'] ?? 0);
            $verifiedUntil = (int)($_SESSION['password_reset_verified_until'] ?? 0);
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($studentId <= 0 || $verifiedUntil < time()) {
                throw new RuntimeException('Your password reset authorization expired. Start again.');
            }

            $policyErrors = passwordPolicyErrors($newPassword);
            if ($newPassword === '' || !empty($policyErrors)) {
                throw new RuntimeException($newPassword === '' ? 'Password is required.' : strongPasswordMessage($policyErrors));
            }
            if (!hash_equals($newPassword, $confirmPassword)) {
                throw new RuntimeException('Password confirmation does not match.');
            }

            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE students SET password = ? WHERE student_id = ? AND status = 'active' AND COALESCE(is_archived,0) = 0");
            $stmt->bind_param('si', $hash, $studentId);
            if (!$stmt->execute() || $stmt->affected_rows < 1) {
                $stmt->close();
                throw new RuntimeException('Unable to update your password. Please try again.');
            }
            $stmt->close();

            $email = (string)($_SESSION['password_reset_email'] ?? '');
            clearFailedLogins($conn, 'student_password_reset', $email);
            session_regenerate_id(true);

            unset(
                $_SESSION['password_reset_student_id'],
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
<title>Student Password Recovery - JASHS Library</title>
<style>
:root{--navy:#141F52;--blue:#52618D;--sky:#91B0E0;--light:#D2E2F6;--yellow:#F4F916;--white:#FEFEF9;--text:#202A44}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:var(--light);font-family:'Segoe UI',Tahoma,sans-serif;color:var(--text)}
.card{width:min(520px,100%);background:var(--white);border:1px solid var(--sky);border-top:6px solid var(--navy);border-radius:12px;padding:32px;box-shadow:0 14px 40px rgba(20,31,82,.18)}
.logo{display:flex;justify-content:center;margin-bottom:18px}.logo img{width:78px;height:78px;object-fit:cover;border-radius:50%;border:3px solid var(--yellow)}
h1{margin:0 0 8px;text-align:center;color:var(--navy);font-size:24px}p.desc{text-align:center;color:var(--blue);font-size:13px;line-height:1.5;margin:0 0 22px}
.group{margin-bottom:16px}.group label{display:block;font-size:13px;font-weight:700;margin-bottom:7px}.group input{width:100%;padding:12px;border:1px solid var(--light);border-radius:7px;font-size:14px}.group input:focus{outline:none;border-color:var(--navy);box-shadow:0 0 0 3px rgba(244,249,22,.35)}
.btn{width:100%;padding:12px;border:0;border-radius:7px;background:var(--navy);color:#fff;font-weight:800;cursor:pointer}.btn.secondary{background:#E7EEF7;color:var(--text);margin-top:10px}
.notice{padding:12px;border-radius:7px;margin-bottom:16px;font-size:13px;line-height:1.5}.success{background:#EDF5DD;color:#344E15;border:1px solid #B5D27A}.error{background:#FBE8DC;color:#7A3A0E;border:1px solid #E8B08A}
.code{font-size:22px;letter-spacing:7px;text-align:center}.note{padding:12px;background:#F7F9FC;border-radius:7px;color:var(--blue);font-size:12px;line-height:1.5;margin-bottom:16px}
.password-field{position:relative}.password-field input{padding-right:76px}.show-password-btn{position:absolute;right:7px;top:50%;transform:translateY(-50%);padding:6px 9px;width:auto;border:1px solid var(--light);border-radius:6px;background:#F7F9FC;color:var(--blue);font-size:11px;font-weight:800;cursor:pointer}
@media(max-width:480px){.card{padding:22px 18px}.code{letter-spacing:5px;font-size:20px}}
</style>
</head>
<body>
<div class="card">
<div class="logo"><img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School"></div>
<h1>Student Password Recovery</h1>
<p class="desc">Reset your Student Portal password using the registered email address.</p>

<?php if ($message !== ''): ?>
<div class="notice <?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php endif; ?>

<?php if ($step === 'request'): ?>
<form method="POST">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="request_code">
    <div class="group">
        <label for="email">Registered Student Email</label>
        <input type="email" id="email" name="email" required autocomplete="email" placeholder="student@gmail.com">
    </div>
    <button class="btn" type="submit">Send Verification Code</button>
    <a class="btn secondary" href="/LibraryBorrowingSystem/login.php" style="display:block;text-align:center;text-decoration:none;">Back to Login</a>
</form>
<?php elseif ($step === 'verify'): ?>
<div class="note">A 6-digit code was sent to <strong><?php echo htmlspecialchars($masked_email); ?></strong>. It expires in 5 minutes.</div>
<form method="POST">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="verify_code">
    <div class="group">
        <label for="code">Verification Code</label>
        <input class="code" type="text" id="code" name="code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="000000" required>
    </div>
    <button class="btn" type="submit">Verify Code</button>
    <a class="btn secondary" href="?cancel=1" style="display:block;text-align:center;text-decoration:none;">Cancel</a>
</form>
<?php else: ?>
<div class="note">Code verified. Create a new strong password. You will be returned to the login page after saving it.</div>
<form method="POST">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="reset_password">
    <div class="group">
        <label for="new_password">New Password</label>
        <div class="password-field">
            <input type="password" id="new_password" name="new_password" maxlength="128" autocomplete="new-password" required>
            <button type="button" class="show-password-btn" data-password-toggle="new_password">Show</button>
        </div>
    </div>
    <div class="group">
        <label for="confirm_password">Confirm New Password</label>
        <div class="password-field">
            <input type="password" id="confirm_password" name="confirm_password" maxlength="128" autocomplete="new-password" required>
            <button type="button" class="show-password-btn" data-password-toggle="confirm_password">Show</button>
        </div>
    </div>
    <div class="note">Password requirements: at least 10 characters, uppercase, lowercase, number, and special character.</div>
    <button class="btn" type="submit">Save New Password</button>
    <a class="btn secondary" href="?cancel=1" style="display:block;text-align:center;text-decoration:none;">Cancel</a>
</form>
<?php endif; ?>
</div>
<script>
document.querySelectorAll('.show-password-btn').forEach(function(button){
    button.addEventListener('click',function(){
        const input=document.getElementById(button.dataset.passwordToggle);
        if(!input)return;
        const showing=input.type==='text';
        input.type=showing?'password':'text';
        button.textContent=showing?'Show':'Hide';
    });
});
</script>
</body>
</html>
