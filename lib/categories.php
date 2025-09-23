<?php
require_once __DIR__ . '/db.php';

function load_categories(): array {
  // DB-only fetch of categories. No filesystem fallback.
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
    return array_values(array_filter(array_map('strval', (array)$rows)));
  } catch (Throwable $e) {
    return [];
  }
}
