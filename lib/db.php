<?php
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $config = require __DIR__ . '/../config.php';
    $db = $config['db'];
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    try {
        $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Access denied for user') !== false && $db['host'] === '127.0.0.1') {
            // Fallback to localhost (socket auth) if user exists only for @localhost
            $dsnLocal = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', 'localhost', $db['port'], $db['name'], $db['charset']);
            $pdo = new PDO($dsnLocal, $db['user'], $db['pass'], $options);
            return $pdo;
        }
        if (stripos($msg, 'Unknown database') !== false) {
            // Attempt to create the database automatically, then reconnect
            $hostDsn = sprintf('mysql:host=%s;port=%s;charset=%s', $db['host'], $db['port'], $db['charset']);
            $tmp = new PDO($hostDsn, $db['user'], $db['pass'], $options);
            $dbName = preg_replace('/[^A-Za-z0-9_]/', '', $db['name']);
            $tmp->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$db['charset']} COLLATE utf8mb4_unicode_ci");
            $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
        } else {
            // Re-throw to let callers handle gracefully (try/catch)
            throw $e;
        }
    }
    return $pdo;
}
?>
