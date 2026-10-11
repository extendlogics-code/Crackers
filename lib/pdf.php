<?php
// Minimal PDF generator for simple text-based order summaries (A4, single page)
// Now: bank details replaced with embedded QR image + UPI ID (JPEG only for simplicity)

function pdf_escape($s){
    return str_replace(['\\','(',')',"\r","\n"], ['\\\\','\(','\)',' ',' '], $s);
}

/**
 * Normalize text for Helvetica (WinAnsi-ish): collapse whitespace, drop unsupported glyphs.
 */
function pdf_plain_text($s): string {
    $s = (string)$s;
    if ($s === '') return '';
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s);
        if ($converted !== false) {
            $s = $converted;
        }
    }
    $s = preg_replace("/[\\r\\n\\t]+/", ' ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

/**
 * Helvetica glyph widths in AFM units (1/1000 em). Unknown chars use 600.
 */
function pdf_helvetica_unit_width(string $ch): float {
    static $w = null;
    if ($w === null) {
        $w = [
            ' '=>278,'!'=>278,'"'=>355,'#'=>556,'$'=>556,'%'=>889,'&'=>667,"'"=>191,
            '('=>333,')'=>333,'*'=>389,'+'=>584,','=>278,'-'=>333,'.'=>278,'/'=>278,
            '0'=>556,'1'=>556,'2'=>556,'3'=>556,'4'=>556,'5'=>556,'6'=>556,'7'=>556,
            '8'=>556,'9'=>556,':'=>278,';'=>278,'<'=>584,'='=>584,'>'=>584,'?'=>556,
            '@'=>1015,'A'=>667,'B'=>667,'C'=>722,'D'=>722,'E'=>667,'F'=>611,'G'=>778,
            'H'=>722,'I'=>278,'J'=>500,'K'=>667,'L'=>556,'M'=>833,'N'=>722,'O'=>778,
            'P'=>667,'Q'=>778,'R'=>722,'S'=>667,'T'=>611,'U'=>722,'V'=>667,'W'=>944,
            'X'=>667,'Y'=>667,'Z'=>611,'['=>278,'\\'=>278,']'=>278,'^'=>469,'_'=>556,
            '`'=>333,'a'=>556,'b'=>556,'c'=>500,'d'=>556,'e'=>556,'f'=>278,'g'=>556,
            'h'=>556,'i'=>222,'j'=>222,'k'=>500,'l'=>222,'m'=>833,'n'=>556,'o'=>556,
            'p'=>556,'q'=>556,'r'=>333,'s'=>500,'t'=>278,'u'=>556,'v'=>500,'w'=>722,
            'x'=>500,'y'=>500,'z'=>500,'{'=>334,'|'=>260,'}'=>334,'~'=>584,
        ];
    }
    if (isset($w[$ch])) return (float)$w[$ch];
    // Accented / high-byte latin1: approximate
    $ord = ord($ch);
    if ($ord >= 128) return 556.0;
    return 600.0;
}

/** Measured string width in PDF points for Helvetica at $size. */
function pdf_text_width(string $s, float $size): float {
    if ($s === '') return 0.0;
    $units = 0.0;
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $units += pdf_helvetica_unit_width($s[$i]);
    }
    return $units * $size / 1000.0;
}

/**
 * Word-wrap text to fit $maxWidth (points). Breaks long tokens letter-by-letter.
 * @return list<string>
 */
function pdf_wrap_text(string $s, float $maxWidth, float $size): array {
    $s = pdf_plain_text($s);
    if ($s === '') return [];
    if ($maxWidth <= 0) return [$s];

    $words = preg_split('/\s+/', $s) ?: [];
    $lines = [];
    $current = '';

    $flush = static function () use (&$lines, &$current) {
        if ($current !== '') {
            $lines[] = $current;
            $current = '';
        }
    };

    $breakToken = static function (string $token) use ($maxWidth, $size): array {
        $parts = [];
        $buf = '';
        $len = strlen($token);
        for ($i = 0; $i < $len; $i++) {
            $try = $buf . $token[$i];
            if ($buf !== '' && pdf_text_width($try, $size) > $maxWidth) {
                $parts[] = $buf;
                $buf = $token[$i];
            } else {
                $buf = $try;
            }
        }
        if ($buf !== '') $parts[] = $buf;
        return $parts ?: [$token];
    };

    foreach ($words as $word) {
        if ($word === '') continue;
        $chunks = (pdf_text_width($word, $size) > $maxWidth) ? $breakToken($word) : [$word];
        foreach ($chunks as $chunk) {
            $try = ($current === '') ? $chunk : ($current . ' ' . $chunk);
            if ($current !== '' && pdf_text_width($try, $size) > $maxWidth) {
                $flush();
                $current = $chunk;
            } else {
                $current = $try;
            }
        }
    }
    $flush();
    return $lines;
}

/** Truncate with ellipsis so the string fits $maxWidth. */
function pdf_fit_text(string $s, float $maxWidth, float $size, string $ellipsis = '...'): string {
    $s = pdf_plain_text($s);
    if ($s === '' || pdf_text_width($s, $size) <= $maxWidth) return $s;
    $ellW = pdf_text_width($ellipsis, $size);
    if ($ellW >= $maxWidth) return '';
    $budget = $maxWidth - $ellW;
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $try = $out . $s[$i];
        if (pdf_text_width($try, $size) > $budget) break;
        $out = $try;
    }
    return rtrim($out) . $ellipsis;
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
        $safe = pdf_escape(pdf_plain_text($txt));
        $text .= sprintf("BT /F1 %d Tf %.2f %.2f Td (%s) Tj ET\n", $size, $x, $ytop, $safe);
    };
    // Pull data
    $vendor = $order['vendor'] ?? [];
    $vName = strtoupper(pdf_plain_text($vendor['name'] ?? 'PSK CRACKERS'));
    $vAddr = pdf_plain_text($vendor['address'] ?? '');
    $vPhone = pdf_plain_text($vendor['phone'] ?? '');
    $vGst = pdf_plain_text($vendor['gst'] ?? '');

    $cust = $order['customer'] ?? [];
    $cName = pdf_plain_text($cust['name'] ?? '');
    $cCity = pdf_plain_text($cust['city'] ?? '');
    $cState = pdf_plain_text($cust['state'] ?? '');
    $cPin = pdf_plain_text($cust['pincode'] ?? '');
    $cPhone = pdf_plain_text($cust['phone'] ?? '');
    $cAddr1 = pdf_plain_text($cust['address_line1'] ?? '');
    $cAddr2 = pdf_plain_text($cust['address_line2'] ?? '');

    // Estimation No — prefer yearly invoice code (INV-2026-000001)
    $datePart = date('Ymd-His');
    $ordPart = isset($order['order_id']) ? str_pad((string)$order['order_id'], 6, '0', STR_PAD_LEFT) : date('His');
    $estNo = pdf_plain_text($order['est_no']
        ?? $order['invoice_code']
        ?? ('EST-' . $datePart . '-' . $ordPart));
    $stateSupply = pdf_plain_text($order['state_of_supply'] ?? '');
    $eway = pdf_plain_text($order['eway_bill'] ?? '');
    $vehicle = pdf_plain_text($order['vehicle_no'] ?? '');

    $items = $order['items'] ?? [];
    $items = array_values(array_filter($items, static function ($it) {
        return (int)($it['qty'] ?? 0) > 0;
    }));
    $subtotal = (float)($order['subtotal'] ?? 0);
    $discount = (float)($order['discount'] ?? 0);
    $discount_pct = isset($order['discount_pct']) ? (float)$order['discount_pct'] : null;
    $total = (float)($order['total'] ?? $subtotal);
    // NET AMOUNT mirrors stored total (shipping removed)
    $netAmount = (float)$total;

    // Reusable layout metrics
    $titleH = 26; $innerW = $W - 2*$M; $x0 = $M; $yTop = $H - $M;
    $halfW = $innerW/2;
    $padX = 6;
    $textMaxW = $halfW - 2*$padX; // left/right column content width
    $lineH10 = 13;
    $lineH9 = 12;
    $boxPadTop = 16;
    $boxPadBottom = 10;
    $minBoxH = 110;
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
    $particularMaxW = ($colX[2] - $colX[1]) - 12;

    // Pre-wrap party-box lines so box heights grow instead of overflowing the divider
    $pushLabeled = static function (array &$out, string $label, string $value, int $size) use ($textMaxW): void {
        $value = pdf_plain_text($value);
        if ($value === '' && $label === '') return;
        if ($value === '') return;
        foreach (pdf_wrap_text($label . $value, $textMaxW, (float)$size) as $ln) {
            $out[] = ['t' => $ln, 's' => $size];
        }
    };

    $billedByLines = [];
    $pushLabeled($billedByLines, 'Billed By : ', $vName, 10);
    if ($vAddr !== '') {
        foreach (pdf_wrap_text($vAddr, $textMaxW, 9) as $ln) {
            $billedByLines[] = ['t' => $ln, 's' => 9];
        }
    }
    $pushLabeled($billedByLines, 'GST : ', $vGst, 9);
    $pushLabeled($billedByLines, 'Ph : ', $vPhone, 9);

    $estLines = [];
    $pushLabeled($estLines, 'Estimation No: ', $estNo, 10);
    $pushLabeled($estLines, 'Date: ', date('Y-m-d'), 10);
    $pushLabeled($estLines, 'State Of Supply: ', $stateSupply, 9);
    $pushLabeled($estLines, 'E-way Bill No: ', $eway, 9);
    $pushLabeled($estLines, 'Vehicle No: ', $vehicle, 9);

    $billToLines = [];
    $pushLabeled($billToLines, 'Bill To : ', $cName !== '' ? $cName : '-', 10);
    $pushLabeled($billToLines, 'Address Line 1 : ', $cAddr1, 9);
    $pushLabeled($billToLines, 'Address Line 2 : ', $cAddr2, 9);
    $cityStateParts = array_filter([$cCity, $cState], static function ($p) { return $p !== ''; });
    if ($cityStateParts) {
        $line = implode(', ', $cityStateParts);
        if ($cPin !== '') { $line .= ' - ' . $cPin; }
        $pushLabeled($billToLines, 'City / State : ', $line, 9);
    } else {
        $pushLabeled($billToLines, 'PIN : ', $cPin, 9);
    }
    $pushLabeled($billToLines, 'Ph : ', $cPhone, 9);

    $linesHeight = static function (array $lines) use ($lineH9, $lineH10, $boxPadTop, $boxPadBottom, $minBoxH): float {
        if (!$lines) return (float)$minBoxH;
        $h = $boxPadTop + $boxPadBottom;
        foreach ($lines as $ln) {
            $h += (($ln['s'] ?? 9) >= 10) ? $lineH10 : $lineH9;
        }
        // small extra so last baseline clears bottom border
        $h += 4;
        return max((float)$minBoxH, $h);
    };

    $boxHUpper = max($linesHeight($billedByLines), $linesHeight($estLines));
    $boxHLower = max($linesHeight($billToLines), (float)$minBoxH);

    $headerBlockH = $titleH + $boxHUpper + $boxHLower + $tableHeaderGap + $rowH;
    $footerReserveMid = 40;
    $availMid = $H - 2*$M - $headerBlockH - $footerReserveMid;
    $itemsPerPage = max(1, (int)floor($availMid / $rowH));
    if ($itemsPerPage > 22) $itemsPerPage = 22;

    $chunks = array_chunk($items, $itemsPerPage);
    if (empty($chunks)) {
        $chunks = [[]];
    }
    $totalItemCount = count($items);
    $totalPages = count($chunks);
    $serial = max(1, (int)($order['sno_start'] ?? 1));
    $pages = [];

    $renderPage = function(array $pageItems, bool $isLast, int $currentSerial, int $pageIndex) use (
        $H,$M,$titleH,$innerW,$x0,$yTop,$boxHUpper,$boxHLower,$halfW,$tableHeaderGap,$rowH,$colX,
        $drawRect,$drawLine,$addText,$padX,$lineH9,$lineH10,$boxPadTop,$particularMaxW,
        $billedByLines,$estLines,$billToLines,
        $subtotal,$netAmount,$order,$totalItemCount,$totalPages,$vendor
    ){
        $ops = ''; $text = '';

        $drawLinesBlock = static function (&$text, $x, $yStart, array $lines) use ($addText, $lineH9, $lineH10) {
            $y = $yStart;
            foreach ($lines as $ln) {
                $size = (int)($ln['s'] ?? 9);
                $addText($text, $x, $y, (string)($ln['t'] ?? ''), $size);
                $y -= ($size >= 10) ? $lineH10 : $lineH9;
            }
            return $y;
        };

        // Title
        $drawRect($ops, $x0, $yTop, $innerW, $titleH);
        $addText($text, $x0 + $innerW/2 - 50, $yTop - 18, 'Invoice Order', 14);
        $addText($text, $x0 + $innerW - 60, $yTop - 12, '[Original]', 8);

        // Party boxes (heights match wrapped content; left text cannot cross halfW)
        $yTop2 = $yTop - $titleH;
        $drawRect($ops, $x0, $yTop2, $halfW, $boxHUpper); // Billed By
        $drawRect($ops, $x0 + $halfW, $yTop2, $halfW, $boxHUpper); // Estimation Details
        $drawRect($ops, $x0, $yTop2 - $boxHUpper, $halfW, $boxHLower); // Bill To
        $drawRect($ops, $x0 + $halfW, $yTop2 - $boxHUpper, $halfW, $boxHLower); // blank

        $drawLinesBlock($text, $x0 + $padX, $yTop2 - $boxPadTop, $billedByLines);
        $drawLinesBlock($text, $x0 + $halfW + $padX, $yTop2 - $boxPadTop, $estLines);
        $drawLinesBlock($text, $x0 + $padX, $yTop2 - $boxHUpper - $boxPadTop, $billToLines);

        // Items table header
        $tableTop = $yTop2 - $boxHUpper - $boxHLower - $tableHeaderGap;
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
            $name = strtoupper(pdf_plain_text((string)($it['name'] ?? ($it['id'] ?? 'Item'))));
            $name = pdf_fit_text($name, $particularMaxW, 9);
            $price = (float)($it['price'] ?? ($it['unit_price'] ?? 0));
            $amount = $price * $qty;

            $drawRect($ops, $x0, $yCursor, $innerW, $rowH);
            for ($i=1;$i<count($colX)-1;$i++) { $drawLine($ops, $colX[$i], $yCursor - $rowH, $colX[$i], $yCursor); }

            $addText($text, $colX[0] + 6, $yCursor - 12, (string)$currentSerial, 9);
            $addText($text, $colX[1] + 6, $yCursor - 12, $name, 9);
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
            $wordsPrefix = 'Amount in words : ';
            $wordsLines = pdf_wrap_text($wordsPrefix . $words, $innerW - 12, 9);
            if (!$wordsLines) $wordsLines = [$wordsPrefix . $words];
            $wordsBoxH = max(20.0, 8 + count($wordsLines) * $lineH9);
            $drawRect($ops, $x0, $wordsTop, $innerW, $wordsBoxH);
            $wy = $wordsTop - 14;
            foreach ($wordsLines as $wln) {
                $addText($text, $x0 + 6, $wy, $wln, 9);
                $wy -= $lineH9;
            }

            $netStripTop = $wordsTop - $wordsBoxH - 4;
            $drawRect($ops, $x0, $netStripTop, $innerW, 20);
            $netLabel = 'NET AMOUNT : ' . number_format($netAmount,2);
            $netX = $x0 + $innerW - 6 - pdf_text_width($netLabel, 10);
            $addText($text, max($x0 + 6, $netX), $netStripTop - 14, $netLabel, 10);

            // Footer sign line
            $addText($text, $x0 + 6, $M + 26, 'Customer Sign', 9);
            $forLabel = pdf_fit_text('For ' . pdf_plain_text($vendor['name'] ?? 'PSK CRACKERS'), 160, 9);
            $addText($text, $x0 + $innerW - 120, $M + 26, $forLabel, 9);
            $footerNote = !empty($order['notes'])
                ? (string)$order['notes']
                : 'We Declare that this Estimation shows the actual price of the goods described and that all particulars are true and correct';
            $footerLines = pdf_wrap_text($footerNote, $innerW - 12, 8);
            $fy = $M + 12;
            foreach (array_slice($footerLines, 0, 2) as $fln) {
                $addText($text, $x0 + 6, $fy, $fln, 8);
                $fy -= 10;
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
