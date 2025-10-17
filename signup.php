<?php
session_start();
if (isset($_SESSION['user_id'])) { header('Location: homepage.php'); exit; }
require __DIR__ . '/db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $username = trim((string)($_POST['username'] ?? ''));

    if ($email === '' || $password === '' || $username === '') {
        $error = 'Please complete all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        // Check existing user
        $stmt = $mysqli->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $existing = db_fetch_one($stmt);
        if ($existing) {
            $error = 'Email is already registered.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $now = date('Y-m-d H:i:s');
            $stmt = $mysqli->prepare('INSERT INTO users (name, email, password_hash, created_at) VALUES (?,?,?,?)');
            $stmt->bind_param('ssss', $username, $email, $hash, $now);
            $stmt->execute();
            // After successful sign-up, redirect to login page instead of auto-login
            header('Location: login.php?registered=1');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Sign up • CloudBox</title>
  <style>
    :root{--bg:#071427;--panel:#0e2940;--muted:#9fb3c8;--text:#e6f3fb;--accent:#4fc3f7}
    html,body{height:100%;margin:0;font-family:Inter,ui-sans-serif,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,var(--bg),#041225);color:var(--text)}
    .wrap{max-width:420px;margin:40px auto;padding:22px;background:var(--panel);border:1px solid rgba(255,255,255,0.06);border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.25);transition:transform .25s}
    .wrap:hover{transform:translateY(-2px)}
    input{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:var(--text);outline:none;transition:border-color .2s, box-shadow .2s}
    input:focus{border-color:rgba(79,195,247,0.6);box-shadow:0 0 0 3px rgba(79,195,247,0.15)}
    .field{display:flex;flex-direction:column;gap:8px;margin-bottom:12px}
    .btn{width:100%;background:var(--accent);border:none;padding:12px;border-radius:10px;color:#04202a;font-weight:700;cursor:pointer;transition:transform .15s ease, box-shadow .2s}
    .btn:hover{transform:translateY(-1px);box-shadow:0 10px 24px rgba(79,195,247,0.25)}
    a{color:var(--accent);text-decoration:none;transition:opacity .2s}
    a:hover{opacity:.85}
    .muted{color:var(--muted)}
    .err{color:#ffb4b4;margin-bottom:10px}
    h2{text-align:center}
    footer{text-align:center;margin-top:14px;color:var(--muted);font-size:13px}
    .tips{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:10px;padding:10px;display:none}
    .tip{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px;margin:4px 0}
    .ok{color:#7ee787}
    .icon{width:18px;display:inline-block;text-align:center}
  </style>
  <script>
    // client-side minimal validation
    function validate(e){
      const email=document.getElementById('email').value.trim();
      const pwd=document.getElementById('password').value;
      const username=document.getElementById('username').value.trim();
      if(!email||!pwd||!username){alert('Please complete all fields.');e.preventDefault();return;}
      if(pwd.length<8){alert('Password must be at least 8 characters.');e.preventDefault();}
    }

    function updateStrength(){
      const v=document.getElementById('password').value;
      const tips=document.querySelector('.tips');
      if(v.length>0){ tips.style.display='block'; } else { tips.style.display='none'; }
      const hasLower=/[a-z]/.test(v);
      const hasUpper=/[A-Z]/.test(v);
      const hasNum=/[0-9]/.test(v);
      const hasSpec=/[^A-Za-z0-9]/.test(v);
      const hasLen=v.length>=8;
      setTip('tip-lower',hasLower);
      setTip('tip-upper',hasUpper);
      setTip('tip-num',hasNum);
      setTip('tip-spec',hasSpec);
      setTip('tip-len',hasLen);
    }
    function setTip(id,ok){
      const row=document.getElementById(id);
      const icon=row.querySelector('.icon');
      row.className='tip'+(ok?' ok':'');
      icon.textContent= ok ? '✓' : '•';
    }
    function togglePwd(){
      const i=document.getElementById('password');
      i.type = i.type==='password' ? 'text' : 'password';
      i.focus();
    }
  </script>
  </head>
<body>
  <div class="wrap">
    <h2 style="margin-top:0">Create your account</h2>
    <?php if ($error): ?><div class="err"><?php echo e($error); ?></div><?php endif; ?>
    <form method="post" onsubmit="validate(event)">
      <div class="field"><label for="username" class="muted">Username</label><input id="username" name="username" placeholder="juan"></div>
      <div class="field"><label for="email" class="muted">Email</label><input id="email" type="email" name="email" placeholder="you@example.com"></div>
      <div class="field"><label for="password" class="muted">Password</label>
        <div style="position:relative">
          <input id="password" type="password" name="password" placeholder="At least 8 characters" oninput="updateStrength()">
          <button type="button" onclick="togglePwd()" style="position:absolute;right:8px;top:8px;background:transparent;border:none;color:var(--muted);cursor:pointer">Show</button>
        </div>
      </div>
      <div class="tips">
        <div id="tip-len" class="tip"><span class="icon">•</span>At least 8 characters</div>
        <div id="tip-lower" class="tip"><span class="icon">•</span>Lowercase letter</div>
        <div id="tip-upper" class="tip"><span class="icon">•</span>Uppercase letter</div>
        <div id="tip-num" class="tip"><span class="icon">•</span>Number</div>
        <div id="tip-spec" class="tip"><span class="icon">•</span>Special character</div>
      </div>
      <button class="btn" type="submit">Sign up</button>
    </form>
    <div class="muted" style="margin-top:10px;text-align:center">Already have an account? <a href="login.php">Log in</a></div>
  </div>
  <footer>FronStorage • Create your account</footer>
</body>
</html>


