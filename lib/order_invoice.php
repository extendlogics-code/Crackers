<?php
/**
 * Customer invoice download tokens (HMAC, no admin session required).
 */

function order_invoice_secret(): string {
    static $secret;
    if ($secret !== null) {
        return $secret;
    }
    $cfg = require __DIR__ . '/../config.php';
    $pepper = (string)($cfg['invoice']['hmac_key'] ?? '');
    if ($pepper === '') {
        $pepper = (string)($cfg['db']['name'] ?? 'psk') . '|' . (string)($cfg['db']['user'] ?? 'psk') . '|invoice-v1';
    }
    $secret = hash('sha256', $pepper, true);
    return $secret;
}

function order_invoice_download_token(int $orderId, string $invoiceCode): string {
    if ($orderId <= 0) {
        return '';
    }
    $payload = $orderId . '|' . trim($invoiceCode);
    return substr(hash_hmac('sha256', $payload, order_invoice_secret()), 0, 48);
}

function order_invoice_verify_token(int $orderId, string $token, string $invoiceCode): bool {
    $token = trim($token);
    if ($orderId <= 0 || $token === '') {
        return false;
    }
    $expected = order_invoice_download_token($orderId, $invoiceCode);
    return hash_equals($expected, $token);
}

/** Standard UPI deep link for checkout QR (amount in INR, 2 decimals). */
function order_upi_pay_uri(float $amountInr, string $upiId = 'pskcrackers@axl', string $payeeName = 'PSK Crackers'): string {
    $am = number_format(max(0, $amountInr), 2, '.', '');
    $params = [
        'pa' => $upiId,
        'pn' => $payeeName,
        'am' => $am,
        'cu' => 'INR',
    ];
    return 'upi://pay?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}
