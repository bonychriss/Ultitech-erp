<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Cache-Control: no-store');

if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    http_response_code(403);
    echo 'You do not have access to website quote requests.';
    exit;
}

$quoteAccessLib = __DIR__ . '/../modules/sales/quote-requests/includes/quote-requests-lib.php';
if (is_file($quoteAccessLib)) {
    require_once $quoteAccessLib;
}
$canWebsite = (function_exists('isUltimate') && isUltimate())
    || (function_exists('isRoadmaster') && isRoadmaster());
if (!$canWebsite || (function_exists('salesQuoteRequestsUserCanAccess') && !salesQuoteRequestsUserCanAccess())) {
    http_response_code(403);
    echo 'You do not have access to website quote requests.';
    exit;
}

require_once __DIR__ . '/../modules/sales/functions.php';
require_once __DIR__ . '/../modules/sales/customers/includes/catalogue-lib.php';
require_once __DIR__ . '/../stock/includes/web-services-lib.php';

$quoteNumber = substr(trim((string) ($_GET['quote'] ?? '')), 0, 64);
$fallback = function_exists('company_url') ? company_url('sales/quote-create') : '/ultimate/sales/quote-create';

try {
    $salesDb = function_exists('sales_pdo') ? sales_pdo() : ($GLOBALS['pdo'] ?? null);
    if ($quoteNumber === '' || !($salesDb instanceof PDO)) {
        header('Location: ' . $fallback, true, 302);
        exit;
    }
    $companyId = function_exists('currentCompanyId') ? (int) (currentCompanyId() ?? 0) : 0;
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $target = webQuotePrepareQuotation($quoteNumber, $salesDb, $companyId, $userId);
} catch (Throwable $e) {
    error_log('website_quote_prefill: ' . $e->getMessage());
    $target = $fallback;
}

header('Location: ' . $target, true, 302);
exit;
