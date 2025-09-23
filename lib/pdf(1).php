<?php
// Minimal PDF generator for simple text-based order summaries (A4, single page)
// Not a full PDF library; adequate for compact invoices/receipts.

function pdf_escape($s){
    return str_replace(['\\','(',')',"\r","\n"], ['\\\\','\(','\)',' ',' '], $s);
}

function build_order_pdf(array $order): string {
    // Page size
    $W = 595; $H = 842; // A4 portrait points

    // Helpers
    $ops = '';
    $text = '';
    $M = 28; // margin
    $y = $H - $M; // top cursor for text blocks (decreasing)
    $left = $M + 8;

    $addText = function($txt, $size = 11, $x = null) use (&$text, &$y, $left){
        if ($x === null) $x = $left;
        $safe = pdf_escape($txt);
        $text .= sprintf("BT /F1 %d Tf %.2f %.2f Td (%s) Tj ET\n", $size, $x, $y, $safe);
        $y -= ($size + 4);
    };
    $addAt = function($x,$ytop,$txt,$size=10) use (&$text,$H){
        $safe = pdf_escape($txt);
        $yy = $ytop; // already in PDF coordinates? we'll pass top-space and convert
        $text .= sprintf("BT /F1 %d Tf %.2f %.2f Td (%s) Tj ET\n", $size, $x, $yy, $safe);
    };
    $drawRect = function($x,$yTop,$w,$h) use (&$ops,$H){
        $yy = $yTop - $h; // PDF wants bottom-left
        $ops .= sprintf("%.2f %.2f %.2f %.2f re S\n", $x, $yy, $w, $h);
    };
    $drawLine = function($x1,$y1,$x2,$y2) use (&$ops){
        $ops .= sprintf("%.2f %.2f m %.2f %.2f l S\n", $x1, $y1, $x2, $y2);
    };

    // Pull data
    $vendor = $order['vendor'] ?? [];
    $vName = strtoupper($vendor['name'] ?? 'PSK CRACKERS');
    $vAddr = trim(($vendor['address'] ?? '')); // optional
    $vPhone = $vendor['phone'] ?? '';
    $vGst = $vendor['gst'] ?? '';

    $cust = $order['customer'] ?? [];
    $cName = $cust['name'] ?? '';
    $cCity = $cust['city'] ?? '';
    $cState = $cust['state'] ?? '';
    $cPin = $cust['pincode'] ?? '';
    $cPhone = $cust['phone'] ?? '';
    $cAddr1 = trim($cust['address_line1'] ?? '');
    $cAddr2 = trim($cust['address_line2'] ?? '');

    // Estimation No: EST-YYYYMMDD-HHMMSS-<6 digit order no or time>
    $datePart = date('Ymd-His');
    $ordPart = isset($order['order_id']) ? str_pad((string)$order['order_id'], 6, '0', STR_PAD_LEFT) : date('His');
    $estNo = $order['est_no'] ?? ('EST-' . $datePart . '-' . $ordPart);
    $stateSupply = $order['state_of_supply'] ?? '';
    $eway = $order['eway_bill'] ?? '';
    $vehicle = $order['vehicle_no'] ?? '';

    $items = $order['items'] ?? [];
    $subtotal = (float)($order['subtotal'] ?? 0);
    $shipping = (float)($order['shipping'] ?? 0);
    $discount = (float)($order['discount'] ?? 0);
    $discount_pct = isset($order['discount_pct']) ? (float)$order['discount_pct'] : null;
    $disc_amount = $discount_pct !== null ? round($subtotal * $discount_pct/100, 2) : $discount;
    $sgst = (float)($order['sgst'] ?? 0);
    $cgst = (float)($order['cgst'] ?? 0);
    $netAmount = (float)($order['total'] ?? max(0, $subtotal - $disc_amount + $shipping + $sgst + $cgst));

    // Title bar
    $titleH = 26; $innerW = $W - 2*$M; $x0 = $M; $yTop = $H - $M;
    $drawRect($x0, $yTop, $innerW, $titleH);
    // Title centered (approx)
    $addAt($x0 + $innerW/2 - 50, $yTop - 18, 'Tax Estimation', 14);
    $addAt($x0 + $innerW - 60, $yTop - 12, '[Original]', 8);

    // Party boxes
    $boxH = 110; $halfW = $innerW/2; $yTop2 = $yTop - $titleH;
    $drawRect($x0, $yTop2, $halfW, $boxH); // Billed By
    $drawRect($x0 + $halfW, $yTop2, $halfW, $boxH); // Estimation Details

    // Ship to box under left (same height)
    $drawRect($x0, $yTop2 - $boxH, $halfW, $boxH);
    // Right lower info box (blank)
    $drawRect($x0 + $halfW, $yTop2 - $boxH, $halfW, $boxH);

    // Fill left upper: Billed By
    $addAt($x0 + 6, $yTop2 - 16, 'Billed By : ' . $vName, 10);
    if ($vAddr !== '') $addAt($x0 + 6, $yTop2 - 30, $vAddr, 9);
    if ($vGst !== '') $addAt($x0 + 6, $yTop2 - 44, 'GST : ' . $vGst, 9);
    if ($vPhone !== '') $addAt($x0 + 6, $yTop2 - 58, 'Ph : ' . $vPhone, 9);

    // Fill right upper: Estimation meta
    $addAt($x0 + $halfW + 6, $yTop2 - 16, 'Estimation No: ' . $estNo, 10);
    $addAt($x0 + $halfW + 6, $yTop2 - 30, 'Date: ' . date('Y-m-d'), 10);
    if ($stateSupply !== '') $addAt($x0 + $halfW + 6, $yTop2 - 44, 'State Of Supply: ' . $stateSupply, 9);
    if ($eway !== '') $addAt($x0 + $halfW + 6, $yTop2 - 58, 'E-way Bill No: ' . $eway, 9);
    if ($vehicle !== '') $addAt($x0 + $halfW + 6, $yTop2 - 72, 'Vehicle No: ' . $vehicle, 9);

    // Ship To (left lower)
    $addAt($x0 + 6, $yTop2 - $boxH - 16, 'Ship To : ' . $cName, 10);
    $shipAddr = trim(implode(', ', array_filter([$cAddr1, $cAddr2, $cCity, $cState, $cPin])));
    if ($shipAddr !== '') $addAt($x0 + 6, $yTop2 - $boxH - 30, 'Address : ' . $shipAddr, 9);
    if ($cPhone !== '') $addAt($x0 + 6, $yTop2 - $boxH - 44, 'Ph : ' . $cPhone, 9);

    // Items table header
    $tableTop = $yTop2 - 2*$boxH - 6; // gap
    $colX = [
        $x0,                      // left border
        $x0 + 36,                 // S No
        $x0 + 36 + 300,           // Particular
        $x0 + 36 + 300 + 60,      // Qty
        $x0 + 36 + 300 + 60 + 60, // Unit
        $x0 + 36 + 300 + 60 + 60 + 70, // Price
        $x0 + $innerW             // Amount/right
    ];
    $rowH = 18;
    // Header row box
    $drawRect($x0, $tableTop, $innerW, $rowH);
    $addAt($colX[0] + 6, $tableTop - 12, 'S No', 9);
    $addAt($colX[1] + 6, $tableTop - 12, 'Particular', 9);
    $addAt($colX[2] + 6, $tableTop - 12, 'Qty', 9);
    $addAt($colX[3] + 6, $tableTop - 12, 'Unit', 9);
    $addAt($colX[4] + 6, $tableTop - 12, 'Price', 9);
    $addAt($colX[5] + 6, $tableTop - 12, 'Amount', 9);
    // Vertical lines for columns
    for ($i=1;$i<count($colX)-1;$i++) { $drawLine($colX[$i], $tableTop - $rowH, $colX[$i], $tableTop); }

    // Item rows
    $yCursor = $tableTop - $rowH; $sno = (int)($order['sno_start'] ?? 1); $maxRows = 18;
    foreach ($items as $it) {
        if ($maxRows-- <= 0) break;
        $qty = (int)($it['qty'] ?? 0);
        $unit = $it['unit'] ?? 'Box';
        $name = (string)($it['name'] ?? ($it['id'] ?? 'Item'));
        $price = (float)($it['price'] ?? ($it['unit_price'] ?? 0));
        $amount = $price * $qty;
        // Row box
        $drawRect($x0, $yCursor, $innerW, $rowH);
        for ($i=1;$i<count($colX)-1;$i++) { $drawLine($colX[$i], $yCursor - $rowH, $colX[$i], $yCursor); }
        // Text
        $addAt($colX[0] + 6, $yCursor - 12, (string)$sno, 9);
        $addAt($colX[1] + 6, $yCursor - 12, strtoupper(mb_strimwidth($name,0,44,'…','UTF-8')), 9);
        $addAt($colX[2] + 6, $yCursor - 12, (string)$qty, 9);
        $addAt($colX[3] + 6, $yCursor - 12, $unit, 9);
        $addAt($colX[4] + 6, $yCursor - 12, number_format($price,2), 9);
        $addAt($colX[5] + 6, $yCursor - 12, number_format($amount,2), 9);
        $yCursor -= $rowH; $sno++;
    }

    // Bottom area: bank + totals
    $bottomBoxH = 80; $bottomY = $M + 70; // leave footer space
    // Bank left box
    $drawRect($x0, $bottomY + 20, $innerW/2, $bottomBoxH);
    $addAt($x0 + 6, $bottomY + $bottomBoxH + 20 - 16, 'Bank Name : ' . ($vendor['bank_name'] ?? 'ICICI'), 9);
    $addAt($x0 + 6, $bottomY + $bottomBoxH + 20 - 30, 'Account No : ' . ($vendor['bank_ac'] ?? '101630666'), 9);
    $addAt($x0 + 6, $bottomY + $bottomBoxH + 20 - 44, 'IFSC : ' . ($vendor['bank_ifsc'] ?? 'ICIC0000001'), 9);

    // Totals right box
    $drawRect($x0 + $innerW/2, $bottomY + 20, $innerW/2, $bottomBoxH);
    $lineY = $bottomY + $bottomBoxH + 20 - 16;
    $addAt($x0 + $innerW/2 + 6, $lineY, 'Sub Total : ' . number_format($subtotal,2), 9);
    $lineY -= 14;
    if ($shipping > 0) { $addAt($x0 + $innerW/2 + 6, $lineY, 'Shipping : ' . number_format($shipping,2), 9); $lineY -= 14; }
    if ($disc_amount > 0) {
        $label = 'Discount' . ($discount_pct !== null ? (' ' . (int)$discount_pct . ' %') : '');
        $addAt($x0 + $innerW/2 + 6, $lineY, $label . ' : ' . number_format($disc_amount,2), 9); $lineY -= 14;
    }
    if ($sgst > 0) { $addAt($x0 + $innerW/2 + 6, $lineY, 'SGST : ' . number_format($sgst,2), 9); $lineY -= 14; }
    if ($cgst > 0) { $addAt($x0 + $innerW/2 + 6, $lineY, 'CGST : ' . number_format($cgst,2), 9); $lineY -= 14; }
    $addAt($x0 + $innerW/2 + 6, $lineY, 'NET AMOUNT : ' . number_format($netAmount,2), 10);

    // Amount in words
    $words = amount_in_words_indian($netAmount);
    $drawRect($x0, $bottomY, $innerW, 20);
    $addAt($x0 + 6, $bottomY + 20 - 14, 'Amount in words : ' . $words, 9);
    // Bottom net amount strip
    $drawRect($x0, $bottomY - 20, $innerW, 20);
    $addAt($x0 + $innerW - 160, $bottomY - 20 + 6, 'NET AMOUNT : ' . number_format($netAmount,2), 10);

    // Footer sign line
    $addAt($x0 + 6, $M + 26, 'Customer Sign', 9);
    $addAt($x0 + $innerW - 120, $M + 26, 'For ' . ($vendor['name'] ?? 'PSK CRACKERS'), 9);
    if (!empty($order['notes'])) $addAt($x0 + 6, $M + 12, (string)$order['notes'], 8);
    else $addAt($x0 + 6, $M + 12, 'We Declare that this Estimation shows the actual price of the goods described and that all particulars are true and correct', 8);

    // Build PDF objects
    $xref = [0];
    $out = "%PDF-1.4\n";
    $emit = function($objNum, $data) use (&$out, &$xref) {
        $xref[] = strlen($out);
        $out .= $objNum . " 0 obj\n" . $data . "\nendobj\n";
    };

    // Resources
    $emit(1, "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");

    // Content stream: set stroke width, stroke color, then draw ops and text
    $content = "0 0 0 RG 0 0 0 rg 0.8 w\n" . $ops . $text;
    $emit(2, "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream");

    // Page and catalog
    $emit(3, "<< /Type /Page /Parent 4 0 R /MediaBox [0 0 $W $H] /Contents 2 0 R /Resources << /Font << /F1 1 0 R >> >> >>");
    $emit(4, "<< /Type /Pages /Kids [3 0 R] /Count 1 >>");
    $emit(5, "<< /Type /Catalog /Pages 4 0 R >>");

    // XRef
    $start = strlen($out);
    $out .= "xref\n0 " . count($xref) . "\n";
    foreach ($xref as $i=>$ofs) { $out .= sprintf("%010d %05d %s\n", $ofs, $i?0:65535, $i?'n':'f'); }
    $out .= "trailer << /Size " . count($xref) . " /Root 5 0 R >>\nstartxref\n$start\n%%EOF";
    return $out;
}

// Convert number to words (Indian system, rupees only)
function amount_in_words_indian($amount): string {
    $n = (int)round($amount);
    if ($n === 0) return 'Zero Rupees Only';
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $parts = [];
    $fmt2 = function($num) use($ones,$tens){
        if ($num < 20) return $ones[$num];
        $t = intdiv($num,10); $o = $num%10; return $tens[$t] . ($o?(' '.$ones[$o]):'');
    };
    $add = function($num,$label) use (&$parts,$fmt2){ if ($num) $parts[] = trim($fmt2(intdiv($num,100)).(intdiv($num,100)?' Hundred':'').' '.($fmt2($num%100))) . ($label?(' '.$label):''); };
    $add(intdiv($n,10000000),'Crore'); $n%=10000000;
    $add(intdiv($n,100000),'Lakh'); $n%=100000;
    $add(intdiv($n,1000),'Thousand'); $n%=1000;
    $add($n,'');
    return trim(implode(' ', array_filter($parts))) . ' Rupees Only';
}
?>
