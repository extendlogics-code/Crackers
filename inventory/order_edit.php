<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/routes.php';
require_once __DIR__ . '/../lib/products.php';
require_once __DIR__ . '/../lib/orders_year.php';
admin_require_login();

$pdo = get_pdo();
ensure_products_catalog_schema($pdo);
ensure_order_year_schema($pdo);

$routeExt = route_extension();
$routeBase = route_base();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$order = null;
if ($id) {
  $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
  $st->execute([$id]);
  $order = $st->fetch();
  if (!$order) { http_response_code(404); echo 'Order not found'; exit; }
}

// Existing line items for edit
$orderItems = [];
if ($id) {
  $st = $pdo->prepare('SELECT id, sku, product_name, unit_price, quantity FROM order_items WHERE order_id = ? ORDER BY id');
  $st->execute([$id]);
  $orderItems = $st->fetchAll() ?: [];
}

// Catalog for product picker (active only)
$products = [];
try {
  $products = $pdo->query(
    "SELECT sku, name, unit, price, discount_pct, category
     FROM products
     WHERE COALESCE(is_active, 1) = 1
     ORDER BY category ASC, name ASC"
  )->fetchAll() ?: [];
} catch (Throwable $__) {
  $products = [];
}

$catalogBySku = [];
foreach ($products as $p) {
  $skuKey = trim((string)($p['sku'] ?? ''));
  if ($skuKey === '') continue;
  $list = (float)$p['price'];
  $disc = max(0.0, min(100.0, (float)($p['discount_pct'] ?? 0)));
  $sell = round(max(0.0, $list * (1 - $disc / 100)), 2);
  $catalogBySku[$skuKey] = [
    'sku' => $skuKey,
    'name' => (string)$p['name'],
    'unit' => (string)($p['unit'] ?? 'Box'),
    'price' => $list,
    'discount_pct' => $disc,
    'sell' => $sell,
    'category' => (string)($p['category'] ?? ''),
  ];
}

// Recent customers for dropdown
$customers = $pdo->query('SELECT id, name, phone, city FROM customers ORDER BY id DESC LIMIT 50')->fetchAll();

/**
 * Normalize posted line items; returns [items, error].
 * @return array{0: list<array{sku:?string,name:string,price:float,qty:int}>, 1: string}
 */
function admin_parse_order_items(array $post, array $catalogBySku): array {
  $skus = $post['item_sku'] ?? [];
  $names = $post['item_name'] ?? [];
  $prices = $post['item_price'] ?? [];
  $qtys = $post['item_qty'] ?? [];
  if (!is_array($skus)) $skus = [];
  if (!is_array($names)) $names = [];
  if (!is_array($prices)) $prices = [];
  if (!is_array($qtys)) $qtys = [];

  $n = max(count($skus), count($names), count($prices), count($qtys));
  if ($n > 200) {
    return [[], 'Too many line items (max 200).'];
  }

  $merged = []; // key: sku|name|price → qty
  for ($i = 0; $i < $n; $i++) {
    $sku = trim((string)($skus[$i] ?? ''));
    $name = trim((string)($names[$i] ?? ''));
    $priceRaw = $prices[$i] ?? '';
    $qty = (int)($qtys[$i] ?? 0);

    // Skip completely blank rows
    if ($sku === '' && $name === '' && ($priceRaw === '' || $priceRaw === null) && $qty === 0) {
      continue;
    }

    if ($qty < 1) {
      return [[], 'Each product needs a quantity of at least 1.'];
    }
    if ($qty > 99999) {
      return [[], 'Quantity cannot exceed 99,999 for a single line.'];
    }

    // Fill name/price from catalog when SKU known and fields omitted
    if ($sku !== '' && isset($catalogBySku[$sku])) {
      if ($name === '') $name = $catalogBySku[$sku]['name'];
      if ($priceRaw === '' || $priceRaw === null) {
        $priceRaw = $catalogBySku[$sku]['sell'];
      }
    }

    if ($name === '') {
      return [[], 'Product name is required for every line item.'];
    }
    if (function_exists('mb_substr')) {
      $name = mb_substr($name, 0, 255);
      $sku = mb_substr($sku, 0, 64);
    } else {
      $name = substr($name, 0, 255);
      $sku = substr($sku, 0, 64);
    }

    $price = round((float)$priceRaw, 2);
    if ($price < 0) {
      return [[], 'Unit price cannot be negative.'];
    }
    if ($price > 9999999.99) {
      return [[], 'Unit price is too large.'];
    }

    // Merge identical lines (same sku + name + price)
    $key = strtolower($sku) . "\0" . strtolower($name) . "\0" . number_format($price, 2, '.', '');
    if (isset($merged[$key])) {
      $merged[$key]['qty'] += $qty;
      if ($merged[$key]['qty'] > 99999) {
        return [[], 'Merged quantity exceeds 99,999 for "' . $name . '".'];
      }
    } else {
      $merged[$key] = [
        'sku' => $sku !== '' ? $sku : null,
        'name' => $name,
        'price' => $price,
        'qty' => $qty,
      ];
    }
  }

  $items = array_values($merged);
  if (!$items) {
    return [[], 'Add at least one product to the order.'];
  }
  return [$items, ''];
}

$err = '';
$postedItemsForUi = null; // preserve UI on validation error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!admin_csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); echo 'Invalid CSRF'; exit; }
  $status = trim((string)($_POST['status'] ?? 'new'));
  $allowedStatus = ['new','processing','confirmed','shipped','closed','cancelled'];
  if (!in_array($status, $allowedStatus, true)) {
    $status = 'new';
  }
  $notes = trim((string)($_POST['notes'] ?? ''));

  // Keep raw rows for redisplay even when parse fails
  $rawSkus = $_POST['item_sku'] ?? [];
  $rawNames = $_POST['item_name'] ?? [];
  $rawPrices = $_POST['item_price'] ?? [];
  $rawQtys = $_POST['item_qty'] ?? [];
  if (is_array($rawSkus) || is_array($rawNames)) {
    $postedItemsForUi = [];
    $rn = max(
      is_array($rawSkus) ? count($rawSkus) : 0,
      is_array($rawNames) ? count($rawNames) : 0,
      is_array($rawPrices) ? count($rawPrices) : 0,
      is_array($rawQtys) ? count($rawQtys) : 0
    );
    for ($ri = 0; $ri < $rn; $ri++) {
      $postedItemsForUi[] = [
        'sku' => (string)($rawSkus[$ri] ?? ''),
        'product_name' => (string)($rawNames[$ri] ?? ''),
        'unit_price' => (float)($rawPrices[$ri] ?? 0),
        'quantity' => max(1, (int)($rawQtys[$ri] ?? 1)),
      ];
    }
  }

  [$parsedItems, $itemErr] = admin_parse_order_items($_POST, $catalogBySku);

  $customer_id = (int)($_POST['customer_id'] ?? 0);
  $new_name = trim((string)($_POST['new_name'] ?? ''));

  if ($itemErr !== '') {
    $err = $itemErr;
  } elseif ($customer_id <= 0 && $new_name === '') {
    $err = 'Select an existing customer or create a new one.';
  } else {
    $subtotal = 0.0;
    foreach ($parsedItems as $it) {
      $subtotal += $it['price'] * $it['qty'];
    }
    $subtotal = round($subtotal, 2);
    $total = $subtotal; // admin orders: no shipping/tax layer

    try {
      $pdo->beginTransaction();

      if ($customer_id <= 0) {
        $st = $pdo->prepare('INSERT INTO customers (name, email, phone, address_line1, address_line2, city, state, pincode) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([
          $new_name,
          trim((string)($_POST['new_email'] ?? '')) ?: null,
          trim((string)($_POST['new_phone'] ?? '')) ?: null,
          trim((string)($_POST['new_address1'] ?? '')) ?: null,
          trim((string)($_POST['new_address2'] ?? '')) ?: null,
          trim((string)($_POST['new_city'] ?? '')) ?: null,
          trim((string)($_POST['new_state'] ?? '')) ?: null,
          trim((string)($_POST['new_pincode'] ?? '')) ?: null,
        ]);
        $customer_id = (int)$pdo->lastInsertId();
      } else {
        // Ensure customer still exists
        $chk = $pdo->prepare('SELECT id FROM customers WHERE id = ? LIMIT 1');
        $chk->execute([$customer_id]);
        if (!$chk->fetch()) {
          throw new RuntimeException('Selected customer no longer exists.');
        }
      }

      if ($id) {
        $st = $pdo->prepare('UPDATE orders SET customer_id=?, subtotal=?, total=?, status=?, notes=? WHERE id=?');
        $st->execute([$customer_id, $subtotal, $total, $status, $notes !== '' ? $notes : null, $id]);
        // Stale cached PDF no longer matches line items
        try {
          $pdo->prepare('UPDATE orders SET pdf_path=NULL, pdf_blob=NULL WHERE id=?')->execute([$id]);
        } catch (Throwable $__) { /* columns may be absent on older DBs */ }
        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$id]);
      } else {
        $invoice = allocate_invoice_number($pdo);
        $st = $pdo->prepare('INSERT INTO orders (customer_id, subtotal, total, status, notes, order_year, invoice_seq, invoice_code) VALUES (?,?,?,?,?,?,?,?)');
        $st->execute([
          $customer_id,
          $subtotal,
          $total,
          $status,
          $notes !== '' ? $notes : null,
          $invoice['year'],
          $invoice['seq'],
          $invoice['code'],
        ]);
        $id = (int)$pdo->lastInsertId();
      }

      $ins = $pdo->prepare('INSERT INTO order_items (order_id, sku, product_name, unit_price, quantity) VALUES (?,?,?,?,?)');
      foreach ($parsedItems as $it) {
        $ins->execute([$id, $it['sku'], $it['name'], $it['price'], $it['qty']]);
      }

      $pdo->commit();
      header('Location: ' . $routeBase . '/inventory/dashboard' . $routeExt);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = 'Could not save order. ' . (admin_is_manager() ? $e->getMessage() : 'Please try again.');
    }
  }
}

// Items to render: posted (failed validation) > existing DB > empty
$renderItems = [];
if (is_array($postedItemsForUi) && $postedItemsForUi) {
  $renderItems = $postedItemsForUi;
} elseif ($orderItems) {
  $renderItems = $orderItems;
}

$catalogJson = json_encode(array_values($catalogBySku), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);

$selectedCustomerId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $selectedCustomerId = (int)($_POST['customer_id'] ?? 0);
} elseif (is_array($order)) {
  $selectedCustomerId = (int)($order['customer_id'] ?? 0);
}
$curStatus = is_array($order)
  ? (string)($order['status'] ?? 'new')
  : (string)($_POST['status'] ?? 'new');
$curNotes = is_array($order)
  ? (string)($order['notes'] ?? '')
  : (string)($_POST['notes'] ?? '');

?><!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/pkslogo.png">
    <link rel="apple-touch-icon" href="/pkslogo.png">
    <title><?= $id ? 'Edit Order #'.$id : 'Create Order' ?></title>
    <style>
      :root{ --bg:#0b1020; --text:#e7eefc; --muted:#9fb4d6; --brand:#e11d48; --brand2:#f97316; --line:rgba(255,255,255,.14); --ok:#34d399; --bad:#fca5a5 }
      *{box-sizing:border-box}
      body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
      header{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.1)}
      .wrap{max-width:1100px;margin:0 auto;padding:12px 16px 40px}
      label{display:block;margin-top:8px;color:var(--muted)}
      input,select,textarea{width:100%;padding:10px;border-radius:8px;border:1px solid var(--line);background:rgba(255,255,255,.06);color:var(--text)}
      .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      @media (max-width:720px){ .row{grid-template-columns:1fr} }
      .btn{appearance:none;border:0;border-radius:8px;padding:8px 12px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
      .ghost{background:transparent;border:1px solid rgba(255,255,255,.2);color:var(--text)}
      .btn-sm{padding:6px 10px;font-size:12px}
      .btn-danger{background:rgba(248,113,113,.15);border:1px solid rgba(248,113,113,.4);color:#fecaca}
      .err{color:var(--bad);margin-top:8px;padding:10px;border:1px solid rgba(248,113,113,.35);border-radius:8px;background:rgba(248,113,113,.08)}
      details{margin-top:10px}
      summary{cursor:pointer}
      .panel{margin-top:18px;padding:14px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.03)}
      .panel h2{margin:0 0 6px;font-size:1.05rem}
      .panel .hint{margin:0 0 12px;color:var(--muted);font-size:12px}
      .add-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px}
      .add-bar .grow{flex:1 1 220px;min-width:180px}
      .add-bar .qty{flex:0 0 90px}
      .items-wrap{overflow-x:auto}
      table.items{width:100%;border-collapse:collapse;min-width:640px}
      table.items th, table.items td{padding:8px 6px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;vertical-align:middle}
      table.items th{color:var(--muted);font-size:12px;font-weight:600}
      table.items input{padding:8px;font-size:13px}
      table.items .sku{width:110px}
      table.items .name{width:auto}
      table.items .price{width:110px}
      table.items .qty{width:80px}
      table.items .line{width:110px;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
      table.items .act{width:70px}
      .totals{display:flex;justify-content:flex-end;gap:18px;margin-top:12px;flex-wrap:wrap}
      .totals .box{min-width:140px}
      .totals strong{display:block;color:var(--text);font-size:1.15rem;margin-top:4px}
      .empty-items{color:var(--muted);padding:14px;text-align:center;border:1px dashed var(--line);border-radius:8px}
      .custom-toggle{margin-top:8px;font-size:12px}
    </style>
  </head>
  <body>
    <header>
      <strong><?= $id ? 'Edit Order #'.$id : 'Create Order' ?></strong>
      <div><?php include __DIR__ . '/_nav.php'; ?></div>
    </header>
    <div class="wrap">
      <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <form method="post" id="orderForm" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
        <label>Existing Customer
          <select name="customer_id" id="customer_id">
            <option value="0">-- Select --</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $selectedCustomerId===(int)$c['id']?'selected':'' ?>>#<?= (int)$c['id'] ?> — <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars((string)$c['phone']) ?>, <?= htmlspecialchars((string)$c['city']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>

        <details<?= (!$order && $err && trim((string)($_POST['new_name'] ?? '')) !== '') ? ' open' : '' ?>>
          <summary>Or create new customer</summary>
          <div class="row">
            <label>Name<input name="new_name" value="<?= htmlspecialchars((string)($_POST['new_name'] ?? '')) ?>"></label>
            <label>Phone<input name="new_phone" value="<?= htmlspecialchars((string)($_POST['new_phone'] ?? '')) ?>"></label>
          </div>
          <div class="row">
            <label>Email<input type="email" name="new_email" value="<?= htmlspecialchars((string)($_POST['new_email'] ?? '')) ?>"></label>
            <label>Pincode<input name="new_pincode" value="<?= htmlspecialchars((string)($_POST['new_pincode'] ?? '')) ?>"></label>
          </div>
          <label>Address line 1<input name="new_address1" value="<?= htmlspecialchars((string)($_POST['new_address1'] ?? '')) ?>"></label>
          <label>Address line 2<input name="new_address2" value="<?= htmlspecialchars((string)($_POST['new_address2'] ?? '')) ?>"></label>
          <div class="row">
            <label>City<input name="new_city" value="<?= htmlspecialchars((string)($_POST['new_city'] ?? '')) ?>"></label>
            <label>State<input name="new_state" value="<?= htmlspecialchars((string)($_POST['new_state'] ?? '')) ?>"></label>
          </div>
        </details>

        <div class="panel">
          <h2>Products</h2>
          <p class="hint">Add products from the catalog or a custom line. Edit quantity and unit price; totals recalculate automatically. Identical lines are merged on save.</p>

          <div class="add-bar">
            <label class="grow">Product
              <input type="search" id="productSearch" placeholder="Search by name, SKU, or category…" autocomplete="off" list="productSuggestions">
              <datalist id="productSuggestions"></datalist>
              <select id="productPick" size="1">
                <option value="">— Select product —</option>
                <?php foreach ($products as $p):
                  $sku = (string)$p['sku'];
                  $list = (float)$p['price'];
                  $disc = max(0.0, min(100.0, (float)($p['discount_pct'] ?? 0)));
                  $sell = round(max(0.0, $list * (1 - $disc / 100)), 2);
                  $label = $sku . ' — ' . $p['name'] . ' (₹' . number_format($sell, 2) . ')';
                ?>
                  <option
                    value="<?= htmlspecialchars($sku) ?>"
                    data-name="<?= htmlspecialchars($p['name']) ?>"
                    data-sell="<?= htmlspecialchars((string)$sell) ?>"
                    data-category="<?= htmlspecialchars((string)($p['category'] ?? '')) ?>"
                    data-search="<?= htmlspecialchars(strtolower($sku . ' ' . $p['name'] . ' ' . ($p['category'] ?? ''))) ?>"
                  ><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="qty">Qty
              <input type="number" id="addQty" min="1" max="99999" step="1" value="1">
            </label>
            <button type="button" class="btn" id="btnAddProduct">Add</button>
            <button type="button" class="ghost btn" id="btnAddCustom">Custom line</button>
          </div>

          <div class="items-wrap">
            <table class="items" id="itemsTable" <?= $renderItems ? '' : 'hidden' ?>>
              <thead>
                <tr>
                  <th>SKU</th>
                  <th>Product</th>
                  <th>Unit price (₹)</th>
                  <th>Qty</th>
                  <th>Line total</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="itemsBody">
                <?php foreach ($renderItems as $it):
                  $sku = (string)($it['sku'] ?? '');
                  $name = (string)($it['product_name'] ?? '');
                  $price = (float)($it['unit_price'] ?? 0);
                  $qty = (int)($it['quantity'] ?? 1);
                  $line = $price * $qty;
                ?>
                <tr>
                  <td><input class="sku" name="item_sku[]" value="<?= htmlspecialchars($sku) ?>" maxlength="64" placeholder="SKU"></td>
                  <td><input class="name" name="item_name[]" value="<?= htmlspecialchars($name) ?>" maxlength="255" required placeholder="Product name"></td>
                  <td><input class="price" type="number" name="item_price[]" value="<?= htmlspecialchars(number_format($price, 2, '.', '')) ?>" min="0" step="0.01" required></td>
                  <td><input class="qty" type="number" name="item_qty[]" value="<?= $qty ?>" min="1" max="99999" step="1" required></td>
                  <td class="line">₹<?= number_format($line, 2) ?></td>
                  <td class="act"><button type="button" class="ghost btn btn-sm btn-danger btn-remove">Remove</button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <div class="empty-items" id="emptyItems" <?= $renderItems ? 'hidden' : '' ?>>No products yet. Select a product above and click Add.</div>
          </div>

          <div class="totals">
            <div class="box">
              <span class="hint">Subtotal</span>
              <strong id="subtotalDisplay">₹0.00</strong>
              <input type="hidden" name="subtotal" id="subtotalInput" value="0">
            </div>
            <div class="box">
              <span class="hint">Total</span>
              <strong id="totalDisplay">₹0.00</strong>
              <input type="hidden" name="total" id="totalInput" value="0">
            </div>
          </div>
        </div>

        <div class="row">
          <label>Status
            <select name="status">
              <?php foreach (['new','processing','confirmed','shipped','closed','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $curStatus===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Notes<textarea name="notes" rows="2"><?= htmlspecialchars($curNotes) ?></textarea></label>
        </div>

        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn" type="submit">Save</button>
          <a class="ghost btn" href="<?= $routeBase ?>/inventory/dashboard<?=$routeExt ?>">Cancel</a>
        </div>
      </form>
    </div>
    <script>
    (function () {
      var catalog = <?= $catalogJson ?: '[]' ?>;
      var catalogMap = {};
      catalog.forEach(function (p) { catalogMap[p.sku] = p; });

      var body = document.getElementById('itemsBody');
      var table = document.getElementById('itemsTable');
      var empty = document.getElementById('emptyItems');
      var pick = document.getElementById('productPick');
      var search = document.getElementById('productSearch');
      var addQty = document.getElementById('addQty');
      var suggestions = document.getElementById('productSuggestions');

      function money(n) {
        return '₹' + (Math.round(n * 100) / 100).toFixed(2);
      }

      function syncEmpty() {
        var has = body.children.length > 0;
        table.hidden = !has;
        empty.hidden = has;
      }

      function recalc() {
        var sum = 0;
        Array.prototype.forEach.call(body.querySelectorAll('tr'), function (tr) {
          var price = parseFloat(tr.querySelector('input[name="item_price[]"]').value) || 0;
          var qty = parseInt(tr.querySelector('input[name="item_qty[]"]').value, 10) || 0;
          if (price < 0) price = 0;
          if (qty < 0) qty = 0;
          var line = price * qty;
          sum += line;
          tr.querySelector('.line').textContent = money(line);
        });
        sum = Math.round(sum * 100) / 100;
        document.getElementById('subtotalDisplay').textContent = money(sum);
        document.getElementById('totalDisplay').textContent = money(sum);
        document.getElementById('subtotalInput').value = sum.toFixed(2);
        document.getElementById('totalInput').value = sum.toFixed(2);
      }

      function bindRow(tr) {
        tr.querySelectorAll('input').forEach(function (inp) {
          inp.addEventListener('input', recalc);
          inp.addEventListener('change', recalc);
        });
        tr.querySelector('.btn-remove').addEventListener('click', function () {
          tr.remove();
          syncEmpty();
          recalc();
        });
      }

      function findRowBySku(sku) {
        if (!sku) return null;
        var rows = body.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
          var v = (rows[i].querySelector('input[name="item_sku[]"]').value || '').trim();
          if (v === sku) return rows[i];
        }
        return null;
      }

      function addRow(sku, name, price, qty) {
        sku = (sku || '').trim();
        name = (name || '').trim();
        price = Math.round((parseFloat(price) || 0) * 100) / 100;
        qty = parseInt(qty, 10) || 1;
        if (qty < 1) qty = 1;
        if (qty > 99999) qty = 99999;

        // Merge into existing SKU row when present
        if (sku) {
          var existing = findRowBySku(sku);
          if (existing) {
            var qInp = existing.querySelector('input[name="item_qty[]"]');
            var next = (parseInt(qInp.value, 10) || 0) + qty;
            if (next > 99999) next = 99999;
            qInp.value = next;
            // Keep existing unit price (admin may have overridden)
            syncEmpty();
            recalc();
            return;
          }
        }

        var tr = document.createElement('tr');
        tr.innerHTML =
          '<td><input class="sku" name="item_sku[]" value="" maxlength="64" placeholder="SKU"></td>' +
          '<td><input class="name" name="item_name[]" value="" maxlength="255" required placeholder="Product name"></td>' +
          '<td><input class="price" type="number" name="item_price[]" value="0" min="0" step="0.01" required></td>' +
          '<td><input class="qty" type="number" name="item_qty[]" value="1" min="1" max="99999" step="1" required></td>' +
          '<td class="line">₹0.00</td>' +
          '<td class="act"><button type="button" class="ghost btn btn-sm btn-danger btn-remove">Remove</button></td>';
        tr.querySelector('input[name="item_sku[]"]').value = sku;
        tr.querySelector('input[name="item_name[]"]').value = name;
        tr.querySelector('input[name="item_price[]"]').value = price.toFixed(2);
        tr.querySelector('input[name="item_qty[]"]').value = qty;
        body.appendChild(tr);
        bindRow(tr);
        syncEmpty();
        recalc();
      }

      function filterProducts() {
        var q = (search.value || '').trim().toLowerCase();
        var opts = pick.querySelectorAll('option');
        var matchCount = 0;
        suggestions.innerHTML = '';
        opts.forEach(function (opt, idx) {
          if (idx === 0) { opt.hidden = false; return; }
          var hay = opt.getAttribute('data-search') || '';
          var show = !q || hay.indexOf(q) !== -1;
          opt.hidden = !show;
          if (show) {
            matchCount++;
            if (matchCount <= 12 && suggestions) {
              var o = document.createElement('option');
              o.value = opt.textContent;
              suggestions.appendChild(o);
            }
          }
        });
        // Auto-select first visible match when typing exact SKU
        var exact = catalogMap[q] || catalogMap[q.toUpperCase()] || null;
        if (q && exact) {
          pick.value = exact.sku;
        }
      }

      document.getElementById('btnAddProduct').addEventListener('click', function () {
        var sku = (pick.value || '').trim();
        if (!sku) {
          alert('Select a product first.');
          return;
        }
        var opt = pick.selectedOptions[0];
        var name = opt ? (opt.getAttribute('data-name') || '') : '';
        var sell = opt ? (opt.getAttribute('data-sell') || '0') : '0';
        if (catalogMap[sku]) {
          name = catalogMap[sku].name;
          sell = catalogMap[sku].sell;
        }
        addRow(sku, name, sell, addQty.value);
        pick.value = '';
        search.value = '';
        filterProducts();
        addQty.value = '1';
      });

      document.getElementById('btnAddCustom').addEventListener('click', function () {
        addRow('', '', 0, addQty.value || 1);
        var last = body.lastElementChild;
        if (last) last.querySelector('input[name="item_name[]"]').focus();
      });

      search.addEventListener('input', filterProducts);
      search.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          document.getElementById('btnAddProduct').click();
        }
      });

      Array.prototype.forEach.call(body.querySelectorAll('tr'), bindRow);
      syncEmpty();
      recalc();

      document.getElementById('orderForm').addEventListener('submit', function (e) {
        if (!body.children.length) {
          e.preventDefault();
          alert('Add at least one product before saving.');
          return;
        }
        var cust = parseInt(document.getElementById('customer_id').value, 10) || 0;
        var newName = (document.querySelector('input[name="new_name"]').value || '').trim();
        if (cust <= 0 && newName === '') {
          e.preventDefault();
          alert('Select an existing customer or enter a new customer name.');
        }
        recalc();
      });
    })();
    </script>
  </body>
</html>
