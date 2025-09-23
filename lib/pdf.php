<?php
// Minimal PDF generator for simple text-based order summaries (A4, single page)
// Now: bank details replaced with embedded QR image + UPI ID (JPEG only for simplicity)

function pdf_escape($s){
    return str_replace(['\\','(',')',"\r","\n"], ['\\\\','\(','\)',' ',' '], $s);
}

/**
 * Read JPEG dimensions (very small parser for SOF0/SOF2)
 * @return array [width, height] or [0,0] on failure
 */
function jpeg_dims(string $bytes): array {
    if (strlen($bytes) < 4 || $bytes[0] !== "\xFF" || $bytes[1] !== "\xD8") return [0,0];
    $i = 2;
    $len = strlen($bytes);
    while ($i+3 < $len) {
        if ($bytes[$i] !== "\xFF") { $i++; continue; }
        $marker = ord($bytes[$i+1]);
        $i += 2;
        if ($marker === 0xD9 || $marker === 0xDA) break; // EOI / SOS
        if ($i+1 >= $len) break;
        $segLen = (ord($bytes[$i])<<8) + ord($bytes[$i+1]);
        if ($segLen < 2 || $i+$segLen > $len) break;
        // SOF0(0xC0), SOF2(0xC2) carry dims
        if ($marker === 0xC0 || $marker === 0xC2) {
            if ($segLen >= 7) {
                $h = (ord($bytes[$i+3])<<8) + ord($bytes[$i+4]);
                $w = (ord($bytes[$i+5])<<8) + ord($bytes[$i+6]);
                return [$w,$h];
            }
            break;
        }
        $i += $segLen;
    }
    return [0,0];
}

function build_order_pdf(array $order): string {
    // Page size
    $W = 595; $H = 842; // A4 portrait points

    // Resources / XObjects (we’ll push later)
    $xobjects = [];
    $imageObjNum = null;

    // Try to load QR JPEG (path override allowed via $order['qr_path'])
    $qrPath = $order['qr_path'] ?? __DIR__ . '/../images/payments/upi_qr.jpg';
    if (!is_file($qrPath)) {
        // also try relative to current script
        $alt = __DIR__ . '/images/payments/upi_qr.jpg';
        if (is_file($alt)) $qrPath = $alt; else $qrPath = null;
    }
    $qrBytes = null; $qrW = 0; $qrH = 0;
    if ($qrPath) {
        $qrBytes = @file_get_contents($qrPath);
        if ($qrBytes !== false) {
            [$qrW,$qrH] = jpeg_dims($qrBytes);
            if ($qrW <= 0 || $qrH <= 0) { $qrBytes = null; }
        } else {
            $qrBytes = null;
        }
    }

    // Helpers for drawing
    $ops = '';
    $text = '';
    $M = 28; // margin
    $left = $M + 8;

    $addAt = function($x,$ytop,$txt,$size=10) use (&$text){
        $safe = pdf_escape($txt);
        $text .= sprintf("BT /F1 %d Tf %.2f %.2f Td (%s) Tj ET\n", $size, $x, $ytop, $safe);
    };
    $drawRect = function($x,$yTop,$w,$h) use (&$ops){
        $yy = $yTop - $h; // bottom-left
        $ops .= sprintf("%.2f %.2f %.2f %.2f re S\n", $x, $yy, $w, $h);
    };
    $drawLine = function($x1,$y1,$x2,$y2) use (&$ops){
        $ops .= sprintf("%.2f %.2f m %.2f %.2f l S\n", $x1, $y1, $x2, $y2);
    };

    // Pull data
    $vendor = $order['vendor'] ?? [];
    $vName = strtoupper($vendor['name'] ?? 'PSK CRACKERS');
    $vAddr = trim(($vendor['address'] ?? ''));
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

    // Estimation No
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

    // Title
    $titleH = 26; $innerW = $W - 2*$M; $x0 = $M; $yTop = $H - $M;
    $drawRect($x0, $yTop, $innerW, $titleH);
    $addAt($x0 + $innerW/2 - 50, $yTop - 18, 'Tax Estimation', 14);
    $addAt($x0 + $innerW - 60, $yTop - 12, '[Original]', 8);

    // Party boxes
    $boxH = 110; $halfW = $innerW/2; $yTop2 = $yTop - $titleH;
    $drawRect($x0, $yTop2, $halfW, $boxH); // Billed By
    $drawRect($x0 + $halfW, $yTop2, $halfW, $boxH); // Estimation Details
    $drawRect($x0, $yTop2 - $boxH, $halfW, $boxH); // Ship To
    $drawRect($x0 + $halfW, $yTop2 - $boxH, $halfW, $boxH); // blank

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

    // Items table
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
    // Header row
    $drawRect($x0, $tableTop, $innerW, $rowH);
    $addAt($colX[0] + 6, $tableTop - 12, 'S No', 9);
    $addAt($colX[1] + 6, $tableTop - 12, 'Particular', 9);
    $addAt($colX[2] + 6, $tableTop - 12, 'Qty', 9);
    $addAt($colX[3] + 6, $tableTop - 12, 'Unit', 9);
    $addAt($colX[4] + 6, $tableTop - 12, 'Price', 9);
    $addAt($colX[5] + 6, $tableTop - 12, 'Amount', 9);
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

    // Bottom area: LEFT = QR panel, RIGHT = totals
    $bottomBoxH = 120; // a bit taller for QR + text
    $bottomTop = $M + 110; // panel top (PDF coords, down from bottom)
    // Draw left panel border (visual)
    $drawRect($x0, $bottomTop, $innerW/2, $bottomBoxH);
    // Place QR (if available) at ~220x220pt inside left panel
    $qrTargetSize = 120; // points
    $qrX = $x0 + 12;
    $qrY = $bottomTop - 16 - $qrTargetSize; // yTop - h

    if ($qrBytes) {
        // We will register an XObject name /Im1 (object num assigned later)
        // Content stream: q  sX 0 0 sY  x y cm  /Im1 Do  Q
        $ops .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q\n",
                        $qrTargetSize, $qrTargetSize, $qrX, $qrY);
        // Caption: "Scan & Pay" and UPI ID under image
        $addAt($qrX, $qrY - 12, 'Scan & Pay', 10);
        $addAt($qrX, $qrY - 26, 'UPI ID: pskcrackers@axl', 9);
        // Remember to emit XObject later
        $xobjects['/Im1'] = ['bytes'=>$qrBytes, 'w'=>$qrW?:400, 'h'=>$qrH?:400];
    } else {
        // Fallback: draw placeholder and label
        $drawRect($qrX, $qrY + $qrTargetSize, $qrTargetSize, $qrTargetSize);
        $addAt($qrX + 6, $qrY + $qrTargetSize - 16, 'QR not available', 9);
        $addAt($qrX, $qrY - 12, 'UPI ID: pskcrackers@axl', 9);
    }

    // Totals right box
    $drawRect($x0 + $innerW/2, $bottomTop, $innerW/2, $bottomBoxH);
    $lineY = $bottomTop + $bottomBoxH - 16;
    $addAt($x0 + $innerW/2 + 6, $lineY, 'Sub Total : ' . number_format($subtotal,2), 10);
    $lineY -= 14;
    if ($shipping > 0) { $addAt($x0 + $innerW/2 + 6, $lineY, 'Shipping : ' . number_format($shipping,2), 10); $lineY -= 14; }
    if ($disc_amount > 0) {
        $label = 'Discount' . ($discount_pct !== null ? (' ' . (int)$discount_pct . ' %') : '');
        $addAt($x0 + $innerW/2 + 6, $lineY, $label . ' : ' . number_format($disc_amount,2), 10); $lineY -= 14;
    }
    if ($sgst > 0) { $addAt($x0 + $innerW/2 + 6, $lineY, 'SGST : ' . number_format($sgst,2), 10); $lineY -= 14; }
    if ($cgst > 0) { $addAt($x0 + $innerW/2 + 6, $lineY, 'CGST : ' . number_format($cgst,2), 10); $lineY -= 14; }
    $addAt($x0 + $innerW/2 + 6, $lineY, 'NET AMOUNT : ' . number_format($netAmount,2), 11);

    // Amount in words strip
    $words = amount_in_words_indian($netAmount);
    $drawRect($x0, $M + 72, $innerW, 20);
    $addAt($x0 + 6, $M + 72 + 6, 'Amount in words : ' . $words, 9);
    // Bottom net strip
    $drawRect($x0, $M + 52, $innerW, 20);
    $addAt($x0 + $innerW - 160, $M + 52 + 6, 'NET AMOUNT : ' . number_format($netAmount,2), 10);

    // Footer sign line
    $addAt($x0 + 6, $M + 26, 'Customer Sign', 9);
    $addAt($x0 + $innerW - 120, $M + 26, 'For ' . ($vendor['name'] ?? 'PSK CRACKERS'), 9);
    if (!empty($order['notes'])) $addAt($x0 + 6, $M + 12, (string)$order['notes'], 8);
    else $addAt($x0 + 6, $M + 12, 'We Declare that this Estimation shows the actual price of the goods described and that all particulars are true and correct', 8);

    // ------------ Build PDF objects ------------
    $xref = [0];
    $out = "%PDF-1.4\n";
    $emit = function($objNum, $data) use (&$out, &$xref) {
        $xref[] = strlen($out);
        $out .= $objNum . " 0 obj\n" . $data . "\nendobj\n";
    };

    // Font
    $emit(1, "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");

    // Image XObject (if available)
    if ($qrBytes) {
        $imageObjNum = 6; // reserve a number for the image
        // JPEG as DCTDecode, assume RGB, 8bpc
        $imgDict = sprintf(
            "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>",
            $xobjects['/Im1']['w'], $xobjects['/Im1']['h'], strlen($qrBytes)
        );
        $emit($imageObjNum, $imgDict . "\nstream\n" . $qrBytes . "\nendstream");
    }

    // Content stream (ops + text)
    $content = "0 0 0 RG 0 0 0 rg 0.8 w\n" . $ops . $text;
    $emit(2, "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream");

    // Page dict (include XObject if image)
    if ($qrBytes) {
        $emit(3, "<< /Type /Page /Parent 4 0 R /MediaBox [0 0 $W $H] /Contents 2 0 R " .
                 "/Resources << /Font << /F1 1 0 R >> /XObject << /Im1 $imageObjNum 0 R >> >> >>");
    } else {
        $emit(3, "<< /Type /Page /Parent 4 0 R /MediaBox [0 0 $W $H] /Contents 2 0 R " .
                 "/Resources << /Font << /F1 1 0 R >> >> >>");
    }

    // Pages & Catalog
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
    $add = function($num,$label) use (&$parts,$fmt2){
        if ($num) {
            $hund = intdiv($num,100); $rem = $num%100;
            $seg = [];
            if ($hund) $seg[] = $ones[$hund] . ' Hundred';
            if ($rem) $seg[] = $fmt2($rem);
            $parts[] = trim(implode(' ', $seg)) . ($label?(' '.$label):'');
        }
    };
    $add(intdiv($n,10000000),'Crore'); $n%=10000000;
    $add(intdiv($n,100000),'Lakh'); $n%=100000;
    $add(intdiv($n,1000),'Thousand'); $n%=1000;
    $add($n,'');
    return trim(implode(' ', array_filter($parts))) . ' Rupees Only';
}
?>
