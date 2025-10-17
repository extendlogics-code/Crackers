<?php
// Generates a PDF order summary. When an order_id is provided we load the data
// directly from the database; otherwise we fall back to JSON payloads (legacy preview).
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/pdf.php';

admin_require_login();

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function get_payload_from_request(): array {
  $ctype = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
  if (strpos($ctype, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
  }
  // Accept form-encoded with key order_json or order
  if (!empty($_POST['order_json'])) {
    $data = json_decode($_POST['order_json'], true);
    return is_array($data) ? $data : [];
  }
  if (!empty($_POST['order'])) {
    $data = json_decode($_POST['order'], true);
    return is_array($data) ? $data : [];
  }
  return [];
}

function load_payload_from_db(PDO $pdo, int $orderId): ?array {
  $st = $pdo->prepare(
    "SELECT o.id, o.subtotal, o.total, o.status, o.created_at, o.transaction_id,
            c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
            c.address_line1 AS customer_address_line1, c.address_line2 AS customer_address_line2,
            c.city AS customer_city, c.state AS customer_state, c.pincode AS customer_pincode
     FROM orders o
     JOIN customers c ON c.id = o.customer_id
     WHERE o.id = ?
     LIMIT 1"
  );
  $st->execute([$orderId]);
  $order = $st->fetch();
  if (!$order) {
    return null;
  }

  $stItems = $pdo->prepare('SELECT sku, product_name, unit_price, quantity FROM order_items WHERE order_id = ? ORDER BY id');
  $stItems->execute([$orderId]);
  $items = $stItems->fetchAll() ?: [];

  $addrLine1 = trim((string)($order['customer_address_line1'] ?? ''));
  $addrLine2 = trim((string)($order['customer_address_line2'] ?? ''));

  return [
    'order_id' => $orderId,
    'subtotal' => (float)$order['subtotal'],
    'total' => (float)$order['total'],
    'transaction_id' => (string)($order['transaction_id'] ?? ''),
    'created_at' => (string)($order['created_at'] ?? ''),
    'customer' => [
      'name' => (string)$order['customer_name'],
      'email' => (string)$order['customer_email'],
      'phone' => (string)$order['customer_phone'],
      'address_line1' => $addrLine1,
      'address_line2' => $addrLine2,
      'address_line_1' => $addrLine1,
      'address_line_2' => $addrLine2,
      'address_lines' => array_values(array_filter([$addrLine1, $addrLine2], static function ($line) {
        return $line !== '';
      })),
      'full_address' => trim($addrLine1 . ($addrLine1 !== '' && $addrLine2 !== '' ? ', ' : '') . $addrLine2),
      'city' => (string)$order['customer_city'],
      'state' => (string)$order['customer_state'],
      'pincode' => (string)$order['customer_pincode'],
    ],
    'items' => array_map(static function ($it) {
      return [
        'id' => $it['sku'],
        'name' => (string)$it['product_name'],
        'qty' => (int)$it['quantity'],
        'price' => (float)$it['unit_price'],
        'unit' => 'Box',
      ];
    }, $items),
    'vendor' => [
      'name' => 'PSK CRACKERS',
      'branch' => 'Chennai',
    ],
  ];
}

$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : (int)($_GET['order_id'] ?? 0);
$order = [];

if ($orderId > 0) {
  $pdo = get_pdo();
  $order = load_payload_from_db($pdo, $orderId) ?? [];
  if (!$order) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Order not found.';
    exit;
  }
} else {
  $order = get_payload_from_request();
}

if (!$order) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Invalid or empty order payload';
  exit;
}

// Require an order_id to generate the official downloadable PDF
if (empty($order['order_id'])) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'This invoice is a preview. Please place the order to download the final PDF.';
  exit;
}

$pdf = build_order_pdf($order);

// Build filename: prefer Customer Name + City, else fallback to vendor/branch
$cust = $order['customer'] ?? [];
$namePart = '';
if (!empty($cust['name'])) {
  $namePart = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)$cust['name']));
}
$cityPart = '';
if (!empty($cust['city'])) {
  $cityPart = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)$cust['city']));
}

if ($namePart !== '') {
  $base = $namePart . ($cityPart !== '' ? '_' . $cityPart : '');
} else {
  $vendor = $order['vendor'] ?? [];
  $vName = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)($vendor['name'] ?? 'Kannan')));
  $vBranch = preg_replace('/[^A-Za-z0-9_-]+/', '_', trim((string)($vendor['branch'] ?? 'Chennai')));
  $base = $vName . '_' . $vBranch;
}

if (!empty($order['order_id'])) {
  $base .= '-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$order['order_id']);
}

$name = $base . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $name . '"');
echo $pdf;
?>
