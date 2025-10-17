<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/routes.php';
admin_require_login();
admin_require_role('superadmin');

$pdo = get_pdo();
$routeExt = route_extension();
// Ensure admin_users table exists
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'co_worker',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $__){ /* ignore */ }

$msg = '';$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!admin_csrf_check($_POST['csrf'] ?? '')) { $err = 'Invalid session. Please reload.'; }
  $username = trim($_POST['username'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  $role = $_POST['role'] ?? 'co_worker';
  if (!$err) {
    if ($username === '' || !preg_match('/^[A-Za-z0-9_.-]{3,120}$/', $username)) $err = 'Enter a valid username (3-120 chars, letters/digits/._-).';
    elseif (strlen($password) < 6) $err = 'Password must be at least 6 characters.';
    elseif (!in_array($role, ['admin','co_worker','superadmin'], true)) $role = 'co_worker';
  }
  if (!$err) {
    try {
      $exists = (int)$pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE username = ?')->execute([$username]) ?: 0;
      $stc = $pdo->prepare('SELECT COUNT(*) AS c FROM admin_users WHERE username = ?'); $stc->execute([$username]);
      $rowc = $stc->fetch(); $exists = (int)($rowc['c'] ?? 0);
      if ($exists > 0) { $err = 'Username already exists'; }
      else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $st = $pdo->prepare('INSERT INTO admin_users (username, password_hash, role, active) VALUES (?,?,?,1)');
        $st->execute([$username, $hash, $role]);
        $msg = 'User created successfully';
      }
    } catch (Throwable $e) {
      $err = 'DB error: ' . $e->getMessage();
    }
  }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Admin User</title>
    <style>
      :root{ --bg:#0b1020; --panel:rgba(255,255,255,.07); --line:rgba(255,255,255,.12); --text:#e7eefc; --muted:#9fb4d6; --brand:#22c55e; --brand2:#16a34a }
      body{margin:0;min-height:100vh;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto}
      .wrap{display:grid;place-items:center;min-height:100vh;padding:24px}
      .card{width:420px;max-width:92vw;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:20px}
      h1{margin:0 0 6px;font-size:20px}
      label{display:block;margin:10px 0 6px;color:var(--muted);font-size:13px}
      input,select{width:100%;padding:12px;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,.06);color:var(--text)}
      .row{display:flex;justify-content:space-between;gap:10px;margin-top:12px}
      .btn{appearance:none;border:0;border-radius:10px;padding:12px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:800;cursor:pointer}
      .ghost{background:transparent;border:1px solid var(--line)}
      .msg{margin-top:8px;color:#86efac}
      .err{margin-top:8px;color:#fca5a5}
      a{color:#93c5fd;text-decoration:none}
    </style>
  </head>
  <body>
    <div class="wrap">
      <form class="card" method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
        <h1>Create Admin User</h1>
        <p style="margin:0 0 10px;color:var(--muted)">Only superadmin can access this page.</p>
        <label>Username</label>
        <input name="username" required placeholder="e.g. manager01" value="<?= isset($_POST['username'])?htmlspecialchars($_POST['username']):'' ?>">
        <label>Password</label>
        <input name="password" type="password" required placeholder="Choose a strong password">
        <label>Role</label>
        <select name="role">
          <option value="co_worker" <?= (($_POST['role'] ?? '')==='co_worker')?'selected':'' ?>>Co-worker</option>
          <option value="admin" <?= (($_POST['role'] ?? '')==='admin')?'selected':'' ?>>Admin</option>
          <option value="superadmin" <?= (($_POST['role'] ?? '')==='superadmin')?'selected':'' ?>>Superadmin</option>
        </select>
        <div class="row">
          <a class="ghost btn" href="/inventory/dashboard<?= $routeExt ?>">Back</a>
          <button class="btn" type="submit">Create User</button>
        </div>
        <?php if ($msg): ?><div class="msg">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="err">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>
      </form>
    </div>
  </body>
 </html>
