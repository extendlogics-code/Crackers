<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/routes.php';
admin_require_login();

$pdo = get_pdo();
$routeExt = route_extension();
$routeBase = route_base();
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
$sql = "SELECT o.id, o.subtotal, o.total, o.status, o.created_at, o.transaction_id,
               o.order_year, o.invoice_seq, o.invoice_code,
               c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
               c.address_line1 AS customer_address_line1, c.address_line2 AS customer_address_line2,
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

// Attach item lists for template rendering
foreach ($orders as &$orderRef) {
  $oid = (int)$orderRef['id'];
  $itms = $itemsByOrder[$oid] ?? [];
  $orderRef['items_list'] = $itms;
}
unset($orderRef);

// Display-only helpers (no business logic change)
$pageRevenue = 0.0;
foreach ($orders as $oRow) { $pageRevenue += (float)($oRow['total'] ?? 0); }
$user = admin_current_user();
$role = admin_current_role();
$statusClass = static function (string $status): string {
  $s = strtolower(trim($status));
  if (in_array($s, ['new', 'pending'], true)) return 'st-new';
  if (in_array($s, ['confirmed', 'paid', 'completed', 'done'], true)) return 'st-ok';
  if (in_array($s, ['cancelled', 'canceled', 'failed'], true)) return 'st-bad';
  return 'st-muted';
};

$renderOrderActions = static function (int $oid, string $invLabel, string $routeExt) use ($routeBase) {
  ?>
  <div class="actions">
    <a class="ghost btn btn-sm" href="<?= $routeBase ?>/inventory/order_edit<?=$routeExt ?>?id=<?= $oid ?>">Edit</a>
    <form method="post" action="<?= $routeBase ?>/api/order_pdf<?=$routeExt ?>" target="_blank">
      <input type="hidden" name="order_id" value="<?= $oid ?>">
      <button class="ghost btn btn-sm" type="submit">PDF</button>
    </form>
    <?php if (admin_is_manager()): ?>
    <form method="post" action="<?= $routeBase ?>/inventory/order_delete<?=$routeExt ?>" onsubmit="return confirm('Delete order <?= htmlspecialchars($invLabel, ENT_QUOTES) ?>? This will remove its items too.');">
      <input type="hidden" name="id" value="<?= $oid ?>">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
      <button class="btn btn-sm btn-danger" type="submit">Delete</button>
    </form>
    <?php endif; ?>
  </div>
  <?php
};

$renderItems = static function (array $itms) {
  if (!$itms) {
    echo '<span class="meta">No items</span>';
    return;
  }
  ?>
  <details class="items">
    <summary><?= count($itms) ?> item<?= count($itms)===1?'':'s' ?></summary>
    <table class="items-table">
      <thead><tr><th>SKU</th><th>Product</th><th>Qty</th><th>Amount</th></tr></thead>
      <tbody>
        <?php foreach ($itms as $it): $amt=(float)$it['unit_price']*(int)$it['quantity']; ?>
        <tr>
          <td><?= htmlspecialchars((string)$it['sku']) ?></td>
          <td><?= htmlspecialchars($it['product_name']) ?></td>
          <td><?= (int)$it['quantity'] ?></td>
          <td>₹<?= number_format($amt,2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </details>
  <?php
};
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0c1222">
    <meta name="application-name" content="PSK Admin">
    <meta name="apple-mobile-web-app-title" content="PSK Admin">
    <link rel="icon" type="image/png" href="/pkslogo.png">
    <link rel="apple-touch-icon" href="/pkslogo.png">
    <title>Orders Dashboard · PSK Admin</title>
    <style>
      :root{
        --bg:#0c1222;
        --bg2:#111827;
        --panel:#151c2e;
        --panel2:#1a2338;
        --line:rgba(255,255,255,.08);
        --line2:rgba(255,255,255,.14);
        --text:#f3f6fb;
        --muted:#93a4c3;
        --brand:#e11d48;
        --brand2:#f97316;
        --ok:#34d399;
        --warn:#fbbf24;
        --bad:#f87171;
        --radius:14px;
        --shadow:0 12px 40px rgba(0,0,0,.28);
        --pad: max(18px, env(safe-area-inset-left));
        --padr: max(18px, env(safe-area-inset-right));
      }
      *{box-sizing:border-box}
      html{-webkit-text-size-adjust:100%}
      body{
        margin:0;
        min-height:100vh;
        min-height:100dvh;
        color:var(--text);
        font:14px/1.5 "Segoe UI", system-ui, -apple-system, Roboto, sans-serif;
        background:
          radial-gradient(900px 420px at 10% -10%, rgba(249,115,22,.18), transparent 55%),
          radial-gradient(700px 360px at 90% 0%, rgba(225,29,72,.14), transparent 50%),
          linear-gradient(180deg, #0a1020 0%, #0c1222 40%, #0b101c 100%);
        padding-bottom: env(safe-area-inset-bottom);
      }
      a{color:#93c5fd;text-decoration:none}
      a:hover{color:#bfdbfe}

      .topbar{
        position:sticky; top:0; z-index:40;
        backdrop-filter:saturate(140%) blur(12px);
        background:rgba(12,18,34,.88);
        border-bottom:1px solid var(--line);
        padding-top: env(safe-area-inset-top);
      }
      .topbar-inner{
        max-width:1280px; margin:0 auto;
        padding:12px var(--padr) 12px var(--pad);
        display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;
      }
      .brand-block{display:flex; align-items:center; gap:12px; min-width:0}
      .brand-mark{
        width:40px; height:40px; border-radius:12px; flex:0 0 auto;
        background:linear-gradient(135deg, var(--brand), var(--brand2));
        display:grid; place-items:center; font-weight:900; color:#fff; letter-spacing:.5px;
        box-shadow:0 8px 20px rgba(225,29,72,.28); font-size:13px;
      }
      .brand-block h1{margin:0; font-size:1.05rem; font-weight:800; letter-spacing:.2px}
      .brand-block p{margin:2px 0 0; color:var(--muted); font-size:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:42vw}

      .nav-shell{min-width:0; flex:1 1 auto; display:flex; justify-content:flex-end}
      .inv-nav{display:flex; gap:6px; flex-wrap:wrap; align-items:center; justify-content:flex-end}
      .inv-nav .btn, .inv-nav .ghost.btn{
        border-radius:999px !important;
        padding:8px 14px !important;
        font-size:13px;
        font-weight:700;
        border:1px solid transparent;
        transition:background .15s ease, border-color .15s ease, transform .12s ease;
        min-height:36px;
        white-space:nowrap;
      }
      .inv-nav .ghost.btn{
        background:rgba(255,255,255,.04) !important;
        border-color:var(--line2) !important;
        color:var(--text) !important;
      }
      .inv-nav .ghost.btn:hover{background:rgba(255,255,255,.09) !important; border-color:rgba(255,255,255,.22) !important}
      .inv-nav .btn:not(.ghost){
        background:linear-gradient(135deg, var(--brand), var(--brand2)) !important;
        color:#fff !important;
        box-shadow:0 6px 16px rgba(225,29,72,.25);
      }

      .wrap{max-width:1280px; margin:0 auto; padding:18px var(--padr) 28px var(--pad)}

      .hero{
        display:flex; justify-content:space-between; align-items:flex-end; gap:14px; flex-wrap:wrap;
        margin-bottom:16px;
      }
      .hero h2{margin:0; font-size:clamp(1.2rem, 2.5vw, 1.45rem); font-weight:800}
      .hero p{margin:6px 0 0; color:var(--muted)}
      .hero-actions{display:flex; gap:8px; flex-wrap:wrap}

      .btn{
        appearance:none; border:0; border-radius:10px; padding:10px 14px;
        background:linear-gradient(135deg, var(--brand), var(--brand2));
        color:#fff; font-weight:750; cursor:pointer;
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        transition:transform .12s ease, filter .12s ease;
        min-height:40px; text-decoration:none;
      }
      .btn:hover{filter:brightness(1.05); transform:translateY(-1px)}
      .ghost{
        background:rgba(255,255,255,.04);
        border:1px solid var(--line2);
        color:var(--text);
      }
      .ghost:hover{background:rgba(255,255,255,.08)}
      .btn-danger{
        background:rgba(248,113,113,.12);
        border:1px solid rgba(248,113,113,.35);
        color:#fecaca;
      }
      .btn-danger:hover{background:rgba(248,113,113,.2)}
      .btn-sm{padding:7px 10px; font-size:12px; border-radius:8px; min-height:34px}

      .stats{
        display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px;
      }
      .stat{
        background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));
        border:1px solid var(--line);
        border-radius:var(--radius);
        padding:16px 16px 14px;
        box-shadow:var(--shadow);
        position:relative; overflow:hidden;
        min-width:0;
      }
      .stat::after{
        content:""; position:absolute; right:-20px; top:-20px; width:90px; height:90px; border-radius:50%;
        background:radial-gradient(circle, rgba(249,115,22,.18), transparent 70%);
        pointer-events:none;
      }
      .stat .label{color:var(--muted); font-size:12px; font-weight:600; letter-spacing:.3px; text-transform:uppercase}
      .stat .value{margin-top:8px; font-size:clamp(1.15rem, 3vw, 1.55rem); font-weight:850; letter-spacing:-.02em; word-break:break-word}
      .stat .hint{margin-top:4px; color:var(--muted); font-size:12px}

      .panel{
        background:rgba(21,28,46,.92);
        border:1px solid var(--line);
        border-radius:calc(var(--radius) + 2px);
        box-shadow:var(--shadow);
        overflow:hidden;
      }
      .panel-head{
        padding:14px 16px;
        border-bottom:1px solid var(--line);
        display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;
        background:rgba(255,255,255,.02);
      }
      .panel-head h3{margin:0; font-size:15px; font-weight:800}
      .panel-head .meta{color:var(--muted); font-size:12px}

      .filters{
        display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;
        padding:14px 16px; border-bottom:1px solid var(--line);
        background:rgba(0,0,0,.12);
      }
      .filters label{
        display:flex; flex-direction:column; gap:6px;
        color:var(--muted); font-size:12px; font-weight:600;
        min-width:0;
      }
      .filters .filter-actions{display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding-bottom:1px}
      input,select{
        padding:10px 11px; border-radius:10px;
        border:1px solid var(--line2);
        background:rgba(255,255,255,.05);
        color:var(--text); min-width:140px;
        font-size:16px; /* avoid iOS zoom */
      }
      input:focus, select:focus{
        outline:2px solid rgba(249,115,22,.35);
        border-color:rgba(249,115,22,.55);
      }

      .toolbar{
        display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;
        padding:12px 16px; border-bottom:1px solid var(--line);
      }
      .role-pill{
        display:inline-flex; align-items:center; gap:8px;
        padding:6px 10px; border-radius:999px;
        background:rgba(255,255,255,.05); border:1px solid var(--line2);
        font-size:12px; color:var(--muted);
      }
      .role-pill b{color:var(--text); text-transform:capitalize}

      .table-desktop{width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch}
      table.orders{width:100%; border-collapse:separate; border-spacing:0; min-width:980px}
      table.orders th{
        text-align:left; padding:12px 14px;
        font-size:11px; letter-spacing:.4px; text-transform:uppercase; color:var(--muted);
        background:rgba(255,255,255,.03); border-bottom:1px solid var(--line);
        position:sticky; top:0;
      }
      table.orders td{
        padding:14px; border-bottom:1px solid var(--line);
        vertical-align:top;
      }
      table.orders tbody tr{transition:background .12s ease}
      table.orders tbody tr:hover{background:rgba(255,255,255,.03)}
      table.orders tbody tr:last-child td{border-bottom:0}

      .order-cards{display:none; padding:12px; gap:12px; flex-direction:column}
      .order-card{
        background:rgba(255,255,255,.03);
        border:1px solid var(--line);
        border-radius:12px;
        padding:14px;
        display:flex; flex-direction:column; gap:12px;
      }
      .order-card-top{
        display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap;
      }
      .order-card-grid{
        display:grid; grid-template-columns:1fr 1fr; gap:10px 12px;
      }
      .order-card-grid .full{grid-column:1 / -1}
      .field-label{display:block; color:var(--muted); font-size:11px; font-weight:650; text-transform:uppercase; letter-spacing:.3px; margin-bottom:4px}
      .order-card .actions{margin-top:2px}

      .invoice{
        display:inline-flex; align-items:center; gap:6px;
        padding:4px 10px; border-radius:999px;
        background:rgba(249,115,22,.12); border:1px solid rgba(249,115,22,.28);
        color:#fdba74; font-weight:750; font-size:12px;
        max-width:100%; word-break:break-all;
      }
      .status{
        display:inline-flex; margin-top:8px; padding:3px 8px; border-radius:999px;
        font-size:11px; font-weight:700; text-transform:capitalize;
        border:1px solid transparent;
      }
      .st-new{background:rgba(251,191,36,.12); border-color:rgba(251,191,36,.3); color:#fcd34d}
      .st-ok{background:rgba(52,211,153,.12); border-color:rgba(52,211,153,.3); color:#6ee7b7}
      .st-bad{background:rgba(248,113,113,.12); border-color:rgba(248,113,113,.3); color:#fca5a5}
      .st-muted{background:rgba(255,255,255,.06); border-color:var(--line2); color:var(--muted)}

      .cust-name{font-weight:750; margin:0 0 4px; word-break:break-word}
      .meta{color:var(--muted); font-size:12px; line-height:1.45; word-break:break-word}
      .money strong{font-size:15px}
      .money .sub{color:var(--muted); font-size:12px}

      .actions{display:flex; gap:6px; flex-wrap:wrap; align-items:center}
      .actions form{display:inline; margin:0}

      details.items{
        background:rgba(255,255,255,.03);
        border:1px solid var(--line);
        border-radius:10px; padding:8px 10px;
      }
      details.items summary{
        cursor:pointer; color:#93c5fd; font-weight:650; font-size:13px; list-style:none;
        min-height:28px; display:flex; align-items:center;
      }
      details.items summary::-webkit-details-marker{display:none}
      details.items summary::before{content:"▸ "; color:var(--muted)}
      details.items[open] summary::before{content:"▾ "}
      .items-table{width:100%; border-collapse:collapse; margin-top:8px; min-width:0; table-layout:fixed}
      .items-table th,.items-table td{padding:6px 8px; border-bottom:1px solid var(--line); font-size:12px; word-break:break-word}
      .items-table th{color:var(--muted); font-weight:650; background:transparent; text-transform:none; letter-spacing:0}

      .empty{
        padding:48px 20px; text-align:center; color:var(--muted);
      }
      .empty strong{display:block; color:var(--text); font-size:1.05rem; margin-bottom:6px}

      .pagi{
        display:flex; gap:8px; align-items:center; justify-content:space-between; flex-wrap:wrap;
        padding:14px 16px; border-top:1px solid var(--line);
      }
      .pagi .pages{display:flex; gap:8px; align-items:center; flex-wrap:wrap}

      /* Tablet */
      @media (max-width:1024px){
        .stats{grid-template-columns:repeat(2,minmax(0,1fr))}
        .nav-shell{flex:1 1 100%; justify-content:flex-start}
        .inv-nav{justify-content:flex-start; width:100%}
        .topbar-inner{align-items:flex-start}
      }

      /* Phone + small tablet: card layout */
      @media (max-width:860px){
        .table-desktop{display:none}
        .order-cards{display:flex}
        .hero-actions .btn{flex:1 1 auto; min-width:140px}
        .filters label{flex:1 1 140px}
        .filters .filter-actions{width:100%}
        .filters .filter-actions .btn{flex:1 1 auto}
        input,select{width:100%; min-width:0}
      }

      @media (max-width:640px){
        .stats{grid-template-columns:1fr 1fr}
        .brand-block p{max-width:58vw}
        .order-card-grid{grid-template-columns:1fr}
        .pagi{flex-direction:column; align-items:stretch}
        .pagi .pages{justify-content:space-between}
        .pagi .pages .btn{flex:1}
        .inv-nav .btn, .inv-nav .ghost.btn{padding:8px 12px !important; font-size:12px}
      }

      @media (max-width:400px){
        .stats{grid-template-columns:1fr}
      }

      @media (prefers-reduced-motion: reduce){
        .btn,.inv-nav .btn{transition:none}
        .btn:hover{transform:none}
      }
    </style>
  </head>
  <body>
    <div class="topbar">
      <div class="topbar-inner">
        <div class="brand-block">
          <div class="brand-mark" aria-hidden="true">PSK</div>
          <div>
            <h1>Orders Dashboard</h1>
            <p title="<?= htmlspecialchars((string)($user['username'] ?? 'admin')) ?>">Signed in as <?= htmlspecialchars((string)($user['username'] ?? 'admin')) ?></p>
          </div>
        </div>
        <div class="nav-shell"><?php include __DIR__ . '/_nav.php'; ?></div>
      </div>
    </div>

    <div class="wrap">
      <div class="hero">
        <div>
          <h2>Order overview</h2>
          <p>Review, filter, and manage customer orders.</p>
        </div>
        <div class="hero-actions">
          <?php if (admin_is_manager()): ?>
            <a class="btn ghost" href="<?= $routeBase ?>/inventory/product<?=$routeExt ?>">Manage Products</a>
            <a class="btn" href="<?= $routeBase ?>/inventory/order_edit<?=$routeExt ?>">Create Order</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="stats">
        <div class="stat">
          <div class="label">Total orders</div>
          <div class="value"><?= (int)$totalOrders ?></div>
          <div class="hint">Matching current filters</div>
        </div>
        <div class="stat">
          <div class="label">This page</div>
          <div class="value"><?= (int)$page ?> <span style="font-size:.7em;color:var(--muted);font-weight:600">/ <?= (int)$totalPages ?></span></div>
          <div class="hint"><?= count($orders) ?> rows shown</div>
        </div>
        <div class="stat">
          <div class="label">Page revenue</div>
          <div class="value">₹<?= number_format($pageRevenue, 0) ?></div>
          <div class="hint">Sum of visible orders</div>
        </div>
        <div class="stat">
          <div class="label">Date range</div>
          <div class="value" style="font-size:1.05rem;line-height:1.35">
            <?= htmlspecialchars($dateFrom ?: 'Any') ?>
            <span style="color:var(--muted);font-weight:600"> → </span>
            <?= htmlspecialchars($dateTo ?: 'Any') ?>
          </div>
          <div class="hint">Filter with the form below</div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">
          <div>
            <h3>Orders</h3>
            <div class="meta">Newest first · <?= (int)$totalOrders ?> total</div>
          </div>
          <div class="role-pill">Role <b><?= htmlspecialchars($role) ?></b></div>
        </div>

        <form class="filters" method="get">
          <label>From
            <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>">
          </label>
          <label>To
            <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>">
          </label>
          <label>Per page
            <select name="limit">
              <?php foreach ([25,50,100,200] as $opt): ?>
                <option value="<?= $opt ?>" <?= $opt===$limit?'selected':'' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="filter-actions">
            <button class="btn" type="submit">Apply filters</button>
            <?php if ($dateFrom !== '' || $dateTo !== ''): ?>
              <a class="btn ghost btn-sm" href="<?= $routeBase ?>/inventory/dashboard<?=$routeExt ?>">Clear</a>
            <?php endif; ?>
          </div>
        </form>

        <?php if (!$orders): ?>
          <div class="empty">
            <strong>No orders yet</strong>
            Nothing matches these filters. Try clearing dates or create a new order.
          </div>
        <?php else: ?>
          <div class="table-desktop">
            <table class="orders">
              <thead>
                <tr>
                  <th style="width:130px">Invoice</th>
                  <th>Customer</th>
                  <th style="width:160px">Contact</th>
                  <th style="width:150px">Transaction</th>
                  <th style="width:120px">Totals</th>
                  <th style="width:150px">Placed</th>
                  <th style="width:180px">Items</th>
                  <th style="width:200px">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($orders as $o):
                  $oid=(int)$o['id'];
                  $itms=$o['items_list'] ?? [];
                  $invLabel = !empty($o['invoice_code']) ? (string)$o['invoice_code'] : ('#' . $oid);
                  $stCls = $statusClass((string)($o['status'] ?? ''));
                  $placed = (string)($o['created_at'] ?? '');
                  $placedFmt = $placed;
                  $ts = strtotime($placed);
                  if ($ts) { $placedFmt = date('d M Y, h:i A', $ts); }
                  $cityState = trim(implode(', ', array_filter([
                    $o['customer_city'] ?? '',
                    $o['customer_state'] ?? ''
                  ])));
                ?>
                <tr>
                  <td>
                    <span class="invoice"><?= htmlspecialchars($invLabel) ?></span>
                    <div class="status <?= htmlspecialchars($stCls) ?>"><?= htmlspecialchars((string)$o['status']) ?></div>
                  </td>
                  <td>
                    <div class="cust-name"><?= htmlspecialchars($o['customer_name']) ?></div>
                    <?php if (!empty($o['customer_address_line1'])): ?>
                      <div class="meta"><?= htmlspecialchars($o['customer_address_line1']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($o['customer_address_line2'])): ?>
                      <div class="meta"><?= htmlspecialchars($o['customer_address_line2']) ?></div>
                    <?php endif; ?>
                    <?php if ($cityState !== ''): ?>
                      <div class="meta"><?= htmlspecialchars($cityState) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($o['customer_pincode'])): ?>
                      <div class="meta">PIN: <?= htmlspecialchars((string)$o['customer_pincode']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div><?= htmlspecialchars((string)$o['customer_phone']) ?></div>
                    <div class="meta"><?= htmlspecialchars((string)$o['customer_email']) ?></div>
                  </td>
                  <td>
                    <?php if (!empty($o['transaction_id'])): ?>
                      <div style="word-break:break-all"><?= htmlspecialchars((string)$o['transaction_id']) ?></div>
                    <?php else: ?>
                      <span class="meta">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="money">
                    <div class="sub">Sub ₹<?= number_format((float)$o['subtotal'],2) ?></div>
                    <strong>₹<?= number_format((float)$o['total'],2) ?></strong>
                  </td>
                  <td><div><?= htmlspecialchars($placedFmt) ?></div></td>
                  <td><?php $renderItems($itms); ?></td>
                  <td><?php $renderOrderActions($oid, $invLabel, $routeExt); ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="order-cards" aria-label="Orders list">
            <?php foreach ($orders as $o):
              $oid=(int)$o['id'];
              $itms=$o['items_list'] ?? [];
              $invLabel = !empty($o['invoice_code']) ? (string)$o['invoice_code'] : ('#' . $oid);
              $stCls = $statusClass((string)($o['status'] ?? ''));
              $placed = (string)($o['created_at'] ?? '');
              $placedFmt = $placed;
              $ts = strtotime($placed);
              if ($ts) { $placedFmt = date('d M Y, h:i A', $ts); }
              $cityState = trim(implode(', ', array_filter([
                $o['customer_city'] ?? '',
                $o['customer_state'] ?? ''
              ])));
            ?>
            <article class="order-card">
              <div class="order-card-top">
                <div>
                  <span class="invoice"><?= htmlspecialchars($invLabel) ?></span>
                  <div class="status <?= htmlspecialchars($stCls) ?>"><?= htmlspecialchars((string)$o['status']) ?></div>
                </div>
                <div class="money" style="text-align:right">
                  <div class="sub">Sub ₹<?= number_format((float)$o['subtotal'],2) ?></div>
                  <strong>₹<?= number_format((float)$o['total'],2) ?></strong>
                </div>
              </div>
              <div class="order-card-grid">
                <div class="full">
                  <span class="field-label">Customer</span>
                  <div class="cust-name"><?= htmlspecialchars($o['customer_name']) ?></div>
                  <?php if (!empty($o['customer_address_line1'])): ?>
                    <div class="meta"><?= htmlspecialchars($o['customer_address_line1']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($o['customer_address_line2'])): ?>
                    <div class="meta"><?= htmlspecialchars($o['customer_address_line2']) ?></div>
                  <?php endif; ?>
                  <?php if ($cityState !== ''): ?>
                    <div class="meta"><?= htmlspecialchars($cityState) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($o['customer_pincode'])): ?>
                    <div class="meta">PIN: <?= htmlspecialchars((string)$o['customer_pincode']) ?></div>
                  <?php endif; ?>
                </div>
                <div>
                  <span class="field-label">Contact</span>
                  <div><?= htmlspecialchars((string)$o['customer_phone'] ?: '—') ?></div>
                  <div class="meta"><?= htmlspecialchars((string)$o['customer_email'] ?: '—') ?></div>
                </div>
                <div>
                  <span class="field-label">Placed</span>
                  <div><?= htmlspecialchars($placedFmt) ?></div>
                </div>
                <div class="full">
                  <span class="field-label">Transaction</span>
                  <?php if (!empty($o['transaction_id'])): ?>
                    <div style="word-break:break-all"><?= htmlspecialchars((string)$o['transaction_id']) ?></div>
                  <?php else: ?>
                    <span class="meta">—</span>
                  <?php endif; ?>
                </div>
                <div class="full">
                  <span class="field-label">Items</span>
                  <?php $renderItems($itms); ?>
                </div>
              </div>
              <?php $renderOrderActions($oid, $invLabel, $routeExt); ?>
            </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="pagi">
          <div class="meta">Page <?= (int)$page ?> of <?= (int)$totalPages ?></div>
          <div class="pages">
            <?php if ($page>1): ?>
              <a class="ghost btn btn-sm" href="?<?= http_build_query(['from'=>$dateFrom,'to'=>$dateTo,'limit'=>$limit,'page'=>$page-1]) ?>">← Prev</a>
            <?php endif; ?>
            <?php if ($page<$totalPages): ?>
              <a class="ghost btn btn-sm" href="?<?= http_build_query(['from'=>$dateFrom,'to'=>$dateTo,'limit'=>$limit,'page'=>$page+1]) ?>">Next →</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </body>
</html>
