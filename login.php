<?php
session_start();
if (isset($_SESSION['user_id'])) { header('Location: homepage.php'); exit; }
require __DIR__ . '/db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($email === '' || $password === '') {
        $error = 'Enter email and password.';
    } else {
        $stmt = $mysqli->prepare('SELECT id, password_hash FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = db_fetch_one($stmt);
        if (!$row || !password_verify($password, $row['password_hash'])) {
            $error = 'Invalid credentials.';
        } else {
            $_SESSION['user_id'] = (int)$row['id'];
            header('Location: homepage.php');
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
  <title>Log in • CloudBox</title>
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
    .tips{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:10px;padding:10px}
    .tip{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:13px;margin:4px 0}
    .ok{color:#7ee787}
    .icon{width:18px;display:inline-block;text-align:center}
  </style>
  <script>
    function updateStrength(){
      const v=document.getElementById('password').value;
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
      if(!row) return;
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
    <h2 style="margin-top:0">Welcome back</h2>
    <?php if (isset($_GET['registered']) && $_GET['registered']=='1'): ?>
      <div class="ok">Account created successfully. Please log in.</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="err"><?php echo e($error); ?></div><?php endif; ?>
    <form method="post">
      <div class="field"><label for="email" class="muted">Email</label><input id="email" type="email" name="email" placeholder="you@example.com"></div>
      <div class="field"><label for="password" class="muted">Password</label>
        <div style="position:relative">
          <input id="password" type="password" name="password" placeholder="Your password" oninput="updateStrength()">
          <button type="button" onclick="togglePwd()" style="position:absolute;right:8px;top:8px;background:transparent;border:none;color:var(--muted);cursor:pointer">Show</button>
        </div>
      </div>
      <button class="btn" type="submit">Log in</button>
    </form>
    <div class="muted" style="margin-top:10px;text-align:center">
      <a href="forgot_password.php">Forgot password?</a>
    </div>
    <div class="muted" style="margin-top:10px;text-align:center">No account yet? <a href="signup.php">Sign up</a></div>
  </div>
  <footer>FronStorage • Log in</footer>
</body>
</html>


