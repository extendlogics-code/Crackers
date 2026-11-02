<?php
// Catalog quick edit used from the order edit page (managers only).
// GET  ?sku=X                → current product + fingerprint
// POST csrf, sku, fingerprint, name, category, unit, price, discount_pct, is_active
// Never touches order_items: existing orders keep the prices they were sold at.
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/products.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function pqe_out(int $code, array $body): void {
  http_response_code($code);
  echo json_encode($body, JSON_UNESCAPED_UNICODE);
  exit;
}

function pqe_product_payload(array $row, int $ordersUsing = -1): array {
  $out = [
    'sku' => (string)$row['sku'],
    'name' => (string)$row['name'],
    'category' => (string)($row['category'] ?? ''),
    'unit' => (string)($row['unit'] ?? ''),
    'price' => round((float)$row['price'], 2),
    'discount_pct' => round((float)($row['discount_pct'] ?? 0), 2),
    'sell' => product_sell_price($row['price'], $row['discount_pct'] ?? 0),
    'is_active' => (int)($row['is_active'] ?? 1) === 1,
    'fingerprint' => product_fingerprint($row),
  ];
  if ($ordersUsing >= 0) $out['orders_using'] = $ordersUsing;
  return $out;
}

/** Trimmed, control-char-free UTF-8 text; null when encoding is invalid. */
function pqe_clean_text($v): ?string {
  $s = trim((string)$v);
  if ($s !== '' && function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) return null;
  $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
  return $s === null ? null : trim($s);
}

function pqe_len(string $s): int {
  return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

admin_session_start();
if (!admin_logged_in()) {
  pqe_out(401, ['ok' => false, 'error' => 'Your session expired. Sign in again, then retry.']);
}
if (!admin_is_manager()) {
  pqe_out(403, ['ok' => false, 'error' => 'Only admins can edit catalog products.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sku = trim((string)($method === 'POST' ? ($_POST['sku'] ?? '') : ($_GET['sku'] ?? '')));
if ($sku === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sku)) {
  pqe_out(400, ['ok' => false, 'error' => 'Invalid product SKU.']);
}

try {
  $pdo = get_pdo();
  ensure_products_catalog_schema($pdo);
} catch (Throwable $e) {
  pqe_out(500, ['ok' => false, 'error' => 'Database unavailable.']);
}

$select = 'SELECT id, sku, name, category, unit, price, discount_pct, COALESCE(is_active, 1) AS is_active FROM products WHERE sku = ? LIMIT 1';

$countOrders = static function (PDO $pdo, string $sku): int {
  try {
    $st = $pdo->prepare('SELECT COUNT(DISTINCT order_id) FROM order_items WHERE sku = ?');
    $st->execute([$sku]);
    return (int)$st->fetchColumn();
  } catch (Throwable $__) {
    return -1;
  }
};

if ($method !== 'POST') {
  $st = $pdo->prepare($select);
  $st->execute([$sku]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    pqe_out(404, ['ok' => false, 'error' => 'Product ' . $sku . ' is no longer in the catalog (it may have been deleted).']);
  }
  pqe_out(200, ['ok' => true, 'product' => pqe_product_payload($row, $countOrders($pdo, $sku))]);
}

if (!admin_csrf_check((string)($_POST['csrf'] ?? ''))) {
  pqe_out(400, ['ok' => false, 'error' => 'Security token expired. Reload the page and try again.']);
}

$name = pqe_clean_text($_POST['name'] ?? '');
$category = pqe_clean_text($_POST['category'] ?? '');
$unit = pqe_clean_text($_POST['unit'] ?? '');
$priceRaw = trim((string)($_POST['price'] ?? ''));
$discRaw = trim((string)($_POST['discount_pct'] ?? ''));
$isActive = (string)($_POST['is_active'] ?? '1') === '1' ? 1 : 0;
$fingerprint = trim((string)($_POST['fingerprint'] ?? ''));

if ($name === null || $category === null || $unit === null) {
  pqe_out(422, ['ok' => false, 'error' => 'Text contains invalid characters.']);
}
if ($unit === '') $unit = 'Box';
if ($discRaw === '') $discRaw = '0';

$err = '';
if ($name === '') {
  $err = 'Name is required.';
} elseif (pqe_len($name) > 255) {
  $err = 'Name is too long (max 255 characters).';
} elseif ($category === '') {
  $err = 'Category is required.';
} elseif (pqe_len($category) > 190) {
  $err = 'Category is too long (max 190 characters).';
} elseif (pqe_len($unit) > 64) {
  $err = 'Unit is too long (max 64 characters).';
} elseif (!is_numeric($priceRaw) || (float)$priceRaw <= 0) {
  $err = 'Price must be a number greater than 0.';
} elseif ((float)$priceRaw > 99999999.99) {
  $err = 'Price is too large.';
} elseif (!is_numeric($discRaw) || (float)$discRaw < 0 || (float)$discRaw > 100) {
  $err = 'Discount must be between 0 and 100.';
}
if ($err !== '') {
  pqe_out(422, ['ok' => false, 'error' => $err]);
}
$price = round((float)$priceRaw, 2);
$discount = round((float)$discRaw, 2);

$newCategory = false;
try {
  $pdo->beginTransaction();
  $st = $pdo->prepare($select . ' FOR UPDATE');
  $st->execute([$sku]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $pdo->rollBack();
    pqe_out(404, ['ok' => false, 'error' => 'Product ' . $sku . ' is no longer in the catalog (it may have been deleted).']);
  }
  if ($fingerprint === '' || !hash_equals(product_fingerprint($row), $fingerprint)) {
    $pdo->rollBack();
    pqe_out(409, [
      'ok' => false,
      'conflict' => true,
      'error' => 'Someone else changed this product since you opened it. The latest values are now shown — review and save again.',
      'product' => pqe_product_payload($row, $countOrders($pdo, $sku)),
    ]);
  }

  $unchanged = (string)$row['name'] === $name
    && (string)$row['category'] === $category
    && (string)$row['unit'] === $unit
    && round((float)$row['price'], 2) === $price
    && round((float)$row['discount_pct'], 2) === $discount
    && (int)$row['is_active'] === $isActive;

  if (!$unchanged) {
    try {
      $chk = $pdo->prepare('SELECT id FROM categories WHERE name = ? LIMIT 1');
      $chk->execute([$category]);
      if (!$chk->fetch()) {
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $category), '-')) ?: substr(sha1($category), 0, 8);
        $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE slug=VALUES(slug)')->execute([$category, $slug]);
        $newCategory = true;
      }
    } catch (Throwable $__) { /* categories table optional */ }

    $u = $pdo->prepare('UPDATE products SET name=?, category=?, unit=?, price=?, discount_pct=?, is_active=? WHERE id=?');
    $u->execute([$name, $category, $unit, $price, $discount, $isActive, (int)$row['id']]);
  }
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  pqe_out(500, ['ok' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
}

if (!$unchanged) {
  products_invalidate_shop_cache($newCategory || (string)$row['category'] !== $category);
}

$st = $pdo->prepare($select);
$st->execute([$sku]);
$fresh = $st->fetch(PDO::FETCH_ASSOC) ?: $row;
pqe_out(200, [
  'ok' => true,
  'unchanged' => $unchanged,
  'product' => pqe_product_payload($fresh, $countOrders($pdo, $sku)),
]);
