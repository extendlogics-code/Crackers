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
    // Keep QR panel enabled if image is valid

    // Helpers for drawing
    $M = 28; // margin
    $drawRect = static function (&$ops, $x, $yTop, $w, $h){
        $yy = $yTop - $h; // bottom-left
        $ops .= sprintf("%.2f %.2f %.2f %.2f re S\n", $x, $yy, $w, $h);
    };
    $drawLine = static function (&$ops, $x1, $y1, $x2, $y2){
        $ops .= sprintf("%.2f %.2f m %.2f %.2f l S\n", $x1, $y1, $x2, $y2);
    };
    $addText = static function (&$text, $x, $ytop, $txt, $size=10){
        $safe = pdf_escape($txt);
        $text .= sprintf("BT /F1 %d Tf %.2f %.2f Td (%s) Tj ET\n", $size, $x, $ytop, $safe);
    };
    $trimmedName = static function($s){
        $max = 44; $ellipsis = '…';
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($s, 0, $max, $ellipsis, 'UTF-8');
        }
        $s = (string)$s;
        return (strlen($s) > $max) ? substr($s, 0, max(0,$max-1)) . $ellipsis : $s;
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
    $discount = (float)($order['discount'] ?? 0);
    $discount_pct = isset($order['discount_pct']) ? (float)$order['discount_pct'] : null;
    $total = (float)($order['total'] ?? $subtotal);
    // NET AMOUNT mirrors stored total (shipping removed)
    $netAmount = (float)$total;

    // Reusable layout metrics
    $titleH = 26; $innerW = $W - 2*$M; $x0 = $M; $yTop = $H - $M;
    $boxH = 110; $halfW = $innerW/2;
    $tableHeaderGap = 6;
    $rowH = 18;
    $colX = [
        $x0,                      // left border
        $x0 + 36,                 // S No
        $x0 + 36 + 200,           // Particular
        $x0 + 36 + 200 + 60,      // Qty
        $x0 + 36 + 200 + 60 + 60, // Price
        $x0 + $innerW             // Amount/right
    ];

    $itemsPerPage = 18;
    $chunks = array_chunk($items, $itemsPerPage);
    if (empty($chunks)) {
        $chunks = [[]];
    }
    $totalItemCount = count($items);
    $totalPages = count($chunks);
    $pageNo = 0;
    $serial = (int)($order['sno_start'] ?? 1);
    $pages = [];

    $renderPage = function(array $pageItems, bool $isLast, int $currentSerial, int $pageIndex) use (
        $W,$H,$M,$titleH,$innerW,$x0,$yTop,$boxH,$halfW,$tableHeaderGap,$rowH,$colX,
        $drawRect,$drawLine,$addText,$trimmedName,
        $vName,$vAddr,$vGst,$vPhone,
        $cName,$cAddr1,$cAddr2,$cCity,$cState,$cPin,$cPhone,
        $estNo,$stateSupply,$eway,$vehicle,
        $subtotal,$netAmount,$order,$totalItemCount,$totalPages,$vendor
    ){
        $ops = ''; $text = '';

        // Title
        $drawRect($ops, $x0, $yTop, $innerW, $titleH);
        $addText($text, $x0 + $innerW/2 - 50, $yTop - 18, 'Invoice Order', 14);
        $addText($text, $x0 + $innerW - 60, $yTop - 12, '[Original]', 8);

        // Party boxes
        $yTop2 = $yTop - $titleH;
        $drawRect($ops, $x0, $yTop2, $halfW, $boxH); // Billed By
        $drawRect($ops, $x0 + $halfW, $yTop2, $halfW, $boxH); // Estimation Details
        $drawRect($ops, $x0, $yTop2 - $boxH, $halfW, $boxH); // Ship To
        $drawRect($ops, $x0 + $halfW, $yTop2 - $boxH, $halfW, $boxH); // blank

        // Fill left upper: Billed By
        $addText($text, $x0 + 6, $yTop2 - 16, 'Billed By : ' . $vName, 10);
        if ($vAddr !== '') $addText($text, $x0 + 6, $yTop2 - 30, $vAddr, 9);
        if ($vGst !== '') $addText($text, $x0 + 6, $yTop2 - 44, 'GST : ' . $vGst, 9);
        if ($vPhone !== '') $addText($text, $x0 + 6, $yTop2 - 58, 'Ph : ' . $vPhone, 9);

        // Fill right upper: Estimation meta
        $addText($text, $x0 + $halfW + 6, $yTop2 - 16, 'Estimation No: ' . $estNo, 10);
        $addText($text, $x0 + $halfW + 6, $yTop2 - 30, 'Date: ' . date('Y-m-d'), 10);
        if ($stateSupply !== '') $addText($text, $x0 + $halfW + 6, $yTop2 - 44, 'State Of Supply: ' . $stateSupply, 9);
        if ($eway !== '') $addText($text, $x0 + $halfW + 6, $yTop2 - 58, 'E-way Bill No: ' . $eway, 9);
        if ($vehicle !== '') $addText($text, $x0 + $halfW + 6, $yTop2 - 72, 'Vehicle No: ' . $vehicle, 9);

        // Ship To (left lower)
        $addText($text, $x0 + 6, $yTop2 - $boxH - 16, 'Ship To : ' . $cName, 10);
        $cityStateParts = array_filter([$cCity, $cState]);
        $yAddr = $yTop2 - $boxH - 30;
        if ($cAddr1 !== '') {
            $addText($text, $x0 + 6, $yAddr, 'Address Line 1 : ' . $cAddr1, 9);
            $yAddr -= 12;
        }
        if ($cAddr2 !== '') {
            $addText($text, $x0 + 6, $yAddr, 'Address Line 2 : ' . $cAddr2, 9);
            $yAddr -= 12;
        }
        if ($cityStateParts) {
            $line = implode(', ', $cityStateParts);
            if ($cPin !== '') { $line .= ' - ' . $cPin; }
            $addText($text, $x0 + 6, $yAddr, 'City / State : ' . $line, 9);
            $yAddr -= 12;
        } elseif ($cPin !== '') {
            $addText($text, $x0 + 6, $yAddr, 'PIN : ' . $cPin, 9);
            $yAddr -= 12;
        }
        if ($cPhone !== '') {
            $addText($text, $x0 + 6, $yAddr, 'Ph : ' . $cPhone, 9);
            $yAddr -= 12;
        }

        // Items table header
        $tableTop = $yTop2 - 2*$boxH - $tableHeaderGap;
        $drawRect($ops, $x0, $tableTop, $innerW, $rowH);
        $addText($text, $colX[0] + 6, $tableTop - 12, 'S No', 9);
        $addText($text, $colX[1] + 6, $tableTop - 12, 'Particular', 9);
        $addText($text, $colX[2] + 6, $tableTop - 12, 'Qty', 9);
        $addText($text, $colX[3] + 6, $tableTop - 12, 'Price', 9);
        $addText($text, $colX[4] + 6, $tableTop - 12, 'Amount', 9);
        for ($i=1;$i<count($colX)-1;$i++) { $drawLine($ops, $colX[$i], $tableTop - $rowH, $colX[$i], $tableTop); }

        // Item rows
        $yCursor = $tableTop - $rowH;
        foreach ($pageItems as $it) {
            $qty = (int)($it['qty'] ?? 0);
            $name = (string)($it['name'] ?? ($it['id'] ?? 'Item'));
            $price = (float)($it['price'] ?? ($it['unit_price'] ?? 0));
            $amount = $price * $qty;

            $drawRect($ops, $x0, $yCursor, $innerW, $rowH);
            for ($i=1;$i<count($colX)-1;$i++) { $drawLine($ops, $colX[$i], $yCursor - $rowH, $colX[$i], $yCursor); }

            $addText($text, $colX[0] + 6, $yCursor - 12, (string)$currentSerial, 9);
            $addText($text, $colX[1] + 6, $yCursor - 12, strtoupper($trimmedName($name)), 9);
            $addText($text, $colX[2] + 6, $yCursor - 12, (string)$qty, 9);
            $addText($text, $colX[3] + 6, $yCursor - 12, number_format($price,2), 9);
            $addText($text, $colX[4] + 6, $yCursor - 12, number_format($amount,2), 9);

            $yCursor -= $rowH;
            $currentSerial++;
        }

        // Sub total only on last page when more than one item overall
        if ($isLast && $totalItemCount > 1) {
            $drawRect($ops, $x0, $yCursor, $innerW, $rowH);
            for ($i=1;$i<count($colX)-1;$i++) { $drawLine($ops, $colX[$i], $yCursor - $rowH, $colX[$i], $yCursor); }
            $addText($text, $colX[1] + 6, $yCursor - 12, 'SUB TOTAL', 9);
            $addText($text, $colX[4] + 6, $yCursor - 12, number_format($subtotal,2), 9);
            $yCursor -= $rowH;
        }

        if ($isLast) {
            // Totals section with QR panel (like invoice_preview)
            $sectionTop = max($M + 220, $yCursor - 24);

            // RIGHT: totals box
            $rows = [];
            $rows[] = ['Sub Total', $subtotal];
            $rows[] = ['NET AMOUNT :', $netAmount];
            $sumRowH = 20; $sumW = 320; $sumX = $x0 + $innerW - $sumW - 12;
            $sumTop = $sectionTop; $sumH = $sumRowH * count($rows);
            $drawRect($ops, $sumX, $sumTop, $sumW, $sumH);
            $ry = $sumTop;
            for ($i=1;$i<count($rows);$i++){
                $ry -= $sumRowH;
                $drawLine($ops, $sumX, $ry, $sumX+$sumW, $ry);
            }
            $valX = $sumX + $sumW - 120; // align amounts
            $ty = $sumTop;
            foreach ($rows as $i=>$r){
                $ty -= ($sumRowH - 8);
                $size = ($i === count($rows)-1) ? 10 : 9;
                $addText($text, $sumX + 8, $ty, $r[0], $size);
                $addText($text, $valX, $ty, number_format((float)$r[1],2), $size);
                $ty -= 8;
            }

            $sectionH = $sumH;
            $wordsTop = $sectionTop - $sectionH - 12;
            $words = amount_in_words_indian($netAmount);
            $drawRect($ops, $x0, $wordsTop, $innerW, 20);
            $addText($text, $x0 + 6, $wordsTop - 14, 'Amount in words : ' . $words, 9);

            $netStripTop = $wordsTop - 20;
            $drawRect($ops, $x0, $netStripTop, $innerW, 20);
            $addText($text, $x0 + $innerW - 160, $netStripTop - 14, 'NET AMOUNT : ' . number_format($netAmount,2), 10);

            // Footer sign line
            $addText($text, $x0 + 6, $M + 26, 'Customer Sign', 9);
            $addText($text, $x0 + $innerW - 120, $M + 26, 'For ' . ($vendor['name'] ?? 'PSK CRACKERS'), 9);
            if (!empty($order['notes'])) {
                $addText($text, $x0 + 6, $M + 12, (string)$order['notes'], 8);
            } else {
                $addText($text, $x0 + 6, $M + 12, 'We Declare that this Estimation shows the actual price of the goods described and that all particulars are true and correct', 8);
            }
        } else {
            // Continuation notice on intermediate pages
            $addText($text, $x0 + $innerW - 160, $M + 20, 'Continued on next page.', 9);
        }

        // Page footer with page number
        $addText($text, $x0 + $innerW - 80, $M + 4, 'Page ' . ($pageIndex + 1) . '/' . $totalPages, 8);

        return ['ops' => $ops, 'text' => $text];
    };

    foreach ($chunks as $idx => $chunk) {
        $isLast = ($idx === $totalPages - 1);
        $pages[] = $renderPage($chunk, $isLast, $serial, $idx);
        $serial += count($chunk);
        if (!$isLast && empty($chunk)) {
            // avoid infinite loop with empty chunk (should not happen, but guard)
            $serial++;
        }
    }

    // If last chunk was empty ensure we still produced at least one page
    if (empty($pages)) {
        $pages[] = $renderPage([], true, $serial, 0);
    }

    // ------------ Build PDF objects ------------
    $xref = [0];
    $out = "%PDF-1.4\n";
    $emit = function($objNum, $data) use (&$out, &$xref) {
        $xref[] = strlen($out);
        $out .= $objNum . " 0 obj\n" . $data . "\nendobj\n";
    };

    $nextObj = 1;
    $fontObjNum = $nextObj++;
    $imageObjNum = null;
    if ($qrBytes) {
        $imageObjNum = $nextObj++;
    }
    $contentObjNums = [];
    foreach ($pages as $_) {
        $contentObjNums[] = $nextObj++;
    }
    $pageObjNums = [];
    foreach ($pages as $_) {
        $pageObjNums[] = $nextObj++;
    }
    $pagesRootObjNum = $nextObj++;
    $catalogObjNum = $nextObj++;

    // Font
    $emit($fontObjNum, "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");

    // Image XObject (if available)
    if ($imageObjNum !== null) {
        $emit($imageObjNum, sprintf(
            "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream",
            max(1,$qrW), max(1,$qrH), strlen($qrBytes), $qrBytes
        ));
    }

    // Content streams
    foreach ($pages as $i => $pageData) {
        $content = "0 0 0 RG 0 0 0 rg 0.8 w\n" . $pageData['ops'] . $pageData['text'];
        $emit($contentObjNums[$i], "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream");
    }

    // Page dictionaries
    $kids = [];
    foreach ($pages as $i => $pageData) {
        $resources = "<< /Font << /F1 $fontObjNum 0 R >>";
        if ($imageObjNum !== null) {
            $resources .= " /XObject << /Im1 $imageObjNum 0 R >>";
        }
        $resources .= " >>";
        $emit($pageObjNums[$i], "<< /Type /Page /Parent $pagesRootObjNum 0 R /MediaBox [0 0 $W $H] /Contents " . $contentObjNums[$i] . " 0 R /Resources $resources >>");
        $kids[] = $pageObjNums[$i] . " 0 R";
    }

    // Pages & Catalog
    $emit($pagesRootObjNum, "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($kids) . " >>");
    $emit($catalogObjNum, "<< /Type /Catalog /Pages $pagesRootObjNum 0 R >>");

    // XRef
    $start = strlen($out);
    $out .= "xref\n0 " . count($xref) . "\n";
    foreach ($xref as $i=>$ofs) { $out .= sprintf("%010d %05d %s\n", $ofs, $i?0:65535, $i?'n':'f'); }
    $out .= "trailer << /Size " . count($xref) . " /Root $catalogObjNum 0 R >>\nstartxref\n$start\n%%EOF";
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
            if ($hund) $seg[] = $fmt2($hund) . ' Hundred';
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
