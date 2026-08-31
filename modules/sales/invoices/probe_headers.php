<?php
/**
 * Live Request Header Inspector
 * Access via: https://ultitech.io/modules/sales/invoices/probe_headers.php?company_slug=ultimate
 */

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "=== Loopback HTTP Request Diagnostic ===\n";

// Get active cookies
$cookieStr = '';
foreach ($_COOKIE as $name => $value) {
    $cookieStr .= "$name=$value; ";
}
$cookieStr = rtrim($cookieStr, '; ');
echo "Active Cookies: " . ($cookieStr !== '' ? $cookieStr : '(None)') . "\n";

// Target URL
$targetUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'ultitech.io') . '/ultimate/modules/sales/invoices/create.php?module=sales';
echo "Targeting URL: $targetUrl\n";

// Set up stream options
$options = [
    'http' => [
        'method' => 'GET',
        'header' => "Cookie: $cookieStr\r\nUser-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AntigravityProbe/1.0\r\n",
        'follow_location' => 0, // Do NOT follow redirects automatically so we can see 302s
        'ignore_errors' => true // Capture 500/404 responses
    ]
];

$context = stream_context_create($options);

$responseBody = file_get_contents($targetUrl, false, $context);
$responseHeaders = $http_response_header ?? [];

echo "\n--- Response HTTP Headers ---\n";
foreach ($responseHeaders as $h) {
    echo "  $h\n";
}

echo "\n--- Response Body Length ---\n";
echo "Length: " . strlen((string) $responseBody) . " bytes\n";

echo "\n--- Response Body Preview (first 500 chars) ---\n";
echo substr((string) $responseBody, 0, 500) . "\n";
echo "=== End of Diagnostic ===\n";
