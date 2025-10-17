<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/routes.php';
admin_require_login();

$cats = require __DIR__ . '/../data/categories.php';
$msg = '';
$err = '';
$routeExt = route_extension();

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
    $sku = trim((string)($_POST['id'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
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

// Load products for listing with primary image if any
$products = [];
try {
  $st = $pdo->query("SELECT p.id, p.sku, p.name, p.category, p.unit, p.price, p.discount_pct,
                            p.image_url,
                            (SELECT path FROM product_images pi WHERE pi.product_id=p.id AND pi.is_primary=1 ORDER BY pi.id LIMIT 1) AS primary_image,
                            (SELECT COUNT(*) FROM product_images pi2 WHERE pi2.product_id=p.id) AS img_count
                     FROM products p ORDER BY p.name");
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
      table{width:100%;border-collapse:collapse}
      th,td{padding:8px;border-bottom:1px solid rgba(255,255,255,.1);text-align:left}
      th{background:rgba(255,255,255,.06)}
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
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
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
                <select name="category" required>
                  <option value="">Select</option>
                  <?php foreach ($cats as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                  <?php endforeach; ?>
                </select>
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
          <h3 style="margin:0 0 8px">Existing Products (<?= count($products) ?>)</h3>
          <div style="max-height:480px;overflow:auto">
            <table>
              <thead>
                <tr>
                  <th style="width:120px">ID</th>
                  <th>Name</th>
                  <th style="width:180px">Category</th>
                  <th style="width:90px">Price</th>
                  <th style="width:90px">Discount</th>
                  <th style="width:90px">Images</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($products as $p): ?>
                <tr>
                  <td><span class="ghost btn" style="padding:2px 8px"><?= htmlspecialchars((string)($p['sku'] ?? '')) ?></span></td>
                  <td style="display:flex;align-items:center;gap:8px">
                    <?php if (!empty($p['primary_image'])): ?>
                      <img src="/<?= htmlspecialchars($p['primary_image']) ?>" alt="thumb" style="width:28px;height:28px;object-fit:cover;border-radius:4px;border:1px solid rgba(255,255,255,.2)">
                    <?php endif; ?>
                    <?= htmlspecialchars((string)($p['name'] ?? '')) ?>
                  </td>
                  <td style="color:var(--muted)"><?= htmlspecialchars((string)($p['category'] ?? '')) ?></td>
                  <td>₹<?= number_format((float)($p['price'] ?? 0),2) ?></td>
                  <td><?= (int)($p['discount_pct'] ?? 0) ?>%</td>
                  <td><?= (int)($p['img_count'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </body>
</html>
