<?php
session_start();
require __DIR__ . '/db.php';

$info = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email === '') {
        $error = 'Please enter your email.';
    } else {
        // Look up user
        $stmt = $mysqli->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = db_fetch_one($stmt);

        // Always show success to avoid email enumeration
        $info = 'If the email exists, we sent a reset link.';

        if ($row) {
            $userId = (int)$row['id'];
            // Generate token
            $token = bin2hex(random_bytes(32)); // 64 hex chars
            $expiresAt = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');
            $createdAt = (new DateTime())->format('Y-m-d H:i:s');

            // Insert token
            $stmt2 = $mysqli->prepare('INSERT INTO password_resets (user_id, token, expires_at, created_at) VALUES (?,?,?,?)');
            $stmt2->bind_param('isss', $userId, $token, $expiresAt, $createdAt);
            $stmt2->execute();

            // Build reset link (using current host)
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
            $link = $scheme . '://' . $host . $base . '/reset_password.php?token=' . urlencode($token);

            // In this demo, we show the link instead of sending email
            $_SESSION['last_reset_link'] = $link;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Forgot Password • CloudBox</title>
  <style>
    :root{--bg:#071427;--panel:#0e2940;--muted:#9fb3c8;--text:#e6f3fb;--accent:#4fc3f7}
    html,body{height:100%;margin:0;font-family:Inter,ui-sans-serif,Segoe UI,Roboto,Arial;background:linear-gradient(180deg,var(--bg),#041225);color:var(--text)}
    .wrap{max-width:420px;margin:40px auto;padding:22px;background:var(--panel);border:1px solid rgba(255,255,255,0.06);border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.25)}
    input{width:100%;padding:12px;border-radius:10px;border:1px solid rgba(255,255,255,0.06);background:transparent;color:var(--text);outline:none}
    .field{display:flex;flex-direction:column;gap:8px;margin-bottom:12px}
    .btn{width:100%;background:var(--accent);border:none;padding:12px;border-radius:10px;color:#04202a;font-weight:700;cursor:pointer}
    a{color:var(--accent);text-decoration:none}
    .muted{color:var(--muted)}
    .err{color:#ffb4b4;margin-bottom:10px}
    .ok{color:#7ee787;margin-bottom:10px}
    .hint{background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:10px;padding:10px;margin-top:10px;font-size:13px}
  </style>
</head>
<body>
  <div class="wrap">
    <h2 style="margin-top:0">Forgot password</h2>
    <?php if ($error): ?><div class="err"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($info): ?><div class="ok"><?php echo e($info); ?></div><?php endif; ?>
    <form method="post">
      <div class="field"><label for="email" class="muted">Your email</label><input id="email" type="email" name="email" placeholder="you@example.com"></div>
      <button class="btn" type="submit">Send reset link</button>
    </form>
    <div class="muted" style="margin-top:10px;text-align:center"><a href="login.php">Back to login</a></div>
    <?php if (!empty($_SESSION['last_reset_link'])): ?>
      <div class="hint">Dev hint: reset link <a href="<?php echo e($_SESSION['last_reset_link']); ?>">open</a><br><code><?php echo e($_SESSION['last_reset_link']); ?></code></div>
    <?php endif; ?>
  </div>
</body>
</html>


