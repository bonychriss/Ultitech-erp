<?php
/**
 * Live Request Byte Inspector
 * Access via: https://ultitech.io/modules/sales/invoices/probe_bytes.php?company_slug=ultimate
 */

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Cookies
$cookieStr = '';
foreach ($_COOKIE as $name => $value) {
    $cookieStr .= "$name=$value; ";
}
$cookieStr = rtrim($cookieStr, '; ');

// Target
$targetUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'ultitech.io') . '/ultimate/modules/sales/invoices/create.php?module=sales';

$options = [
    'http' => [
        'method' => 'GET',
        'header' => "Cookie: $cookieStr\r\nUser-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AntigravityProbe/1.0\r\n",
        'follow_location' => 0,
        'ignore_errors' => true
    ]
];

$context = stream_context_create($options);
$response = file_get_contents($targetUrl, false, $context);

echo "Response length: " . strlen((string) $response) . " bytes\n";
echo "Response Hex Dump:\n";
for ($i = 0; $i < strlen((string) $response); $i++) {
    $char = $response[$i];
    $ord = ord($char);
    echo "  Byte $i: 0x" . dechex($ord) . " (Char: " . var_export($char, true) . ")\n";
}
