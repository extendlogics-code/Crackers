<?php
// Helper for deciding whether to append .php in generated links.
function route_extension(): string {
    static $cached;
    if ($cached !== null) {
        return $cached;
    }
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $ext = '.php';
    if ($scriptPath !== '') {
        $scriptNoExt = preg_replace('/\.php$/', '', $scriptPath);
        if ($scriptNoExt !== $scriptPath && rtrim($requestPath, '/') === rtrim($scriptNoExt, '/')) {
            $ext = '';
        } elseif (substr($requestPath, -4) === '.php') {
            $ext = '.php';
        }
    }
    $cached = $ext;
    return $cached;
}
?>
