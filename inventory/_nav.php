<?php
/**
 * Shared inventory admin navigation.
 * Expects $routeExt / $routeBase from route helpers.
 */
if (!isset($routeExt) || !isset($routeBase)) {
  require_once __DIR__ . '/../lib/routes.php';
  if (!isset($routeExt)) { $routeExt = route_extension(); }
  if (!isset($routeBase)) { $routeBase = route_base(); }
}
$role = function_exists('admin_current_role') ? admin_current_role() : 'guest';
$canManage = function_exists('admin_is_manager') ? admin_is_manager() : in_array($role, ['admin','superadmin'], true);
$here = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$link = function(string $href, string $label, bool $active = false) {
  $cls = $active ? 'btn' : 'ghost btn';
  echo '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a>';
};
$is = function(string $prefix) use ($here): bool {
  return strpos($here, $prefix) === 0;
};
?>
<nav class="inv-nav" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
  <?php
    $link($routeBase . '/inventory/dashboard' . $routeExt, 'Orders', $is('dashboard'));
    if ($canManage) {
      $link($routeBase . '/inventory/product' . $routeExt, 'Products', $is('product') || $is('shop'));
      $link($routeBase . '/inventory/order_edit' . $routeExt, 'New Order', $is('order_edit'));
    }
    if ($role === 'superadmin') {
      $link($routeBase . '/inventory/users' . $routeExt, 'Users', $is('users') || $is('register'));
    }
    $link($routeBase . '/inventory/change_password' . $routeExt, 'Password', $is('change_password'));
    $link($routeBase . '/inventory/login' . $routeExt . '?logout=1', 'Logout', false);
  ?>
</nav>
