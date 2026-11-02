<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/products.php';
require_once __DIR__ . '/../lib/price_list_import.php';
require_once __DIR__ . '/../lib/categories.php';
require_once __DIR__ . '/../lib/routes.php';
admin_require_login();
if (!admin_is_manager()) {
  http_response_code(403);
  echo 'Forbidden — admin or superadmin required to manage products.';
  exit;
}

$routeExt = route_extension();

$routeBase = route_base();
$cats = load_categories();
$msg = '';
$err = '';
$pdfImportReport = null;

// Download CSV template (GET, authenticated manager)
if (($_GET['download'] ?? '') === 'price_list_template') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="price_list_lines_template.csv"');
  header('Cache-Control: no-store');
  echo price_list_template_csv();
  exit;
}

// Next SKU for category (GET JSON — manager-only, already gated above)
if (($_GET['action'] ?? '') === 'next_sku') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  try {
    $pdoSku = get_pdo();
    ensure_products_catalog_schema($pdoSku);
    $cat = trim((string)($_GET['category'] ?? ''));
    if ($cat === '__new__') {
      $cat = trim((string)($_GET['category_new'] ?? ''));
    }
    echo json_encode(price_list_next_sku_for_category($pdoSku, $cat), JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

// Ensure DB tables exist and have required columns (lightweight migration)
try {
  $pdo = get_pdo();
  ensure_products_catalog_schema($pdo);
  price_list_ensure_schema($pdo);
  $pdo->exec("CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(190) NOT NULL,
    unit VARCHAR(64) NOT NULL DEFAULT 'Box',
    price DECIMAL(10,2) NOT NULL,
    discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    image_url VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_sku (sku),
    KEY idx_category (category)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $pdo->exec("CREATE TABLE IF NOT EXISTS product_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    path VARCHAR(500) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_images_product FOREIGN KEY (product_id) REFERENCES products(id)
      ON DELETE CASCADE ON UPDATE CASCADE,
    KEY idx_product (product_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  // Categories table (for inline add-new category)
  $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_name (name),
    UNIQUE KEY uniq_slug (slug)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  // Add missing columns if table already existed with an older shape
  $ensureCols = function(PDO $pdo, string $table, array $cols): void {
    $existing = [];
    try {
      $st = $pdo->query('SHOW COLUMNS FROM `'.str_replace('`','',$table).'`');
      foreach ($st->fetchAll() as $c) { $existing[strtolower($c['Field'])] = true; }
    } catch (Throwable $_) { return; }
    foreach ($cols as $col => $def) {
      if (!isset($existing[strtolower($col)])) {
        try { $pdo->exec('ALTER TABLE `'.str_replace('`','',$table).'` ADD COLUMN `'.$col.'` '.$def); } catch (Throwable $__) { /* ignore */ }
      }
    }
  };
  $ensureCols($pdo, 'products', [
    'sku' => 'VARCHAR(64) NULL',
    'name' => 'VARCHAR(255) NULL',
    'category' => 'VARCHAR(190) NULL',
    'unit' => "VARCHAR(64) NOT NULL DEFAULT 'Box'",
    'price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
    'discount_pct' => 'DECIMAL(5,2) NOT NULL DEFAULT 0',
    'image_url' => 'VARCHAR(500) NULL',
    'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP'
  ]);
  $ensureCols($pdo, 'product_images', [
    'product_id' => 'BIGINT UNSIGNED NOT NULL',
    'path' => 'VARCHAR(500) NOT NULL',
    'is_primary' => 'TINYINT(1) NOT NULL DEFAULT 0',
    'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP'
  ]);
} catch (Throwable $e) {
  $err = 'DB error: ' . htmlspecialchars($e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
  $csrf = $_POST['csrf'] ?? '';
  if (!admin_csrf_check($csrf)) {
    http_response_code(400);
    $err = 'Invalid CSRF token. Please retry.';
  } else {
    $action = $_POST['action'] ?? 'single';
    // --- Price list PDF / CSV import ---
    if ($action === 'pdf_upload') {
      try {
        if (empty($_FILES['price_pdf'])) {
          throw new RuntimeException('Please choose a PDF file.');
        }
        $stored = price_list_store_uploaded_pdf($_FILES['price_pdf']);
        if (!$stored['ok']) throw new RuntimeException($stored['error'] ?? 'PDF upload failed');
        $meta = price_list_last_pdf_meta();
        $extract = is_array($meta['text_extract'] ?? null) ? $meta['text_extract'] : [];
        $msg = 'Stored price-list PDF as ' . htmlspecialchars((string)($stored['filename'] ?? ''))
          . '. Use “Preview / Apply PSK price list” below to sync names &amp; rates'
          . ' (built-in 2026 list from sample Karthi PSK.pdf — no CSV required).';
        if (!empty($extract['ok'])) {
          $msg .= ' Text extract via ' . htmlspecialchars((string)($extract['method'] ?? ''))
            . ' (' . (int)($extract['chars'] ?? 0) . ' chars) is archival only on Hostinger.';
        }
      } catch (Throwable $e) {
        $err = 'PDF upload failed: ' . htmlspecialchars($e->getMessage());
      }
    } elseif ($action === 'pdf_import' || $action === 'pdf_reapply') {
      try {
        // Double-submit guard (apply only)
        $dryRun = !empty($_POST['dry_run']);
        $archiveMissing = !empty($_POST['archive_missing']);
        $nonce = (string)($_POST['import_nonce'] ?? '');
        $sessNonce = (string)($_SESSION['price_list_import_nonce'] ?? '');
        if (!$dryRun) {
          if ($nonce === '' || $sessNonce === '' || !hash_equals($sessNonce, $nonce)) {
            throw new RuntimeException('Import already used or invalid. Refresh the page and try again (prevents double submit).');
          }
        }

        $parseErrors = [];
        $lines = [];
        $sourceLabel = '';
        $usedCanonical = false;

        if ($action === 'pdf_reapply') {
          // Prefer last applied canonical, then last CSV, then bundled 2026 list
          $canon = price_list_resolve_canonical(false);
          if (!empty($canon['ok'])) {
            $lines = $canon['lines'] ?? [];
            $parseErrors = $canon['errors'] ?? [];
            $sourceLabel = (string)($canon['source'] ?? 'canonical');
            $usedCanonical = true;
          } else {
            $last = price_list_last_csv_path();
            if (!$last) {
              throw new RuntimeException($canon['error'] ?? 'No previously applied price list. Upload a PDF and apply the built-in list, or upload a CSV.');
            }
            $parsed = price_list_parse_csv_file($last);
            $lines = $parsed['lines'];
            $parseErrors = $parsed['errors'];
            $sourceLabel = 'last uploaded CSV';
          }
        } else {
          // Optional PDF archive alongside sync
          if (!empty($_FILES['price_pdf']) && (int)($_FILES['price_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $storedPdf = price_list_store_uploaded_pdf($_FILES['price_pdf']);
            if (!$storedPdf['ok']) throw new RuntimeException('PDF: ' . ($storedPdf['error'] ?? 'upload failed'));
          }

          $hasCsvUpload = !empty($_FILES['price_csv'])
            && (int)($_FILES['price_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
          $useLastCsv = !empty($_POST['use_last_csv']);
          $useCanonical = !empty($_POST['use_canonical']) || (!$hasCsvUpload && !$useLastCsv);

          if ($hasCsvUpload) {
            $storedCsv = price_list_store_uploaded_csv($_FILES['price_csv']);
            if (!$storedCsv['ok']) {
              $extra = '';
              if (!empty($storedCsv['parse_errors'])) {
                $extra = ' ' . implode(' | ', array_slice($storedCsv['parse_errors'], 0, 3));
              }
              throw new RuntimeException(($storedCsv['error'] ?? 'CSV failed') . $extra);
            }
            $lines = $storedCsv['lines'] ?? [];
            $parseErrors = $storedCsv['parse_errors'] ?? [];
            $sourceLabel = 'uploaded CSV';
          } elseif ($useLastCsv) {
            $last = price_list_last_csv_path();
            if (!$last) throw new RuntimeException('No CSV uploaded and no last list on file.');
            $parsed = price_list_parse_csv_file($last);
            $lines = $parsed['lines'];
            $parseErrors = $parsed['errors'];
            $sourceLabel = 'last uploaded CSV';
          } elseif ($useCanonical) {
            $preferBundled = !empty($_POST['prefer_bundled_canonical']);
            $canon = price_list_resolve_canonical($preferBundled);
            if (empty($canon['ok'])) {
              throw new RuntimeException($canon['error'] ?? 'Built-in PSK price list missing.');
            }
            $lines = $canon['lines'] ?? [];
            $parseErrors = $canon['errors'] ?? [];
            $which = (string)($canon['which'] ?? 'canonical');
            $sourceLabel = ($which === 'bundled')
              ? 'PSK 2026 built-in list (Karthi PSK.pdf sample)'
              : ((string)($canon['source'] ?? 'last applied canonical'));
            $usedCanonical = true;
          } else {
            throw new RuntimeException('Choose a CSV, “Use last CSV”, or the built-in PSK 2026 price list.');
          }
        }

        if (!$lines) throw new RuntimeException('No valid lines to sync.');

        $pdfImportReport = price_list_sync($pdo, $lines, $dryRun, $archiveMissing);
        $msg = price_list_format_report_html($pdfImportReport, $parseErrors);
        if ($sourceLabel !== '') {
          $msg = 'Source: ' . htmlspecialchars($sourceLabel) . '. ' . $msg;
        }

        if (!$dryRun) {
          // Save applied rows so next PDF-only reapply uses the latest list
          price_list_save_last_canonical(
            $lines,
            $sourceLabel !== '' ? $sourceLabel : ($usedCanonical ? 'canonical' : 'CSV apply')
          );
          // Invalidate nonce after successful apply
          unset($_SESSION['price_list_import_nonce']);
          // Refresh categories for the form below
          $cats = load_categories();
        }
      } catch (Throwable $e) {
        $err = 'Price list import failed: ' . htmlspecialchars($e->getMessage());
      }
    } elseif ($action === 'delete') {
      try {
        $skuDel = trim((string)($_POST['id'] ?? ''));
        if ($skuDel === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $skuDel)) {
          throw new RuntimeException('Invalid product ID.');
        }
        $d = $pdo->prepare('DELETE FROM products WHERE sku = ?');
        $d->execute([$skuDel]);
        $msg = 'Deleted product ' . htmlspecialchars($skuDel);
      } catch (Throwable $e) {
        $err = 'Delete failed: ' . htmlspecialchars($e->getMessage());
      }
    } elseif ($action === 'archive' || $action === 'restore') {
      try {
        $skuTog = trim((string)($_POST['id'] ?? ''));
        if ($skuTog === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $skuTog)) {
          throw new RuntimeException('Invalid product ID.');
        }
        $active = ($action === 'restore') ? 1 : 0;
        $u = $pdo->prepare('UPDATE products SET is_active = ? WHERE sku = ?');
        $u->execute([$active, $skuTog]);
        if ($u->rowCount() < 1) {
          // Still ok if already in desired state
          $chk = $pdo->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
          $chk->execute([$skuTog]);
          if (!$chk->fetch()) throw new RuntimeException('Product not found.');
        }
        $msg = ($active ? 'Restored to pricing list: ' : 'Archived (hidden from shop): ') . htmlspecialchars($skuTog);
      } catch (Throwable $e) {
        $err = 'Archive update failed: ' . htmlspecialchars($e->getMessage());
      }
    } elseif ($action === 'bulk') {
      // Bulk CSV upload
      try {
        if (empty($_FILES['csv']) || (int)$_FILES['csv']['error'] === UPLOAD_ERR_NO_FILE) {
          throw new RuntimeException('Please choose a CSV file.');
        }
        $file = $_FILES['csv'];
        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
          throw new RuntimeException('Upload failed with error code ' . (int)$file['error']);
        }
        $sizeMb = $file['size'] / (1024*1024);
        if ($sizeMb > 10) throw new RuntimeException('CSV too large. Max 10 MB');

        $fh = fopen($file['tmp_name'], 'r');
        if (!$fh) throw new RuntimeException('Unable to read uploaded CSV');

        // Read header
        $header = fgetcsv($fh);
        if (!$header) throw new RuntimeException('Empty CSV');
        $norm = array_map(function($h){ return strtolower(trim((string)$h)); }, $header);
        // Map common names
        $idx = [
          'sku' => array_search('sku', $norm),
          'id' => array_search('id', $norm),
          'name' => array_search('name', $norm),
          'category' => array_search('category', $norm),
          'unit' => array_search('unit', $norm),
          'price' => array_search('price', $norm),
          'discount_pct' => array_search('discount_pct', $norm),
          'image_url' => array_search('image_url', $norm),
          'image' => array_search('image', $norm),
        ];
        $skuIdx = $idx['sku'] !== false ? $idx['sku'] : $idx['id'];
        if ($skuIdx === false || $idx['name'] === false || $idx['category'] === false || $idx['price'] === false) {
          throw new RuntimeException('CSV must include headers: sku(or id), name, category, price');
        }

        $ins = $pdo->prepare('INSERT INTO products (sku, name, category, unit, price, discount_pct, image_url) VALUES (?,?,?,?,?,?,?)');
        $upd = $pdo->prepare('UPDATE products SET name=?, category=?, unit=?, price=?, discount_pct=?, image_url=? WHERE id=?');
        $sel = $pdo->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');

        $pdo->beginTransaction();
        $inserted=0; $updated=0; $skipped=0; $lineNo=1; $errors=[];
        while (($row = fgetcsv($fh)) !== false) {
          $lineNo++;
          $sku = trim((string)($row[$skuIdx] ?? ''));
          $name = trim((string)($row[$idx['name']] ?? ''));
          $category = trim((string)($row[$idx['category']] ?? ''));
          $unit = ($idx['unit']!==false) ? trim((string)($row[$idx['unit']] ?? '')) : '';
          if ($unit === '') { $unit = 'Box'; }
          $unit = mb_substr($unit, 0, 64);
          $price = (float)(($idx['price']!==false) ? $row[$idx['price']] : 0);
          $discount = round((float)(($idx['discount_pct']!==false) ? $row[$idx['discount_pct']] : 0), 2);
          $imageUrl = ($idx['image_url']!==false) ? trim((string)($row[$idx['image_url']] ?? '')) : (($idx['image']!==false) ? trim((string)($row[$idx['image']] ?? '')) : '');

          // Basic validation
          if ($sku === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $sku)) { $errors[] = "Line $lineNo: invalid sku"; $skipped++; continue; }
          if ($name === '' || $category === '' || $price <= 0) { $errors[] = "Line $lineNo: missing name/category/price"; $skipped++; continue; }
          if ($discount < 0 || $discount > 100) { $errors[] = "Line $lineNo: discount out of range"; $skipped++; continue; }

          $sel->execute([$sku]);
          $ex = $sel->fetch();
          if ($ex) {
            $pid = (int)$ex['id'];
            $upd->execute([$name, $category, $unit, $price, $discount, ($imageUrl!==''?$imageUrl:null), $pid]);
            $updated++;
          } else {
            $ins->execute([$sku, $name, $category, $unit, $price, $discount, ($imageUrl!==''?$imageUrl:null)]);
            $inserted++;
          }
        }
        fclose($fh);
        $pdo->commit();
        $msg = "Bulk upload complete — Inserted: $inserted, Updated: $updated, Skipped: $skipped";
        if ($errors) { $msg .= '. Some issues: ' . htmlspecialchars(implode(' | ', array_slice($errors,0,5))) . (count($errors)>5?' ...':''); }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = 'Bulk upload failed: ' . htmlspecialchars($e->getMessage());
      }
    } else {
      // Single product save (create or update existing by DB id / SKU)
      $sku = trim((string)($_POST['id'] ?? ''));
      $name = trim((string)($_POST['name'] ?? ''));
      $categorySel = trim((string)($_POST['category'] ?? ''));
      $category = $categorySel === '__new__' ? trim((string)($_POST['category_new'] ?? '')) : $categorySel;
      $price = (float)($_POST['price'] ?? 0);
      $discount = round((float)($_POST['discount_pct'] ?? 0), 2);
      $unit = trim((string)($_POST['unit'] ?? ''));
      if ($unit === '') { $unit = 'Box'; }
      $unit = mb_substr($unit, 0, 64);
      $imageUrl = trim((string)($_POST['image'] ?? ''));
      $editProductId = (int)($_POST['edit_product_id'] ?? 0);
      $editingExisting = $editProductId > 0 || (string)($_POST['editing_existing'] ?? '') === '1';

      // Auto-allocate SKU when creating and ID left blank (never while editing)
      if ($sku === '' && !$editingExisting && $category !== '') {
        $auto = price_list_next_sku_for_category($pdo, $category);
        if (!empty($auto['ok']) && !empty($auto['sku'])) {
          $sku = (string)$auto['sku'];
        }
      }

      if ($editingExisting && $editProductId <= 0 && $sku === '') {
        $err = 'Editing an existing product requires a Product ID. Click Edit again from the list.';
      } elseif ($sku === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $sku)) {
        $err = 'Provide a valid ID (letters, numbers, dash, underscore), or pick a category to auto-generate one.';
      } elseif ($name === '') {
        $err = 'Name is required.';
      } elseif ($category === '') {
        $err = 'Category is required.';
      } elseif ($price <= 0) {
        $err = 'Price must be greater than 0.';
      } elseif ($discount < 0 || $discount > 100) {
        $err = 'Discount must be between 0 and 100.';
      } else {
        try {
          $pdo->beginTransaction();
          // Upsert category if a new one is entered
          if ($categorySel === '__new__') {
            if ($category === '') { throw new RuntimeException('New category name is required.'); }
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/','-', $category), '-')) ?: substr(sha1($category),0,8);
            $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?) ON DUPLICATE KEY UPDATE slug=VALUES(slug)')->execute([$category, $slug]);
          }

          // Resolve target row: prefer edit_product_id (same product even if SKU renamed)
          $row = null;
          if ($editProductId > 0) {
            $st = $pdo->prepare('SELECT id, sku, image_url FROM products WHERE id = ? LIMIT 1');
            $st->execute([$editProductId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) {
              throw new RuntimeException('That product no longer exists (deleted or archived elsewhere). Click Edit again from the list.');
            }
          } else {
            $st = $pdo->prepare('SELECT id, sku, image_url FROM products WHERE sku = ? LIMIT 1');
            $st->execute([$sku]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
          }

          $isInsert = !$row;
          if ($row) {
            $pid = (int)$row['id'];
            $oldSku = (string)($row['sku'] ?? '');

            // Renaming Product ID: block if another row already owns the new SKU
            if ($oldSku !== '' && strcasecmp($oldSku, $sku) !== 0) {
              $chk = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND id <> ? LIMIT 1');
              $chk->execute([$sku, $pid]);
              if ($chk->fetch()) {
                throw new RuntimeException('Product ID ' . $sku . ' is already used by another product. Keep the current ID or choose a free one.');
              }
            }

            // Editing with blank Image URL: keep existing URL (avoid accidental wipe)
            if ($imageUrl === '' && !empty($row['image_url'])) {
              $imageUrl = (string)$row['image_url'];
            }

            $u = $pdo->prepare('UPDATE products SET sku=?, name=?, category=?, unit=?, price=?, discount_pct=?, image_url=? WHERE id=?');
            $u->execute([$sku, $name, $category, $unit, $price, $discount, ($imageUrl!==''?$imageUrl:null), $pid]);
          } else {
            // Must not invent a new row when the UI said we were editing
            if ($editingExisting) {
              throw new RuntimeException('Could not find the product to update. Click Edit on the product in the list, then Save.');
            }
            $i = $pdo->prepare('INSERT INTO products (sku, name, category, unit, price, discount_pct, image_url, is_active) VALUES (?,?,?,?,?,?,?,1)');
            try {
              $i->execute([$sku, $name, $category, $unit, $price, $discount, ($imageUrl!==''?$imageUrl:null)]);
            } catch (PDOException $dup) {
              // Concurrent create or user typed an existing SKU without clicking Edit → update that row instead
              $sqlState = (string)($dup->errorInfo[0] ?? '');
              $driverCode = (int)($dup->errorInfo[1] ?? 0);
              if ($sqlState === '23000' || $driverCode === 1062) {
                $st2 = $pdo->prepare('SELECT id, sku, image_url FROM products WHERE sku = ? LIMIT 1');
                $st2->execute([$sku]);
                $existing = $st2->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                  $pid = (int)$existing['id'];
                  if ($imageUrl === '' && !empty($existing['image_url'])) {
                    $imageUrl = (string)$existing['image_url'];
                  }
                  $u = $pdo->prepare('UPDATE products SET name=?, category=?, unit=?, price=?, discount_pct=?, image_url=? WHERE id=?');
                  $u->execute([$name, $category, $unit, $price, $discount, ($imageUrl!==''?$imageUrl:null), $pid]);
                  $isInsert = false;
                } else {
                  $retry = price_list_next_sku_for_category($pdo, $category);
                  if (empty($retry['ok']) || empty($retry['sku']) || (string)$retry['sku'] === $sku) {
                    throw $dup;
                  }
                  $sku = (string)$retry['sku'];
                  $i->execute([$sku, $name, $category, $unit, $price, $discount, ($imageUrl!==''?$imageUrl:null)]);
                  $pid = (int)$pdo->lastInsertId();
                }
              } else {
                throw $dup;
              }
            }
            if ($isInsert && !isset($pid)) {
              $pid = (int)$pdo->lastInsertId();
            }
          }

          // Handle file upload (optional)
          if (!empty($_FILES['upload']) && is_array($_FILES['upload']) && (int)$_FILES['upload']['error'] !== UPLOAD_ERR_NO_FILE) {
            $up = $_FILES['upload'];
            if ((int)$up['error'] === UPLOAD_ERR_OK && (int)$up['size'] > 0) {
              $sizeMb = $up['size'] / (1024*1024);
              if ($sizeMb > 8) { throw new RuntimeException('Image too large. Max 8 MB'); }
              $finfo = new finfo(FILEINFO_MIME_TYPE);
              $mime = $finfo->file($up['tmp_name']);
              $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => null,
              };
              if (!$ext) { throw new RuntimeException('Unsupported image type'); }
              $safeSku = preg_replace('/[^A-Za-z0-9_-]/', '-', $sku);
              $baseDir = realpath(__DIR__ . '/..') . '/images/products/' . $safeSku;
              if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true)) {
                throw new RuntimeException('Failed creating image directory');
              }
              $fileName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
              $dest = $baseDir . '/' . $fileName;
              if (!move_uploaded_file($up['tmp_name'], $dest)) {
                throw new RuntimeException('Failed to store uploaded image');
              }
              // Save relative path in DB; set primary if none exists yet
              $rel = 'images/products/' . $safeSku . '/' . $fileName;
              $hasPrimary = (int)$pdo->query('SELECT COUNT(*) FROM product_images WHERE product_id='.(int)$pid.' AND is_primary=1')->fetchColumn();
              $insImg = $pdo->prepare('INSERT INTO product_images (product_id, path, is_primary) VALUES (?,?,?)');
              $insImg->execute([$pid, $rel, $hasPrimary ? 0 : 1]);
            } else {
              // Normalize common upload errors
              if ((int)$up['error'] !== UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Upload failed with error code ' . (int)$up['error']);
              }
            }
          }

          $pdo->commit();
          $msg = ($isInsert ? 'Created' : 'Updated') . ' product ' . htmlspecialchars($sku);
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $dupMsg = $e instanceof PDOException && (
            (string)($e->errorInfo[0] ?? '') === '23000' || (int)($e->errorInfo[1] ?? 0) === 1062
          );
          $err = $dupMsg
            ? 'Product ID already exists. Click Edit on that product to update it, or Generate a new ID.'
            : ('Save failed: ' . htmlspecialchars($e->getMessage()));
        }
      }
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $msg !== '' && $err === '') {
  products_invalidate_shop_cache(true);
}

// Load products for listing with pagination, search, and primary image if any
$products = [];
$totalProducts = 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$per = (int)($_GET['per'] ?? 20);
$q = trim((string)($_GET['q'] ?? ''));
$catFilter = trim((string)($_GET['cat'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'active')); // active|archived|all
if ($per < 10) $per = 10; if ($per > 100) $per = 100;
try {
  ensure_products_catalog_schema($pdo);
  $where = [];
  $params = [];
  if ($q !== '') {
    $where[] = '(p.sku LIKE ? OR p.name LIKE ? OR p.category LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
  }
  if ($catFilter !== '') {
    $where[] = 'p.category = ?';
    $params[] = $catFilter;
  }
  if ($statusFilter === 'archived') {
    $where[] = 'COALESCE(p.is_active, 1) = 0';
  } elseif ($statusFilter !== 'all') {
    $where[] = 'COALESCE(p.is_active, 1) = 1';
  }
  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

  $stCnt = $pdo->prepare("SELECT COUNT(*) FROM products p $whereSql");
  $stCnt->execute($params);
  $totalProducts = (int)$stCnt->fetchColumn();
  $pages = max(1, (int)ceil($totalProducts / ($per ?: 1)));
  if ($page > $pages) $page = $pages;
  $offset = ($page - 1) * $per;
  $sql = "SELECT p.id, p.sku, p.name, p.category, p.unit, p.price, p.discount_pct,
                  p.image_url, COALESCE(p.is_active, 1) AS is_active,
                  (SELECT path FROM product_images pi WHERE pi.product_id=p.id AND pi.is_primary=1 ORDER BY pi.id LIMIT 1) AS primary_image,
                  (SELECT COUNT(*) FROM product_images pi2 WHERE pi2.product_id=p.id) AS img_count
           FROM products p
           $whereSql
           ORDER BY p.category, p.name
           LIMIT " . (int)$per . " OFFSET " . (int)$offset;
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $products = $st->fetchAll();
} catch (Throwable $__) { /* ignore if fresh */ }

// One-time nonce for apply (not dry-run)
if (empty($_SESSION['price_list_import_nonce'])) {
  $_SESSION['price_list_import_nonce'] = bin2hex(random_bytes(16));
}
$priceListImportNonce = (string)$_SESSION['price_list_import_nonce'];
$lastPdfMeta = price_list_last_pdf_meta();
$lastCsvMeta = price_list_last_csv_meta();
$lastCanonicalMeta = price_list_last_canonical_meta();
$bundledCanonicalOk = price_list_bundled_available();
$bundledCanonicalCount = 0;
if ($bundledCanonicalOk) {
  $bundledPeek = price_list_load_canonical_file(price_list_bundled_canonical_path());
  $bundledCanonicalCount = (int)($bundledPeek['line_count'] ?? 0);
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0c1222">
    <link rel="icon" type="image/png" href="/pkslogo.png">
    <link rel="apple-touch-icon" href="/pkslogo.png">
    <title>Products · PSK Admin</title>
    <style>
      :root{
        --bg:#0c1222;
        --panel:#151c2e;
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
        --pad: max(16px, env(safe-area-inset-left));
        --padr: max(16px, env(safe-area-inset-right));
        --page-max:1360px;
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
          radial-gradient(900px 420px at 10% -10%, rgba(249,115,22,.16), transparent 55%),
          radial-gradient(700px 360px at 90% 0%, rgba(225,29,72,.12), transparent 50%),
          linear-gradient(180deg, #0a1020 0%, #0c1222 40%, #0b101c 100%);
        padding-bottom: env(safe-area-inset-bottom);
      }
      a{color:#93c5fd;text-decoration:none}
      a:hover{color:#bfdbfe}
      code{font-size:.92em; color:#fdba74}

      .topbar{
        position:sticky; top:0; z-index:40;
        backdrop-filter:saturate(140%) blur(12px);
        background:rgba(12,18,34,.88);
        border-bottom:1px solid var(--line);
        padding-top: env(safe-area-inset-top);
      }
      .topbar-inner{
        max-width:var(--page-max); margin:0 auto;
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

      .wrap{max-width:var(--page-max); margin:0 auto; padding:18px var(--padr) 32px var(--pad)}

      .hero{
        display:flex; justify-content:space-between; align-items:flex-end; gap:14px; flex-wrap:wrap;
        margin-bottom:16px;
      }
      .hero h2{margin:0; font-size:clamp(1.2rem, 2.5vw, 1.45rem); font-weight:800}
      .hero p{margin:6px 0 0; color:var(--muted)}

      .btn{
        appearance:none; border:0; border-radius:10px; padding:10px 14px;
        background:linear-gradient(135deg, var(--brand), var(--brand2));
        color:#fff; font-weight:750; cursor:pointer;
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        transition:transform .12s ease, filter .12s ease;
        min-height:40px; text-decoration:none; font-size:13px;
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
      .btn-sm{padding:8px 12px; font-size:12px; border-radius:8px; min-height:40px}

      .card, .panel{
        background:rgba(21,28,46,.92);
        border:1px solid var(--line);
        border-radius:calc(var(--radius) + 2px);
        box-shadow:var(--shadow);
        overflow:hidden;
      }
      .card{padding:16px}
      .card h3, .panel-head h3{margin:0 0 8px; font-size:15px; font-weight:800}
      .panel{margin-top:16px}
      .panel-head{
        padding:14px 16px;
        border-bottom:1px solid var(--line);
        display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;
        background:rgba(255,255,255,.02);
      }
      .panel-head h3{margin:0}
      .panel-head .meta{color:var(--muted); font-size:12px}

      .grid{display:grid; grid-template-columns:minmax(0,2fr) minmax(0,3fr); gap:14px; align-items:start}
      .row{display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap}
      label{display:flex; flex-direction:column; gap:6px; min-width:160px; color:var(--muted); font-size:12px; font-weight:600}
      label > span, .field-hint{color:var(--muted)}

      input, select, textarea{
        padding:10px 11px; border-radius:10px;
        border:1px solid var(--line2);
        background:rgba(255,255,255,.05);
        color:var(--text); min-width:0; width:100%;
        font-size:16px;
      }
      input:focus, select:focus{
        outline:2px solid rgba(249,115,22,.35);
        border-color:rgba(249,115,22,.55);
      }
      input[type="file"]{padding:8px; font-size:14px}
      input[type="checkbox"]{width:auto; min-width:0; accent-color:var(--brand2)}

      .err{
        color:#fecaca; background:rgba(239,68,68,.15); border:1px solid rgba(239,68,68,.35);
        padding:10px 12px; border-radius:10px; margin-bottom:12px;
      }
      .ok{
        color:#bbf7d0; background:rgba(16,185,129,.15); border:1px solid rgba(16,185,129,.35);
        padding:10px 12px; border-radius:10px; margin-bottom:12px;
      }

      .filters{
        display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;
        padding:14px 16px; border-bottom:1px solid var(--line);
        background:rgba(0,0,0,.12);
      }
      .filters label{flex:1 1 140px; max-width:100%}
      .filters .filter-grow{flex:2 1 220px}
      .filters .filter-actions{display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding-bottom:1px; flex:0 0 auto}

      .table-desktop{width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch}
      table.products{width:100%; border-collapse:separate; border-spacing:0; min-width:920px}
      table.products th{
        text-align:left; padding:12px 14px;
        font-size:11px; letter-spacing:.4px; text-transform:uppercase; color:var(--muted);
        background:rgba(255,255,255,.03); border-bottom:1px solid var(--line);
        position:sticky; top:0; z-index:1;
      }
      table.products th.num, table.products td.num{text-align:right}
      table.products td{
        padding:12px 14px; border-bottom:1px solid var(--line);
        vertical-align:middle;
      }
      table.products tbody tr{transition:background .12s ease}
      table.products tbody tr:hover{background:rgba(255,255,255,.03)}
      table.products tbody tr.is-archived{opacity:.78}
      table.products tbody tr:last-child td{border-bottom:0}

      .sku-pill{
        display:inline-flex; align-items:center; max-width:100%;
        padding:4px 10px; border-radius:999px;
        background:rgba(249,115,22,.12); border:1px solid rgba(249,115,22,.28);
        color:#fdba74; font-weight:750; font-size:12px;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
      }
      .name-cell{display:flex; align-items:center; gap:10px; min-width:0; max-width:320px}
      .name-text{
        min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
        font-weight:650;
      }
      .cat-text{
        color:var(--muted); max-width:160px;
        overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
      }
      .thumb, .ph{
        width:36px; height:36px; border-radius:8px; flex:0 0 auto;
        border:1px solid rgba(255,255,255,.16);
        object-fit:cover; background:rgba(255,255,255,.06);
        display:inline-block;
      }
      .ph{box-shadow:inset 0 0 0 1px rgba(255,255,255,.04)}

      .badge{
        display:inline-flex; align-items:center; padding:3px 8px; border-radius:999px;
        font-size:11px; font-weight:750; border:1px solid transparent; white-space:nowrap;
      }
      .badge-ok{background:rgba(52,211,153,.12); border-color:rgba(52,211,153,.3); color:#6ee7b7}
      .badge-warn{background:rgba(251,191,36,.12); border-color:rgba(251,191,36,.3); color:#fcd34d}

      .actions{display:flex; gap:6px; flex-wrap:wrap; align-items:center}
      .actions form{display:inline; margin:0}

      .product-cards{display:none; padding:12px; gap:12px; flex-direction:column}
      .product-card{
        background:rgba(255,255,255,.03);
        border:1px solid var(--line);
        border-radius:12px;
        padding:14px;
        display:flex; flex-direction:column; gap:12px;
      }
      .product-card.is-archived{opacity:.85}
      .product-card-top{
        display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap;
      }
      .product-card-title{
        display:flex; gap:10px; align-items:flex-start; min-width:0; flex:1 1 180px;
      }
      .product-card-title .name-text{
        white-space:normal; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;
        overflow:hidden; word-break:break-word;
      }
      .product-card-grid{
        display:grid; grid-template-columns:1fr 1fr; gap:10px 12px;
      }
      .product-card-grid .full{grid-column:1 / -1}
      .field-label{
        display:block; color:var(--muted); font-size:11px; font-weight:650;
        text-transform:uppercase; letter-spacing:.3px; margin-bottom:4px;
      }
      .money strong{font-size:15px}

      .empty{
        padding:48px 20px; text-align:center; color:var(--muted);
      }
      .empty strong{display:block; color:var(--text); font-size:1.05rem; margin-bottom:6px}

      .pagi{
        display:flex; gap:8px; align-items:center; justify-content:space-between; flex-wrap:wrap;
        padding:14px 16px; border-top:1px solid var(--line);
      }
      .pagi .pages{display:flex; gap:6px; align-items:center; flex-wrap:wrap}
      .pagi .pages .btn.is-current{
        background:rgba(255,255,255,.12);
        border-color:rgba(255,255,255,.22);
        pointer-events:none;
      }
      .pagi .pages .btn.is-disabled{
        pointer-events:none; opacity:.55;
      }
      .pagi-meta{color:var(--muted); font-size:13px}

      .import-meta{color:var(--muted); font-size:12px; line-height:1.45}
      .check-row label{
        flex-direction:row; align-items:center; gap:8px; min-width:auto;
        color:var(--text); font-weight:550; font-size:13px;
      }

      @media (max-width:1024px){
        .nav-shell{flex:1 1 100%; justify-content:flex-start}
        .inv-nav{justify-content:flex-start; width:100%}
        .topbar-inner{align-items:flex-start}
        .grid{grid-template-columns:1fr}
      }

      @media (max-width:860px){
        .table-desktop{display:none}
        .product-cards{display:flex}
        .filters label{flex:1 1 140px; max-width:none}
        .filters .filter-actions{width:100%}
        .filters .filter-actions .btn{flex:1 1 auto}
        .name-cell{max-width:100%}
      }

      @media (max-width:640px){
        .brand-block p{max-width:58vw}
        .product-card-grid{grid-template-columns:1fr}
        .pagi{flex-direction:column; align-items:stretch}
        .pagi .pages{justify-content:stretch}
        .pagi .pages .btn{flex:1 1 auto; min-width:0}
        .inv-nav .btn, .inv-nav .ghost.btn{padding:8px 12px !important; font-size:12px}
        .row label{flex:1 1 100%; min-width:0}
      }

      @media (prefers-reduced-motion: reduce){
        .btn,.inv-nav .btn{transition:none}
        .btn:hover{transform:none}
      }
    </style>
  </head>
  <body>
<?php $user = admin_current_user(); ?>
    <div class="topbar">
      <div class="topbar-inner">
        <div class="brand-block">
          <div class="brand-mark" aria-hidden="true">PSK</div>
          <div>
            <h1>Products</h1>
            <p title="<?= htmlspecialchars((string)($user['username'] ?? 'admin')) ?>">Signed in as <?= htmlspecialchars((string)($user['username'] ?? 'admin')) ?></p>
          </div>
        </div>
        <div class="nav-shell"><?php include __DIR__ . '/_nav.php'; ?></div>
      </div>
    </div>

    <div class="wrap">
      <div class="hero">
        <div>
          <h2>Catalog management</h2>
          <p>Import price lists, create products, and manage the live shop catalog.</p>
        </div>
      </div>

      <?php if ($err): ?><div class="err" id="formFlash"><?= $err ?></div><?php endif; ?>
      <?php if ($msg): ?><div class="ok" id="formFlash"><?= $msg ?></div><?php endif; ?>

      <div class="card" style="margin-bottom:14px">
        <h3>Update from Price List PDF</h3>
        <p class="import-meta" style="margin:0 0 12px">
          <strong style="color:var(--text)">Applies the PSK 2026 price list (from sample Karthi PSK.pdf).</strong>
          Upload replaces the stored reference PDF and refreshes all matching product names &amp; market rates.
          Hostinger has no OCR — the built-in list (<?= (int)$bundledCanonicalCount ?> products) is the sync source when you upload PDF alone.
          Optional CSV overrides for a future edition that differs. Gift/family packs use <code>discount_pct=0</code>; others default to 80%.
          Products never hard-delete — missing items are archived when that option is on. Images are kept.
        </p>
        <div class="row" style="margin-bottom:10px;gap:12px;align-items:center">
          <a class="ghost btn btn-sm" href="<?= $routeBase ?>/inventory/product<?= htmlspecialchars($routeExt) ?>?download=price_list_template">Download CSV template</a>
          <?php if ($lastPdfMeta): ?>
            <span class="import-meta">Last PDF: <?= htmlspecialchars((string)($lastPdfMeta['original_name'] ?? $lastPdfMeta['stored_as'] ?? '')) ?>
              (<?= htmlspecialchars((string)($lastPdfMeta['uploaded_at'] ?? '')) ?>)</span>
          <?php endif; ?>
          <?php if ($lastCanonicalMeta): ?>
            <span class="import-meta">Last applied list: <?= (int)($lastCanonicalMeta['line_count'] ?? 0) ?> lines
              (<?= htmlspecialchars((string)($lastCanonicalMeta['saved_at'] ?? '')) ?>)</span>
          <?php elseif ($bundledCanonicalOk): ?>
            <span class="import-meta">Built-in PSK 2026 list ready (<?= (int)$bundledCanonicalCount ?> lines)</span>
          <?php endif; ?>
          <?php if ($lastCsvMeta): ?>
            <span class="import-meta">Last CSV override: <?= (int)($lastCsvMeta['line_count'] ?? 0) ?> lines
              (<?= htmlspecialchars((string)($lastCsvMeta['uploaded_at'] ?? '')) ?>)</span>
          <?php endif; ?>
        </div>

        <form method="post" action="<?= $routeBase ?>/inventory/product<?=$routeExt ?>" enctype="multipart/form-data" style="margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid var(--line)">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
          <input type="hidden" name="action" value="pdf_upload">
          <div class="row">
            <label style="flex:1 1 240px">1) Store reference PDF (Karthi PSK.pdf)
              <input type="file" name="price_pdf" accept=".pdf,application/pdf" required>
            </label>
            <div style="align-self:flex-end">
              <button class="ghost btn" type="submit">Upload PDF only</button>
            </div>
          </div>
        </form>

        <form method="post" action="<?= $routeBase ?>/inventory/product<?=$routeExt ?>" enctype="multipart/form-data" id="pdfImportForm">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
          <input type="hidden" name="action" value="pdf_import">
          <input type="hidden" name="import_nonce" value="<?= htmlspecialchars($priceListImportNonce) ?>">
          <div class="row" style="align-items:flex-end">
            <label style="flex:1 1 220px">PDF (optional — stores reference copy)
              <input type="file" name="price_pdf" accept=".pdf,application/pdf">
            </label>
            <label style="flex:1 1 220px">CSV override (only if list differs from 2026 sample)
              <input type="file" name="price_csv" accept=".csv,text/csv">
            </label>
          </div>
          <div class="row check-row" style="margin-top:10px;align-items:center">
            <label>
              <input type="checkbox" name="use_canonical" value="1" <?= $bundledCanonicalOk ? 'checked' : 'disabled' ?>>
              Use built-in / last-applied PSK price list (PDF alone)
            </label>
            <label>
              <input type="checkbox" name="prefer_bundled_canonical" value="1">
              Force sample Karthi PSK.pdf 2026 list (ignore last applied)
            </label>
            <label>
              <input type="checkbox" name="dry_run" value="1" checked>
              Dry-run preview (no DB writes)
            </label>
            <label>
              <input type="checkbox" name="archive_missing" value="1" checked>
              Archive active products not on this list
            </label>
            <label>
              <input type="checkbox" name="use_last_csv" value="1" <?= price_list_last_csv_path() ? '' : 'disabled' ?>>
              Use last uploaded CSV instead
            </label>
          </div>
          <div class="row" style="justify-content:flex-end;margin-top:12px;gap:8px">
            <button class="ghost btn" type="submit" onclick="var d=this.form.querySelector('input[name=dry_run]'); if(d) d.checked=true;">Preview dry-run</button>
            <button class="btn" type="submit" onclick="var d=this.form.querySelector('input[name=dry_run]'); if(d) d.checked=false; return confirm('Apply PSK price list names & rates to the live catalog?');">Apply changes</button>
          </div>
          <div class="import-meta" style="margin-top:10px">
            With no CSV: syncs from <code>data/psk_2026_canonical_price_list.json</code> (or <code>storage/price_lists/last_canonical.json</code> after a prior apply).
            CSV headers if overriding: <code>sno,name,category,unit,market_rate,discount_pct</code> (optional <code>sku</code>).
            Matching: explicit SKU → saved PDF map → unique normalized name. Ambiguous names are skipped.
          </div>
        </form>

        <?php if ((is_file(price_list_last_canonical_path())) || price_list_last_csv_path() || $bundledCanonicalOk): ?>
        <form method="post" action="<?= $routeBase ?>/inventory/product<?=$routeExt ?>" style="margin-top:12px" onsubmit="return confirm('Re-apply the current PSK price list to the catalog?');">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
          <input type="hidden" name="action" value="pdf_reapply">
          <input type="hidden" name="import_nonce" value="<?= htmlspecialchars($priceListImportNonce) ?>">
          <input type="hidden" name="archive_missing" value="1">
          <div class="row" style="align-items:center;gap:10px">
            <button class="ghost btn" type="submit">Re-apply current price list</button>
            <label style="flex-direction:row;align-items:center;gap:6px;min-width:auto;color:var(--text)">
              <input type="checkbox" name="dry_run" value="1"> Dry-run
            </label>
          </div>
        </form>
        <?php endif; ?>

        <?php if (is_array($pdfImportReport)): ?>
          <details style="margin-top:14px" open>
            <summary style="cursor:pointer;font-weight:700">Import details</summary>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;margin-top:8px;font-size:12px">
              <?php
                $buckets = [
                  'Added' => $pdfImportReport['added'] ?? [],
                  'Updated' => $pdfImportReport['updated'] ?? [],
                  'Archived' => $pdfImportReport['archived'] ?? [],
                  'Skipped' => $pdfImportReport['skipped'] ?? [],
                  'Unchanged' => $pdfImportReport['unchanged'] ?? [],
                ];
                foreach ($buckets as $label => $items):
              ?>
                <div style="border:1px solid var(--line);border-radius:8px;padding:8px;max-height:180px;overflow:auto;background:rgba(0,0,0,.12)">
                  <div style="font-weight:700;margin-bottom:4px"><?= htmlspecialchars($label) ?> (<?= count($items) ?>)</div>
                  <?php if (!$items): ?>
                    <div style="color:var(--muted)">—</div>
                  <?php else: foreach (array_slice($items, 0, 40) as $it): ?>
                    <div style="color:var(--muted);margin-bottom:2px;word-break:break-word">
                      <?= htmlspecialchars(trim(($it['sku'] ?? '') . ' ' . ($it['name'] ?? '') . (isset($it['reason']) ? ' — ' . $it['reason'] : '') . (isset($it['new_price']) ? ' → ₹' . $it['new_price'] : ''))) ?>
                    </div>
                  <?php endforeach; if (count($items) > 40): ?>
                    <div style="color:var(--muted)">… +<?= count($items) - 40 ?> more</div>
                  <?php endif; endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endif; ?>
      </div>

      <div class="grid">
        <div class="card">
          <h3 id="productFormTitle">Create / Update Product</h3>
          <form method="post" action="<?= $routeBase ?>/inventory/product<?=$routeExt ?>" enctype="multipart/form-data" id="productEditForm">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="single">
            <input type="hidden" name="editing_existing" id="editingExisting" value="0">
            <input type="hidden" name="edit_product_id" id="editProductId" value="">
            <div class="row">
              <label style="flex:1 1 160px">Category
                <select id="catSel" name="category" required>
                  <option value="">Select</option>
                  <?php foreach ($cats as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                  <?php endforeach; ?>
                  <option value="__new__">➕ Add new…</option>
                </select>
              </label>
              <label id="catNewWrap" style="display:none;flex:1 1 160px">New Category
                <input id="catNew" name="category_new" placeholder="Type new category name">
              </label>
            </div>
            <div class="row" style="align-items:flex-end">
              <label style="flex:1 1 160px">Product ID
                <input name="id" id="productSku" required placeholder="Auto from category…">
              </label>
              <div style="display:flex;gap:8px;flex-wrap:wrap;padding-bottom:2px">
                <button type="button" class="ghost btn btn-sm" id="btnGenSku" title="Allocate next SKU for this category’s prefix">Generate</button>
                <button type="button" class="ghost btn btn-sm" id="btnNewProduct" title="Clear form for a new product">New</button>
              </div>
              <label style="flex:1 1 180px">Name
                <input name="name" required placeholder="Product name">
              </label>
            </div>
            <p class="import-meta" id="skuHint" style="margin:0 0 8px">Pick a category to auto-fill the next Product ID (e.g. FP-011). Prefix is global — FP is shared by flower pots &amp; family packs. Manual override allowed.</p>
            <div class="row">
              <label style="flex:1 1 120px">Price (₹)
                <input type="number" name="price" step="0.01" min="0" required>
              </label>
              <label style="flex:1 1 120px">Discount %
                <input type="number" name="discount_pct" step="any" min="0" max="100" value="80">
              </label>
              <label style="flex:1 1 140px">Unit <span style="font-weight:500">(defaults to Box)</span>
                <input name="unit" placeholder="Box, Pack, Piece…" maxlength="64">
              </label>
            </div>
            <div class="row">
              <label style="flex:1 1 180px">Image URL (optional)
                <input name="image" placeholder="https://...">
              </label>
              <label style="flex:1 1 180px">Upload Image (optional)
                <input type="file" name="upload" accept="image/*">
              </label>
            </div>
            <div class="row" style="justify-content:flex-end;margin-top:10px">
              <button class="btn" type="submit" id="btnSaveProduct">Save Product</button>
            </div>
          </form>
          <p class="import-meta" style="margin-top:10px">Tip: To show local photos in the public shop, place images under <code>images/products/{id}/</code>. Otherwise the Image URL will be used.</p>
        </div>

        <div class="card">
          <h3>Bulk Upload (CSV)</h3>
          <form method="post" action="<?= $routeBase ?>/inventory/product<?=$routeExt ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="bulk">
            <div class="row">
              <label style="flex:1 1 220px">CSV File
                <input type="file" name="csv" accept=".csv,text/csv" required>
              </label>
              <div style="align-self:flex-end">
                <button class="btn" type="submit">Upload CSV</button>
              </div>
            </div>
            <div class="import-meta" style="margin-top:10px;line-height:1.45">
              Expected headers: <code>sku</code> (or <code>id</code>), <code>name</code>, <code>category</code>, <code>price</code>, optional <code>discount_pct</code>, <code>unit</code>, <code>image_url</code>.
            </div>
          </form>
        </div>
      </div>

<?php
  // Presentation helpers for product list actions (same POST contracts as before)
  $renderProductActions = static function(array $p, string $routeExt, bool $isActive): void {
    $sku = (string)($p['sku'] ?? '');
?>
                    <div class="actions">
                      <button
                        type="button"
                        class="ghost btn btn-sm btn-edit"
                        data-id="<?= (int)($p['id'] ?? 0) ?>"
                        data-sku="<?= htmlspecialchars($sku) ?>"
                        data-name="<?= htmlspecialchars((string)($p['name'] ?? '')) ?>"
                        data-category="<?= htmlspecialchars((string)($p['category'] ?? '')) ?>"
                        data-price="<?= htmlspecialchars((string)($p['price'] ?? '')) ?>"
                        data-discount="<?= htmlspecialchars(format_discount_pct($p['discount_pct'] ?? 0)) ?>"
                        data-unit="<?= htmlspecialchars((string)($p['unit'] ?? '')) ?>"
                        data-image="<?= htmlspecialchars((string)($p['image_url'] ?? '')) ?>"
                      >Edit</button>
                      <form method="post" action="<?= $routeBase ?>/inventory/product<?=$routeExt ?>" onsubmit="return confirm('<?= $isActive ? 'Archive this product? It will hide from the public pricing list.' : 'Restore this product to the public pricing list?' ?>')">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                        <input type="hidden" name="action" value="<?= $isActive ? 'archive' : 'restore' ?>">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($sku) ?>">
                        <button class="ghost btn btn-sm" type="submit"><?= $isActive ? 'Archive' : 'Restore' ?></button>
                      </form>
                      <form method="post" action="<?= $routeBase ?>/inventory/product<?=$routeExt ?>" onsubmit="return confirm('Permanently delete product & images?')">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($sku) ?>">
                        <button class="ghost btn btn-sm btn-danger" type="submit">Delete</button>
                      </form>
                    </div>
<?php
  };

  $pages = max(1, (int)ceil(($totalProducts ?: 0)/$per));
  $page = max(1, min($page, $pages));
  $start = $totalProducts ? ($page - 1) * $per + 1 : 0;
  $end = min($totalProducts, $page * $per);
  $qs = array_filter([
    'q' => $q !== '' ? $q : null,
    'cat' => $catFilter !== '' ? $catFilter : null,
    'status' => $statusFilter !== 'active' ? $statusFilter : null,
    'per' => (int)$per,
  ], static fn($v) => $v !== null && $v !== '');
  $base = $routeBase . '/inventory/product' . $routeExt . '?' . http_build_query($qs) . ($qs ? '&' : '') . 'page=';
  $statusLabel = $statusFilter === 'archived' ? 'Archived' : ($statusFilter === 'all' ? 'All statuses' : 'Active (shop)');
?>
      <section class="panel" id="existing-products" aria-labelledby="existing-products-title">
        <div class="panel-head">
          <div>
            <h3 id="existing-products-title">Existing Products (<?= (int)$totalProducts ?>)</h3>
            <div class="meta"><?= htmlspecialchars($statusLabel) ?> · page <?= (int)$page ?> of <?= (int)$pages ?></div>
          </div>
        </div>

        <form method="get" action="<?= $routeBase ?>/inventory/product<?=$routeExt ?>" class="filters">
          <label class="filter-grow">Search
            <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="SKU, name, category…" enterkeyhint="search">
          </label>
          <label>Category
            <select name="cat">
              <option value="">All</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $catFilter===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Status
            <select name="status">
              <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active (shop)</option>
              <option value="archived" <?= $statusFilter==='archived'?'selected':'' ?>>Archived</option>
              <option value="all" <?= $statusFilter==='all'?'selected':'' ?>>All</option>
            </select>
          </label>
          <label>Per page
            <select name="per">
              <?php foreach ([10,20,50,100] as $opt): ?>
                <option value="<?= $opt ?>" <?= $per===$opt?'selected':'' ?>><?= $opt ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="filter-actions">
            <button class="btn" type="submit">Filter</button>
          </div>
        </form>

        <?php if (!$products): ?>
          <div class="empty">
            <strong>No products match</strong>
            Try clearing search or switching status / category filters.
          </div>
        <?php else: ?>

        <div class="table-desktop" role="region" aria-label="Products table" tabindex="0">
          <table class="products">
            <thead>
              <tr>
                <th style="width:120px">ID</th>
                <th>Name</th>
                <th style="width:160px">Category</th>
                <th style="width:90px">Status</th>
                <th class="num" style="width:100px">Price</th>
                <th class="num" style="width:90px">Discount</th>
                <th class="num" style="width:80px">Images</th>
                <th style="width:260px">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($products as $p):
                $isActive = (int)($p['is_active'] ?? 1) === 1;
                $sku = (string)($p['sku'] ?? '');
                $pname = (string)($p['name'] ?? '');
                $pcat = (string)($p['category'] ?? '');
              ?>
              <tr class="<?= $isActive ? '' : 'is-archived' ?>">
                <td>
                  <span class="sku-pill" title="<?= htmlspecialchars($sku) ?>"><?= htmlspecialchars($sku) ?></span>
                </td>
                <td>
                  <div class="name-cell">
                    <?php if (!empty($p['primary_image'])): ?>
                      <img class="thumb" src="/<?= htmlspecialchars($p['primary_image']) ?>" alt="" loading="lazy" width="36" height="36">
                    <?php else: ?>
                      <span class="ph" aria-hidden="true" title="No image"></span>
                    <?php endif; ?>
                    <span class="name-text" title="<?= htmlspecialchars($pname) ?>"><?= htmlspecialchars($pname) ?></span>
                  </div>
                </td>
                <td><div class="cat-text" title="<?= htmlspecialchars($pcat) ?>"><?= htmlspecialchars($pcat) ?></div></td>
                <td>
                  <?php if ($isActive): ?>
                    <span class="badge badge-ok">Active</span>
                  <?php else: ?>
                    <span class="badge badge-warn">Archived</span>
                  <?php endif; ?>
                </td>
                <td class="num">₹<?= number_format((float)($p['price'] ?? 0),2) ?></td>
                <td class="num"><?= htmlspecialchars(format_discount_pct($p['discount_pct'] ?? 0)) ?>%</td>
                <td class="num"><?= (int)($p['img_count'] ?? 0) ?></td>
                <td><?php $renderProductActions($p, $routeExt, $isActive); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="product-cards" aria-label="Products list">
          <?php foreach ($products as $p):
            $isActive = (int)($p['is_active'] ?? 1) === 1;
            $sku = (string)($p['sku'] ?? '');
            $pname = (string)($p['name'] ?? '');
            $pcat = (string)($p['category'] ?? '');
          ?>
          <article class="product-card<?= $isActive ? '' : ' is-archived' ?>">
            <div class="product-card-top">
              <div class="product-card-title">
                <?php if (!empty($p['primary_image'])): ?>
                  <img class="thumb" src="/<?= htmlspecialchars($p['primary_image']) ?>" alt="" loading="lazy" width="36" height="36">
                <?php else: ?>
                  <span class="ph" aria-hidden="true" title="No image"></span>
                <?php endif; ?>
                <div style="min-width:0">
                  <div class="name-text" title="<?= htmlspecialchars($pname) ?>"><?= htmlspecialchars($pname) ?></div>
                  <div style="margin-top:6px">
                    <span class="sku-pill" title="<?= htmlspecialchars($sku) ?>"><?= htmlspecialchars($sku) ?></span>
                  </div>
                </div>
              </div>
              <?php if ($isActive): ?>
                <span class="badge badge-ok">Active</span>
              <?php else: ?>
                <span class="badge badge-warn">Archived</span>
              <?php endif; ?>
            </div>
            <div class="product-card-grid">
              <div>
                <span class="field-label">Category</span>
                <div title="<?= htmlspecialchars($pcat) ?>" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($pcat !== '' ? $pcat : '—') ?></div>
              </div>
              <div class="money">
                <span class="field-label">Price</span>
                <strong>₹<?= number_format((float)($p['price'] ?? 0),2) ?></strong>
              </div>
              <div>
                <span class="field-label">Discount</span>
                <div><?= htmlspecialchars(format_discount_pct($p['discount_pct'] ?? 0)) ?>%</div>
              </div>
              <div>
                <span class="field-label">Images</span>
                <div><?= (int)($p['img_count'] ?? 0) ?></div>
              </div>
            </div>
            <?php $renderProductActions($p, $routeExt, $isActive); ?>
          </article>
          <?php endforeach; ?>
        </div>

        <?php endif; ?>

        <div class="pagi">
          <div class="pagi-meta">Showing <?= (int)$start ?>–<?= (int)$end ?> of <?= (int)$totalProducts ?></div>
          <div class="pages">
            <a class="ghost btn btn-sm<?= $page<=1 ? ' is-disabled' : '' ?>" href="<?= htmlspecialchars($base . max(1,$page-1)) ?>" <?= $page<=1?'aria-disabled="true"':'' ?>>Prev</a>
            <?php for($i=1;$i<=$pages;$i++): if ($i===1 || $i===$pages || abs($i-$page)<=2): ?>
              <a class="ghost btn btn-sm<?= $i===$page?' is-current':'' ?>" href="<?= htmlspecialchars($base . $i) ?>" <?= $i===$page?'aria-current="page"':'' ?>><?= (int)$i ?></a>
            <?php elseif ($i==2 && $page>4): ?>
              <span style="padding:6px 4px;color:var(--muted)">…</span>
            <?php elseif ($i==$pages-1 && $page<$pages-3): ?>
              <span style="padding:6px 4px;color:var(--muted)">…</span>
            <?php endif; endfor; ?>
            <a class="ghost btn btn-sm<?= $page>=$pages ? ' is-disabled' : '' ?>" href="<?= htmlspecialchars($base . min($pages,$page+1)) ?>" <?= $page>=$pages?'aria-disabled="true"':'' ?>>Next</a>
          </div>
        </div>
      </section>
    </div>
    <script>
      // Prefill the product form when clicking Edit + auto SKU by category
      (function(){
        const form = document.getElementById('productEditForm');
        if (!form) return;
        const selCat = document.getElementById('catSel');
        const wrap = document.getElementById('catNewWrap');
        const inp = document.getElementById('catNew');
        const skuInput = document.getElementById('productSku');
        const editingEl = document.getElementById('editingExisting');
        const editIdEl = document.getElementById('editProductId');
        const formTitle = document.getElementById('productFormTitle');
        const btnSave = document.getElementById('btnSaveProduct');
        const hint = document.getElementById('skuHint');
        const btnGen = document.getElementById('btnGenSku');
        const btnNew = document.getElementById('btnNewProduct');
        let editing = false;
        let skuTouched = false;
        let fetchTimer = null;
        const nextSkuUrl = <?= json_encode($routeBase . '/inventory/product' . $routeExt) ?>;

        function setEditing(on, productId){
          editing = !!on;
          if (editingEl) editingEl.value = editing ? '1' : '0';
          if (editIdEl) editIdEl.value = editing && productId ? String(productId) : '';
          if (formTitle) formTitle.textContent = editing ? 'Update Product' : 'Create / Update Product';
          if (btnSave) btnSave.textContent = editing ? 'Update Product' : 'Save Product';
        }
        function syncCatNew(){
          const addNew = !!(selCat && selCat.value === '__new__');
          if (wrap) wrap.style.display = addNew ? 'flex' : 'none';
          if (inp) {
            // Hidden+required fields block Save with no visible error — disable when unused
            inp.disabled = !addNew;
            inp.required = addNew;
            if (!addNew) inp.value = '';
          }
        }
        function categoryForSku(){
          if (!selCat) return '';
          if (selCat.value === '__new__') return (inp && inp.value.trim()) || '';
          return selCat.value || '';
        }
        function pickCategory(cat){
          if (!selCat) return;
          const wanted = (cat || '').trim();
          let match = Array.from(selCat.options).find(o => o.value === wanted);
          if (!match && wanted) {
            const lower = wanted.toLowerCase();
            match = Array.from(selCat.options).find(o => o.value.toLowerCase() === lower);
          }
          if (match && match.value !== '__new__' && match.value !== '') {
            selCat.value = match.value;
          } else if (wanted) {
            selCat.value = '__new__';
            if (inp) { inp.disabled = false; inp.value = wanted; }
          } else {
            selCat.value = '';
          }
          syncCatNew();
          if (selCat.value === '__new__' && inp && wanted) inp.value = wanted;
        }
        async function fetchNextSku(force){
          const cat = categoryForSku();
          if (!cat) {
            if (hint) hint.textContent = 'Pick a category to auto-fill the next Product ID.';
            return;
          }
          // Edit mode: never overwrite an existing SKU unless Generate (force) or field empty
          if (editing && !force && skuInput && skuInput.value.trim() !== '') return;
          if (!force && skuTouched && skuInput && skuInput.value.trim() !== '') return;
          try {
            const params = new URLSearchParams({ action: 'next_sku', category: selCat.value === '__new__' ? '__new__' : cat });
            if (selCat.value === '__new__') params.set('category_new', cat);
            const res = await fetch(nextSkuUrl + '?' + params.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (!data || !data.ok) {
              if (hint) hint.textContent = (data && data.error) ? data.error : 'Could not allocate SKU.';
              return;
            }
            if (skuInput && (force || !editing || !skuInput.value.trim())) {
              skuInput.value = data.sku;
              skuTouched = false;
            }
            if (hint) {
              if (editing) {
                hint.textContent = 'Editing this product — new ID ' + data.sku + ' will rename the same product on Update (not create a duplicate).';
              } else {
                hint.textContent = 'Next ID: ' + data.sku + ' (prefix ' + data.prefix + ', last used ' + data.max_used + '). '
                  + 'Numbering is global per prefix — FP is shared by flower pots & family packs. Manual override OK.';
              }
            }
          } catch (e) {
            if (hint) hint.textContent = 'Could not fetch next SKU.';
          }
        }
        function scheduleFetch(){
          if (fetchTimer) clearTimeout(fetchTimer);
          fetchTimer = setTimeout(() => fetchNextSku(false), 180);
        }

        if (selCat) selCat.addEventListener('change', () => { syncCatNew(); scheduleFetch(); });
        if (inp) inp.addEventListener('input', () => { if (selCat && selCat.value === '__new__') scheduleFetch(); });
        if (skuInput) skuInput.addEventListener('input', () => { skuTouched = true; });
        if (btnGen) btnGen.addEventListener('click', () => {
          if (editing) {
            const ok = window.confirm('Generate a new Product ID for this existing product? Saving will rename this same product (not create a new one).');
            if (!ok) return;
          }
          skuTouched = false;
          fetchNextSku(true);
        });
        if (btnNew) btnNew.addEventListener('click', () => {
          setEditing(false);
          skuTouched = false;
          form.reset();
          if (editingEl) editingEl.value = '0';
          if (editIdEl) editIdEl.value = '';
          const disc = form.querySelector('input[name="discount_pct"]');
          if (disc) disc.value = '80';
          syncCatNew();
          if (hint) hint.textContent = 'Pick a category to auto-fill the next Product ID.';
          if (skuInput) skuInput.focus();
        });

        // Ensure Save always posts while editing (fix hidden-field validation + edit ids)
        form.addEventListener('submit', (e) => {
          syncCatNew();
          if (editing) {
            if (editingEl) editingEl.value = '1';
            if (editIdEl && !editIdEl.value && form.dataset.editId) {
              editIdEl.value = form.dataset.editId;
            }
          }
          if (!form.checkValidity()) {
            e.preventDefault();
            form.reportValidity();
            return;
          }
        });

        syncCatNew();

        document.querySelectorAll('.btn-edit').forEach(btn => {
          btn.addEventListener('click', () => {
            const get = (sel) => form.querySelector(sel);
            const setVal = (sel, v) => { const el = get(sel); if (el) el.value = v ?? ''; };
            const pid = btn.dataset.id || '';
            setEditing(true, pid);
            form.dataset.editId = pid;
            skuTouched = true; // preserve existing SKU when category changes
            setVal('input[name="id"]', btn.dataset.sku || '');
            setVal('input[name="name"]', btn.dataset.name || '');
            pickCategory(btn.dataset.category || '');
            setVal('input[name="price"]', btn.dataset.price || '');
            setVal('input[name="discount_pct"]', btn.dataset.discount || '0');
            setVal('input[name="unit"]', btn.dataset.unit || '');
            setVal('input[name="image"]', btn.dataset.image || '');
            // Re-assert edit markers after all field writes
            if (editingEl) editingEl.value = '1';
            if (editIdEl) editIdEl.value = pid;
            if (hint) hint.textContent = 'Editing ' + (btn.dataset.sku || '') + ' — change any field and click Update Product to save.';
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            const nameEl = form.querySelector('input[name="name"]');
            if (nameEl) nameEl.focus();
          });
        });
      })();
    </script>
  </body>
</html>
