<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/routes.php';
admin_require_login();
admin_require_role('superadmin');

$pdo = get_pdo();
$routeExt = route_extension();

$msg = '';$err='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!admin_csrf_check($_POST['csrf'] ?? '')) { $err = 'Invalid session. Please retry.'; }
  $action = $_POST['action'] ?? '';
  $uid = (int)($_POST['id'] ?? 0);
  $me = admin_current_user();
  if (!$err && $uid > 0) {
    try {
      if ($action === 'toggle') {
        if ($uid === ($me['id'] ?? -1)) { throw new Exception('Cannot change your own active state'); }
        $st = $pdo->prepare('UPDATE admin_users SET active = 1 - active WHERE id = ?');
        $st->execute([$uid]);
        $msg = 'Updated active state.';
      } elseif ($action === 'set_role') {
        $role = $_POST['role'] ?? 'co_worker';
        if (!in_array($role, ['co_worker','admin','superadmin'], true)) { throw new Exception('Invalid role'); }
        if ($uid === ($me['id'] ?? -1) && $role !== 'superadmin') { throw new Exception('Cannot demote your own role'); }
        $st = $pdo->prepare('UPDATE admin_users SET role = ? WHERE id = ?');
        $st->execute([$role, $uid]);
        $msg = 'Updated role.';
      } elseif ($action === 'reset_pass') {
        $new = (string)($_POST['new_pass'] ?? '');
        if (strlen($new) < 6) { throw new Exception('Password must be at least 6 characters'); }
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $st = $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
        $st->execute([$hash, $uid]);
        $msg = 'Password reset.';
      }
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}

// Load users
$rows = [];
try {
  $rows = $pdo->query('SELECT id, username, role, active, created_at FROM admin_users ORDER BY id')->fetchAll();
} catch (Throwable $__) {}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Users</title>
    <style>
      :root{ --bg:#0b1020; --text:#e7eefc; --muted:#9fb4d6; --brand:#22c55e; --brand2:#16a34a }
      body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
      header{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.1)}
      a{color:#93c5fd;text-decoration:none}
      .wrap{max-width:1000px;margin:0 auto;padding:12px 16px}
      table{width:100%;border-collapse:collapse;margin-top:10px}
      th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.12);text-align:left}
      th{background:rgba(255,255,255,.06)}
      .btn{appearance:none;border:0;border-radius:8px;padding:6px 10px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;cursor:pointer}
      .ghost{background:transparent;border:1px solid rgba(255,255,255,.2);color:#e7eefc}
      input,select{padding:6px 8px;border-radius:8px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.06);color:var(--text)}
      .pill{padding:2px 8px;border:1px solid rgba(255,255,255,.25);border-radius:999px}
      .ok{color:#86efac}
      .err{color:#fca5a5}
    </style>
  </head>
  <body>
    <header>
      <strong>Manage Users</strong>
      <div>
        <a class="ghost btn" href="/inventory/dashboard<?= $routeExt ?>">Back to Dashboard</a>
        <a class="ghost btn" href="/inventory/register<?= $routeExt ?>">Create User</a>
      </div>
    </header>
    <div class="wrap">
      <?php if ($msg): ?><div class="ok">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="err">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>
      <table>
        <thead>
          <tr>
            <th style="width:80px">ID</th>
            <th>Username</th>
            <th style="width:140px">Role</th>
            <th style="width:100px">Active</th>
            <th style="width:200px">Created</th>
            <th style="width:320px">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): $id=(int)$r['id']; ?>
          <tr>
            <td>#<?= $id ?></td>
            <td><?= htmlspecialchars($r['username']) ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="set_role">
                <input type="hidden" name="id" value="<?= $id ?>">
                <select name="role" onchange="this.form.submit()">
                  <?php foreach (['co_worker'=>'Co-worker','admin'=>'Admin','superadmin'=>'Superadmin'] as $val=>$lab): ?>
                  <option value="<?= $val ?>" <?= $r['role']===$val?'selected':'' ?>><?= $lab ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td>
              <form method="post" style="display:inline" onsubmit="return confirm('Toggle active state for <?= htmlspecialchars($r['username']) ?>?')">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $id ?>">
                <button class="ghost btn" type="submit"><?= ((int)$r['active']===1?'Active':'Inactive') ?></button>
              </form>
            </td>
            <td><?= htmlspecialchars($r['created_at']) ?></td>
            <td>
              <form method="post" style="display:inline" onsubmit="return confirm('Reset password for <?= htmlspecialchars($r['username']) ?>?')">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="reset_pass">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input name="new_pass" placeholder="New password" required>
                <button class="btn" type="submit">Reset</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </body>
 </html>
