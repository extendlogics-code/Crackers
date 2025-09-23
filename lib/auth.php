<?php
function admin_session_start(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        $params = [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($params);
        } else {
            // Fallback for older versions
            session_set_cookie_params($params['lifetime'], $params['path'].'; SameSite='.$params['samesite'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_start();
        // Mitigate session fixation
        if (!isset($_SESSION['INIT'])) {
            session_regenerate_id(true);
            $_SESSION['INIT'] = 1;
        }
    }
}

function admin_logged_in(): bool {
    admin_session_start();
    return !empty($_SESSION['admin_logged_in']);
}

function admin_require_login(): void {
    if (!admin_logged_in()) {
        header('Location: /inventory/login.php');
        exit;
    }
}

function admin_login(string $user, string $pass): bool {
    admin_session_start();
    // Simple rate limiting: max 5 attempts per 15 minutes per session
    $_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? [];
    $now = time();
    // Purge old attempts
    $_SESSION['login_attempts'] = array_filter($_SESSION['login_attempts'], fn($t) => ($now - $t) < 900);
    if (count($_SESSION['login_attempts']) >= 5) {
        return false;
    }
    // Try DB auth first
    try {
        require_once __DIR__ . '/db.php';
        $pdo = get_pdo();
        $st = $pdo->prepare('SELECT id, username, password_hash, role, active FROM admin_users WHERE username = ? LIMIT 1');
        $st->execute([$user]);
        $row = $st->fetch();
        if ($row && (int)$row['active'] === 1 && password_verify($pass, $row['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user'] = [ 'id' => (int)$row['id'], 'username' => $row['username'], 'role' => $row['role'] ];
            session_regenerate_id(true);
            return true;
        }
    } catch (Throwable $__) {
        // ignore and fall back
    }
    // Fallback to config credentials
    $cfg = require __DIR__ . '/../config.php';
    $ok = hash_equals((string)$cfg['admin']['user'], $user) && hash_equals((string)$cfg['admin']['pass'], $pass);
    if ($ok) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = [ 'id' => 0, 'username' => $user, 'role' => 'admin' ];
        session_regenerate_id(true);
    } else {
        unset($_SESSION['admin_logged_in'], $_SESSION['admin_user']);
        $_SESSION['login_attempts'][] = $now;
    }
    return $ok;
}

function admin_logout(): void {
    admin_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// Simple CSRF helpers for admin forms
function admin_csrf_token(): string {
    admin_session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function admin_csrf_check(string $token): bool {
    admin_session_start();
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function admin_current_user(): array {
    admin_session_start();
    return $_SESSION['admin_user'] ?? [ 'id'=>0, 'username'=>'', 'role'=>'guest' ];
}

function admin_current_role(): string {
    $u = admin_current_user();
    return $u['role'] ?? 'guest';
}

function admin_require_role($allowed): void {
    $role = admin_current_role();
    $allowedList = is_array($allowed) ? $allowed : [$allowed];
    if (!in_array($role, $allowedList, true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}
?>
