<?php
/**
 * URL helpers for in-app links, form actions, and redirects.
 * App-generated paths omit .php; Apache maps extensionless URLs to the .php files.
 */

/** Empty string = hide .php in generated links (see .htaccess rewrite/redirect). */
function route_extension(): string {
    return '';
}

/**
 * Prefix when the app is served under /stage (empty on production root or stage subdomain).
 * Detected from SCRIPT_NAME so nested scripts under /stage/inventory work.
 * Subdomain stage.example.com has SCRIPT_NAME like /contact.php → base stays ''.
 */
function route_base(): string {
    static $cached;
    if ($cached !== null) {
        return $cached;
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $cached = (strncmp($script, '/stage/', 7) === 0 || $script === '/stage') ? '/stage' : '';
    return $cached;
}

/**
 * Build an in-app href/action/redirect path.
 * Examples: route_path('/shop'), route_path('inventory/login?logout=1')
 * Does not double extensions; leaves static asset extensions unchanged.
 */
function route_path(string $path): string {
    $path = trim($path);
    if ($path === '' || $path === '/') {
        return route_base() . '/';
    }

    $hash = '';
    $hashPos = strpos($path, '#');
    if ($hashPos !== false) {
        $hash = substr($path, $hashPos);
        $path = substr($path, 0, $hashPos);
    }

    $qs = '';
    $qPos = strpos($path, '?');
    if ($qPos !== false) {
        $qs = substr($path, $qPos);
        $path = substr($path, 0, $qPos);
    }

    $path = '/' . ltrim($path, '/');

    $base = route_base();
    if ($base !== '' && strpos($path, $base . '/') === 0) {
        $path = substr($path, strlen($base)) ?: '/';
    } elseif ($base !== '' && $path === $base) {
        return $base . '/' . $qs . $hash;
    }

    // Static / non-PHP assets: prefix only
    if (preg_match('/\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|map|pdf|json|txt|xml)$/i', $path)) {
        return $base . $path . $qs . $hash;
    }

    $ext = route_extension();
    if ($ext === '') {
        // Prefer extensionless URLs; strip accidental .php
        if (preg_match('/\.php$/i', $path)) {
            $path = substr($path, 0, -4);
        }
        // /index → site root (matches .htaccess index.php → /)
        if ($path === '/index') {
            $path = '/';
        }
    } elseif (!preg_match('/\.php$/i', $path)) {
        $path .= $ext;
    }

    return $base . $path . $qs . $hash;
}
?>
