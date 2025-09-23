<?php
// Seed categories from data/categories.php into DB table `categories`.
// Usage: php tools/seed_categories.php

require_once __DIR__ . '/../lib/db.php';

function slugify(string $name): string {
  $s = strtolower(trim($name));
  $s = preg_replace('/[^a-z0-9]+/','-', $s);
  $s = trim($s, '-');
  return $s ?: substr(sha1($name),0,8);
}

$pdo = get_pdo();

// Ensure table exists (idempotent)
$sql = trim(file_get_contents(__DIR__ . '/../db/schema.sql'));
// Execute only the categories create statement quickly
if (preg_match('/CREATE TABLE IF NOT EXISTS categories[\s\S]*?;/', $sql, $m)) {
  $pdo->exec($m[0]);
}

$cats = require __DIR__ . '/../data/categories.php';
if (!is_array($cats) || empty($cats)) {
  fwrite(STDERR, "No categories loaded from data/categories.php\n");
  exit(1);
}

$ins = $pdo->prepare('INSERT INTO categories (name, slug) VALUES (:name, :slug) ON DUPLICATE KEY UPDATE slug=VALUES(slug)');
$added = 0; $updated = 0;
foreach ($cats as $c) {
  $name = trim((string)$c);
  if ($name === '') continue;
  $slug = slugify($name);
  try {
    $ins->execute([':name'=>$name, ':slug'=>$slug]);
    $rowCount = $ins->rowCount();
    if ($rowCount === 1) { $added++; } else { $updated++; }
  } catch (Throwable $e) {
    fwrite(STDERR, "Failed for '{$name}': " . $e->getMessage() . "\n");
  }
}
echo "Categories seeded. Added: {$added}, Updated: {$updated}\n";

