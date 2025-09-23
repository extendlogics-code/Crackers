<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
admin_require_login();
$pdo = get_pdo();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$order = null;
if ($id) {
  $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
  $st->execute([$id]);
  $order = $st->fetch();
  if (!$order) { http_response_code(404); echo 'Order not found'; exit; }
}

// Fetch recent customers for dropdown
$customers = $pdo->query('SELECT id, name, phone, city FROM customers ORDER BY id DESC LIMIT 50')->fetchAll();

// Save
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!admin_csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); echo 'Invalid CSRF'; exit; }
  $status = trim($_POST['status'] ?? 'new');
  $subtotal = (float)($_POST['subtotal'] ?? 0);
  $shipping = (float)($_POST['shipping'] ?? 0);
  $total = (float)($_POST['total'] ?? 0);
  $notes = trim($_POST['notes'] ?? '');

  // Customer: either existing customer_id or create new one
  $customer_id = (int)($_POST['customer_id'] ?? 0);
  $new_name = trim($_POST['new_name'] ?? '');
  if ($customer_id <= 0 && $new_name === '') {
    $err = 'Select an existing customer or create a new one.';
  } else {
    if ($customer_id <= 0) {
      // create customer
      $st = $pdo->prepare('INSERT INTO customers (name, email, phone, address_line1, address_line2, city, state, pincode) VALUES (?,?,?,?,?,?,?,?)');
      $st->execute([
        $new_name,
        trim($_POST['new_email'] ?? '' ) ?: null,
        trim($_POST['new_phone'] ?? '' ) ?: null,
        trim($_POST['new_address1'] ?? '') ?: null,
        trim($_POST['new_address2'] ?? '') ?: null,
        trim($_POST['new_city'] ?? '' ) ?: null,
        trim($_POST['new_state'] ?? '' ) ?: null,
        trim($_POST['new_pincode'] ?? '' ) ?: null,
      ]);
      $customer_id = (int)$pdo->lastInsertId();
    }
    if ($id) {
      $st = $pdo->prepare('UPDATE orders SET customer_id=?, subtotal=?, shipping=?, total=?, status=?, notes=? WHERE id=?');
      $st->execute([$customer_id, $subtotal, $shipping, $total, $status, $notes ?: null, $id]);
    } else {
      $st = $pdo->prepare('INSERT INTO orders (customer_id, subtotal, shipping, total, status, notes) VALUES (?,?,?,?,?,?)');
      $st->execute([$customer_id, $subtotal, $shipping, $total, $status, $notes ?: null]);
      $id = (int)$pdo->lastInsertId();
    }
    header('Location: /inventory/dashboard.php');
    exit;
  }
}

?><!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $id? 'Edit Order #'.$id : 'Create Order' ?></title>
    <style>
      :root{ --bg:#0b1020; --text:#e7eefc; --muted:#9fb4d6; --brand:#e11d48; --brand2:#f97316 }
      body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
      header{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.1)}
      .wrap{max-width:900px;margin:0 auto;padding:12px 16px}
      label{display:block;margin-top:8px;color:var(--muted)}
      input,select,textarea{width:90%;padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.06);color:var(--text)}
      .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      @media (max-width:720px){ .row{grid-template-columns:1fr} }
      .btn{appearance:none;border:0;border-radius:8px;padding:8px 12px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;cursor:pointer}
      .ghost{background:transparent;border:1px solid rgba(255,255,255,.2)}
      .err{color:#fca5a5;margin-top:8px}
      details{margin-top:10px}
      summary{cursor:pointer}
    </style>
  </head>
  <body>
    <header>
      <strong><?= $id? 'Edit Order #'.$id : 'Create Order' ?></strong>
      <div><a class="ghost btn" href="/inventory/dashboard.php">Back</a></div>
    </header>
    <div class="wrap">
      <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
        <label>Existing Customer
          <select name="customer_id">
            <option value="0">-- Select --</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $order && $order['customer_id']==$c['id']?'selected':'' ?>>#<?= (int)$c['id'] ?> — <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars((string)$c['phone']) ?>, <?= htmlspecialchars((string)$c['city']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>

        <details>
          <summary>Or create new customer</summary>
          <div class="row">
            <label>Name<input name="new_name"></label>
            <label>Phone<input name="new_phone"></label>
          </div>
          <div class="row">
            <label>Email<input type="email" name="new_email"></label>
            <label>Pincode<input name="new_pincode"></label>
          </div>
          <label>Address line 1<input name="new_address1"></label>
          <label>Address line 2<input name="new_address2"></label>
          <div class="row">
            <label>City<input name="new_city"></label>
            <label>State<input name="new_state"></label>
          </div>
        </details>

        <div class="row">
          <label>Subtotal<input type="number" step="0.01" name="subtotal" value="<?= htmlspecialchars($order['subtotal'] ?? '0') ?>" required></label>
          <label>Shipping<input type="number" step="0.01" name="shipping" value="<?= htmlspecialchars($order['shipping'] ?? '0') ?>"></label>
        </div>
        <label>Total<input type="number" step="0.01" name="total" value="<?= htmlspecialchars($order['total'] ?? '0') ?>" required></label>
        <div class="row">
          <label>Status
            <select name="status">
              <?php foreach (['new','processing','confirmed','shipped','closed','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= ($order['status'] ?? 'new')===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Notes<textarea name="notes" rows="2"><?= htmlspecialchars($order['notes'] ?? '') ?></textarea></label>
        </div>

        <div style="margin-top:12px;display:flex;gap:8px">
          <button class="btn" type="submit">Save</button>
          <a class="ghost btn" href="/inventory/dashboard.php">Cancel</a>
        </div>
      </form>
    </div>
  </body>
</html>
