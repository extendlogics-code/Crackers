<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/categories.php';
require_once __DIR__ . '/../lib/routes.php';
admin_require_login();

$routeExt = route_extension();

$cats = load_categories();
$msg = '';
$err = '';

// Ensure DB tables exist and have required columns (lightweight migration)
try {
  $pdo = get_pdo();
  $pdo->exec("CREATE TABLE IF NOT EXISTS products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(190) NOT NULL,
    unit VARCHAR(64) NULL,
    price DECIMAL(10,2) NOT NULL,
    discount_pct INT UNSIGNED NOT NULL DEFAULT 0,
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
    'unit' => 'VARCHAR(64) NULL',
    'price' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
    'discount_pct' => 'INT UNSIGNED NOT NULL DEFAULT 0',
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
    // Delete product by SKU
    if ($action === 'delete') {
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
          $price = (float)(($idx['price']!==false) ? $row[$idx['price']] : 0);
          $discount = (int)(($idx['discount_pct']!==false) ? $row[$idx['discount_pct']] : 0);
          $imageUrl = ($idx['image_url']!==false) ? trim((string)($row[$idx['image_url']] ?? '')) : (($idx['image']!==false) ? trim((string)($row[$idx['image']] ?? '')) : '');

          // Basic validation
          if ($sku === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $sku)) { $errors[] = "Line $lineNo: invalid sku"; $skipped++; continue; }
          if ($name === '' || $category === '' || $price <= 0) { $errors[] = "Line $lineNo: missing name/category/price"; $skipped++; continue; }
          if ($discount < 0 || $discount > 100) { $errors[] = "Line $lineNo: discount out of range"; $skipped++; continue; }

          $sel->execute([$sku]);
          $ex = $sel->fetch();
          if ($ex) {
            $pid = (int)$ex['id'];
            $upd->execute([$name, $category, ($unit!==''?$unit:null), $price, $discount, ($imageUrl!==''?$imageUrl:null), $pid]);
            $updated++;
          } else {
            $ins->execute([$sku, $name, $category, ($unit!==''?$unit:null), $price, $discount, ($imageUrl!==''?$imageUrl:null)]);
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
      // Single product save
      $sku = trim((string)($_POST['id'] ?? ''));
      $name = trim((string)($_POST['name'] ?? ''));
      $categorySel = trim((string)($_POST['category'] ?? ''));
      $category = $categorySel === '__new__' ? trim((string)($_POST['category_new'] ?? '')) : $categorySel;
      $price = (float)($_POST['price'] ?? 0);
      $discount = (int)($_POST['discount_pct'] ?? 0);
      $unit = trim((string)($_POST['unit'] ?? ''));
      $imageUrl = trim((string)($_POST['image'] ?? ''));

      if ($sku === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $sku)) {
        $err = 'Provide a valid ID (letters, numbers, dash, underscore).';
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
          // Upsert product by sku
          $st = $pdo->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
          $st->execute([$sku]);
          $row = $st->fetch();
          if ($row) {
            $pid = (int)$row['id'];
            $u = $pdo->prepare('UPDATE products SET name=?, category=?, unit=?, price=?, discount_pct=?, image_url=? WHERE id=?');
            $u->execute([$name, $category, ($unit!==''?$unit:null), $price, $discount, ($imageUrl!==''?$imageUrl:null), $pid]);
          } else {
            $i = $pdo->prepare('INSERT INTO products (sku, name, category, unit, price, discount_pct, image_url) VALUES (?,?,?,?,?,?,?)');
            $i->execute([$sku, $name, $category, ($unit!==''?$unit:null), $price, $discount, ($imageUrl!==''?$imageUrl:null)]);
            $pid = (int)$pdo->lastInsertId();
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
          $msg = 'Saved product ' . htmlspecialchars($sku);
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $err = 'Save failed: ' . htmlspecialchars($e->getMessage());
        }
      }
    }
  }
}

// Load products for listing with pagination and primary image if any
$products = [];
$totalProducts = 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$per = (int)($_GET['per'] ?? 20);
if ($per < 10) $per = 10; if ($per > 100) $per = 100;
try {
  $totalProducts = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
  $pages = max(1, (int)ceil($totalProducts / ($per ?: 1)));
  if ($page > $pages) $page = $pages;
  $offset = ($page - 1) * $per;
  $sql = "SELECT p.id, p.sku, p.name, p.category, p.unit, p.price, p.discount_pct,
                  p.image_url,
                  (SELECT path FROM product_images pi WHERE pi.product_id=p.id AND pi.is_primary=1 ORDER BY pi.id LIMIT 1) AS primary_image,
                  (SELECT COUNT(*) FROM product_images pi2 WHERE pi2.product_id=p.id) AS img_count
           FROM products p ORDER BY p.name LIMIT :lim OFFSET :off";
  $st = $pdo->prepare($sql);
  $st->bindValue(':lim', (int)$per, PDO::PARAM_INT);
  $st->bindValue(':off', (int)$offset, PDO::PARAM_INT);
  $st->execute();
  $products = $st->fetchAll();
} catch (Throwable $__) { /* ignore if fresh */ }
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventory • Products</title>
    <style>
      :root{ --bg:#0b1020; --text:#e7eefc; --muted:#9fb4d6; --brand:#e11d48; --brand2:#f97316 }
      body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
      header{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.1)}
      a{color:#93c5fd;text-decoration:none}
      .wrap{max-width:1100px;margin:0 auto;padding:12px 16px}
      input,select{padding:8px 10px;border-radius:8px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.06);color:var(--text)}
      .btn{appearance:none;border:0;border-radius:8px;padding:8px 12px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;cursor:pointer}
      .ghost{background:transparent;border:1px solid rgba(255,255,255,.2)}
      .grid{display:grid;grid-template-columns:2fr 3fr;gap:14px}
      .card{padding:12px;border:1px solid rgba(255,255,255,.12);border-radius:10px;background:rgba(255,255,255,.05)}
      .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
      label{display:flex;flex-direction:column;gap:6px;min-width:180px}
      .err{color:#fecaca;background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.35);padding:8px 10px;border-radius:8px}
      .ok{color:#bbf7d0;background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.35);padding:8px 10px;border-radius:8px}
      table{width:auto;border-collapse:collapse;table-layout:fixed}
      th,td{padding:8px;border-bottom:1px solid rgba(255,255,255,.1);text-align:left;vertical-align:middle}
      th{background:rgba(255,255,255,.06)}
      /* helpers for single-line row alignment */
      .name-cell{display:flex;align-items:center;gap:8px;min-width:0}
      .name-text{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .ph{display:inline-block;width:28px;height:28px;border-radius:4px;border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.06)}
      @media (max-width:900px){ .grid{grid-template-columns:1fr} }
    </style>
  </head>
  <body>
    <header>
      <strong>Products</strong>
      <div>
        <a class="ghost btn" href="/inventory/dashboard<?= $routeExt ?>">Orders</a>
        <a class="ghost btn" href="/inventory/login<?= $routeExt ?>?logout=1">Logout</a>
      </div>
    </header>
    <div class="wrap">
      <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <?php if ($msg): ?><div class="ok"><?= $msg ?></div><?php endif; ?>

      <div class="grid">
        <div class="card">
          <h3 style="margin:0 0 8px">Create / Update Product</h3>
          <form method="post" action="/inventory/product<?= $routeExt ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="single">
            <div class="row">
              <label>Product ID
                <input name="id" required placeholder="e.g. CRK-100">
              </label>
              <label>Name
                <input name="name" required placeholder="Product name">
              </label>
            </div>
            <div class="row">
              <label>Category
                <select id="catSel" name="category" required>
                  <option value="">Select</option>
                  <?php foreach ($cats as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                  <?php endforeach; ?>
                  <option value="__new__">➕ Add new…</option>
                </select>
              </label>
              <label id="catNewWrap" style="display:none">New Category
                <input id="catNew" name="category_new" placeholder="Type new category name">
              </label>
              <label>Price (₹)
                <input type="number" name="price" step="0.01" min="0" required>
              </label>
            </div>
            <div class="row">
              <label>Discount %
                <input type="number" name="discount_pct" min="0" max="100" value="0">
              </label>
              <label>Unit (optional)
                <input name="unit" placeholder="e.g. Box, Pack">
              </label>
            </div>
            <div class="row">
              <label>Image URL (optional)
                <input name="image" placeholder="https://...">
              </label>
              <label>Upload Image (optional)
                <input type="file" name="upload" accept="image/*">
              </label>
            </div>
            <div class="row" style="justify-content:flex-end;margin-top:8px">
              <button class="btn" type="submit">Save Product</button>
            </div>
          </form>
          <p class="muted" style="color:var(--muted);margin-top:8px">Tip: To show local photos in the public shop, place images under <code>images/products/{id}/</code>. Otherwise the Image URL will be used.</p>
        </div>

        <div class="card">
          <h3 style="margin:0 0 8px">Bulk Upload (CSV)</h3>
          <form method="post" action="/inventory/product<?= $routeExt ?>" enctype="multipart/form-data" style="margin-bottom:10px">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
            <input type="hidden" name="action" value="bulk">
            <div class="row">
              <label>CSV File
                <input type="file" name="csv" accept=".csv,text/csv" required>
              </label>
            </div>
            <div class="row" style="justify-content:flex-end;margin-top:8px">
              <button class="btn" type="submit">Upload CSV</button>
            </div>
            <div class="muted" style="color:var(--muted);margin-top:6px;line-height:1.4">
              Expected headers: <code>sku</code> (or <code>id</code>), <code>name</code>, <code>category</code>, <code>price</code>, optional <code>discount_pct</code>, <code>unit</code>, <code>image_url</code>.
            </div>
          </form>

          <h3 style="margin:8px 0">Existing Products (<?= (int)$totalProducts ?>)</h3>
          <div style="max-height:480px;overflow:auto">
            <table>
              <thead>
                <tr>
                  <th style="width:120px">ID</th>
                  <th style="min-width:260px">Name</th>
                  <th style="width:180px">Category</th>
                  <th style="width:90px;text-align:right">Price</th>
                  <th style="width:90px;text-align:right">Discount</th>
                  <th style="width:90px;text-align:right">Images</th>
                  <th style="width:180px">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($products as $p): ?>
                <tr>
                  <td><span class="ghost btn" style="padding:2px 8px"><?= htmlspecialchars((string)($p['sku'] ?? '')) ?></span></td>
                  <td class="name-cell">
                    <?php if (!empty($p['primary_image'])): ?>
                      <img src="/<?= htmlspecialchars($p['primary_image']) ?>" alt="thumb" style="width:28px;height:28px;object-fit:cover;border-radius:4px;border:1px solid rgba(255,255,255,.2)">
                    <?php else: ?>
                      <span class="ph" aria-hidden="true"></span>
                    <?php endif; ?>
                    <span class="name-text" title="<?= htmlspecialchars((string)($p['name'] ?? '')) ?>"><?= htmlspecialchars((string)($p['name'] ?? '')) ?></span>
                  </td>
                  <td style="color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars((string)($p['category'] ?? '')) ?></td>
                  <td style="text-align:right">₹<?= number_format((float)($p['price'] ?? 0),2) ?></td>
                  <td style="text-align:right"><?= (int)($p['discount_pct'] ?? 0) ?>%</td>
                  <td style="text-align:right"><?= (int)($p['img_count'] ?? 0) ?></td>
                  <td>
                    <div style="display:flex;gap:8px;flex-wrap:nowrap">
                      <button
                        type="button"
                        class="ghost btn btn-edit"
                        data-sku="<?= htmlspecialchars((string)($p['sku'] ?? '')) ?>"
                        data-name="<?= htmlspecialchars((string)($p['name'] ?? '')) ?>"
                        data-category="<?= htmlspecialchars((string)($p['category'] ?? '')) ?>"
                        data-price="<?= htmlspecialchars((string)($p['price'] ?? '')) ?>"
                        data-discount="<?= (int)($p['discount_pct'] ?? 0) ?>"
                        data-unit="<?= htmlspecialchars((string)($p['unit'] ?? '')) ?>"
                        data-image="<?= htmlspecialchars((string)($p['image_url'] ?? '')) ?>"
                      >Edit</button>
                      <form method="post" action="/inventory/product<?= $routeExt ?>" onsubmit="return confirm('Delete product & images?')">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= htmlspecialchars((string)($p['sku'] ?? '')) ?>">
                        <button class="ghost btn" type="submit">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php
            $pages = max(1, (int)ceil(($totalProducts ?: 0)/$per));
            $page = max(1, min($page, $pages));
            $start = $totalProducts ? ($page - 1) * $per + 1 : 0;
            $end = min($totalProducts, $page * $per);
            $base = '/inventory/product' . $routeExt . '?per=' . (int)$per . '&page=';
          ?>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px">
            <div style="color:var(--muted)">Showing <?= (int)$start ?>–<?= (int)$end ?> of <?= (int)$totalProducts ?></div>
            <div class="pager" style="display:flex;gap:6px;flex-wrap:wrap">
              <a class="ghost btn" href="<?= htmlspecialchars($base . max(1,$page-1)) ?>" <?= $page<=1?'aria-disabled="true" style="pointer-events:none;opacity:.6"':'' ?>>Prev</a>
              <?php for($i=1;$i<=$pages;$i++): if ($i===1 || $i===$pages || abs($i-$page)<=2): ?>
                <a class="ghost btn" href="<?= htmlspecialchars($base . $i) ?>" <?= $i===$page?'style="background:rgba(255,255,255,.12)"':'' ?>><?= (int)$i ?></a>
              <?php elseif ($i==2 && $page>4): ?>
                <span style="padding:6px 8px;color:var(--muted)">…</span>
              <?php elseif ($i==$pages-1 && $page<$pages-3): ?>
                <span style="padding:6px 8px;color:var(--muted)">…</span>
              <?php endif; endfor; ?>
              <a class="ghost btn" href="<?= htmlspecialchars($base . min($pages,$page+1)) ?>" <?= $page>=$pages?'aria-disabled="true" style="pointer-events:none;opacity:.6"':'' ?>>Next</a>
            </div>
          </div>
        </div>
      </div>
    </div>
    <script>
      // Prefill the product form when clicking Edit
      (function(){
        const form = document.querySelector('form[method="post"][enctype="multipart/form-data"]');
        if (!form) return;
        // Toggle new category input when selecting "Add new…"
        const selCat = document.getElementById('catSel');
        const wrap = document.getElementById('catNewWrap');
        const inp = document.getElementById('catNew');
        function sync(){
          const addNew = selCat && selCat.value === '__new__';
          if (wrap) wrap.style.display = addNew ? 'flex' : 'none';
          if (inp) inp.required = !!addNew;
        }
        if (selCat) selCat.addEventListener('change', sync);
        sync();

        document.querySelectorAll('.btn-edit').forEach(btn => {
          btn.addEventListener('click', () => {
            const get = (sel) => form.querySelector(sel);
            const setVal = (sel, v) => { const el = get(sel); if (el) el.value = v ?? ''; };
            setVal('input[name="id"]', btn.dataset.sku || '');
            setVal('input[name="name"]', btn.dataset.name || '');
            const catSel = form.querySelector('select[name="category"]');
            if (catSel) {
              const cat = btn.dataset.category || '';
              const has = Array.from(catSel.options).some(o => o.value === cat);
              if (has) { catSel.value = cat; if (wrap) wrap.style.display='none'; if (inp) inp.value=''; }
              else { catSel.value='__new__'; if (wrap) wrap.style.display='flex'; if (inp) inp.value = cat; }
            }
            setVal('input[name="price"]', btn.dataset.price || '');
            setVal('input[name="discount_pct"]', btn.dataset.discount || '0');
            setVal('input[name="unit"]', btn.dataset.unit || '');
            setVal('input[name="image"]', btn.dataset.image || '');
            window.scrollTo({ top: 0, behavior: 'smooth' });
          });
        });
      })();
    </script>
  </body>
</html>
