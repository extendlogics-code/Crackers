<?php
// Generates a PDF order summary from posted JSON (no DB read required)
require_once __DIR__ . '/../lib/pdf.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

function get_payload(): array {
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

$order = get_payload();
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
