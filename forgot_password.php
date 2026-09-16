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
     $errors = passwordPolicyErrors($pass);
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
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Password Recovery - JASHS Library</title>
<style>
:root{--navy:#141F52;--blue:#52618D;--sky:#91B0E0;--light:#D2E2F6;--yellow:#F4F916;--white:#FEFEF9;--text:#202A44}
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:var(--light);font-family:'Segoe UI',Tahoma,sans-serif;color:var(--text)}
.card{width:min(520px,100%);background:var(--white);border:1px solid var(--sky);border-top:6px solid var(--navy);border-radius:12px;padding:32px;box-shadow:0 14px 40px rgba(20,31,82,.18)}
.logo{display:flex;justify-content:center;margin-bottom:18px}.logo img{width:78px;height:78px;object-fit:cover;border-radius:50%;border:3px solid var(--yellow)}
h1{text-align:center;color:var(--navy);font-size:24px;margin:0 0 8px}.desc{text-align:center;color:var(--blue);font-size:13px;margin-bottom:22px}
.group{margin-bottom:16px}.group label{display:block;font-size:13px;font-weight:700;margin-bottom:7px}.group input{width:100%;padding:12px;border:1px solid var(--light);border-radius:7px}
.btn{width:100%;padding:12px;border:0;border-radius:7px;background:var(--navy);color:#fff;font-weight:800;cursor:pointer;text-decoration:none;display:block;text-align:center;margin-top:10px}
.secondary{background:#E7EEF7;color:var(--text)}.notice{padding:12px;border-radius:7px;margin-bottom:16px;font-size:13px}.success{background:#EDF5DD;color:#344E15}.error{background:#FBE8DC;color:#7A3A0E}
.code{font-size:22px;letter-spacing:7px;text-align:center}.note{padding:12px;background:#F7F9FC;border-radius:7px;color:var(--blue);font-size:12px;margin-bottom:16px}
.password-field{position:relative}.show-password-btn{position:absolute;right:7px;top:50%;transform:translateY(-50%);padding:6px 9px;width:auto;border:1px solid var(--light);border-radius:6px;background:#F7F9FC;cursor:pointer}
</style>
</head>
<body>
<div class="card">
<div class="logo"><img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg"></div>
<h1>Password Recovery</h1>
<p class="desc">Reset your password using your registered student or teacher email.</p>
<?php if($message): ?><div class="notice <?=$type==='success'?'success':'error'?>"><?=htmlspecialchars($message)?></div><?php endif; ?>

<?php if($step==='request'): ?>
<form method="post"><?=csrfField()?><input type="hidden" name="action" value="request">
<div class="group"><label>Registered Email</label><input type="email" name="email" placeholder="student@gmail.com or teacher@gmail.com" required></div>
<button class="btn">Send Verification Code</button>
<a class="btn secondary" href="/LibraryBorrowingSystem/login.php">Back to Login</a></form>

<?php elseif($step==='verify'): ?>
<div class="note">A verification code was sent to <strong><?=htmlspecialchars($masked)?></strong>.</div>
<form method="post"><?=csrfField()?><input type="hidden" name="action" value="verify">
<div class="group"><label>Verification Code</label><input class="code" name="code" maxlength="6" required></div>
<button class="btn">Verify Code</button><a class="btn secondary" href="?cancel=1">Cancel</a></form>

<?php else: ?>
<div class="note">Password must follow the same password rules used by the system: minimum 10 characters, uppercase, lowercase, number, and special character.</div>
<form method="post"><?=csrfField()?><input type="hidden" name="action" value="save">
<div class="group"><label>New Password</label><div class="password-field"><input id="p1" type="password" name="password" required><button type="button" class="show-password-btn" onclick="toggle('p1',this)">Show</button></div></div>
<div class="group"><label>Confirm Password</label><div class="password-field"><input id="p2" type="password" name="confirm" required><button type="button" class="show-password-btn" onclick="toggle('p2',this)">Show</button></div></div>
<button class="btn">Save New Password</button><a class="btn secondary" href="?cancel=1">Cancel</a></form>
<?php endif; ?>
</div>
<script>function toggle(id,b){let e=document.getElementById(id);e.type=e.type==='password'?'text':'password';b.textContent=e.type==='password'?'Show':'Hide';}</script>
</body></html>
