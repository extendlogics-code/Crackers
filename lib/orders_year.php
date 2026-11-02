<?php
/**
 * Yearly invoice/order numbering helpers.
 *
 * Live orders stay in `orders` / `order_items`.
 * Old years are copied into archive tables (never hard-deleted from backup).
 * Each year rollover empties live tables and resets AUTO_INCREMENT to 1.
 */

function invoice_code_for(int $year, int $seq): string {
    return sprintf('INV-%d-%06d', $year, $seq);
}

function ensure_order_year_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    // Live order columns for year + public invoice code
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN order_year SMALLINT UNSIGNED NULL"); } catch (Throwable $__) {}
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN invoice_seq INT UNSIGNED NULL"); } catch (Throwable $__) {}
    try { $pdo->exec("ALTER TABLE orders ADD COLUMN invoice_code VARCHAR(32) NULL"); } catch (Throwable $__) {}
    try { $pdo->exec("CREATE UNIQUE INDEX uniq_orders_invoice_code ON orders (invoice_code)"); } catch (Throwable $__) {}
    try { $pdo->exec("CREATE UNIQUE INDEX uniq_orders_year_seq ON orders (order_year, invoice_seq)"); } catch (Throwable $__) {}
    try { $pdo->exec("CREATE INDEX idx_orders_year ON orders (order_year)"); } catch (Throwable $__) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_sequences (
      year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
      last_seq INT UNSIGNED NOT NULL DEFAULT 0,
      updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Archive tables — full backup of prior live orders (no deletes of history)
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders_archive (
      archive_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      original_id BIGINT UNSIGNED NOT NULL,
      archive_year SMALLINT UNSIGNED NOT NULL,
      archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      customer_id BIGINT UNSIGNED NOT NULL,
      subtotal DECIMAL(10,2) NULL,
      total DECIMAL(10,2) NOT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'new',
      notes TEXT NULL,
      transaction_id VARCHAR(64) NULL,
      pdf_path VARCHAR(255) NULL,
      pdf_blob LONGBLOB NULL,
      order_year SMALLINT UNSIGNED NULL,
      invoice_seq INT UNSIGNED NULL,
      invoice_code VARCHAR(32) NULL,
      created_at TIMESTAMP NULL,
      KEY idx_arch_year (archive_year),
      KEY idx_arch_original (original_id),
      KEY idx_arch_invoice (invoice_code),
      KEY idx_arch_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items_archive (
      archive_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      archive_year SMALLINT UNSIGNED NOT NULL,
      archived_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      original_item_id BIGINT UNSIGNED NULL,
      original_order_id BIGINT UNSIGNED NOT NULL,
      sku VARCHAR(64) NULL,
      product_name VARCHAR(255) NOT NULL,
      unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      quantity INT UNSIGNED NOT NULL DEFAULT 1,
      KEY idx_items_arch_year (archive_year),
      KEY idx_items_arch_order (original_order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_year_rollbacks (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      start_year SMALLINT UNSIGNED NOT NULL,
      archived_count INT UNSIGNED NOT NULL DEFAULT 0,
      notes VARCHAR(255) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $done = true;
}

/**
 * Allocate next invoice sequence for a year (transaction-safe).
 * Caller must already be inside a transaction.
 * @return array{year:int,seq:int,code:string}
 */
function allocate_invoice_number(PDO $pdo, ?int $year = null): array {
    $year = $year ?: (int)date('Y');
    ensure_order_year_schema($pdo);

    $pdo->prepare('INSERT INTO invoice_sequences (year, last_seq) VALUES (?, 0)
                   ON DUPLICATE KEY UPDATE year = year')->execute([$year]);

    $st = $pdo->prepare('SELECT last_seq FROM invoice_sequences WHERE year = ? FOR UPDATE');
    $st->execute([$year]);
    $last = (int)$st->fetchColumn();
    $seq = $last + 1;
    if ($seq > 999999) {
        throw new RuntimeException('Invoice sequence overflow for year ' . $year);
    }
    $pdo->prepare('UPDATE invoice_sequences SET last_seq = ? WHERE year = ?')->execute([$seq, $year]);

    return [
        'year' => $year,
        'seq' => $seq,
        'code' => invoice_code_for($year, $seq),
    ];
}

/**
 * Archive all live orders into *_archive tables, clear live tables, reset IDs to 1.
 * Old rows remain in archive forever for backup/lookup.
 *
 * @return array{archived_orders:int,archived_items:int,start_year:int}
 */
function rollover_orders_for_year(PDO $pdo, int $startYear, bool $force = false): array {
    if ($startYear < 2000 || $startYear > 2100) {
        throw new InvalidArgumentException('Invalid start year');
    }
    ensure_order_year_schema($pdo);

    $liveCount = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $minYearLive = (int)$pdo->query('SELECT COALESCE(MIN(YEAR(created_at)),0) FROM orders')->fetchColumn();
    $maxYearLive = (int)$pdo->query('SELECT COALESCE(MAX(YEAR(created_at)),0) FROM orders')->fetchColumn();

    // Guard: if live already has orders from the target year, require --force
    if (!$force && $liveCount > 0 && $maxYearLive >= $startYear) {
        throw new RuntimeException(
            "Live orders already include year {$maxYearLive}. " .
            "Refusing to archive them into a {$startYear} fresh start without force. " .
            "Use force only if you intentionally want to re-archive current live data."
        );
    }

    $pdo->beginTransaction();
    try {
        // Snapshot orders
        $insOrders = $pdo->exec("INSERT INTO orders_archive (
              original_id, archive_year, archived_at, customer_id, subtotal, total, status, notes,
              transaction_id, pdf_path, pdf_blob, order_year, invoice_seq, invoice_code, created_at
            )
            SELECT
              id,
              COALESCE(order_year, YEAR(created_at), {$startYear} - 1),
              NOW(),
              customer_id, subtotal, total, status, notes,
              transaction_id, pdf_path, pdf_blob, order_year, invoice_seq, invoice_code, created_at
            FROM orders");

        $pdo->exec("INSERT INTO order_items_archive (
              archive_year, archived_at, original_item_id, original_order_id, sku, product_name, unit_price, quantity
            )
            SELECT
              COALESCE(o.order_year, YEAR(o.created_at), {$startYear} - 1),
              NOW(),
              i.id, i.order_id, i.sku, i.product_name, i.unit_price, i.quantity
            FROM order_items i
            JOIN orders o ON o.id = i.order_id");

        $archivedItems = (int)$pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn();

        // Clear live (FK: items first). Do NOT run ALTER here — DDL auto-commits in MySQL.
        $pdo->exec('DELETE FROM order_items');
        $pdo->exec('DELETE FROM orders');

        // Fresh yearly sequence
        $pdo->prepare('INSERT INTO invoice_sequences (year, last_seq) VALUES (?, 0)
                       ON DUPLICATE KEY UPDATE last_seq = 0')->execute([$startYear]);

        $pdo->prepare('INSERT INTO order_year_rollbacks (start_year, archived_count, notes) VALUES (?,?,?)')
            ->execute([
                $startYear,
                $liveCount,
                sprintf('Archived live orders (created years %s–%s) before fresh start', $minYearLive ?: 'n/a', $maxYearLive ?: 'n/a'),
            ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Reset IDs after commit (DDL)
    $pdo->exec('ALTER TABLE order_items AUTO_INCREMENT = 1');
    $pdo->exec('ALTER TABLE orders AUTO_INCREMENT = 1');

    return [
        'archived_orders' => $liveCount,
        'archived_items' => $archivedItems,
        'start_year' => $startYear,
    ];
}

function format_order_label(array $order): string {
    if (!empty($order['invoice_code'])) {
        return (string)$order['invoice_code'];
    }
    $id = (int)($order['id'] ?? $order['order_id'] ?? 0);
    $year = (int)($order['order_year'] ?? date('Y'));
    if ($id > 0) {
        return invoice_code_for($year, $id);
    }
    return 'INV-DRAFT';
}
?>
