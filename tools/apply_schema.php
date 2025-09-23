<?php
// Apply the SQL in db/schema.sql to the configured database.
// Usage: php tools/apply_schema.php
require_once __DIR__ . '/../lib/db.php';
$pdo = get_pdo();
$sql = file_get_contents(__DIR__ . '/../db/schema.sql');
if ($sql === false) { fwrite(STDERR, "Cannot read schema.sql\n"); exit(1); }
// Split on semicolons at end of statements; naive but ok for this file
$stmts = array_filter(array_map('trim', preg_split('/;\s*\n/m', $sql)));
$applied = 0;
foreach ($stmts as $stmt) {
  if ($stmt === '' || strpos($stmt, '--') === 0) continue;
  $pdo->exec($stmt);
  $applied++;
}
echo "Applied {$applied} schema statements.\n";

