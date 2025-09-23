<?php
require_once __DIR__ . '/../lib/db.php';

function slugify(string $name): string {
  $s = strtolower(trim($name));
  $s = preg_replace('/[^a-z0-9]+/','-', $s);
  return trim($s,'-') ?: substr(sha1($name),0,8);
}

$pdo = get_pdo();
$pdo->exec("CREATE TABLE IF NOT EXISTS categories (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL, slug VARCHAR(190) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_name (name), UNIQUE KEY uniq_slug (slug)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$getProducts = $pdo->query("SELECT id, category FROM products WHERE (category_id IS NULL OR category_id=0) AND category IS NOT NULL AND category<>''");
$findCat = $pdo->prepare('SELECT id FROM categories WHERE name=? LIMIT 1');
$addCat = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (?, ?)');
$setCat = $pdo->prepare('UPDATE products SET category_id=? WHERE id=?');

$n=0; $created=0; $updated=0;
while ($row = $getProducts->fetch(PDO::FETCH_ASSOC)) {
  $name = trim($row['category']);
  if ($name==='') continue;
  $findCat->execute([$name]);
  $c = $findCat->fetch(PDO::FETCH_ASSOC);
  $catId = null;
  if ($c) { $catId = (int)$c['id']; }
  else { $addCat->execute([$name, slugify($name)]); $catId = (int)$pdo->lastInsertId(); $created++; }
  if ($catId) { $setCat->execute([$catId, (int)$row['id']]); $updated++; }
  $n++;
}
echo "Scanned {$n} products. Categories created: {$created}. Products updated: {$updated}.\n";

