<?php
/**
 * Price-list PDF + CSV sync helpers for admin Products.
 *
 * Hostinger has no OCR. Primary path: upload Karthi-style PDF alone and apply
 * the bundled / last-applied canonical list (data/psk_2026_canonical_price_list.json
 * or storage/price_lists/last_canonical.json). CSV remains an override for new editions.
 */

require_once __DIR__ . '/products.php';

const PRICE_LIST_MAX_PDF_BYTES = 20971520; // 20 MB
const PRICE_LIST_MAX_CSV_BYTES = 10485760; // 10 MB
const PRICE_LIST_DEFAULT_DISCOUNT = 80;
const PRICE_LIST_DEFAULT_UNIT = '1 Box';

function price_list_storage_dir(): string {
  $dir = realpath(__DIR__ . '/..') . '/storage/price_lists';
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  return $dir;
}

function price_list_ensure_schema(PDO $pdo): void {
  ensure_products_catalog_schema($pdo);
  $pdo->exec("CREATE TABLE IF NOT EXISTS product_pdf_map (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pdf_name_norm VARCHAR(255) NOT NULL,
    sku VARCHAR(64) NOT NULL,
    pdf_name_raw VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pdf_name (pdf_name_norm),
    KEY idx_sku (sku)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Collapse whitespace / punctuation for fuzzy name matching. */
function price_list_normalize_name(string $name): string {
  $n = trim($name);
  if (function_exists('mb_strtoupper')) {
    $n = mb_strtoupper($n, 'UTF-8');
  } else {
    $n = strtoupper($n);
  }
  // Normalize common OCR / typography variants
  $n = str_replace(["\xC2\xA0", '–', '—', '−', '’', '‘', '"', '"', '"'], [' ', '-', '-', '-', "'", "'", '"', '"', '"'], $n);
  $n = preg_replace('/\s+/u', ' ', $n) ?? $n;
  $n = preg_replace('/[^A-Z0-9\s\-\/\.\(\)&+]/u', '', $n) ?? $n;
  $n = preg_replace('/\s+/u', ' ', $n) ?? $n;
  return trim($n);
}

function price_list_is_no_discount_item(string $name, string $category = ''): bool {
  $hay = price_list_normalize_name($name . ' ' . $category);
  if ($hay === '') return false;
  $needles = [
    'GIFT BOX', 'GIFTBOX', 'FAMILY PACK', 'FAMILY -', 'FAMILY PACKS',
    'PSK FAMILY', 'NO DISCOUNT', 'DISKCOUNT ILLAI', // OCR noise for Tamil note
  ];
  foreach ($needles as $n) {
    if (str_contains($hay, $n)) return true;
  }
  // Category-only gift / family packs
  $cat = price_list_normalize_name($category);
  if ($cat !== '' && (str_contains($cat, 'GIFT') || str_contains($cat, 'FAMILY'))) {
    return true;
  }
  return false;
}

/** Map category name → SKU prefix used by this catalog. */
function price_list_sku_prefix_for_category(string $category): string {
  $c = price_list_normalize_name($category);
  $map = [
    'SINGLE CRACKERS' => 'SC',
    'PAPER BOMB' => 'PB',
    'CHORSA AND GAINT CRACKERS' => 'CGC',
    'CHORSA AND GIANT CRACKERS' => 'CGC',
    'CHORSA & GIANT' => 'CGC',
    'DELUXE CRACKERS' => 'DC',
    'GARLAND WALA' => 'GW',
    'BIJILI CRACKERS' => 'BC',
    'TWINKLING STAR' => 'TW',
    'GROUND CHAKKAR' => 'GC',
    'FLOWERPOTS' => 'FP',
    'FLOWER POTS' => 'FP',
    'ROCKET' => 'RB',
    'EXPLOSIVE BOMB' => 'EB',
    'COLOR MATCHES' => 'CM',
    'COLOUR MATCHES' => 'CM',
    'RAIN PEARL' => 'RP',
    'HAZY RAINBOW' => 'HR',
    'BABY ITEMS' => 'BI',
    'CHILDRENS ITEMS' => 'BI',
    'CHILDREN ITEMS' => 'BI',
    'CHILDREN S ITEMS' => 'BI', // normalize may strip apostrophe oddly
    'SKY SHOT' => 'SS',
    'FANCY SHOWER ITEMS' => 'FSI',
    'SOLO PERFORMANCE FANCY SKY SHOT' => 'SPFSS',
    'CRACKLING & MULTICOLOR SKY SHOT' => 'CMSS',
    'CRACKLING AND MULTICOLOR SKY SHOT' => 'CMSS',
    '7 CM SPARKLERS' => '7CS',
    '10 CM SPARKLERS' => '10CS',
    '15 CM SPARKLERS' => '15CS',
    '30 CM SPARKLERS' => '30CS',
    '50 CM SPARKLERS' => '50CS',
    'ROTATING SPARKLERS' => 'RS',
    'GIFT BOX' => 'GB',
    'PSK - FAMILY PACKS' => 'FP',
    'PSK FAMILY PACKS' => 'FP',
    'FAMILY PACKS' => 'FP',
  ];
  if (isset($map[$c])) return $map[$c];
  foreach ($map as $key => $prefix) {
    if (str_contains($c, $key) || str_contains($key, $c)) return $prefix;
  }
  // Derive a short URL-safe prefix from category words
  $words = preg_split('/\s+/', $c) ?: [];
  $letters = '';
  foreach ($words as $w) {
    if ($w === '' || $w === 'AND' || $w === '&' || $w === 'OF' || $w === 'THE') continue;
    $letters .= substr(preg_replace('/[^A-Z0-9]/', '', $w) ?: 'X', 0, 1);
    if (strlen($letters) >= 4) break;
  }
  if ($letters === '') $letters = 'NEW';
  return substr($letters, 0, 8);
}

/**
 * Next SKU for a prefix: global max across ALL products with PREFIX-NNN + 1.
 * Critical: FP is shared by FLOWERPOTS and FAMILY PACKS — numbering is prefix-global,
 * not category-local (so after FP-010 the next is FP-011 even when creating a family pack).
 * CG vs GC are different prefixes. Non-matching SKUs are ignored for max calc.
 * Padding matches peers (min 3 digits). Gaps are not filled — always max+1.
 */
function price_list_allocate_sku(PDO $pdo, string $prefix): string {
  $prefix = preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'NEW';
  $prefix = strtoupper($prefix);
  $st = $pdo->prepare('SELECT sku FROM products WHERE sku LIKE ?');
  $st->execute([$prefix . '-%']);
  $max = 0;
  $pad = 3;
  foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) as $sku) {
    if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/i', (string)$sku, $m)) {
      $max = max($max, (int)$m[1]);
      $pad = max($pad, strlen($m[1]));
    }
  }
  for ($n = $max + 1; $n < $max + 5000; $n++) {
    $candidate = $prefix . '-' . str_pad((string)$n, $pad, '0', STR_PAD_LEFT);
    $chk = $pdo->prepare('SELECT 1 FROM products WHERE sku = ? LIMIT 1');
    $chk->execute([$candidate]);
    if (!$chk->fetchColumn()) return $candidate;
  }
  return $prefix . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

/**
 * Resolve next SKU for a category name (or new category string).
 * @return array{ok:bool,sku?:string,prefix?:string,pad?:int,max_used?:int,error?:string,note?:string}
 */
function price_list_next_sku_for_category(PDO $pdo, string $category): array {
  $category = trim($category);
  if ($category === '' || $category === '__new__') {
    return ['ok' => false, 'error' => 'Select or enter a category first.'];
  }
  $prefix = price_list_sku_prefix_for_category($category);
  $st = $pdo->prepare('SELECT sku FROM products WHERE sku LIKE ?');
  $st->execute([$prefix . '-%']);
  $max = 0;
  $pad = 3;
  foreach ($st->fetchAll(PDO::FETCH_COLUMN, 0) as $sku) {
    if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/i', (string)$sku, $m)) {
      $max = max($max, (int)$m[1]);
      $pad = max($pad, strlen($m[1]));
    }
  }
  $sku = price_list_allocate_sku($pdo, $prefix);
  $note = 'Next number is global max for prefix ' . $prefix
    . ' across all categories (e.g. FP shared by flower pots & family packs), not gap-fill.';
  return [
    'ok' => true,
    'sku' => $sku,
    'prefix' => $prefix,
    'pad' => $pad,
    'max_used' => $max,
    'note' => $note,
  ];
}

function price_list_ensure_category(PDO $pdo, string $category): void {
  $category = trim($category);
  if ($category === '') return;
  $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $category), '-')) ?: substr(sha1($category), 0, 8);
  $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE slug=VALUES(slug)')
    ->execute([$category, $slug]);
  // Bust file cache used by load_categories()
  $cacheFile = __DIR__ . '/../storage/cache/categories.cache.php';
  if (is_file($cacheFile)) @unlink($cacheFile);
}

/**
 * @return array{ok:bool,path?:string,error?:string,filename?:string}
 */
function price_list_store_uploaded_pdf(array $file): array {
  if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return ['ok' => false, 'error' => 'Please choose a PDF file.'];
  }
  if ((int)($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
    return ['ok' => false, 'error' => 'PDF upload failed (error code ' . (int)$file['error'] . ').'];
  }
  $size = (int)($file['size'] ?? 0);
  if ($size <= 0) return ['ok' => false, 'error' => 'Empty PDF upload.'];
  if ($size > PRICE_LIST_MAX_PDF_BYTES) {
    return ['ok' => false, 'error' => 'PDF too large. Max 20 MB.'];
  }
  $tmp = (string)($file['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    return ['ok' => false, 'error' => 'Invalid upload.'];
  }
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($tmp) ?: '';
  $orig = (string)($file['name'] ?? 'pricelist.pdf');
  $extOk = str_ends_with(strtolower($orig), '.pdf');
  if (!in_array($mime, ['application/pdf', 'application/x-pdf', 'application/octet-stream'], true) || !$extOk) {
    // octet-stream allowed only with .pdf extension
    if ($mime !== 'application/octet-stream' || !$extOk) {
      return ['ok' => false, 'error' => 'File must be a PDF (got MIME ' . $mime . ').'];
    }
  }
  // Reject obvious non-PDF magic
  $fh = fopen($tmp, 'rb');
  $magic = $fh ? fread($fh, 5) : '';
  if ($fh) fclose($fh);
  if ($magic === false || !str_starts_with((string)$magic, '%PDF')) {
    return ['ok' => false, 'error' => 'Corrupt or non-PDF file (missing %PDF header).'];
  }

  $dir = price_list_storage_dir();
  $safeName = date('Ymd_His') . '_pricelist.pdf';
  $dest = $dir . '/' . $safeName;
  if (!move_uploaded_file($tmp, $dest)) {
    return ['ok' => false, 'error' => 'Failed to store PDF under storage/price_lists/.'];
  }
  @chmod($dest, 0640);
  $meta = [
    'stored_as' => $safeName,
    'original_name' => basename(str_replace(["\0", '..'], '', $orig)),
    'size' => $size,
    'uploaded_at' => date('c'),
    'text_extract' => price_list_try_extract_pdf_text($dest),
  ];
  @file_put_contents($dir . '/last_pdf_meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  @file_put_contents($dir . '/last.pdf', file_get_contents($dest));
  return ['ok' => true, 'path' => $dest, 'filename' => $safeName];
}

/**
 * Attempt text extraction without Composer. Returns summary array.
 * @return array{method:string,ok:bool,chars:int,preview?:string,note?:string}
 */
function price_list_try_extract_pdf_text(string $pdfPath): array {
  $real = realpath($pdfPath);
  $rootStorage = realpath(__DIR__ . '/../storage');
  if ($real === false || $rootStorage === false || !str_starts_with($real, $rootStorage)) {
    return ['method' => 'none', 'ok' => false, 'chars' => 0, 'note' => 'Path rejected'];
  }

  // 1) pdftotext if available on PATH
  if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
    $bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
    if ($bin !== '') {
      $outFile = $real . '.txt';
      @shell_exec(escapeshellarg($bin) . ' -layout ' . escapeshellarg($real) . ' ' . escapeshellarg($outFile) . ' 2>/dev/null');
      if (is_file($outFile)) {
        $text = (string)file_get_contents($outFile);
        @unlink($outFile);
        $chars = strlen(trim($text));
        if ($chars > 40) {
          @file_put_contents(dirname($real) . '/last_pdf_extract.txt', $text);
          return [
            'method' => 'pdftotext',
            'ok' => true,
            'chars' => $chars,
            'preview' => substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 200),
          ];
        }
      }
    }
  }

  // 2) Naive literal-string scrape (works for some text-layer PDFs only)
  $raw = @file_get_contents($real, false, null, 0, 8 * 1024 * 1024);
  if (is_string($raw) && $raw !== '') {
    $parts = [];
    if (preg_match_all('/\((?:\\\\.|[^\\\\)]){2,120}\)/s', $raw, $m)) {
      foreach ($m[0] as $tok) {
        $s = substr($tok, 1, -1);
        $s = stripcslashes($s);
        $s = preg_replace('/[^\x20-\x7E\xA0-\x{FFFF}]/u', '', $s) ?? $s;
        if (strlen(trim($s)) >= 3) $parts[] = trim($s);
      }
    }
    $joined = implode("\n", array_slice($parts, 0, 2000));
    if (strlen(trim($joined)) > 80) {
      @file_put_contents(dirname($real) . '/last_pdf_extract.txt', $joined);
      return [
        'method' => 'literal_scrape',
        'ok' => true,
        'chars' => strlen($joined),
        'preview' => substr(preg_replace('/\s+/', ' ', $joined) ?? $joined, 0, 200),
        'note' => 'Partial text-layer scrape; prefer CSV for reliable sync.',
      ];
    }
  }

  return [
    'method' => 'none',
    'ok' => false,
    'chars' => 0,
    'note' => 'No usable text layer (likely scanned). Upload a CSV of price-list lines.',
  ];
}

/**
 * Parse price-list lines CSV.
 * Required: name, market_rate (or price). category strongly recommended.
 * Optional: sno, sku, unit, discount_pct, category
 *
 * @return array{lines:list<array>,errors:list<string>}
 */
function price_list_parse_csv_file(string $path): array {
  $fh = fopen($path, 'r');
  if (!$fh) throw new RuntimeException('Unable to read CSV.');
  $header = fgetcsv($fh, 0, ',', '"', '\\');
  if (!$header) {
    fclose($fh);
    throw new RuntimeException('Empty CSV.');
  }
  // Strip BOM
  if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]) ?? (string)$header[0];
  }
  $norm = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
  $idx = [
    'sno' => array_search('sno', $norm, true),
    'sku' => (($i = array_search('sku', $norm, true)) !== false ? $i : array_search('id', $norm, true)),
    'name' => array_search('name', $norm, true),
    'category' => array_search('category', $norm, true),
    'unit' => array_search('unit', $norm, true),
    'market_rate' => (($i = array_search('market_rate', $norm, true)) !== false ? $i : (
      ($i = array_search('price', $norm, true)) !== false ? $i : array_search('mrp', $norm, true)
    )),
    'discount_pct' => array_search('discount_pct', $norm, true),
  ];
  if ($idx['name'] === false || $idx['market_rate'] === false) {
    fclose($fh);
    throw new RuntimeException('CSV must include headers: name, market_rate (or price). Recommended: sno,category,unit,discount_pct,sku');
  }

  $lines = [];
  $errors = [];
  $lineNo = 1;
  $seenNorm = [];
  while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
    $lineNo++;
    if (count($row) === 1 && trim((string)$row[0]) === '') continue;
    $name = trim((string)($row[$idx['name']] ?? ''));
    $category = ($idx['category'] !== false) ? trim((string)($row[$idx['category']] ?? '')) : '';
    $unit = ($idx['unit'] !== false) ? trim((string)($row[$idx['unit']] ?? '')) : '';
    $sku = ($idx['sku'] !== false) ? trim((string)($row[$idx['sku']] ?? '')) : '';
    $sno = ($idx['sno'] !== false) ? trim((string)($row[$idx['sno']] ?? '')) : '';
    $priceRaw = trim((string)($row[$idx['market_rate']] ?? ''));
    $priceRaw = str_replace([',', '₹', 'Rs.', 'Rs', ' '], '', $priceRaw);
    $price = (float)$priceRaw;

    if ($name === '') {
      $errors[] = "Line $lineNo: missing name";
      continue;
    }
    if ($price <= 0) {
      $errors[] = "Line $lineNo ($name): invalid market_rate";
      continue;
    }

    $discountSpecified = $idx['discount_pct'] !== false && trim((string)($row[$idx['discount_pct']] ?? '')) !== '';
    if ($discountSpecified) {
      $discount = (float)$row[$idx['discount_pct']];
      if ($discount < 0 || $discount > 100) {
        $errors[] = "Line $lineNo ($name): discount out of range";
        continue;
      }
      $discount = round($discount, 2);
    } elseif (price_list_is_no_discount_item($name, $category)) {
      $discount = 0;
    } else {
      $discount = PRICE_LIST_DEFAULT_DISCOUNT;
    }

    $normName = price_list_normalize_name($name);
    if ($normName === '') {
      $errors[] = "Line $lineNo: name empty after normalize";
      continue;
    }
    if (isset($seenNorm[$normName])) {
      $errors[] = "Line $lineNo: duplicate PDF name \"$name\" (also line {$seenNorm[$normName]}) — skipped";
      continue;
    }
    $seenNorm[$normName] = $lineNo;

    if ($sku !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $sku)) {
      $errors[] = "Line $lineNo ($name): invalid sku \"$sku\"";
      $sku = '';
    }

    $lines[] = [
      'line' => $lineNo,
      'sno' => $sno,
      'sku' => $sku,
      'name' => function_exists('mb_substr') ? mb_substr($name, 0, 255) : substr($name, 0, 255),
      'name_norm' => $normName,
      'category' => function_exists('mb_substr') ? mb_substr($category, 0, 190) : substr($category, 0, 190),
      'unit' => product_unit_or_default($unit !== '' ? $unit : null, PRICE_LIST_DEFAULT_UNIT),
      'price' => round($price, 2),
      'discount_pct' => $discount,
      'no_discount' => price_list_is_no_discount_item($name, $category),
    ];
  }
  fclose($fh);
  return ['lines' => $lines, 'errors' => $errors];
}

/**
 * @return array{ok:bool,path?:string,error?:string,lines?:list,parse_errors?:list}
 */
function price_list_store_uploaded_csv(array $file): array {
  if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return ['ok' => false, 'error' => 'Please choose a CSV file of price-list lines.'];
  }
  if ((int)($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
    return ['ok' => false, 'error' => 'CSV upload failed (error code ' . (int)$file['error'] . ').'];
  }
  $size = (int)($file['size'] ?? 0);
  if ($size <= 0) return ['ok' => false, 'error' => 'Empty CSV.'];
  if ($size > PRICE_LIST_MAX_CSV_BYTES) {
    return ['ok' => false, 'error' => 'CSV too large. Max 10 MB.'];
  }
  $tmp = (string)($file['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    return ['ok' => false, 'error' => 'Invalid CSV upload.'];
  }
  $orig = strtolower((string)($file['name'] ?? ''));
  if (!str_ends_with($orig, '.csv')) {
    return ['ok' => false, 'error' => 'File must be a .csv'];
  }
  $parsed = price_list_parse_csv_file($tmp);
  if (!$parsed['lines']) {
    return ['ok' => false, 'error' => 'No valid product lines in CSV.', 'parse_errors' => $parsed['errors']];
  }
  $dir = price_list_storage_dir();
  $safeName = date('Ymd_His') . '_lines.csv';
  $dest = $dir . '/' . $safeName;
  if (!move_uploaded_file($tmp, $dest)) {
    // Already read tmp; copy instead
    if (!@copy($tmp, $dest)) {
      return ['ok' => false, 'error' => 'Failed to store CSV.'];
    }
  }
  @copy($dest, $dir . '/last_lines.csv');
  @file_put_contents($dir . '/last_csv_meta.json', json_encode([
    'stored_as' => $safeName,
    'original_name' => basename(str_replace(["\0", '..'], '', (string)($file['name'] ?? 'lines.csv'))),
    'line_count' => count($parsed['lines']),
    'uploaded_at' => date('c'),
  ], JSON_PRETTY_PRINT));
  return ['ok' => true, 'path' => $dest, 'lines' => $parsed['lines'], 'parse_errors' => $parsed['errors']];
}

/**
 * Sync DB from parsed price-list lines.
 *
 * @param list<array> $lines
 * @return array{added:list,updated:list,archived:list,skipped:list,unchanged:list,dry_run:bool}
 */
function price_list_sync(PDO $pdo, array $lines, bool $dryRun, bool $archiveMissing): array {
  price_list_ensure_schema($pdo);
  $report = [
    'added' => [],
    'updated' => [],
    'archived' => [],
    'skipped' => [],
    'unchanged' => [],
    'dry_run' => $dryRun,
  ];
  if (!$lines) {
    $report['skipped'][] = ['reason' => 'No lines to import'];
    return $report;
  }

  $all = $pdo->query('SELECT id, sku, name, category, unit, price, discount_pct, image_url, COALESCE(is_active,1) AS is_active FROM products')->fetchAll(PDO::FETCH_ASSOC);
  $bySku = [];
  $byNorm = [];
  foreach ($all as $row) {
    $bySku[strtoupper((string)$row['sku'])] = $row;
    $nn = price_list_normalize_name((string)$row['name']);
    if ($nn === '') continue;
    $byNorm[$nn][] = $row;
  }

  $mapRows = [];
  try {
    foreach ($pdo->query('SELECT pdf_name_norm, sku FROM product_pdf_map') as $m) {
      $mapRows[strtoupper((string)$m['pdf_name_norm'])] = (string)$m['sku'];
    }
  } catch (Throwable $_) { /* table missing until ensure */ }

  $matchedSkus = [];
  $allocatedNew = [];
  $startedTx = false;
  if (!$dryRun) {
    $pdo->beginTransaction();
    $startedTx = true;
  }

  try {
    $ins = $pdo->prepare('INSERT INTO products (sku, name, category, unit, price, discount_pct, image_url, is_active) VALUES (?,?,?,?,?,?,NULL,1)');
    $upd = $pdo->prepare('UPDATE products SET sku=?, name=?, category=?, unit=?, price=?, discount_pct=?, is_active=1 WHERE id=?');
    $mapUpsert = $pdo->prepare('INSERT INTO product_pdf_map (pdf_name_norm, sku, pdf_name_raw) VALUES (?,?,?)
      ON DUPLICATE KEY UPDATE sku=VALUES(sku), pdf_name_raw=VALUES(pdf_name_raw)');

    $nextSku = static function (PDO $pdo, string $prefix, array &$allocatedNew) use ($bySku): string {
      $sku = price_list_allocate_sku($pdo, $prefix);
      // Avoid collisions among multiple creates in one dry-run / batch before commit is visible
      $guard = 0;
      while (isset($allocatedNew[strtoupper($sku)]) || isset($bySku[strtoupper($sku)])) {
        if (preg_match('/^(.*)-(\d+)$/', $sku, $m)) {
          $sku = sprintf('%s-%03d', $m[1], ((int)$m[2]) + 1);
        } else {
          $sku = $prefix . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }
        if (++$guard > 5000) break;
      }
      $allocatedNew[strtoupper($sku)] = true;
      return $sku;
    };

    foreach ($lines as $line) {
      $match = null;
      $matchHow = '';

      // 1) Explicit SKU in CSV
      if ($line['sku'] !== '') {
        $key = strtoupper($line['sku']);
        if (isset($bySku[$key])) {
          $match = $bySku[$key];
          $matchHow = 'sku';
        } else {
          $report['skipped'][] = [
            'line' => $line['line'],
            'name' => $line['name'],
            'sku' => $line['sku'],
            'reason' => 'SKU from CSV not found in DB — not auto-creating with foreign SKU; remove sku column to auto-add',
          ];
          continue;
        }
      }

      // 2) Prior PDF map
      if (!$match && isset($mapRows[$line['name_norm']])) {
        $mappedSku = strtoupper($mapRows[$line['name_norm']]);
        if (isset($bySku[$mappedSku])) {
          $match = $bySku[$mappedSku];
          $matchHow = 'pdf_map';
        }
      }

      // 3) Unique normalized name
      if (!$match) {
        $cands = $byNorm[$line['name_norm']] ?? [];
        if (count($cands) === 1) {
          $match = $cands[0];
          $matchHow = 'name';
        } elseif (count($cands) > 1) {
          $skus = array_map(static fn($r) => $r['sku'], $cands);
          $report['skipped'][] = [
            'line' => $line['line'],
            'name' => $line['name'],
            'reason' => 'Ambiguous DB match for name — SKUs: ' . implode(', ', $skus),
          ];
          continue;
        }
      }

      $category = $line['category'] !== '' ? $line['category'] : ($match['category'] ?? 'UNCATEGORIZED');
      $unit = product_unit_or_default($line['unit'], PRICE_LIST_DEFAULT_UNIT);
      $price = (float)$line['price'];
      $discount = round((float)$line['discount_pct'], 2);
      if ($line['no_discount']) $discount = 0;

      if ($match) {
        $matchedSkus[strtoupper((string)$match['sku'])] = true;
        $nameSafe = (string)$match['name'];
        // Update name when safe: same normalize, or map/sku match with short PDF name filling gaps
        if ($matchHow === 'name' || price_list_normalize_name($nameSafe) === $line['name_norm']) {
          $nameSafe = $line['name'];
        } elseif ($matchHow === 'sku' || $matchHow === 'pdf_map') {
          // Prefer PDF name for catalog alignment when confidently mapped
          $nameSafe = $line['name'];
        }
        // Prefer canonical SKU casing from the price list when provided
        $skuSafe = (string)$match['sku'];
        if ($line['sku'] !== '' && strcasecmp($line['sku'], $skuSafe) === 0) {
          $skuSafe = $line['sku'];
        }

        $same =
          (string)$match['sku'] === $skuSafe
          && abs((float)$match['price'] - $price) < 0.005
          && abs((float)$match['discount_pct'] - $discount) < 0.005
          && product_unit_or_default((string)$match['unit']) === $unit
          && (string)$match['name'] === $nameSafe
          && (string)$match['category'] === $category
          && (int)$match['is_active'] === 1;

        if ($same) {
          $report['unchanged'][] = ['sku' => $skuSafe, 'name' => $nameSafe];
          if (!$dryRun) {
            $mapUpsert->execute([$line['name_norm'], $skuSafe, $line['name']]);
          }
          continue;
        }

        $entry = [
          'sku' => $skuSafe,
          'old_sku' => (string)$match['sku'],
          'name' => $nameSafe,
          'old_price' => (float)$match['price'],
          'new_price' => $price,
          'old_disc' => (float)$match['discount_pct'],
          'new_disc' => $discount,
          'unit' => $unit,
          'category' => $category,
          'via' => $matchHow,
        ];
        if (!$dryRun) {
          if ($category !== '') price_list_ensure_category($pdo, $category);
          $upd->execute([$skuSafe, $nameSafe, $category, $unit, $price, $discount, (int)$match['id']]);
          $mapUpsert->execute([$line['name_norm'], $skuSafe, $line['name']]);
          // Refresh in-memory
          $match['sku'] = $skuSafe;
          $match['name'] = $nameSafe;
          $match['category'] = $category;
          $match['unit'] = $unit;
          $match['price'] = $price;
          $match['discount_pct'] = $discount;
          $match['is_active'] = 1;
          $bySku[strtoupper((string)$match['sku'])] = $match;
        }
        $report['updated'][] = $entry;
      } else {
        // Create new
        if ($category === '' || $category === 'UNCATEGORIZED') {
          $report['skipped'][] = [
            'line' => $line['line'],
            'name' => $line['name'],
            'reason' => 'No DB match and missing category — cannot create SKU',
          ];
          continue;
        }
        $prefix = price_list_sku_prefix_for_category($category);
        $newSku = $nextSku($pdo, $prefix, $allocatedNew);
        $entry = [
          'sku' => $newSku,
          'name' => $line['name'],
          'price' => $price,
          'discount_pct' => $discount,
          'unit' => $unit,
          'category' => $category,
        ];
        if (!$dryRun) {
          price_list_ensure_category($pdo, $category);
          $ins->execute([$newSku, $line['name'], $category, $unit, $price, $discount]);
          $mapUpsert->execute([$line['name_norm'], $newSku, $line['name']]);
          $pid = (int)$pdo->lastInsertId();
          $row = [
            'id' => $pid,
            'sku' => $newSku,
            'name' => $line['name'],
            'category' => $category,
            'unit' => $unit,
            'price' => $price,
            'discount_pct' => $discount,
            'image_url' => null,
            'is_active' => 1,
          ];
          $bySku[strtoupper($newSku)] = $row;
          $byNorm[$line['name_norm']][] = $row;
        }
        $matchedSkus[strtoupper($newSku)] = true;
        $report['added'][] = $entry;
      }
    }

    if ($archiveMissing) {
      $arch = $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ? AND COALESCE(is_active,1) = 1');
      foreach ($all as $row) {
        $skuKey = strtoupper((string)$row['sku']);
        if (isset($matchedSkus[$skuKey])) continue;
        if ((int)$row['is_active'] !== 1) continue;
        $report['archived'][] = [
          'sku' => $row['sku'],
          'name' => $row['name'],
          'category' => $row['category'],
          'reason' => 'Active in DB but not on uploaded price list',
        ];
        if (!$dryRun) {
          $arch->execute([(int)$row['id']]);
        }
      }
    }

    if ($startedTx) $pdo->commit();
  } catch (Throwable $e) {
    if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  return $report;
}

function price_list_last_pdf_meta(): ?array {
  $f = price_list_storage_dir() . '/last_pdf_meta.json';
  if (!is_file($f)) return null;
  $j = json_decode((string)file_get_contents($f), true);
  return is_array($j) ? $j : null;
}

function price_list_last_csv_meta(): ?array {
  $f = price_list_storage_dir() . '/last_csv_meta.json';
  if (!is_file($f)) return null;
  $j = json_decode((string)file_get_contents($f), true);
  return is_array($j) ? $j : null;
}

function price_list_last_csv_path(): ?string {
  $p = price_list_storage_dir() . '/last_lines.csv';
  return is_file($p) ? $p : null;
}

/** Bundled Karthi PSK.pdf 2026 list shipped with the app (deployed under data/). */
function price_list_bundled_canonical_path(): string {
  return realpath(__DIR__ . '/..') . '/data/psk_2026_canonical_price_list.json';
}

/** Last successfully applied list (runtime; under storage/price_lists/). */
function price_list_last_canonical_path(): string {
  return price_list_storage_dir() . '/last_canonical.json';
}

function price_list_last_canonical_meta(): ?array {
  $f = price_list_storage_dir() . '/last_canonical_meta.json';
  if (!is_file($f)) return null;
  $j = json_decode((string)file_get_contents($f), true);
  return is_array($j) ? $j : null;
}

/**
 * Convert raw JSON line objects into sync-ready rows (same shape as CSV parser).
 *
 * @param list<array> $rawLines
 * @return array{lines:list<array>,errors:list<string>}
 */
function price_list_normalize_canonical_lines(array $rawLines): array {
  $lines = [];
  $errors = [];
  $seenNorm = [];
  $lineNo = 0;
  foreach ($rawLines as $raw) {
    $lineNo++;
    if (!is_array($raw)) {
      $errors[] = "Line $lineNo: not an object";
      continue;
    }
    $name = trim((string)($raw['name'] ?? ''));
    $category = trim((string)($raw['category'] ?? ''));
    $unit = trim((string)($raw['unit'] ?? ''));
    $sku = trim((string)($raw['sku'] ?? ''));
    $sno = trim((string)($raw['sno'] ?? (string)$lineNo));
    $priceRaw = $raw['market_rate'] ?? ($raw['price'] ?? '');
    if (is_string($priceRaw)) {
      $priceRaw = str_replace([',', '₹', 'Rs.', 'Rs', ' '], '', $priceRaw);
    }
    $price = (float)$priceRaw;

    if ($name === '') {
      $errors[] = "Line $lineNo: missing name";
      continue;
    }
    if ($price <= 0) {
      $errors[] = "Line $lineNo ($name): invalid market_rate";
      continue;
    }

    if (array_key_exists('discount_pct', $raw) && $raw['discount_pct'] !== '' && $raw['discount_pct'] !== null) {
      $discount = round((float)$raw['discount_pct'], 2);
      if ($discount < 0 || $discount > 100) {
        $errors[] = "Line $lineNo ($name): discount out of range";
        continue;
      }
    } elseif (price_list_is_no_discount_item($name, $category)) {
      $discount = 0;
    } else {
      $discount = PRICE_LIST_DEFAULT_DISCOUNT;
    }

    $normName = price_list_normalize_name($name);
    if ($normName === '') {
      $errors[] = "Line $lineNo: name empty after normalize";
      continue;
    }
    if (isset($seenNorm[$normName])) {
      $errors[] = "Line $lineNo: duplicate PDF name \"$name\" (also line {$seenNorm[$normName]}) — skipped";
      continue;
    }
    $seenNorm[$normName] = $lineNo;

    if ($sku !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $sku)) {
      $errors[] = "Line $lineNo ($name): invalid sku \"$sku\"";
      $sku = '';
    }

    $lines[] = [
      'line' => $lineNo,
      'sno' => $sno,
      'sku' => $sku,
      'name' => function_exists('mb_substr') ? mb_substr($name, 0, 255) : substr($name, 0, 255),
      'name_norm' => $normName,
      'category' => function_exists('mb_substr') ? mb_substr($category, 0, 190) : substr($category, 0, 190),
      'unit' => product_unit_or_default($unit !== '' ? $unit : null, PRICE_LIST_DEFAULT_UNIT),
      'price' => round($price, 2),
      'discount_pct' => $discount,
      'no_discount' => price_list_is_no_discount_item($name, $category),
    ];
  }
  return ['lines' => $lines, 'errors' => $errors];
}

/**
 * Load canonical price-list JSON from a path.
 *
 * @return array{ok:bool,lines?:list,errors?:list,error?:string,source?:string,path?:string,line_count?:int}
 */
function price_list_load_canonical_file(string $path): array {
  $real = realpath($path);
  if ($real === false || !is_file($real)) {
    return ['ok' => false, 'error' => 'Canonical price list file not found.'];
  }
  $root = realpath(__DIR__ . '/..');
  if ($root === false || !str_starts_with($real, $root)) {
    return ['ok' => false, 'error' => 'Canonical path rejected.'];
  }
  $raw = json_decode((string)file_get_contents($real), true);
  if (!is_array($raw)) {
    return ['ok' => false, 'error' => 'Canonical JSON is invalid.'];
  }
  $rawLines = $raw['lines'] ?? null;
  if (!is_array($rawLines)) {
    return ['ok' => false, 'error' => 'Canonical JSON missing lines[].'];
  }
  $parsed = price_list_normalize_canonical_lines($rawLines);
  if (!$parsed['lines']) {
    return [
      'ok' => false,
      'error' => 'No valid lines in canonical list.',
      'errors' => $parsed['errors'],
    ];
  }
  return [
    'ok' => true,
    'lines' => $parsed['lines'],
    'errors' => $parsed['errors'],
    'source' => (string)($raw['source'] ?? basename($real)),
    'path' => $real,
    'line_count' => count($parsed['lines']),
  ];
}

/**
 * Resolve which canonical list to use: last applied → bundled 2026 sample.
 *
 * @return array{ok:bool,lines?:list,errors?:list,error?:string,source?:string,path?:string,which?:string}
 */
function price_list_resolve_canonical(bool $preferBundled = false): array {
  $candidates = [];
  if ($preferBundled) {
    $candidates[] = ['bundled', price_list_bundled_canonical_path()];
    $last = price_list_last_canonical_path();
    if (is_file($last)) $candidates[] = ['last_applied', $last];
  } else {
    $last = price_list_last_canonical_path();
    if (is_file($last)) $candidates[] = ['last_applied', $last];
    $candidates[] = ['bundled', price_list_bundled_canonical_path()];
  }
  $lastError = 'No canonical price list available.';
  foreach ($candidates as [$which, $path]) {
    if (!is_file($path)) continue;
    $loaded = price_list_load_canonical_file($path);
    if (!empty($loaded['ok'])) {
      $loaded['which'] = $which;
      return $loaded;
    }
    $lastError = $loaded['error'] ?? $lastError;
  }
  return ['ok' => false, 'error' => $lastError];
}

/**
 * Persist applied sync lines as the runtime "last canonical" for PDF-only reapply.
 *
 * @param list<array> $lines Sync-shaped lines (name, category, unit, price, discount_pct, sku, …)
 */
function price_list_save_last_canonical(array $lines, string $sourceLabel = 'applied price list'): void {
  $export = [];
  $sno = 0;
  foreach ($lines as $line) {
    $sno++;
    $export[] = [
      'sno' => (string)($line['sno'] ?? (string)$sno),
      'sku' => (string)($line['sku'] ?? ''),
      'name' => (string)($line['name'] ?? ''),
      'category' => (string)($line['category'] ?? ''),
      'unit' => product_unit_or_default($line['unit'] ?? null, PRICE_LIST_DEFAULT_UNIT),
      'market_rate' => round((float)($line['price'] ?? 0), 2),
      'discount_pct' => round((float)($line['discount_pct'] ?? PRICE_LIST_DEFAULT_DISCOUNT), 2),
    ];
  }
  $payload = [
    'source' => $sourceLabel,
    'generated_at' => date('c'),
    'line_count' => count($export),
    'lines' => $export,
  ];
  $dir = price_list_storage_dir();
  $path = $dir . '/last_canonical.json';
  @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
  @file_put_contents($dir . '/last_canonical_meta.json', json_encode([
    'source' => $sourceLabel,
    'line_count' => count($export),
    'saved_at' => date('c'),
    'path' => 'last_canonical.json',
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function price_list_bundled_available(): bool {
  return is_file(price_list_bundled_canonical_path());
}

function price_list_template_csv(): string {
  $rows = [
    ['sno', 'name', 'category', 'unit', 'market_rate', 'discount_pct', 'sku'],
    ['1', '3 1/2" LAKSHMI', 'SINGLE CRACKERS', '1 Box', '70', '80', ''],
    ['2', 'PAPER BOMB 1/4 Kg', 'PAPER BOMB', '1 Box', '250', '80', ''],
    ['', '31 ITEM GIFT BOX', 'GIFT BOX', '1 Box', '500', '0', ''],
    ['', 'FAMILY - 1', 'PSK - FAMILY PACKS', '1 Box', '3000', '0', ''],
  ];
  $out = fopen('php://temp', 'r+');
  foreach ($rows as $r) fputcsv($out, $r, ',', '"', '\\');
  rewind($out);
  $csv = stream_get_contents($out) ?: '';
  fclose($out);
  return $csv;
}

/** Format sync report as HTML-safe summary lines. */
function price_list_format_report_html(array $report, array $parseErrors = []): string {
  $parts = [];
  $dry = !empty($report['dry_run']) ? ' (dry-run — no DB writes)' : '';
  $parts[] = 'Price list sync' . $dry
    . ' — Added: ' . count($report['added'] ?? [])
    . ', Updated: ' . count($report['updated'] ?? [])
    . ', Archived: ' . count($report['archived'] ?? [])
    . ', Unchanged: ' . count($report['unchanged'] ?? [])
    . ', Skipped: ' . count($report['skipped'] ?? []);

  $sample = static function (array $items, string $label, int $n = 5) use (&$parts): void {
    if (!$items) return;
    $bits = [];
    foreach (array_slice($items, 0, $n) as $it) {
      if (isset($it['sku'], $it['name'])) {
        $bits[] = htmlspecialchars(($it['sku'] ?? '') . ' ' . ($it['name'] ?? ''));
      } elseif (isset($it['name'], $it['reason'])) {
        $bits[] = htmlspecialchars(($it['name'] ?? '') . ' — ' . ($it['reason'] ?? ''));
      } elseif (isset($it['reason'])) {
        $bits[] = htmlspecialchars((string)$it['reason']);
      }
    }
    if ($bits) $parts[] = $label . ': ' . implode(' | ', $bits) . (count($items) > $n ? ' …' : '');
  };

  $sample($report['added'] ?? [], 'Added sample');
  $sample($report['updated'] ?? [], 'Updated sample');
  $sample($report['archived'] ?? [], 'Archived sample');
  $sample($report['skipped'] ?? [], 'Skipped sample');
  if ($parseErrors) {
    $parts[] = 'CSV parse notes: ' . htmlspecialchars(implode(' | ', array_slice($parseErrors, 0, 5)))
      . (count($parseErrors) > 5 ? ' …' : '');
  }
  return implode('. ', $parts);
}
