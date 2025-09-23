<?php
// Base config. You can override these with environment variables OR by
// creating a config.local.php file that returns the same structure.

$config = [
    'db' => [
        // Local defaults (adjust as needed)
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'u265933834_crackers',
        'user' => getenv('DB_USER') ?: 'u265933834_extendlogics',
        'pass' => getenv('DB_PASS') ?: 'Extend@2025',
        'charset' => 'utf8mb4',
    ],
    'admin' => [
        // Change these in config.local.php for production
        // 'user' => getenv('ADMIN_USER') ?: 'admin',
        // 'pass' => getenv('ADMIN_PASS') ?: 'Admin@2025',
    ],
];

// If a local override file exists, merge it (useful for Hostinger/production)
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        $config = array_replace_recursive($config, $override);
    }
}

return $config;
?>
