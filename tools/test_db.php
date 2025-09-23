<?php
// Quick DB connection test using config.php
require __DIR__ . '/../lib/db.php';

header('Content-Type: text/plain; charset=utf-8');
try {
    $pdo = get_pdo();
    $ver = $pdo->query('SELECT VERSION() as v')->fetch()['v'] ?? 'unknown';
    echo "OK: Connected to MySQL (version: $ver)\n";
    // Check or create minimal table to verify permissions
    $pdo->exec("CREATE TABLE IF NOT EXISTS _conn_test (id INT PRIMARY KEY AUTO_INCREMENT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("INSERT INTO _conn_test VALUES (NULL, DEFAULT)");
    $count = $pdo->query('SELECT COUNT(*) c FROM _conn_test')->fetch()['c'] ?? 0;
    echo "OK: Able to create/insert. Row count: $count\n";
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
?>

