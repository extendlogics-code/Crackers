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
$isManager = admin_is_manager();
$h = static fn($s): string => htmlspecialchars(is_scalar($s) ? (string)$s : '', ENT_QUOTES, 'UTF-8');

$baseStatuses = ['new','processing','confirmed','shipped','closed','cancelled'];
// Products on these orders are frozen; status/notes/customer stay editable so the order can be reopened.
$lockedStatuses = ['closed','cancelled'];
$maxLines = 200;
$maxQty = 99999;
$maxUnitPrice = 9999999.99;
$maxOrderTotal = 99999999.99; // orders.total is DECIMAL(10,2)

function order_edit_load_items(PDO $pdo, int $orderId): array {
  $st = $pdo->prepare('SELECT id, sku, product_name, unit_price, quantity FROM order_items WHERE order_id = ? ORDER BY id');
  $st->execute([$orderId]);
  return $st->fetchAll() ?: [];
}

/** Hash of the editable order state, used to detect edits by another admin since the form was opened. */
function order_edit_fingerprint(array $order, array $items): string {
  $parts = [
    (string)(int)($order['customer_id'] ?? 0),
    number_format((float)($order['subtotal'] ?? 0), 2, '.', ''),
    number_format((float)($order['total'] ?? 0), 2, '.', ''),
    (string)($order['status'] ?? ''),
    (string)($order['notes'] ?? ''),
  ];
  foreach ($items as $it) {
    $parts[] = (string)($it['sku'] ?? '') . '|' . (string)($it['product_name'] ?? '') . '|'
      . number_format((float)($it['unit_price'] ?? 0), 2, '.', '') . '|' . (int)($it['quantity'] ?? 0);
  }
  return sha1(implode("\0", $parts));
}

/** Trimmed single-line UTF-8 text; null when the bytes are not valid UTF-8. */
function order_edit_clean_text($v): ?string {
  if (!is_scalar($v)) return '';
  $s = trim((string)$v);
  if ($s === '') return '';
  if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) return null;
  $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
  return $s === null ? null : trim($s);
}

function order_edit_strlen(string $s): int {
  return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$order = null;
if ($id) {
  $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
  $st->execute([$id]);
  $order = $st->fetch();
  if (!$order) { http_response_code(404); echo 'Order not found'; exit; }
}

$orderItems = $id ? order_edit_load_items($pdo, $id) : [];
$currentStatus = is_array($order) ? (string)($order['status'] ?? 'new') : 'new';
$isLocked = is_array($order) && in_array(strtolower($currentStatus), $lockedStatuses, true);
$isLegacyNoItems = is_array($order) && !$orderItems;

$statusOptions = $baseStatuses;
if (is_array($order) && $currentStatus !== '' && !in_array($currentStatus, $statusOptions, true)) {
  $statusOptions[] = $currentStatus; // keep unknown legacy statuses instead of silently resetting them
}

// Full catalog (active + archived) for line status; picker lists active products only
$catalogBySku = [];
$pickerProducts = [];
$categoryNames = [];
try {
  $catalogRows = $pdo->query(
    "SELECT sku, name, unit, price, discount_pct, category, COALESCE(is_active, 1) AS is_active
     FROM products
     ORDER BY category ASC, name ASC"
  )->fetchAll() ?: [];
} catch (Throwable $__) {
  $catalogRows = [];
}
foreach ($catalogRows as $p) {
  $skuKey = trim((string)($p['sku'] ?? ''));
  if ($skuKey === '') continue;
  $info = [
    'sku' => $skuKey,
    'name' => (string)$p['name'],
    'unit' => (string)($p['unit'] ?? 'Box'),
    'price' => round((float)$p['price'], 2),
    'discount_pct' => round(max(0.0, min(100.0, (float)($p['discount_pct'] ?? 0))), 2),
    'sell' => product_sell_price($p['price'], $p['discount_pct'] ?? 0),
    'category' => (string)($p['category'] ?? ''),
    'active' => (int)$p['is_active'] === 1,
  ];
  $catalogBySku[strtolower($skuKey)] = $info;
  if ($info['active']) $pickerProducts[] = $info;
  if ($info['category'] !== '') $categoryNames[strtoupper($info['category'])] = $info['category'];
}
ksort($categoryNames);

// Recent customers for dropdown (+ this order's customer even if older than the recent list)
$customers = $pdo->query('SELECT id, name, phone, city FROM customers ORDER BY id DESC LIMIT 50')->fetchAll() ?: [];
if (is_array($order) && (int)($order['customer_id'] ?? 0) > 0) {
  $orderCustomerId = (int)$order['customer_id'];
  $present = false;
  foreach ($customers as $c) {
    if ((int)$c['id'] === $orderCustomerId) { $present = true; break; }
  }
  if (!$present) {
    $st = $pdo->prepare('SELECT id, name, phone, city FROM customers WHERE id = ? LIMIT 1');
    $st->execute([$orderCustomerId]);
    if ($cRow = $st->fetch()) array_unshift($customers, $cRow);
  }
}

/**
 * Normalize posted line items; returns [items, error]. An empty list is not an error here.
 * @return array{0: list<array{sku:?string,name:string,price:float,qty:int}>, 1: string}
 */
function admin_parse_order_items(array $post, array $catalogBySku, int $maxLines, int $maxQty, float $maxUnitPrice): array {
  $skus = $post['item_sku'] ?? [];
  $names = $post['item_name'] ?? [];
  $prices = $post['item_price'] ?? [];
  $qtys = $post['item_qty'] ?? [];
  if (!is_array($skus)) $skus = [];
  if (!is_array($names)) $names = [];
  if (!is_array($prices)) $prices = [];
  if (!is_array($qtys)) $qtys = [];

  $n = max(count($skus), count($names), count($prices), count($qtys));
  if ($n > $maxLines) {
    return [[], 'Too many line items (max ' . $maxLines . ').'];
  }

  $merged = []; // key: sku|name|price → line
  for ($i = 0; $i < $n; $i++) {
    $label = 'Line ' . ($i + 1) . ': ';
    $sku = order_edit_clean_text($skus[$i] ?? '');
    $name = order_edit_clean_text($names[$i] ?? '');
    $priceRaw = is_scalar($prices[$i] ?? '') ? trim((string)($prices[$i] ?? '')) : '';
    $qtyRaw = is_scalar($qtys[$i] ?? '') ? trim((string)($qtys[$i] ?? '')) : '';
    if ($sku === null || $name === null) {
      return [[], $label . 'text contains invalid characters.'];
    }

    // Skip completely blank rows
    if ($sku === '' && $name === '' && $priceRaw === '' && ($qtyRaw === '' || $qtyRaw === '0')) {
      continue;
    }

    if ($qtyRaw === '' || !preg_match('/^\d+$/', $qtyRaw)) {
      return [[], $label . 'quantity must be a whole number.'];
    }
    $qty = (int)$qtyRaw;
    if ($qty < 1) {
      return [[], $label . 'quantity must be at least 1.'];
    }
    if ($qty > $maxQty) {
      return [[], $label . 'quantity cannot exceed ' . number_format($maxQty) . '.'];
    }

    // Known SKU: use the catalog's spelling and fill omitted name/price
    $cat = $sku !== '' ? ($catalogBySku[strtolower($sku)] ?? null) : null;
    if ($cat) {
      $sku = $cat['sku'];
      if ($name === '') $name = $cat['name'];
      if ($priceRaw === '') $priceRaw = (string)$cat['sell'];
    }

    if ($name === '') {
      return [[], $label . 'product name is required.'];
    }
    if (order_edit_strlen($name) > 255) {
      return [[], $label . 'product name is too long (max 255 characters).'];
    }
    if (order_edit_strlen($sku) > 64) {
      return [[], $label . 'SKU is too long (max 64 characters).'];
    }
    if ($priceRaw === '' || !is_numeric($priceRaw)) {
      return [[], $label . 'unit price must be a number.'];
    }
    $price = round((float)$priceRaw, 2);
    if ($price < 0) {
      return [[], $label . 'unit price cannot be negative.'];
    }
    if ($price > $maxUnitPrice) {
      return [[], $label . 'unit price is too large.'];
    }

    // Merge identical lines (same sku + name + price)
    $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $key = strtolower($sku) . "\0" . $lower . "\0" . number_format($price, 2, '.', '');
    if (isset($merged[$key])) {
      $merged[$key]['qty'] += $qty;
      if ($merged[$key]['qty'] > $maxQty) {
        return [[], 'Merged quantity exceeds ' . number_format($maxQty) . ' for "' . $name . '".'];
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

  return [array_values($merged), ''];
}

$err = '';
$conflict = false;
$postedItemsForUi = null; // preserve UI on validation error
$postedEditHash = '';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$status = $currentStatus;
$notes = is_array($order) ? (string)($order['notes'] ?? '') : '';

if ($isPost) {
  if (!admin_csrf_check((string)($_POST['csrf'] ?? ''))) { http_response_code(400); echo 'Invalid CSRF'; exit; }
  $status = trim((string)($_POST['status'] ?? $currentStatus));
  if (!in_array($status, $statusOptions, true)) {
    $status = is_array($order) ? $currentStatus : 'new';
  }
  $notes = trim(is_scalar($_POST['notes'] ?? '') ? (string)($_POST['notes'] ?? '') : '');
  $postedEditHash = trim((string)($_POST['edit_hash'] ?? ''));
  $forceOverwrite = (string)($_POST['force_overwrite'] ?? '') === '1';

  // Keep raw rows for redisplay even when parse fails
  $rawCols = [];
  foreach (['item_sku', 'item_name', 'item_price', 'item_qty'] as $col) {
    $rawCols[$col] = is_array($_POST[$col] ?? null) ? array_values($_POST[$col]) : [];
  }
  $rn = min($maxLines, max(array_map('count', $rawCols)));
  $postedItemsForUi = [];
  for ($ri = 0; $ri < $rn; $ri++) {
    $cell = static fn(string $col): string => is_scalar($rawCols[$col][$ri] ?? '') ? (string)($rawCols[$col][$ri] ?? '') : '';
    $postedItemsForUi[] = [
      'sku' => $cell('item_sku'),
      'product_name' => $cell('item_name'),
      'unit_price' => $cell('item_price'),
      'quantity' => $cell('item_qty'),
    ];
  }

  $parsedItems = [];
  $itemErr = '';
  if (!$isLocked) {
    [$parsedItems, $itemErr] = admin_parse_order_items($_POST, $catalogBySku, $maxLines, $maxQty, $maxUnitPrice);
  }

  $customer_id = (int)($_POST['customer_id'] ?? 0);
  $new_name = trim((string)($_POST['new_name'] ?? ''));

  $subtotal = 0.0;
  foreach ($parsedItems as $it) {
    $subtotal += $it['price'] * $it['qty'];
  }
  $subtotal = round($subtotal, 2);
  $total = $subtotal; // admin orders: no shipping/tax layer

  if ($notes !== '' && function_exists('mb_check_encoding') && !mb_check_encoding($notes, 'UTF-8')) {
    $err = 'Notes contain invalid characters.';
  } elseif ($itemErr !== '') {
    $err = $itemErr;
  } elseif (!$isLocked && !$parsedItems && !$isLegacyNoItems) {
    $err = 'Add at least one product to the order.';
  } elseif ($subtotal > $maxOrderTotal) {
    $err = 'Order total is too large.';
  } elseif ($customer_id <= 0 && $new_name === '') {
    $err = 'Select an existing customer or create a new one.';
  } else {
    // Legacy orders saved without product lines keep their manual total until products are added
    $replaceItems = !$isLocked && (bool)$parsedItems;

    try {
      $pdo->beginTransaction();

      $lockedRow = null;
      if ($id) {
        $st = $pdo->prepare('SELECT * FROM orders WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $lockedRow = $st->fetch();
        if (!$lockedRow) {
          throw new RuntimeException('This order was deleted by another admin.');
        }
        $dbItems = order_edit_load_items($pdo, $id);
        if (!$forceOverwrite && ($postedEditHash === '' || !hash_equals(order_edit_fingerprint($lockedRow, $dbItems), $postedEditHash))) {
          throw new RuntimeException('conflict', 409);
        }
        if (in_array(strtolower((string)($lockedRow['status'] ?? '')), $lockedStatuses, true)) {
          $replaceItems = false;
        }
      }

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
        if ($replaceItems) {
          $st = $pdo->prepare('UPDATE orders SET customer_id=?, subtotal=?, total=?, status=?, notes=? WHERE id=?');
          $st->execute([$customer_id, $subtotal, $total, $status, $notes !== '' ? $notes : null, $id]);
          $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$id]);
        } else {
          $st = $pdo->prepare('UPDATE orders SET customer_id=?, status=?, notes=? WHERE id=?');
          $st->execute([$customer_id, $status, $notes !== '' ? $notes : null, $id]);
        }
        // Stale cached PDF no longer matches the order; api/order_pdf rebuilds from the DB
        try {
          $pdo->prepare('UPDATE orders SET pdf_path=NULL, pdf_blob=NULL WHERE id=?')->execute([$id]);
        } catch (Throwable $__) { /* columns may be absent on older DBs */ }
        // Orders created by the old admin form never got an invoice number
        if (trim((string)($lockedRow['invoice_code'] ?? '')) === '') {
          try {
            $ts = strtotime((string)($lockedRow['created_at'] ?? ''));
            $yr = (int)($lockedRow['order_year'] ?? 0) ?: (int)date('Y', $ts ?: time());
            if ($yr < 2000 || $yr > 2100) $yr = (int)date('Y');
            $invoice = allocate_invoice_number($pdo, $yr);
            $pdo->prepare("UPDATE orders SET order_year=?, invoice_seq=?, invoice_code=? WHERE id=? AND (invoice_code IS NULL OR invoice_code='')")
              ->execute([$invoice['year'], $invoice['seq'], $invoice['code'], $id]);
          } catch (Throwable $__) { /* numbering is best-effort for legacy rows */ }
        }
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

      if ($replaceItems || !$order) {
        $ins = $pdo->prepare('INSERT INTO order_items (order_id, sku, product_name, unit_price, quantity) VALUES (?,?,?,?,?)');
        foreach ($parsedItems as $it) {
          $ins->execute([$id, $it['sku'], $it['name'], $it['price'], $it['qty']]);
        }
      }

      $pdo->commit();
      header('Location: ' . $routeBase . '/inventory/dashboard' . $routeExt);
      exit;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if ($e instanceof RuntimeException && $e->getCode() === 409) {
        $conflict = true;
        $err = 'Another admin changed this order after you opened it. Your edits are shown below but were NOT saved. '
          . 'Reload to see their changes, or tick "Save anyway" to replace them with yours.';
      } else {
        $userFacing = $e instanceof RuntimeException && !($e instanceof PDOException);
        $err = 'Could not save order. ' . ($isManager || $userFacing ? $e->getMessage() : 'Please try again.');
      }
    }
  }
}

// Items to render: posted (failed validation) > existing DB > empty
$renderItems = [];
if ($isPost && is_array($postedItemsForUi) && !$isLocked) {
  $renderItems = $postedItemsForUi;
} else {
  foreach ($orderItems as $it) {
    $renderItems[] = [
      'sku' => (string)($it['sku'] ?? ''),
      'product_name' => (string)($it['product_name'] ?? ''),
      'unit_price' => number_format((float)($it['unit_price'] ?? 0), 2, '.', ''),
      'quantity' => (string)(int)($it['quantity'] ?? 1),
    ];
  }
}

$editHash = $isPost ? $postedEditHash : (is_array($order) ? order_edit_fingerprint($order, $orderItems) : '');
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$catalogJson = json_encode(array_values($catalogBySku), $jsonFlags | JSON_INVALID_UTF8_SUBSTITUTE);

$selectedCustomerId = $isPost
  ? (int)($_POST['customer_id'] ?? 0)
  : (is_array($order) ? (int)($order['customer_id'] ?? 0) : 0);
$curStatus = $status;
$curNotes = $notes;
$invoiceLabel = is_array($order) ? format_order_label($order) : '';
$productsUrl = $routeBase . '/inventory/product' . $routeExt;
$quickEditUrl = $routeBase . '/inventory/product_quick_edit' . $routeExt;
$isShipped = is_array($order) && strtolower($currentStatus) === 'shipped';

?><!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/pkslogo.png">
    <link rel="apple-touch-icon" href="/pkslogo.png">
    <title><?= $id ? 'Edit Order #'.$id : 'Create Order' ?></title>
    <style>
      :root{ --bg:#0b1020; --text:#e7eefc; --muted:#9fb4d6; --brand:#e11d48; --brand2:#f97316; --line:rgba(255,255,255,.14); --ok:#34d399; --bad:#fca5a5; --warn:#fbbf24 }
      *{box-sizing:border-box}
      body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
      header{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.1)}
      .wrap{max-width:1100px;margin:0 auto;padding:12px 16px 40px}
      label{display:block;margin-top:8px;color:var(--muted)}
      input,select,textarea{width:100%;padding:10px;border-radius:8px;border:1px solid var(--line);background:rgba(255,255,255,.06);color:var(--text)}
      input[readonly]{opacity:.75}
      .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      @media (max-width:720px){ .row{grid-template-columns:1fr} }
      .btn{appearance:none;border:0;border-radius:8px;padding:8px 12px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
      .btn[disabled]{opacity:.6;cursor:not-allowed}
      .ghost{background:transparent;border:1px solid rgba(255,255,255,.2);color:var(--text)}
      .btn-sm{padding:6px 10px;font-size:12px}
      .btn-xs{padding:2px 8px;font-size:11px;border-radius:6px}
      .btn-danger{background:rgba(248,113,113,.15);border:1px solid rgba(248,113,113,.4);color:#fecaca}
      .err{color:var(--bad);margin-top:8px;padding:10px;border:1px solid rgba(248,113,113,.35);border-radius:8px;background:rgba(248,113,113,.08)}
      .notice{margin-top:8px;padding:10px;border-radius:8px;border:1px solid rgba(251,191,36,.35);background:rgba(251,191,36,.08);color:#fde68a}
      .notice.info{border-color:rgba(159,180,214,.35);background:rgba(159,180,214,.08);color:var(--text)}
      .order-meta{display:flex;gap:10px;align-items:center;flex-wrap:wrap;color:var(--muted);margin-bottom:6px}
      .order-meta form{margin:0}
      details{margin-top:10px}
      summary{cursor:pointer}
      .panel{margin-top:18px;padding:14px;border:1px solid var(--line);border-radius:12px;background:rgba(255,255,255,.03)}
      .panel h2{margin:0 0 6px;font-size:1.05rem}
      .panel .hint, .hint{margin:0 0 12px;color:var(--muted);font-size:12px}
      .add-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px}
      .add-bar .grow{flex:1 1 220px;min-width:180px}
      .add-bar .qty{flex:0 0 90px}
      .items-wrap{overflow-x:auto}
      table.items{width:100%;border-collapse:collapse;min-width:760px}
      table.items th, table.items td{padding:8px 6px;border-bottom:1px solid rgba(255,255,255,.08);text-align:left;vertical-align:top}
      table.items th{color:var(--muted);font-size:12px;font-weight:600}
      table.items input{padding:8px;font-size:13px}
      table.items .sku{width:110px}
      table.items .name{width:auto}
      table.items .price{width:120px}
      table.items .qty{width:80px}
      table.items .line{width:110px;text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;padding-top:16px}
      table.items .act{width:150px;white-space:nowrap}
      table.items .act .btn{margin:2px 0}
      .meta{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:4px;font-size:11px;color:var(--muted)}
      .meta:empty{display:none}
      .badge{display:inline-block;padding:1px 7px;border-radius:999px;font-size:11px;border:1px solid var(--line)}
      .badge.ok{color:var(--ok);border-color:rgba(52,211,153,.4)}
      .badge.warn{color:var(--warn);border-color:rgba(251,191,36,.45)}
      .badge.muted{color:var(--muted)}
      .totals{display:flex;justify-content:flex-end;gap:18px;margin-top:12px;flex-wrap:wrap}
      .totals .box{min-width:140px}
      .totals strong{display:block;color:var(--text);font-size:1.15rem;margin-top:4px}
      .empty-items{color:var(--muted);padding:14px;text-align:center;border:1px dashed var(--line);border-radius:8px}
      .force{display:flex;gap:8px;align-items:center;color:#fde68a}
      .force input{width:auto}
      dialog{border:1px solid var(--line);border-radius:12px;background:#121a31;color:var(--text);max-width:520px;width:calc(100% - 32px);padding:16px}
      dialog::backdrop{background:rgba(0,0,0,.6)}
      dialog h3{margin:0 0 4px}
      dialog .check{display:flex;gap:8px;align-items:center}
      dialog .check input{width:auto}
      .dlg-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;align-items:center}
      .toast{position:fixed;right:16px;bottom:16px;max-width:360px;padding:10px 14px;border-radius:10px;background:#123524;border:1px solid rgba(52,211,153,.45);color:#d1fae5;box-shadow:0 8px 24px rgba(0,0,0,.4)}
    </style>
  </head>
  <body>
    <header>
      <strong><?= $id ? 'Edit Order #'.$id : 'Create Order' ?></strong>
      <div><?php include __DIR__ . '/_nav.php'; ?></div>
    </header>
    <div class="wrap">
      <?php if (is_array($order)): ?>
        <div class="order-meta">
          <span>Invoice <strong><?= $h($invoiceLabel) ?></strong></span>
          <?php if (!empty($order['created_at'])): ?><span>· Placed <?= $h($order['created_at']) ?></span><?php endif; ?>
          <span>· Total ₹<?= number_format((float)($order['total'] ?? 0), 2) ?></span>
          <form method="post" action="<?= $routeBase ?>/api/order_pdf<?= $routeExt ?>" target="_blank">
            <input type="hidden" name="order_id" value="<?= (int)$id ?>">
            <button class="ghost btn btn-sm" type="submit" title="Downloads the invoice as last saved">Download PDF</button>
          </form>
        </div>
      <?php endif; ?>
      <?php if ($err): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>
      <?php if ($isLocked): ?>
        <div class="notice">This order is <strong><?= $h(ucfirst($currentStatus)) ?></strong>, so its products are locked. To change products, set the status back to an open status (e.g. Processing), save, then edit again. Customer, status and notes can still be changed.</div>
      <?php elseif ($isShipped): ?>
        <div class="notice">This order is already shipped. Changing products updates its invoice and total.</div>
      <?php endif; ?>
      <?php if ($isLegacyNoItems && !$isLocked): ?>
        <div class="notice info">This order has no product lines (its total of ₹<?= number_format((float)($order['total'] ?? 0), 2) ?> was entered manually). Saving without products keeps that total; adding products replaces it with the calculated total.</div>
      <?php endif; ?>

      <form method="post" id="orderForm" novalidate>
        <input type="hidden" name="csrf" value="<?= $h(admin_csrf_token()) ?>">
        <input type="hidden" name="edit_hash" value="<?= $h($editHash) ?>">
        <label>Existing Customer
          <select name="customer_id" id="customer_id">
            <option value="0">-- Select --</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $selectedCustomerId===(int)$c['id']?'selected':'' ?>>#<?= (int)$c['id'] ?> — <?= $h($c['name']) ?> (<?= $h($c['phone']) ?>, <?= $h($c['city']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>

        <details<?= ($err && trim((string)($_POST['new_name'] ?? '')) !== '') ? ' open' : '' ?>>
          <summary>Or create new customer</summary>
          <div class="row">
            <label>Name<input name="new_name" value="<?= $h($_POST['new_name'] ?? '') ?>"></label>
            <label>Phone<input name="new_phone" value="<?= $h($_POST['new_phone'] ?? '') ?>"></label>
          </div>
          <div class="row">
            <label>Email<input type="email" name="new_email" value="<?= $h($_POST['new_email'] ?? '') ?>"></label>
            <label>Pincode<input name="new_pincode" value="<?= $h($_POST['new_pincode'] ?? '') ?>"></label>
          </div>
          <label>Address line 1<input name="new_address1" value="<?= $h($_POST['new_address1'] ?? '') ?>"></label>
          <label>Address line 2<input name="new_address2" value="<?= $h($_POST['new_address2'] ?? '') ?>"></label>
          <div class="row">
            <label>City<input name="new_city" value="<?= $h($_POST['new_city'] ?? '') ?>"></label>
            <label>State<input name="new_state" value="<?= $h($_POST['new_state'] ?? '') ?>"></label>
          </div>
        </details>

        <div class="panel">
          <h2>Products</h2>
          <?php if ($isLocked): ?>
            <p class="hint">Products are read-only while the order is <?= $h($currentStatus) ?>.<?= $isManager ? ' You can still edit the catalog product.' : '' ?></p>
          <?php else: ?>
            <p class="hint">Add products from the catalog or a custom line. Edit quantity and unit price; totals recalculate automatically. Identical lines are merged on save. Catalog price changes never reprice this order — use “Apply catalog price” on a line to update it.</p>

            <div class="add-bar">
              <label class="grow">Product
                <input type="search" id="productSearch" placeholder="Search by name, SKU, or category…" autocomplete="off" list="productSuggestions">
                <datalist id="productSuggestions"></datalist>
                <select id="productPick" size="1">
                  <option value="">— Select product —</option>
                  <?php foreach ($pickerProducts as $p):
                    $label = $p['sku'] . ' — ' . $p['name'] . ' (₹' . number_format($p['sell'], 2) . ')';
                  ?>
                    <option value="<?= $h($p['sku']) ?>" data-search="<?= $h(strtolower($p['sku'] . ' ' . $p['name'] . ' ' . $p['category'])) ?>"><?= $h($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="qty">Qty
                <input type="number" id="addQty" min="1" max="<?= $maxQty ?>" step="1" value="1">
              </label>
              <button type="button" class="btn" id="btnAddProduct">Add</button>
              <button type="button" class="ghost btn" id="btnAddCustom">Custom line</button>
            </div>
          <?php endif; ?>

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
                  $line = (is_numeric($it['unit_price']) ? (float)$it['unit_price'] : 0) * (int)$it['quantity'];
                  $ro = $isLocked ? ' readonly' : '';
                ?>
                <tr>
                  <td><input class="sku" name="item_sku[]" value="<?= $h($it['sku']) ?>" maxlength="64" placeholder="SKU"<?= $ro ?>></td>
                  <td><input class="name" name="item_name[]" value="<?= $h($it['product_name']) ?>" maxlength="255" required placeholder="Product name"<?= $ro ?>><div class="meta meta-name"></div></td>
                  <td><input class="price" type="number" name="item_price[]" value="<?= $h($it['unit_price']) ?>" min="0" max="<?= $maxUnitPrice ?>" step="0.01" required<?= $ro ?>><div class="meta meta-price"></div></td>
                  <td><input class="qty" type="number" name="item_qty[]" value="<?= $h($it['quantity']) ?>" min="1" max="<?= $maxQty ?>" step="1" required<?= $ro ?>></td>
                  <td class="line">₹<?= number_format($line, 2) ?></td>
                  <td class="act">
                    <button type="button" class="ghost btn btn-sm btn-edit-product" hidden>Edit product</button>
                    <?php if (!$isLocked): ?><button type="button" class="ghost btn btn-sm btn-danger btn-remove">Remove</button><?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <div class="empty-items" id="emptyItems" <?= $renderItems ? 'hidden' : '' ?>><?= $isLocked ? 'This order has no products.' : 'No products yet. Select a product above and click Add.' ?></div>
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
              <?php foreach ($statusOptions as $s): ?>
                <option value="<?= $h($s) ?>" <?= $curStatus===$s?'selected':'' ?>><?= $h(ucfirst($s)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Notes<textarea name="notes" rows="2"><?= $h($curNotes) ?></textarea></label>
        </div>

        <?php if ($conflict): ?>
          <label class="force"><input type="checkbox" name="force_overwrite" value="1"> Save anyway — replace the other admin’s changes with mine</label>
        <?php endif; ?>

        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn" type="submit" id="btnSave">Save</button>
          <?php if ($conflict && $id): ?>
            <a class="ghost btn" href="<?= $routeBase ?>/inventory/order_edit<?= $routeExt ?>?id=<?= (int)$id ?>">Reload latest (discard my edits)</a>
          <?php endif; ?>
          <a class="ghost btn" href="<?= $routeBase ?>/inventory/dashboard<?=$routeExt ?>">Cancel</a>
        </div>
      </form>
    </div>

    <?php if ($isManager): ?>
    <dialog id="productDialog" aria-labelledby="pdTitle">
      <form id="productForm" method="dialog" novalidate>
        <h3 id="pdTitle">Edit catalog product</h3>
        <p class="hint">Changes apply to the shop and future orders. Existing orders — including this one — keep their saved prices.</p>
        <input type="hidden" name="sku" id="pdSku">
        <input type="hidden" name="fingerprint" id="pdFingerprint">
        <label>SKU<input id="pdSkuShow" readonly></label>
        <label>Name<input name="name" id="pdName" maxlength="255" required></label>
        <div class="row">
          <label>Category<input name="category" id="pdCategory" maxlength="190" list="pdCategoryList" required></label>
          <label>Unit<input name="unit" id="pdUnit" maxlength="64" placeholder="Box"></label>
        </div>
        <datalist id="pdCategoryList">
          <?php foreach ($categoryNames as $cn): ?><option value="<?= $h($cn) ?>"></option><?php endforeach; ?>
        </datalist>
        <div class="row">
          <label>List price (₹)<input type="number" name="price" id="pdPrice" min="0.01" step="0.01" required></label>
          <label>Discount %<input type="number" name="discount_pct" id="pdDiscount" min="0" max="100" step="0.01"></label>
        </div>
        <p class="hint" style="margin-top:6px">Selling price: <strong id="pdSell">₹0.00</strong></p>
        <label class="check"><input type="checkbox" id="pdActive"> Active (shown in shop and product picker)</label>
        <p class="hint" id="pdUsage" style="margin-top:8px"></p>
        <div class="err" id="pdErr" hidden></div>
        <div class="dlg-actions">
          <button class="btn" type="submit" id="pdSave">Save product</button>
          <button class="ghost btn" type="button" id="pdCancel">Cancel</button>
          <a class="ghost btn btn-sm" id="pdFullLink" href="<?= $h($productsUrl) ?>" target="_blank" rel="noopener">Open in Products ↗</a>
        </div>
      </form>
    </dialog>
    <?php endif; ?>

    <script>
    (function () {
      var catalog = <?= $catalogJson ?: '[]' ?>;
      var isManager = <?= $isManager ? 'true' : 'false' ?>;
      var locked = <?= $isLocked ? 'true' : 'false' ?>;
      var csrf = <?= json_encode(admin_csrf_token(), $jsonFlags) ?>;
      var quickEditUrl = <?= json_encode($quickEditUrl, $jsonFlags) ?>;
      var productsUrl = <?= json_encode($productsUrl, $jsonFlags) ?>;
      var MAX_QTY = <?= (int)$maxQty ?>;
      var catalogMap = {};
      catalog.forEach(function (p) { catalogMap[p.sku.toLowerCase()] = p; });

      var body = document.getElementById('itemsBody');
      var table = document.getElementById('itemsTable');
      var empty = document.getElementById('emptyItems');
      var pick = document.getElementById('productPick');
      var search = document.getElementById('productSearch');
      var addQty = document.getElementById('addQty');
      var suggestions = document.getElementById('productSuggestions');
      var form = document.getElementById('orderForm');
      var dirty = <?= ($isPost && $err) ? 'true' : 'false' ?>;
      var pdErr = function () {}, pdBusy = function () {}, fillProduct = function () {};

      function money(n) {
        return '₹' + (Math.round(n * 100) / 100).toFixed(2);
      }
      function field(tr, name) { return tr.querySelector('input[name="' + name + '[]"]'); }
      function lookup(sku) { sku = (sku || '').trim().toLowerCase(); return sku ? (catalogMap[sku] || null) : null; }

      function syncEmpty() {
        var has = body.children.length > 0;
        table.hidden = !has;
        empty.hidden = has;
      }

      function recalc() {
        var sum = 0;
        Array.prototype.forEach.call(body.querySelectorAll('tr'), function (tr) {
          var price = parseFloat(field(tr, 'item_price').value) || 0;
          var qty = parseInt(field(tr, 'item_qty').value, 10) || 0;
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

      function badge(host, text, kind, title) {
        var b = document.createElement('span');
        b.className = 'badge ' + (kind || 'muted');
        b.textContent = text;
        if (title) b.title = title;
        host.appendChild(b);
      }
      function miniButton(host, text, cls, title) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ghost btn btn-xs ' + cls;
        b.textContent = text;
        if (title) b.title = title;
        host.appendChild(b);
      }
      function note(host, text) {
        var s = document.createElement('span');
        s.textContent = text;
        host.appendChild(s);
      }

      function decorateAll() {
        var rows = body.querySelectorAll('tr');
        var counts = {};
        Array.prototype.forEach.call(rows, function (tr) {
          var k = field(tr, 'item_sku').value.trim().toLowerCase();
          if (k) counts[k] = (counts[k] || 0) + 1;
        });
        Array.prototype.forEach.call(rows, function (tr) {
          var sku = field(tr, 'item_sku').value.trim();
          var name = field(tr, 'item_name').value.trim();
          var priceStr = field(tr, 'item_price').value;
          var price = parseFloat(priceStr);
          var p = lookup(sku);
          var nameMeta = tr.querySelector('.meta-name');
          var priceMeta = tr.querySelector('.meta-price');
          nameMeta.textContent = '';
          priceMeta.textContent = '';

          if (!sku) {
            badge(nameMeta, 'Custom line', 'muted', 'No SKU — saved exactly as entered.');
          } else if (!p) {
            badge(nameMeta, 'Not in catalog', 'warn', 'SKU ' + sku + ' is not in the product catalog (deleted, renamed or custom). The line is saved as entered.');
          } else {
            if (!p.active) badge(nameMeta, 'Archived in catalog', 'warn', 'Hidden from the shop. The line is kept as entered.');
            if (name && name !== p.name) {
              note(nameMeta, 'Catalog: ' + p.name);
              if (!locked) miniButton(nameMeta, 'Use catalog name', 'btn-use-name');
            }
            if (priceStr === '' || isNaN(price) || Math.abs(price - p.sell) >= 0.005) {
              note(priceMeta, 'Catalog ' + money(p.sell));
              if (!locked) miniButton(priceMeta, 'Apply catalog price', 'btn-apply-price', 'Set this line to the current catalog selling price');
            } else {
              badge(priceMeta, 'Catalog price', 'ok');
            }
          }
          if (sku && counts[sku.toLowerCase()] > 1) {
            badge(nameMeta, 'Duplicate SKU', 'warn', 'This SKU is on more than one line. Lines with the same name and price are merged on save.');
          }
          var editBtn = tr.querySelector('.btn-edit-product');
          if (editBtn) editBtn.hidden = !(isManager && p);
        });
      }

      function refresh() { syncEmpty(); recalc(); decorateAll(); }

      function findRowBySku(sku) {
        var key = (sku || '').trim().toLowerCase();
        if (!key) return null;
        var rows = body.querySelectorAll('tr');
        for (var i = 0; i < rows.length; i++) {
          if (field(rows[i], 'item_sku').value.trim().toLowerCase() === key) return rows[i];
        }
        return null;
      }

      function addRow(sku, name, price, qty) {
        sku = (sku || '').trim();
        name = (name || '').trim();
        price = Math.round((parseFloat(price) || 0) * 100) / 100;
        qty = parseInt(qty, 10) || 1;
        if (qty < 1) qty = 1;
        if (qty > MAX_QTY) qty = MAX_QTY;

        // Merge into existing SKU row when present
        if (sku) {
          var existing = findRowBySku(sku);
          if (existing) {
            var qInp = field(existing, 'item_qty');
            var next = (parseInt(qInp.value, 10) || 0) + qty;
            if (next > MAX_QTY) next = MAX_QTY;
            qInp.value = next;
            // Keep existing unit price (admin may have overridden)
            dirty = true;
            refresh();
            return;
          }
        }

        var tr = document.createElement('tr');
        tr.innerHTML =
          '<td><input class="sku" name="item_sku[]" value="" maxlength="64" placeholder="SKU"></td>' +
          '<td><input class="name" name="item_name[]" value="" maxlength="255" required placeholder="Product name"><div class="meta meta-name"></div></td>' +
          '<td><input class="price" type="number" name="item_price[]" value="0" min="0" max="<?= $maxUnitPrice ?>" step="0.01" required><div class="meta meta-price"></div></td>' +
          '<td><input class="qty" type="number" name="item_qty[]" value="1" min="1" max="' + MAX_QTY + '" step="1" required></td>' +
          '<td class="line">₹0.00</td>' +
          '<td class="act"><button type="button" class="ghost btn btn-sm btn-edit-product" hidden>Edit product</button> ' +
          '<button type="button" class="ghost btn btn-sm btn-danger btn-remove">Remove</button></td>';
        field(tr, 'item_sku').value = sku;
        field(tr, 'item_name').value = name;
        field(tr, 'item_price').value = price.toFixed(2);
        field(tr, 'item_qty').value = qty;
        body.appendChild(tr);
        dirty = true;
        refresh();
      }

      body.addEventListener('input', function () { dirty = true; recalc(); decorateAll(); });
      body.addEventListener('change', function (e) {
        var t = e.target;
        if (!locked && t && t.name === 'item_sku[]') {
          // Typing a known SKU fills only empty fields; it never overwrites a name/price the admin entered
          var tr = t.closest('tr');
          var p = lookup(t.value);
          if (p) {
            t.value = p.sku;
            var n = field(tr, 'item_name'), pr = field(tr, 'item_price');
            if (!n.value.trim()) n.value = p.name;
            if (!pr.value || parseFloat(pr.value) === 0) pr.value = p.sell.toFixed(2);
          }
        }
        recalc();
        decorateAll();
      });
      body.addEventListener('click', function (e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        var tr = btn.closest('tr');
        var p = lookup(field(tr, 'item_sku').value);
        if (btn.classList.contains('btn-remove')) {
          tr.remove();
          dirty = true;
          refresh();
        } else if (btn.classList.contains('btn-apply-price') && p) {
          field(tr, 'item_price').value = p.sell.toFixed(2);
          dirty = true;
          refresh();
        } else if (btn.classList.contains('btn-use-name') && p) {
          field(tr, 'item_name').value = p.name;
          dirty = true;
          refresh();
        } else if (btn.classList.contains('btn-edit-product') && p) {
          openProductDialog(p.sku);
        }
      });

      function filterProducts() {
        if (!pick) return;
        var q = (search.value || '').trim().toLowerCase();
        var opts = pick.querySelectorAll('option');
        var matchCount = 0;
        suggestions.innerHTML = '';
        var picked = null;
        opts.forEach(function (opt, idx) {
          if (idx === 0) { opt.hidden = false; return; }
          var hay = opt.getAttribute('data-search') || '';
          var isLabel = q !== '' && opt.textContent.toLowerCase() === q; // chosen from the suggestion list
          if (isLabel) picked = opt.value;
          var show = !q || isLabel || hay.indexOf(q) !== -1;
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
        // Auto-select when typing an exact active SKU
        var exact = lookup(q);
        if (picked) {
          pick.value = picked;
        } else if (q && exact && exact.active) {
          pick.value = exact.sku;
        }
      }

      if (pick) {
        document.getElementById('btnAddProduct').addEventListener('click', function () {
          var p = lookup(pick.value);
          if (!p) {
            alert('Select a product first.');
            return;
          }
          addRow(p.sku, p.name, p.sell, addQty.value);
          pick.value = '';
          search.value = '';
          filterProducts();
          addQty.value = '1';
        });

        document.getElementById('btnAddCustom').addEventListener('click', function () {
          addRow('', '', 0, addQty.value || 1);
          var last = body.lastElementChild;
          if (last) field(last, 'item_name').focus();
        });

        search.addEventListener('input', filterProducts);
        search.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('btnAddProduct').click();
          }
        });
      }

      refresh();

      form.addEventListener('input', function () { dirty = true; });
      form.addEventListener('submit', function (e) {
        if (!locked && !body.children.length && !<?= $isLegacyNoItems ? 'true' : 'false' ?>) {
          e.preventDefault();
          alert('Add at least one product before saving.');
          return;
        }
        var cust = parseInt(document.getElementById('customer_id').value, 10) || 0;
        var newName = (document.querySelector('input[name="new_name"]').value || '').trim();
        if (cust <= 0 && newName === '') {
          e.preventDefault();
          alert('Select an existing customer or enter a new customer name.');
          return;
        }
        recalc();
        dirty = false;
        document.getElementById('btnSave').disabled = true;
      });
      window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
      });

      // ---- Catalog quick edit (managers) ----
      var dlg = document.getElementById('productDialog');
      function openProductDialog(sku) {
        if (!dlg) return;
        if (typeof dlg.showModal !== 'function') {
          window.open(productsUrl + '?status=all&q=' + encodeURIComponent(sku), '_blank');
          return;
        }
        pdErr('');
        pdBusy(true);
        document.getElementById('pdSkuShow').value = sku;
        document.getElementById('pdUsage').textContent = 'Loading…';
        document.getElementById('pdFullLink').href = productsUrl + '?status=all&q=' + encodeURIComponent(sku);
        dlg.showModal();
        request('GET', quickEditUrl + '?sku=' + encodeURIComponent(sku)).then(function (res) {
          if (res.ok) { fillProduct(res.product); pdBusy(false); }
          else { document.getElementById('pdUsage').textContent = ''; pdErr(res.error); }
        });
      }

      function request(method, url, data) {
        var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (data) opts.body = data;
        return fetch(url, opts).then(function (r) {
          return r.text().then(function (t) {
            try { var j = JSON.parse(t); j.status = r.status; return j; }
            catch (_) { return { ok: false, status: r.status, error: r.status === 200 ? 'Session expired — sign in again in another tab, then retry.' : 'Server error (' + r.status + ').' }; }
          });
        }).catch(function () { return { ok: false, error: 'Network error — check your connection and retry.' }; });
      }

      if (dlg) {
        var pdForm = document.getElementById('productForm');
        var pdPrice = document.getElementById('pdPrice');
        var pdDiscount = document.getElementById('pdDiscount');

        pdErr = function (msg) {
          var el = document.getElementById('pdErr');
          el.textContent = msg || '';
          el.hidden = !msg;
        };
        pdBusy = function (on) { document.getElementById('pdSave').disabled = !!on; };
        var updateSell = function () {
          var lp = parseFloat(pdPrice.value) || 0;
          var d = Math.min(100, Math.max(0, parseFloat(pdDiscount.value) || 0));
          document.getElementById('pdSell').textContent = money(Math.max(0, lp * (1 - d / 100)));
        };
        fillProduct = function (p) {
          document.getElementById('pdSku').value = p.sku;
          document.getElementById('pdSkuShow').value = p.sku;
          document.getElementById('pdFingerprint').value = p.fingerprint;
          document.getElementById('pdName').value = p.name;
          document.getElementById('pdCategory').value = p.category;
          document.getElementById('pdUnit').value = p.unit;
          pdPrice.value = Number(p.price).toFixed(2);
          pdDiscount.value = Number(p.discount_pct);
          document.getElementById('pdActive').checked = !!p.is_active;
          var n = typeof p.orders_using === 'number' ? p.orders_using : -1;
          document.getElementById('pdUsage').textContent = n >= 0
            ? 'Used on ' + n + ' order' + (n === 1 ? '' : 's') + '. Their saved prices will not change.'
            : '';
          updateSell();
        };
        pdPrice.addEventListener('input', updateSell);
        pdDiscount.addEventListener('input', updateSell);
        document.getElementById('pdCancel').addEventListener('click', function () { dlg.close(); });

        pdForm.addEventListener('submit', function (e) {
          e.preventDefault();
          var name = document.getElementById('pdName').value.trim();
          var cat = document.getElementById('pdCategory').value.trim();
          var lp = parseFloat(pdPrice.value);
          var d = pdDiscount.value === '' ? 0 : parseFloat(pdDiscount.value);
          if (!name) return pdErr('Name is required.');
          if (!cat) return pdErr('Category is required.');
          if (!(lp > 0)) return pdErr('Price must be greater than 0.');
          if (isNaN(d) || d < 0 || d > 100) return pdErr('Discount must be between 0 and 100.');
          pdErr('');
          pdBusy(true);
          var fd = new FormData(pdForm);
          fd.append('csrf', csrf);
          fd.append('is_active', document.getElementById('pdActive').checked ? '1' : '0');
          request('POST', quickEditUrl, fd).then(function (res) {
            pdBusy(false);
            if (res.ok) {
              applyCatalogUpdate(res.product);
              dlg.close();
              toast(res.unchanged ? 'No changes to save.' : 'Product ' + res.product.sku + ' updated. This order’s prices were not changed.');
            } else {
              if (res.conflict && res.product) fillProduct(res.product);
              pdErr(res.error || 'Save failed.');
            }
          });
        });
      }

      function applyCatalogUpdate(p) {
        var entry = { sku: p.sku, name: p.name, unit: p.unit, price: p.price, discount_pct: p.discount_pct, sell: p.sell, category: p.category, active: !!p.is_active };
        catalogMap[p.sku.toLowerCase()] = entry;
        if (pick) {
          var opt = null;
          Array.prototype.forEach.call(pick.options, function (o) { if (o.value === p.sku) opt = o; });
          if (!entry.active) {
            if (opt) opt.remove();
          } else {
            if (!opt) { opt = document.createElement('option'); opt.value = p.sku; pick.appendChild(opt); }
            opt.textContent = p.sku + ' — ' + p.name + ' (' + money(p.sell) + ')';
            opt.setAttribute('data-search', (p.sku + ' ' + p.name + ' ' + p.category).toLowerCase());
          }
        }
        var cl = document.getElementById('pdCategoryList');
        if (cl && p.category && !Array.prototype.some.call(cl.options, function (o) { return o.value.toLowerCase() === p.category.toLowerCase(); })) {
          var co = document.createElement('option'); co.value = p.category; cl.appendChild(co);
        }
        decorateAll();
      }

      function toast(msg) {
        var t = document.createElement('div');
        t.className = 'toast';
        t.setAttribute('role', 'status');
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 4000);
      }
    })();
    </script>
  </body>
</html>
