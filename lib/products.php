<?php
/**
 * Product catalog helpers (active / archived for public pricing list).
 */

function ensure_products_catalog_schema(PDO $pdo): void {
  static $done = false;
  if ($done) return;
  try {
    $pdo->exec("ALTER TABLE products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
  } catch (Throwable $__) { /* already exists */ }
  try {
    $pdo->exec("CREATE INDEX idx_products_is_active ON products (is_active)");
  } catch (Throwable $__) { /* already exists */ }
  // Allow fractional discount percentages (e.g. 79.58)
  try {
    $pdo->exec("ALTER TABLE products MODIFY COLUMN discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0");
  } catch (Throwable $__) { /* ignore if table missing / unsupported */ }
  $done = true;
}

/** Format discount % for display (trim trailing zeros: 80, 79.58). */
function format_discount_pct($pct): string {
  $f = (float)$pct;
  $s = number_format($f, 2, '.', '');
  $s = rtrim(rtrim($s, '0'), '.');
  return $s === '' ? '0' : $s;
}

/** Normalize optional unit to DB-safe non-null value. */
function product_unit_or_default(?string $unit, string $default = '1 Box'): string {
  $u = trim((string)$unit);
  if ($u === '') $u = $default;
  if (function_exists('mb_substr')) {
    return mb_substr($u, 0, 64);
  }
  return substr($u, 0, 64);
}

/** Selling price after discount (what the shop charges per unit). */
function product_sell_price($listPrice, $discountPct): float {
  $list = (float)$listPrice;
  $disc = max(0.0, min(100.0, (float)$discountPct));
  return round(max(0.0, $list * (1 - $disc / 100)), 2);
}

/**
 * Stable fingerprint of the editable catalog fields, used to detect that another
 * admin changed the product between loading a form and saving it.
 */
function product_fingerprint(array $row): string {
  return sha1(implode("\0", [
    (string)($row['sku'] ?? ''),
    (string)($row['name'] ?? ''),
    (string)($row['category'] ?? ''),
    (string)($row['unit'] ?? ''),
    number_format((float)($row['price'] ?? 0), 2, '.', ''),
    number_format((float)($row['discount_pct'] ?? 0), 2, '.', ''),
    (string)(int)($row['is_active'] ?? 1),
  ]));
}

/** Drop cached catalog files so the shop/pricing list shows edits immediately. */
function products_invalidate_shop_cache(bool $categoriesToo = false): void {
  $dir = __DIR__ . '/../storage/cache/';
  $files = ['shop_products.cache.php'];
  if ($categoriesToo) $files[] = 'categories.cache.php';
  foreach ($files as $f) {
    if (is_file($dir . $f)) @unlink($dir . $f);
  }
}
