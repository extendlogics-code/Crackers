<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/pdf.php';

function ensure_schema(PDO $pdo): void {
    // Create tables if they don't exist (idempotent)
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(150) NOT NULL,
      email VARCHAR(190) NULL,
      phone VARCHAR(50) NULL,
      address_line1 VARCHAR(255) NULL,
      address_line2 VARCHAR(255) NULL,
      city VARCHAR(120) NULL,
      state VARCHAR(120) NULL,
      pincode VARCHAR(20) NULL,
      transaction_id VARCHAR(64) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_email (email),
      KEY idx_phone (phone)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      customer_id BIGINT UNSIGNED NOT NULL,
      subtotal DECIMAL(10,2) NULL,
      total DECIMAL(10,2) NOT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'new',
      notes TEXT NULL,
      transaction_id VARCHAR(64) NULL,
      pdf_path VARCHAR(255) NULL,
      pdf_blob LONGBLOB NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_customer (customer_id),
      CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      order_id BIGINT UNSIGNED NOT NULL,
      sku VARCHAR(64) NULL,
      product_name VARCHAR(255) NOT NULL,
      unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      quantity INT UNSIGNED NOT NULL DEFAULT 1,
      KEY idx_order (order_id),
      CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // Products inventory table
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      sku VARCHAR(64) NULL UNIQUE,
      name VARCHAR(255) NOT NULL,
      unit VARCHAR(32) NOT NULL DEFAULT 'Box',
      stock_qty INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Admin users (role-based access)
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      username VARCHAR(120) NOT NULL UNIQUE,
      password_hash VARCHAR(255) NOT NULL,
      role VARCHAR(20) NOT NULL DEFAULT 'co_worker', -- 'admin' or 'co_worker'
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    // Attempt to add new columns on existing tables (ignore if already present or unsupported)
    try { $pdo->exec("ALTER TABLE customers ADD COLUMN IF NOT EXISTS transaction_id VARCHAR(64) NULL"); } catch (Throwable $__) {}
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS transaction_id VARCHAR(64) NULL"); } catch (Throwable $__) {}
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS pdf_path VARCHAR(255) NULL"); } catch (Throwable $__) {}
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS pdf_blob LONGBLOB NULL"); } catch (Throwable $__) {}
    try { $pdo->exec("ALTER TABLE orders DROP COLUMN IF EXISTS shipping"); } catch (Throwable $__) {}
    try { $pdo->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS unit VARCHAR(32) NOT NULL DEFAULT 'Box'"); } catch (Throwable $__) {}
    try { $pdo->exec("ALTER TABLE products ADD COLUMN IF NOT EXISTS stock_qty INT NOT NULL DEFAULT 0"); } catch (Throwable $__) {}

    // Seed default admin if table empty
    try {
        $exists = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        if ($exists === 0) {
            $cfg = require __DIR__ . '/../config.php';
            $u = $cfg['admin']['user'] ?? 'admin';
            $p = $cfg['admin']['pass'] ?? 'Admin@2025';
            $hash = password_hash($p, PASSWORD_DEFAULT);
            $st = $pdo->prepare('INSERT INTO admin_users (username, password_hash, role, active) VALUES (?,?,"admin",1)');
            $st->execute([$u, $hash]);
        }
    } catch (Throwable $__) {}
}

function send_order_emails(array $order, string $pdfBytes, int $orderId): void {
    $owner = 'svasan1995@gmail.com';
    $customerEmail = trim($order['customer']['email'] ?? '');
    $subject = 'Order #' . $orderId . ' placed';
    $bodyText = "Thank you! Your order #$orderId has been placed. We will confirm the order soon.\n\n" .
               'Total: ₹' . number_format((float)($order['total'] ?? 0), 2) . "\n" .
               'Customer: ' . ($order['customer']['name'] ?? '') . "\n";

    $boundary = '==MIME_' . md5(uniqid('', true));
    $headers = [];
    $headers[] = 'From: noreply@localhost';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
    $attachment = chunk_split(base64_encode($pdfBytes));
    $message = "--$boundary\r\n" .
               "Content-Type: text/plain; charset=utf-8\r\n\r\n" .
               $bodyText . "\r\n" .
               "--$boundary\r\n" .
               "Content-Type: application/pdf; name=order-$orderId.pdf\r\n" .
               "Content-Transfer-Encoding: base64\r\n" .
               "Content-Disposition: attachment; filename=order-$orderId.pdf\r\n\r\n" .
               $attachment . "\r\n" .
               "--$boundary--";

    // Send to owner and customer (best-effort)
    @mail($owner, $subject, $message, implode("\r\n", $headers));
    if ($customerEmail !== '') {
        @mail($customerEmail, $subject, $message, implode("\r\n", $headers));
    }

    // Also drop an .eml copy locally for audit
    $dir = dirname(__DIR__) . '/storage/emails';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @file_put_contents($dir . '/order-' . $orderId . '.eml', 'To: ' . $owner . "\nSubject: $subject\n" . implode("\n", $headers) . "\n\n" . $message);
}

function queue_notifications(array $order, int $orderId): void {
    $dir = dirname(__DIR__) . '/storage/notifications';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $customerPhone = trim($order['customer']['phone'] ?? '');
    $msg = 'Order #' . $orderId . ' placed. Total ₹' . number_format((float)($order['total'] ?? 0), 2) . '.';
    $payload = [
        'order_id' => $orderId,
        'message' => $msg,
        'customer_phone' => $customerPhone,
        'owner_phone' => '',
        'whatsapp_wa_me_link' => $customerPhone ? ('https://wa.me/' . preg_replace('/\D+/', '', $customerPhone) . '?text=' . rawurlencode($msg)) : null,
    ];
    @file_put_contents($dir . '/order-' . $orderId . '.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function json_input(): array {
    $ctype = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (strpos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    // Fallback to form-encoded
    return $_POST ?: [];
}

function respond($code, $payload) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

try {
    $in = json_input();

    // Expected fields
    $customer = [
        'name' => trim($in['customer']['name'] ?? ($in['name'] ?? '')),
        'email' => trim($in['customer']['email'] ?? ($in['email'] ?? '')),
        'phone' => trim($in['customer']['phone'] ?? ($in['phone'] ?? '')),
        'address_line1' => trim($in['customer']['address_line1'] ?? ($in['address_line1'] ?? '')),
        'address_line2' => trim($in['customer']['address_line2'] ?? ($in['address_line2'] ?? '')),
        'city' => trim($in['customer']['city'] ?? ($in['city'] ?? '')),
        'state' => trim($in['customer']['state'] ?? ($in['state'] ?? '')),
        'pincode' => trim($in['customer']['pincode'] ?? ($in['pincode'] ?? '')),
    ];

    $items = $in['items'] ?? [];
    if (!is_array($items)) $items = [];

    // Server-side pricing enforcement: recalc from DB where possible
    $pdo = get_pdo();
    ensure_schema($pdo);
    $pdo->beginTransaction();

    $calc_items = [];
    $calc_subtotal = 0.0;
    $selProd = $pdo->prepare('SELECT price, discount_pct, name FROM products WHERE sku = ? LIMIT 1');
    foreach ($items as $it) {
        $sku = trim((string)($it['sku'] ?? $it['id'] ?? ''));
        $nameIn = trim((string)($it['name'] ?? $it['product_name'] ?? ''));
        $qty = isset($it['qty']) ? (int)$it['qty'] : (isset($it['quantity']) ? (int)$it['quantity'] : 0);
        if ($qty <= 0) { continue; }
        $price = null;
        if ($sku !== '') {
            $selProd->execute([$sku]);
            if ($row = $selProd->fetch()) {
                $p = (float)$row['price']; $disc = (int)($row['discount_pct'] ?? 0);
                $price = max(0, $p * (1 - max(0,min(100,$disc))/100));
                if ($nameIn === '') { $nameIn = (string)$row['name']; }
            }
        }
        if ($price === null) {
            $price = isset($it['price']) ? (float)$it['price'] : (isset($it['unit_price']) ? (float)$it['unit_price'] : 0.0);
        }
        if ($price < 0) $price = 0.0;
        $calc_items[] = [ 'sku'=>$sku, 'name'=>$nameIn ?: ($sku ?: 'Item'), 'price'=>$price, 'qty'=>$qty ];
        $calc_subtotal += $price * $qty;
    }
    $calc_total = max(0.0, $calc_subtotal);

    // Use computed totals, ignore client-provided totals
    $subtotal = $calc_subtotal;
    $total = $calc_total;
    $notes = trim($in['notes'] ?? '');
    $transaction_id = trim($in['transaction_id'] ?? '');

    // Basic validation
    if ($customer['name'] === '') {
        respond(422, ['ok' => false, 'error' => 'Customer name is required']);
    }
    if ($customer['email'] === '' && $customer['phone'] === '') {
        respond(422, ['ok' => false, 'error' => 'Email or phone is required']);
    }
    if ($customer['address_line1'] === '' || $customer['city'] === '' || $customer['state'] === '' || $customer['pincode'] === '') {
        respond(422, ['ok' => false, 'error' => 'Address, city, state, and pincode are required']);
    }
    if ($customer['pincode'] !== '' && !preg_match('/^[0-9]{4,10}$/', $customer['pincode'])) {
        respond(422, ['ok' => false, 'error' => 'Invalid pincode']);
    }
    if (empty($calc_items)) {
        respond(422, ['ok' => false, 'error' => 'Order items are required']);
    }
    if ($total <= 0) {
        respond(422, ['ok' => false, 'error' => 'Order total must be > 0']);
    }
    if ($total < 2000) {
        respond(422, ['ok' => false, 'error' => 'Minimum order amount is ₹2000']);
    }
    if ($transaction_id === '') {
        respond(422, ['ok' => false, 'error' => 'Transaction ID is required']);
    }

    // We already began a transaction above

    // Upsert customer by email if provided, else by phone; otherwise insert new
    $find = null;
    if ($customer['email'] !== '') {
        $st = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
        $st->execute([$customer['email']]);
        $find = $st->fetch();
    } elseif ($customer['phone'] !== '') {
        $st = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
        $st->execute([$customer['phone']]);
        $find = $st->fetch();
    }

    if ($find) {
        $customer_id = (int)$find['id'];
        $st = $pdo->prepare('UPDATE customers SET name = ?, phone = ?, address_line1 = ?, address_line2 = ?, city = ?, state = ?, pincode = ?, transaction_id = ? WHERE id = ?');
        $st->execute([
            $customer['name'], $customer['phone'], $customer['address_line1'], $customer['address_line2'],
            $customer['city'], $customer['state'], $customer['pincode'], $transaction_id, $customer_id
        ]);
    } else {
        $st = $pdo->prepare('INSERT INTO customers (name, email, phone, address_line1, address_line2, city, state, pincode, transaction_id) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([
            $customer['name'], $customer['email'] ?: null, $customer['phone'] ?: null,
            $customer['address_line1'] ?: null, $customer['address_line2'] ?: null,
            $customer['city'] ?: null, $customer['state'] ?: null, $customer['pincode'] ?: null,
            $transaction_id
        ]);
        $customer_id = (int)$pdo->lastInsertId();
    }

    // Insert order
    $st = $pdo->prepare('INSERT INTO orders (customer_id, subtotal, total, notes, transaction_id) VALUES (?,?,?,?,?)');
    $st->execute([$customer_id, $subtotal, $total, $notes ?: null, $transaction_id]);
    $order_id = (int)$pdo->lastInsertId();

    // Insert items
    $st = $pdo->prepare('INSERT INTO order_items (order_id, sku, product_name, unit_price, quantity) VALUES (?,?,?,?,?)');
    foreach ($calc_items as $it) {
        $st->execute([$order_id, ($it['sku'] ?: null), $it['name'], (float)$it['price'], (int)$it['qty']]);
    }

    // Build order payload for PDF and notifications
    // Generate Estimation No
    $now = new DateTime('now');
    $estNo = 'EST-' . $now->format('Ymd-His') . '-' . str_pad((string)$order_id, 6, '0', STR_PAD_LEFT);

    $order_payload = [
        'order_id' => $order_id,
        'customer' => $customer,
        'items' => [],
        'subtotal' => (float)$subtotal,
        'discount' => 0,
        'total' => (float)$total,
        'est_no' => $estNo,
        'vendor' => [ 'name' => 'PSK CRACKERS', 'branch' => 'Chennai' ],
    ];
    $st = $pdo->prepare('SELECT sku, product_name, unit_price, quantity FROM order_items WHERE order_id = ?');
    $st->execute([$order_id]);
    foreach ($st->fetchAll() as $row) {
        $order_payload['items'][] = [
            'id' => $row['sku'],
            'name' => $row['product_name'],
            'price' => (float)$row['unit_price'],
            'qty' => (int)$row['quantity'],
            'unit' => 'Box',
        ];
    }

    // Generate PDF and save path
    $pdf = build_order_pdf($order_payload);
    $invDir = dirname(__DIR__) . '/storage/invoices';
    if (!is_dir($invDir)) { @mkdir($invDir, 0777, true); }
    $relPath = 'storage/invoices/order-' . $order_id . '.pdf';
    $absPath = dirname(__DIR__) . '/' . $relPath;
    @file_put_contents($absPath, $pdf);

    // Save PDF path/blob on the order row before commit
    try {
        $st = $pdo->prepare('UPDATE orders SET pdf_path = ?, pdf_blob = ? WHERE id = ?');
        $st->execute([$relPath, $pdf, $order_id]);
    } catch (Throwable $__) {}

    $pdo->commit();

    // Best-effort local backup (JSON) in storage/orders
    try {
        $backupDir = dirname(__DIR__) . '/storage/orders';
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0777, true);
        }
        $orderBackup = [
            'order_id' => $order_id,
            'customer_id' => $customer_id,
            'customer' => $customer,
            'items' => array_map(function($it){
                return [
                    'sku' => $it['sku'] ?? null,
                    'name' => $it['name'] ?? ($it['product_name'] ?? ''),
                    'price' => isset($it['price']) ? (float)$it['price'] : (isset($it['unit_price']) ? (float)$it['unit_price'] : 0.0),
                    'qty' => isset($it['qty']) ? (int)$it['qty'] : (isset($it['quantity']) ? (int)$it['quantity'] : 1),
                ];
            }, $calc_items),
            'subtotal' => $subtotal,
            'total' => $total,
            'notes' => $notes ?: null,
            'pdf_path' => $relPath,
            'created_at' => date('c'),
        ];
        $backupPath = $backupDir . '/order-' . $order_id . '.json';
        @file_put_contents($backupPath, json_encode($orderBackup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } catch (Throwable $__) {
        // Ignore backup errors
    }

    // Send emails and queue WhatsApp/SMS
    try { send_order_emails($order_payload, $pdf, $order_id); } catch (Throwable $__) {}
    try { queue_notifications($order_payload, $order_id); } catch (Throwable $__) {}

    respond(200, [
        'ok' => true,
        'order_id' => $order_id,
        'customer_id' => $customer_id,
        'message' => 'Order saved',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Do not leak internal error details in production
    respond(500, ['ok' => false, 'error' => 'Server error']);
}
?>
