<?php
require_once __DIR__ . '/db.php';

function load_categories(): array {
  // File-based cache for 5 minutes
  $cacheFile = __DIR__ . '/../storage/cache/categories.cache.php';
  $cacheTtl = 300; // 5 minutes
  if (file_exists($cacheFile) && (filemtime($cacheFile) > (time() - $cacheTtl))) {
    $data = @include $cacheFile;
    if (is_array($data)) return $data;
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
    $result = array_values(array_filter(array_map('strval', (array)$rows)));
    // Write cache
    if (!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0777, true);
    @file_put_contents($cacheFile, '<?php return ' . var_export($result, true) . ';');
    return $result;
  } catch (Throwable $e) {
    return [];
  }
}
