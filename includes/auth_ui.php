<?php
/**
 * Shared look for the account pages (register, forgot password).
 * Matches the login page: Literata + Public Sans, navy/yellow palette, photo backdrop.
 * Usage: require_once __DIR__ . '/../includes/auth_ui.php'; authUiHead();  (inside <head>)
 */
function authUiHead(): void { ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--navy:#141F52;--blue:#52618D;--sky:#91B0E0;--light:#D2E2F6;--mist:#E7EEF7;--yellow:#F4F916;--white:#FEFEF9;--text:#202A44;--muted:#4A5780;--field:#C5D3EA;--danger:#7A3A0E;--serif:'Inter','Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,'Roboto',sans-serif;--sans:'Inter','Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,'Roboto',sans-serif}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body.auth-page{margin:0;min-height:100vh;min-height:100dvh;font-family:var(--sans);color:var(--text);background:#0d1533;display:flex;align-items:center;justify-content:center;padding:32px 20px;position:relative}
body.auth-page.tall{align-items:flex-start}
.auth-bg{position:fixed;inset:0;z-index:0}
.auth-bg img{width:100%;height:100%;object-fit:cover;object-position:center 45%;display:block}
.auth-bg::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(13,21,51,.78),rgba(13,21,51,.62) 50%,rgba(13,21,51,.84))}
.auth-wrap{position:relative;z-index:1;width:100%;max-width:460px}
.auth-wrap.wide{max-width:860px}
.auth-brand{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:20px;color:#fff;text-shadow:0 1px 12px rgba(8,14,40,.5)}
.auth-brand img{width:48px;height:48px;border-radius:50%;object-fit:contain;background:var(--white);padding:3px;box-shadow:0 4px 14px rgba(0,0,0,.3)}
.auth-brand div{font-size:12.5px;line-height:1.35;font-weight:500;text-align:left}
.auth-brand strong{display:block;font-size:15px;font-weight:700}
.auth-card{background:rgba(254,254,249,.95);border:1px solid rgba(255,255,255,.5);border-radius:18px;box-shadow:0 28px 70px rgba(8,14,40,.45),0 2px 8px rgba(8,14,40,.14);padding:34px 34px 28px;position:relative}
.wide .auth-card{padding:36px 40px 30px}
.auth-icon{width:52px;height:52px;border-radius:14px;background:var(--navy);display:flex;align-items:center;justify-content:center;margin:0 0 18px;box-shadow:0 8px 18px rgba(20,31,82,.3)}
.auth-icon svg{width:26px;height:26px;stroke:var(--yellow);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.auth-title{font-family:var(--serif);font-size:28px;line-height:1.18;font-weight:700;letter-spacing:-.015em;color:var(--navy);margin:0 0 8px}
.auth-sub{font-size:14px;line-height:1.55;color:var(--muted);margin:0 0 22px}
.auth-sub strong{color:var(--navy)}
.steps{display:flex;align-items:center;gap:8px;margin:0 0 24px;padding:0;list-style:none}
.steps li{display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:600;color:#7482A8;flex:1;min-width:0}
.steps li:last-child{flex:0 0 auto}
.steps .dot{width:24px;height:24px;border-radius:50%;border:2px solid #B5C3E0;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;background:#fff}
.steps li.active{color:var(--navy)}
.steps li.active .dot{background:var(--navy);border-color:var(--navy);color:#fff}
.steps li.done{color:var(--blue)}
.steps li.done .dot{background:var(--blue);border-color:var(--blue);color:#fff}
.steps .dot svg{width:12px;height:12px;stroke:#fff;fill:none;stroke-width:3;stroke-linecap:round;stroke-linejoin:round}
.steps .bar{flex:1;height:2px;background:#C9D5EC;border-radius:2px;min-width:12px}
.steps li.done + li .bar{background:var(--blue)}
.steps .label{white-space:nowrap}
@media(max-width:480px){.steps .label{display:none}.steps li.active .label{display:inline}}
.alert{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:10px;margin-bottom:18px;font-size:13.5px;line-height:1.5;border:1px solid}
.alert svg{width:18px;height:18px;flex-shrink:0;margin-top:1px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.alert.error{background:#FDF0E7;border-color:#EBBE9E;color:var(--danger)}
.alert.success{background:#EEF6E0;border-color:#BCD688;color:#344E15}
.alert ul{margin:6px 0 0 18px;padding:0}
.field{margin-bottom:18px}
.field label,.label{display:block;margin-bottom:7px;font-size:13.5px;font-weight:600;color:var(--text)}
.req{color:#B4501A;margin-left:2px}
.hint{margin-top:6px;font-size:12.5px;line-height:1.5;color:var(--muted)}
.input-wrap{position:relative}
.input-wrap>svg.lead{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:18px;height:18px;stroke:var(--muted);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;pointer-events:none;transition:stroke .15s}
.input-wrap:focus-within>svg.lead{stroke:var(--navy)}
.input-wrap.has-icon input{padding-left:42px}
.auth-card input[type=text],.auth-card input[type=email],.auth-card input[type=tel],.auth-card input[type=password],.auth-card select{width:100%;height:48px;padding:12px 14px;font:inherit;font-size:16px;color:var(--text);background:#fff;border:1.5px solid var(--field);border-radius:10px;transition:border-color .15s,box-shadow .15s;appearance:none;-webkit-appearance:none}
.auth-card select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2352618D' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:40px}
.auth-card input::placeholder{color:#7783A6}
.auth-card input:hover,.auth-card select:hover{border-color:var(--blue)}
.auth-card input:focus,.auth-card select:focus{outline:none;border-color:var(--navy);box-shadow:0 0 0 4px rgba(20,31,82,.16)}
.auth-card input:disabled{background:#EEF2F9;color:#5E6B92;cursor:not-allowed}
.auth-card input.code{height:60px;font-family:var(--sans);font-size:28px;font-weight:700;letter-spacing:12px;text-align:center;padding-left:26px}
.password-field{position:relative}
.password-field input{padding-right:78px !important}
.password-field.has-icon input{padding-left:42px}
.password-field>svg.lead{position:absolute;left:14px;top:24px;transform:translateY(-50%);width:18px;height:18px;stroke:var(--muted);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;pointer-events:none}
.show-password-btn{position:absolute;top:24px;right:8px;transform:translateY(-50%);height:34px;min-width:60px;padding:0 10px;border:1px solid rgba(20,31,82,.2);border-radius:7px;background:#F2F6FC;color:var(--navy);font:inherit;font-size:12px;font-weight:600;cursor:pointer}
.show-password-btn:hover{background:var(--mist)}
.show-password-btn:focus-visible{outline:3px solid var(--sky);outline-offset:1px}
.rules{margin:10px 0 0;padding:10px 12px;border-radius:10px;background:#F2F6FC;display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;font-size:12.5px}
.rules div{display:flex;align-items:center;gap:7px;color:#7A4A2A}
.rules div::before{content:"";width:14px;height:14px;border-radius:50%;border:2px solid currentColor;flex-shrink:0;opacity:.7}
.rules div.valid{color:#2F6B1F}
.rules div.valid::before{background:#2F6B1F url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 12l5 5L20 7'/%3E%3C/svg%3E") center/80% no-repeat;border-color:#2F6B1F;opacity:1}
.btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;height:50px;border:0;border-radius:10px;font:inherit;font-size:15.5px;font-weight:700;text-decoration:none;cursor:pointer;transition:background .15s,box-shadow .15s,transform .12s}
.btn.primary{margin-top:6px;color:#fff;background:linear-gradient(180deg,#23316E,var(--navy));box-shadow:0 8px 20px rgba(20,31,82,.32),inset 0 1px 0 rgba(255,255,255,.16)}
.btn.primary:hover{background:linear-gradient(180deg,#2B3A80,#1B2864);box-shadow:0 12px 26px rgba(20,31,82,.4)}
.btn.primary:active{transform:translateY(1px)}
.btn.primary:disabled{background:var(--blue);box-shadow:none;cursor:progress}
.btn.ghost{margin-top:10px;background:transparent;color:var(--navy);border:1.5px solid rgba(20,31,82,.4)}
.btn.ghost:hover{background:rgba(20,31,82,.06);border-color:var(--navy)}
.btn:focus-visible{outline:3px solid var(--sky);outline-offset:3px}
.btn svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
.auth-foot{margin-top:22px;padding-top:18px;border-top:1px solid #DCE5F3;text-align:center;font-size:13.5px;color:var(--muted)}
.auth-foot a{color:var(--navy);font-weight:700;text-decoration:none}
.auth-foot a:hover{text-decoration:underline}
.sec-note{display:flex;gap:8px;align-items:flex-start;margin-top:16px;font-size:12px;line-height:1.5;color:var(--muted)}
.sec-note svg{width:15px;height:15px;flex-shrink:0;margin-top:1px;stroke:var(--muted);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
@media(max-width:480px){body.auth-page{padding:20px 14px}.auth-card,.wide .auth-card{padding:26px 20px 22px;border-radius:16px}.auth-title{font-size:25px}.auth-card input.code{font-size:24px;letter-spacing:9px}.rules{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){*{transition-duration:.001ms !important}}
</style>
<?php }

function authUiBackdrop(): void { ?>
<div class="auth-bg"><img src="/LibraryBorrowingSystem/Img/library1.png" alt="" decoding="async"></div>
<?php }

function authUiBrand(): void { ?>
<div class="auth-brand">
    <img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School logo">
    <div><strong>Jose Abad Santos High School</strong>Library Borrowing System</div>
</div>
<?php }

/** Three-step indicator for password recovery. $current: request|verify|reset */
function authUiSteps(string $current): void {
    $steps = ['request' => 'Email', 'verify' => 'Verify', 'reset' => 'New password'];
    $keys = array_keys($steps); $idx = array_search($current, $keys, true); if ($idx === false) $idx = 0;
    echo '<ol class="steps" aria-label="Progress">';
    foreach ($keys as $i => $k) {
        $cls = $i < $idx ? 'done' : ($i === $idx ? 'active' : '');
        $dot = $i < $idx ? '<svg viewBox="0 0 24 24"><path d="M5 12l5 5L20 7"/></svg>' : ($i + 1);
        echo '<li class="' . $cls . '"' . ($i === $idx ? ' aria-current="step"' : '') . '><span class="dot">' . $dot . '</span><span class="label">' . htmlspecialchars($steps[$k]) . '</span>' . ($i < count($keys) - 1 ? '<span class="bar"></span>' : '') . '</li>';
    }
    echo '</ol>';
}

function authUiToggleScript(): void { ?>
<script>
document.querySelectorAll('.password-field').forEach(function(w){
    var input=w.querySelector('input'),btn=w.querySelector('.show-password-btn');
    if(!input||!btn)return;
    btn.addEventListener('click',function(){
        var showing=input.type==='text';
        input.type=showing?'password':'text';
        btn.textContent=showing?'Show':'Hide';
        btn.setAttribute('aria-pressed',showing?'false':'true');
        btn.setAttribute('aria-label',showing?'Show password':'Hide password');
        input.focus({preventScroll:true});
    });
});
</script>
<?php }
