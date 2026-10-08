<?php
/**
 * Unified Password Recovery
 * Automatically detects whether email belongs to a student or teacher.
 */
require_once __DIR__.'/db.php';
require_once __DIR__.'/includes/gmail_smtp.php';
require_once __DIR__.'/includes/security.php';

$step=$_SESSION['unified_reset_step'] ?? 'request';
$message=''; $type=''; $masked='';

function clearUnifiedReset(){
    foreach(['unified_reset_step','unified_reset_id','unified_reset_role','unified_reset_email','unified_reset_name','unified_reset_hash','unified_reset_expire','unified_reset_verified'] as $k) unset($_SESSION[$k]);
}
if(isset($_GET['cancel'])){clearUnifiedReset();header('Location:/LibraryBorrowingSystem/login.php');exit;}

if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
   requireValidCsrf($_POST['csrf_token']??'');
   $action=$_POST['action']??'';
   if($action==='request'){
     $email=strtolower(trim($_POST['email']??''));
     if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid email address.');
     $role=null;$user=null;
     $q=$conn->prepare("SELECT student_id AS id, full_name, email FROM students WHERE LOWER(email)=? AND status='active' AND COALESCE(is_archived,0)=0 LIMIT 1");
     $q->bind_param('s',$email);$q->execute();$user=$q->get_result()->fetch_assoc();$q->close();
     if($user){$role='student';}
     else{
       $q=$conn->prepare("SELECT teacher_id AS id, full_name, email FROM teachers WHERE LOWER(email)=? AND status='active' AND COALESCE(is_archived,0)=0 LIMIT 1");
       $q->bind_param('s',$email);$q->execute();$user=$q->get_result()->fetch_assoc();$q->close();
       if($user)$role='teacher';
     }
     if(!$user) throw new Exception('Email not found in student or teacher records.');
     $code=generateOtpCode();
     if($role==='student') sendStudentPasswordResetEmail($user['email'],$user['full_name'],$code);
     else sendTeacherPasswordResetEmail($user['email'],$user['full_name'],$code);
     $_SESSION['unified_reset_step']='verify';
     $_SESSION['unified_reset_id']=$user['id'];
     $_SESSION['unified_reset_role']=$role;
     $_SESSION['unified_reset_email']=$user['email'];
     $_SESSION['unified_reset_name']=$user['full_name'];
     $_SESSION['unified_reset_hash']=password_hash($code,PASSWORD_DEFAULT);
     $_SESSION['unified_reset_expire']=time()+300;
     $masked=maskEmail($user['email']);
     $message="Verification code sent to ".$masked;
     $type='success';
     $step='verify';
   }
   if($action==='verify'){
     $code=preg_replace('/\D/','',$_POST['code']??'');
     if(time()>($_SESSION['unified_reset_expire']??0)) throw new Exception('Code expired.');
     if(!password_verify($code,$_SESSION['unified_reset_hash']??'')) throw new Exception('Invalid verification code.');
     $_SESSION['unified_reset_verified']=time()+600;
     $_SESSION['unified_reset_step']='reset';$step='reset';
   }
   if($action==='save'){
     if(($_SESSION['unified_reset_verified']??0)<time()) throw new Exception('Reset session expired.');
     $pass=$_POST['password']??'';$confirm=$_POST['confirm']??'';
     $errors = passwordPolicyErrors($pass, false);
     if(!empty($errors)) throw new Exception(strongPasswordMessage($errors));
     if($pass!==$confirm) throw new Exception('Passwords do not match.');
     $hash=password_hash($pass,PASSWORD_DEFAULT);
     if($_SESSION['unified_reset_role']==='student'){
       $q=$conn->prepare("UPDATE students SET password=? WHERE student_id=?");
     }else{
       $q=$conn->prepare("UPDATE teachers SET password=? WHERE teacher_id=?");
     }
     $q->bind_param('si',$hash,$_SESSION['unified_reset_id']);
     if(!$q->execute()) throw new Exception('Unable to update password.');
     clearUnifiedReset();
     header('Location:/LibraryBorrowingSystem/login.php?reset=1');exit;
   }
 }catch(Throwable $e){$message=$e->getMessage();$type='error';$step=$_SESSION['unified_reset_step']??'request';}
}
if(!$masked && isset($_SESSION['unified_reset_email']))$masked=maskEmail($_SESSION['unified_reset_email']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Password Recovery - JASHS Library</title>
<?php require_once __DIR__ . '/includes/auth_ui.php'; authUiHead(); ?>
<?php require_once __DIR__ . '/includes/responsive.php'; ?>
</head>
<body class="auth-page">
<?php authUiBackdrop(); ?>
<div class="auth-wrap">
<?php authUiBrand(); ?>
<div class="auth-card">
<div class="auth-icon"><svg viewBox="0 0 24 24"><circle cx="8" cy="15" r="4"/><path d="M10.8 12.2 20 3m-4 4 3 3m-5-1 2 2"/></svg></div>
<h1 class="auth-title">Reset your password</h1>
<?php if ($step === 'request'): ?>
<p class="auth-sub">Enter the email address registered to your student or teacher account and we will send you a verification code.</p>
<?php elseif ($step === 'verify'): ?>
<p class="auth-sub">Enter the 6-digit code we sent to <strong><?php echo htmlspecialchars($masked); ?></strong>. It expires in 5 minutes.</p>
<?php else: ?>
<p class="auth-sub">Code verified. Choose a new password for your account.</p>
<?php endif; ?>
<?php authUiSteps($step); ?>

<?php if ($message !== ''): ?>
<div class="alert <?php echo $type === 'success' ? 'success' : 'error'; ?>" role="<?php echo $type === 'success' ? 'status' : 'alert'; ?>">
    <?php echo $type === 'success' ? '<svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/></svg>' : '<svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>'; ?>
    <div><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
</div>
<?php endif; ?>

<?php if ($step === 'request'): ?>
<form method="POST">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="request">
    <div class="field">
        <label for="email">Registered email</label>
        <div class="input-wrap has-icon">
            <input type="email" id="email" name="email" required autocomplete="email" placeholder="     name@gmail.com">
            <svg class="lead" aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
        </div>
    </div>
    <button class="btn primary" type="submit">Send verification code</button>
    <a class="btn ghost" href="/LibraryBorrowingSystem/login.php"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M19 12H5m6-6-6 6 6 6"/></svg> Back to login</a>
</form>
<?php elseif ($step === 'verify'): ?>
<form method="POST">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="verify">
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
    <input type="hidden" name="action" value="save">
    <div class="field">
        <label for="new_password">New password</label>
        <div class="password-field has-icon">
            <svg class="lead" aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <input type="password" id="new_password" name="password" maxlength="128" autocomplete="new-password" required>
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
            <input type="password" id="confirm_password" name="confirm" maxlength="128" autocomplete="new-password" required>
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
