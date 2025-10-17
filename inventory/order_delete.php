<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/routes.php';
admin_require_login();
admin_require_role('admin');
$routeExt = route_extension();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo 'Method Not Allowed'; exit; }
if (!admin_csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); echo 'Invalid CSRF'; exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'Invalid id'; exit; }

$pdo = get_pdo();
$st = $pdo->prepare('DELETE FROM orders WHERE id = ?');
$st->execute([$id]);

header('Location: /inventory/dashboard' . $routeExt);
exit;
?>
