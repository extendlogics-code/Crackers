<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/routes.php';
admin_require_login();

$pdo = get_pdo();
$user = admin_current_user();
$msg = '';$err = '';
$routeExt = route_extension();

// Ensure admin_users table exists (best-effort)
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'co_worker',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $__) {}

$isConfigUser = ((int)($user['id'] ?? 0)) === 0; // logged in via config.php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!admin_csrf_check($_POST['csrf'] ?? '')) { $err = 'Invalid session. Please reload and try again.'; }
  $current = (string)($_POST['current'] ?? '');
  $new = (string)($_POST['new'] ?? '');
  $confirm = (string)($_POST['confirm'] ?? '');

  if (!$err) {
    if ($isConfigUser) {
      $err = 'Password for the config-based superadmin cannot be changed here. Update config.php instead.';
    } else {
      if (strlen($new) < 6) $err = 'New password must be at least 6 characters.';
      if (!$err && $new !== $confirm) $err = 'New password and confirmation do not match.';
      if (!$err) {
        // Fetch current hash
        $st = $pdo->prepare('SELECT password_hash, active FROM admin_users WHERE id = ?');
        $st->execute([(int)$user['id']]);
        $row = $st->fetch();
        if (!$row || (int)$row['active'] !== 1) {
          $err = 'Account not found or inactive.';
        } elseif (!password_verify($current, (string)$row['password_hash'])) {
          $err = 'Current password is incorrect.';
        } else {
          $hash = password_hash($new, PASSWORD_DEFAULT);
          $up = $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
          $up->execute([$hash, (int)$user['id']]);
          $msg = 'Password updated successfully.';
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password</title>
    <style>
      :root{ --bg:#0b1020; --panel:rgba(255,255,255,.07); --line:rgba(255,255,255,.12); --text:#e7eefc; --muted:#9fb4d6; --brand:#2563eb; --brand2:#7c3aed }
      body{margin:0;min-height:100vh;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto}
      .wrap{display:grid;place-items:center;min-height:100vh;padding:24px}
      .card{width:420px;max-width:92vw;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:20px}
      h1{margin:0 0 8px;font-size:20px}
      p.sub{margin:0 0 12px;color:var(--muted);font-size:13px}
      label{display:block;margin:10px 0 6px;color:var(--muted);font-size:13px}
      input{width:100%;padding:12px;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,.06);color:var(--text)}
      .row{display:flex;justify-content:space-between;gap:10px;margin-top:12px}
      .btn{appearance:none;border:0;border-radius:10px;padding:12px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:800;cursor:pointer}
      .ghost{background:transparent;border:1px solid var(--line);color:#e7eefc}
      .ok{margin-top:8px;color:#86efac}
      .err{margin-top:8px;color:#fca5a5}
      a{color:#93c5fd;text-decoration:none}
    </style>
  </head>
  <body>
    <div class="wrap">
      <form class="card" method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
        <h1>Change Password</h1>
        <p class="sub">Signed in as <strong><?= htmlspecialchars($user['username'] ?? '') ?></strong></p>
        <?php if ($isConfigUser): ?>
          <div class="err">Password for the default superadmin user comes from <code>config.php</code>. Please change it there.</div>
        <?php endif; ?>
        <label>Current password</label>
        <input name="current" type="password" required <?= $isConfigUser?'disabled':'' ?>>
        <label>New password</label>
        <input name="new" type="password" required <?= $isConfigUser?'disabled':'' ?> minlength="6">
        <label>Confirm new password</label>
        <input name="confirm" type="password" required <?= $isConfigUser?'disabled':'' ?> minlength="6">
        <div class="row">
          <a class="ghost btn" href="/inventory/dashboard<?= $routeExt ?>">Back</a>
          <button class="btn" type="submit" <?= $isConfigUser?'disabled':'' ?>>Update</button>
        </div>
        <?php if ($msg): ?><div class="ok">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="err">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>
      </form>
    </div>
  </body>
 </html>
