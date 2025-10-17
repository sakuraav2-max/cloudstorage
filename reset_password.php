<?php
session_start();
require __DIR__ . '/db.php';

$token = (string)($_GET['token'] ?? '');
$error = '';
$ok = '';
$valid = false;
$userId = null;

if ($token !== '') {
    $stmt = $mysqli->prepare('SELECT pr.user_id, pr.expires_at, pr.used_at FROM password_resets pr WHERE pr.token = ? LIMIT 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = db_fetch_one($stmt);
    if ($row) {
        $expires = strtotime($row['expires_at'] ?? '');
        $usedAt = $row['used_at'];
        if ($usedAt === null && $expires && $expires > time()) {
            $valid = true;
            $userId = (int)$row['user_id'];
        } else {
            $error = 'Reset link is expired or already used.';
        }
    } else {
        $error = 'Invalid reset token.';
    }
} else {
    $error = 'Missing token.';
}

if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pwd = (string)($_POST['password'] ?? '');
    $pwd2 = (string)($_POST['password2'] ?? '');
    if ($pwd === '' || $pwd2 === '') {
        $error = 'Enter and confirm your new password.';
    } elseif ($pwd !== $pwd2) {
        $error = 'Passwords do not match.';
    } elseif (strlen($pwd) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $hash = password_hash($pwd, PASSWORD_DEFAULT);

        $mysqli->begin_transaction();
        try {
            // Update password
            $stmt1 = $mysqli->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt1->bind_param('si', $hash, $userId);
            $stmt1->execute();

            // Mark token used
            $usedAtNow = (new DateTime())->format('Y-m-d H:i:s');
            $stmt2 = $mysqli->prepare('UPDATE password_resets SET used_at = ? WHERE token = ?');
            $stmt2->bind_param('ss', $usedAtNow, $token);
            $stmt2->execute();

            $mysqli->commit();
            $ok = 'Password updated. You can now log in.';
            $valid = false; // hide form
        } catch (Throwable $e) {
            $mysqli->rollback();
            $error = 'Failed to update password.';
            error_log('[reset_password] ' . $e->getMessage());
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Reset Password • CloudBox</title>
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
  </style>
</head>
<body>
  <div class="wrap">
    <h2 style="margin-top:0">Reset password</h2>
    <?php if ($error): ?><div class="err"><?php echo e($error); ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="ok"><?php echo e($ok); ?> <a href="login.php">Log in</a></div><?php endif; ?>
    <?php if ($valid): ?>
    <form method="post">
      <div class="field"><label for="password" class="muted">New password</label><input id="password" type="password" name="password" placeholder="At least 8 characters"></div>
      <div class="field"><label for="password2" class="muted">Confirm password</label><input id="password2" type="password" name="password2" placeholder="Repeat password"></div>
      <button class="btn" type="submit">Update password</button>
    </form>
    <?php else: ?>
      <div class="muted"><a href="forgot_password.php">Request a new link</a></div>
    <?php endif; ?>
  </div>
</body>
</html>


