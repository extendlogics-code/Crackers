<?php
require_once __DIR__ . '/db.php';

function load_categories(): array {
  // Prefer master order from data/categories.php, intersected with DB names
  // (plus any extra DB-only categories appended A–Z).
  $cacheFile = __DIR__ . '/../storage/cache/categories.cache.php';
  $cacheTtl = 300; // 5 minutes
  if (file_exists($cacheFile) && (filemtime($cacheFile) > (time() - $cacheTtl))) {
    $data = @include $cacheFile;
    if (is_array($data)) return $data;
  }
  $master = [];
  $masterFile = __DIR__ . '/../data/categories.php';
  if (is_file($masterFile)) {
    $loaded = require $masterFile;
    if (is_array($loaded)) {
      foreach ($loaded as $c) {
        $n = trim((string)$c);
        if ($n !== '') $master[] = $n;
      }
    }
  }
  try {
    $pdo = get_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(190) NOT NULL,
      slug VARCHAR(190) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_name (name),
      UNIQUE KEY uniq_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $st = $pdo->query('SELECT name FROM categories ORDER BY name');
    $rows = $st->fetchAll(PDO::FETCH_COLUMN, 0);
    $dbNames = [];
    foreach ((array)$rows as $r) {
      $n = trim((string)$r);
      if ($n !== '') $dbNames[strtoupper($n)] = $n;
    }
    $result = [];
    $seen = [];
    foreach ($master as $m) {
      $key = strtoupper($m);
      if (isset($dbNames[$key]) && !isset($seen[$key])) {
        $result[] = $dbNames[$key];
        $seen[$key] = true;
      } elseif (!isset($seen[$key]) && isset($dbNames[$key])) {
        $result[] = $m;
        $seen[$key] = true;
      }
      // Include master entry even if not yet in DB (so shop filter shows new names after file deploy)
      if (!isset($seen[$key])) {
        $result[] = $m;
        $seen[$key] = true;
      }
    }
    foreach ($dbNames as $key => $n) {
      if (!isset($seen[$key])) {
        // Skip legacy BABY ITEMS when CHILDREN'S ITEMS is present
        if ($key === 'BABY ITEMS' && (isset($seen["CHILDREN'S ITEMS"]) || isset($seen['CHILDRENS ITEMS']))) {
          continue;
        }
        $result[] = $n;
        $seen[$key] = true;
      }
    }
    // Write cache
    if (!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0777, true);
    @file_put_contents($cacheFile, '<?php return ' . var_export($result, true) . ';');
    return $result;
  } catch (Throwable $e) {
    return $master;
  }
}
