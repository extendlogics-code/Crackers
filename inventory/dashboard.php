<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
admin_require_login();

$pdo = get_pdo();

// Pagination and date filters
$limit = max(10, min(200, (int)($_GET['limit'] ?? 50)));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$dateFrom = $_GET['from'] ?? '';
$dateTo = $_GET['to'] ?? '';

$where = [];
$params = [];
if ($dateFrom !== '') { $where[] = 'o.created_at >= ?'; $params[] = $dateFrom . ' 00:00:00'; }
if ($dateTo !== '') { $where[] = 'o.created_at <= ?'; $params[] = $dateTo . ' 23:59:59'; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Count total orders for pagination
$totalOrders = (int)$pdo->prepare("SELECT COUNT(*) FROM orders o $whereSql")->execute($params) ?: 0;
$stmtCnt = $pdo->prepare("SELECT COUNT(*) AS c FROM orders o $whereSql");
$stmtCnt->execute($params);
$totalOrders = (int)($stmtCnt->fetch()['c'] ?? 0);
$totalPages = max(1, (int)ceil($totalOrders / $limit));

// Fetch orders + customers
$sql = "SELECT o.id, o.subtotal, o.shipping, o.total, o.status, o.created_at,
               c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
               c.city AS customer_city, c.state AS customer_state, c.pincode AS customer_pincode
        FROM orders o
        JOIN customers c ON c.id = o.customer_id
        $whereSql
        ORDER BY o.id DESC
        LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$orders = $st->fetchAll();

// Fetch items for these orders in one go
$orderIds = array_map(fn($r) => (int)$r['id'], $orders);
$itemsByOrder = [];
if ($orderIds) {
  $in = implode(',', array_fill(0, count($orderIds), '?'));
  $stI = $pdo->prepare("SELECT order_id, sku, product_name, unit_price, quantity FROM order_items WHERE order_id IN ($in) ORDER BY id");
  $stI->execute($orderIds);
  foreach ($stI->fetchAll() as $row) {
    $oid = (int)$row['order_id'];
    if (!isset($itemsByOrder[$oid])) $itemsByOrder[$oid] = [];
    $itemsByOrder[$oid][] = $row;
  }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Orders Dashboard</title>
    <style>
      :root{ --bg:#0b1020; --text:#e7eefc; --muted:#9fb4d6; --brand:#e11d48; --brand2:#f97316 }
      body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
      header{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.1)}
      a{color:#93c5fd;text-decoration:none}
      .wrap{max-width:1200px;margin:0 auto;padding:12px 16px}
      .filters{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0}
      input,select{padding:8px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.06);color:var(--text)}
      .btn{appearance:none;border:0;border-radius:8px;padding:8px 12px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;cursor:pointer}
      .ghost{background:transparent;border:1px solid rgba(255,255,255,.2)}
      .table-responsive{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;border-radius:10px}
      table{width:100%;border-collapse:collapse;margin-top:10px;min-width:900px}
      th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.1);text-align:left;vertical-align:top}
      th{background:rgba(255,255,255,.06)}
      details{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:8px}
      summary{cursor:pointer;color:#93c5fd}
      .meta{color:var(--muted)}
      .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:10px 0}
      .card{padding:12px;border:1px solid rgba(255,255,255,.12);border-radius:10px;background:rgba(255,255,255,.05)}
      .badge{padding:2px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);font-size:12px}
      .pagi{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:10px}

      @media (max-width:1000px){ .grid{grid-template-columns:repeat(2,1fr)} }
      @media (max-width:640px){ .grid{grid-template-columns:1fr} header{justify-content:center} }
    </style>
  </head>
  <body>
    <header>
      <strong>Orders Dashboard</strong>
      <div>
        <a class="ghost btn" href="/inventory/dashboard.php">Inventory</a>
        <a class="ghost btn" href="/inventory/login.php?logout=1">Logout</a>
      </div>
    </header>
    <div class="wrap">
      <form class="filters" method="get">
        <label>From <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"></label>
        <label>To <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"></label>
        <label>Per page
          <select name="limit">
            <?php foreach ([25,50,100,200] as $opt): ?>
              <option value="<?= $opt ?>" <?= $opt===$limit?'selected':'' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn" type="submit">Apply</button>
      </form>

      <div class="grid">
        <div class="card"><div class="meta">Total Orders</div><div style="font-size:22px;font-weight:800"><?= (int)$totalOrders ?></div></div>
        <div class="card"><div class="meta">Page</div><div style="font-size:22px;font-weight:800"><?= (int)$page ?> / <?= (int)$totalPages ?></div></div>
        <div class="card"><div class="meta">From</div><div><?= htmlspecialchars($dateFrom ?: '-') ?></div></div>
        <div class="card"><div class="meta">To</div><div><?= htmlspecialchars($dateTo ?: '-') ?></div></div>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px">
        <div>Role: <span class="badge"><?= htmlspecialchars(admin_current_role()) ?></span></div>
        <div>
          <?php if (admin_current_role()==='admin'): ?>
            <a class="btn" href="/inventory/product.php">Create Product</a>
            <a class="btn" href="/inventory/order_edit.php">Create Order</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="table-responsive">
      <table>
        <thead>
          <tr>
            <th style="width:80px">Order #</th>
            <th>Customer</th>
            <th style="width:160px">Contact</th>
            <th style="width:140px">Totals</th>
            <th style="width:160px">Placed</th>
            <th>Items</th>
            <th style="width:140px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o): $oid=(int)$o['id']; $itms=$itemsByOrder[$oid] ?? []; ?>
          <tr>
            <td><span class="badge">#<?= $oid ?></span><div class="meta">Status: <?= htmlspecialchars($o['status']) ?></div></td>
            <td>
              <strong><?= htmlspecialchars($o['customer_name']) ?></strong>
              <div class="meta"><?= htmlspecialchars(($o['customer_city'] ?: '') . ', ' . ($o['customer_state'] ?: '')) ?></div>
              <div class="meta">PIN: <?= htmlspecialchars((string)($o['customer_pincode'] ?: '')) ?></div>
            </td>
            <td>
              <div><?= htmlspecialchars((string)$o['customer_phone']) ?></div>
              <div class="meta"><?= htmlspecialchars((string)$o['customer_email']) ?></div>
            </td>
            <td>
              <div>Sub: ₹<?= number_format((float)$o['subtotal'],2) ?></div>
              <div>Ship: ₹<?= number_format((float)$o['shipping'],2) ?></div>
              <div><strong>Total: ₹<?= number_format((float)$o['total'],2) ?></strong></div>
            </td>
            <td><?= htmlspecialchars($o['created_at']) ?></td>
            <td>
              <?php if ($itms): ?>
              <details>
                <summary>View <?= count($itms) ?> items</summary>
                <table style="margin-top:6px">
                  <thead><tr><th>SKU</th><th>Product</th><th class="meta">Unit</th><th>Price</th><th>Qty</th><th>Amount</th></tr></thead>
                  <tbody>
                    <?php foreach ($itms as $it): $amt=(float)$it['unit_price']*(int)$it['quantity']; ?>
                    <tr>
                      <td><?= htmlspecialchars((string)$it['sku']) ?></td>
                      <td><?= htmlspecialchars($it['product_name']) ?></td>
                      <td class="meta">Box</td>
                      <td>₹<?= number_format((float)$it['unit_price'],2) ?></td>
                      <td><?= (int)$it['quantity'] ?></td>
                      <td>₹<?= number_format($amt,2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </details>
              <?php else: ?>
                <span class="meta">No items</span>
              <?php endif; ?>
            </td>
            <td>
              <a class="ghost btn" href="/inventory/order_edit.php?id=<?= $oid ?>">Edit</a>
              <?php if (admin_current_role()==='admin'): ?>
              <form method="post" action="/inventory/order_delete.php" style="display:inline" onsubmit="return confirm('Delete order #<?= $oid ?>? This will remove its items too.');">
                <input type="hidden" name="id" value="<?= $oid ?>">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                <button class="ghost btn" type="submit">Delete</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div class="pagi">
        <?php if ($page>1): ?><a class="ghost btn" href="?<?= http_build_query(['from'=>$dateFrom,'to'=>$dateTo,'limit'=>$limit,'page'=>$page-1]) ?>">Prev</a><?php endif; ?>
        <?php if ($page<$totalPages): ?><a class="ghost btn" href="?<?= http_build_query(['from'=>$dateFrom,'to'=>$dateTo,'limit'=>$limit,'page'=>$page+1]) ?>">Next</a><?php endif; ?>
      </div>
    </div>
  </body>
</html>
