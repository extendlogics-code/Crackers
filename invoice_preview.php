<?php
// Printable HTML invoice preview before placing order
require_once __DIR__ . '/lib/pdf.php'; // for amount_in_words_indian
$order = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $order = json_decode($_POST['order_json'] ?? '{}', true) ?: [];
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$vendor = $order['vendor'] ?? ['name'=>'PSK CRACKERS','branch'=>'Chennai'];
$customer = $order['customer'] ?? [];
$items = $order['items'] ?? [];
$snoStart = (int)($order['sno_start'] ?? 1);
$subtotal = (float)($order['subtotal'] ?? 0);
$shipping = (float)($order['shipping'] ?? 0);
$discount = (float)($order['discount'] ?? 0);
$discount_pct = isset($order['discount_pct']) ? (float)$order['discount_pct'] : null;
$disc_amount = $discount_pct !== null ? round($subtotal * $discount_pct/100, 2) : $discount;
$sgst = (float)($order['sgst'] ?? 0);
$cgst = (float)($order['cgst'] ?? 0);
$net = max(0, $subtotal - $disc_amount + $shipping + $sgst + $cgst);

// Auto-generate Estimation No: EST-YYYYMMDD-HHMMSS-<6 digit order no or time>
$now = new DateTime('now');
$datePart = $now->format('Ymd-His');
$ordPart = !empty($order['order_id']) ? str_pad((string)$order['order_id'], 6, '0', STR_PAD_LEFT) : $now->format('His');
$estNo = $order['est_no'] ?? ('EST-' . $datePart . '-' . $ordPart);
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice Preview — <?= h(($vendor['name'] ?? 'Kannan Crackers') . ' ' . ($vendor['branch'] ?? 'Chennai')) ?></title>
    <style>
      :root{ --fg:#0b1020; --muted:#445; --brand:#e11d48; }
      body{font:14px/1.45 ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto; color:#111; margin:0; background:#f5f7fb}
      .sheet{max-width:900px;margin:20px auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.06);overflow:hidden}
      header{display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border-bottom:2px solid #efeff1;background:#fafafa}
      .brand{font-weight:800;font-size:18px}
      .sub{color:#777}
      .grid2{display:grid;grid-template-columns:1fr 1fr;gap:0;border-top:1px solid #ddd;border-bottom:1px solid #ddd;margin:0 12px}
      .box{min-height:110px;border-right:1px solid #ddd;padding:8px}
      .box:last-child{border-right:0}
      .box h4{margin:0 0 6px;font-size:13px}
      .muted{color:#555}
      .two{display:grid;grid-template-columns:1fr 1fr;gap:0;border-bottom:1px solid #ddd;margin:0 12px}
      .two .box{min-height:90px}
      h3{margin:10px 0 6px}
      table{width:calc(100% - 24px);margin:6px 12px;border-collapse:collapse}
      th,td{padding:8px;border:1px solid #ddd;text-align:left}
      th{background:#fafafa}
      td.num, th.num{text-align:right;white-space:nowrap}
      .totals{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:10px 12px}
      .sum{margin-left:auto;max-width:360px;border:1px solid #ddd;border-radius:4px;overflow:hidden}
      .sum .row{display:flex;justify-content:space-between;padding:6px 10px;border-bottom:1px solid #eee}
      .sum .row:last-child{border-bottom:0;font-weight:800;background:#fafafa}
      .amount-words{border:1px solid #ddd;margin:0 12px;padding:6px 10px}
      footer{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 12px;border-top:2px solid #efeff1;background:#fafafa}
      .btn{appearance:none;border:none;border-radius:8px;padding:10px 12px;background:#111;color:#fff;cursor:pointer}
      .ghost{background:#fff;color:#111;border:1px solid #ddd}

      /* QR panel */
      .qrpanel{border:1px dashed #d1d5db;margin:6px 0 0 12px;padding:10px;border-radius:8px;max-width:260px;background:#fafafa}
      .qrpanel h4{margin:0 0 6px;font-size:14px}
      .qrpanel .upi{color:#333;margin:2px 0 10px}
      .qrwrap{display:grid;place-items:center}
      .qrimg{width:220px;height:220px;object-fit:contain;border:1px solid #e5e7eb;border-radius:8px;background:#fff}
      .qrnote{font-size:12px;color:#666;margin-top:8px;text-align:center}

      @media (max-width:720px){
        .totals{grid-template-columns:1fr}
        .sum{margin-left:0}
        .qrpanel{max-width:none;margin-left:0}
        .qrimg{width:260px;height:260px;max-width:80vw;max-height:80vw}
      }

      @media print{ .actions{display:none} body{background:#fff} .sheet{box-shadow:none;border:0;margin:0} }
    </style>
  </head>
  <body>
    <div class="sheet">
      <header>
        <div class="brand">Tax Estimation</div>
        <div class="sub">[Original]</div>
      </header>

      <div class="grid2">
        <div class="box">
          <h4>Billed By : <?= h($vendor['name'] ?? 'PSK CRACKERS') ?></h4>
          <div class="muted" style="white-space:pre-line;">
            <?= h($vendor['address'] ?? '') ?>
          </div>
          <?php if (!empty($vendor['gst'])): ?><div>GST : <?= h($vendor['gst']) ?></div><?php endif; ?>
          <?php if (!empty($vendor['phone'])): ?><div>Ph : <?= h($vendor['phone']) ?></div><?php endif; ?>
        </div>
        <div class="box">
          <div>Estimation No: <?= h($estNo) ?></div>
          <div>Date: <?= h(date('Y-m-d')) ?></div>
          <div>State Of Supply: <?= h($order['state_of_supply'] ?? '') ?></div>
          <div>E-way Bill No: <?= h($order['eway_bill'] ?? '') ?></div>
          <div>Vehicle No: <?= h($order['vehicle_no'] ?? '') ?></div>
        </div>
      </div>

      <div class="two">
        <div class="box">
          <h4>Ship To : <?= h($customer['name'] ?? '') ?></h4>
          <div class="muted">Address : <?= h(trim(implode(', ', array_filter([
            $customer['address_line1'] ?? '', $customer['address_line2'] ?? '', $customer['city'] ?? '', $customer['state'] ?? '', $customer['pincode'] ?? ''
          ])))) ?></div>
          <?php if (!empty($customer['phone'])): ?><div>Ph : <?= h($customer['phone']) ?></div><?php endif; ?>
          <?php if (!empty($customer['gst'])): ?><div>GST : <?= h($customer['gst']) ?></div><?php endif; ?>
        </div>
        <div class="box"></div>
      </div>

      <div style="padding:0 20px 12px">
        <table>
          <thead>
            <tr>
              <th style="width:60px">S No</th>
              <th>Particular</th>
              <th class="num" style="width:80px">Qty</th>
              <th class="num" style="width:80px">Unit</th>
              <th class="num" style="width:100px">Price</th>
              <th class="num" style="width:120px">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php $s=$snoStart; foreach ($items as $it): $p=(float)($it['price'] ?? ($it['unit_price'] ?? 0)); $q=(int)($it['qty'] ?? 0); $unit=h($it['unit']??'Box'); ?>
              <tr>
                <td><?= $s++ ?></td>
                <td><?= h($it['name'] ?? ($it['id'] ?? 'Item')) ?></td>
                <td class="num"><?= $q ?></td>
                <td class="num"><?= $unit ?></td>
                <td class="num"><?= number_format($p,2) ?></td>
                <td class="num"><?= number_format($p*$q,2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="totals">
        <!-- LEFT: QR code instead of bank details -->
        <div>
          <div class="qrpanel">
            <h4>Scan &amp; Pay</h4>
            <div class="upi">UPI ID: <strong>pskcrackers@axl</strong></div>
            <div class="qrwrap">
              <img
                src="images/payments/qrcode.png"
                alt="Scan this QR to pay via UPI"
                class="qrimg"
                onerror="this.src='images/payments/qrcode.png'">
            </div>
          </div>
        </div>

        <!-- RIGHT: totals box -->
        <div class="sum">
          <div class="row"><div>Sub Total</div><div><?= number_format($subtotal,2) ?></div></div>
          <div class="row"><div>Discount<?= $discount_pct!==null ? ' ' . (int)$discount_pct . ' %' : '' ?></div><div><?= number_format($disc_amount,2) ?></div></div>
          <div class="row"><div>SGST</div><div><?= number_format($sgst,2) ?></div></div>
          <div class="row"><div>CGST</div><div><?= number_format($cgst,2) ?></div></div>
          <div class="row"><div>NET AMOUNT :</div><div><strong><?= number_format($net,2) ?></strong></div></div>
        </div>
      </div>

      <div class="amount-words">Amount in words : <?= h(amount_in_words_indian($net)) ?></div>
      <div style="border:1px solid #ddd;border-top:0;margin:0 12px;padding:6px 10px;display:flex;justify-content:space-between"><div></div><div><strong>NET AMOUNT : <?= number_format($net,2) ?></strong></div></div>

      <div style="display:flex;justify-content:space-between;padding:16px 12px 0;color:#333">
        <div>Customer Sign</div>
        <div>For <?= h($vendor['name'] ?? 'PSK CRACKERS') ?></div>
      </div>
      <div style="padding:6px 12px 16px;color:#666;font-size:12px">We Declare that this Estimation shows the actual price of the goods described and that all particulars are true and correct</div>

      <footer class="actions">
        <div style="color:#666">
          Preview the details.
          <?php if (empty($order['order_id'])): ?>
            Download will be available after placing the order.
          <?php else: ?>
            You may download the finalized invoice below.
          <?php endif; ?>
        </div>
        <div>
          <?php if (!empty($order['order_id'])): ?>
            <form method="post" action="api/order_pdf.php" target="_blank" style="display:inline">
              <input type="hidden" name="order_json" value='<?= h(json_encode($order)) ?>'>
              <button class="btn" type="submit">Download PDF</button>
            </form>
          <?php endif; ?>
          <button class="btn ghost" onclick="window.print()">Print / Save as PDF</button>
        </div>
      </footer>
    </div>
  </body>
</html>
