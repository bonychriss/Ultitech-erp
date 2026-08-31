<?php
/**
 * Stock Purchase Payment Desk JSON API (React UI).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/sppd-lib.php';

function sppd_api_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        $json = json_encode(['success' => false, 'error' => 'Failed to encode API response.'], JSON_UNESCAPED_UNICODE);
    }
    echo $json;
    exit;
}

function sppd_api_error(string $message, int $code = 400): void
{
    sppd_api_json(['success' => false, 'error' => $message], $code);
}

try {
    sppdRequireAccess();
    $pdo = sppdBootstrap();
} catch (Throwable $e) {
    sppd_api_error($e->getMessage(), 500);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string) ($_GET['action'] ?? '');

if ($method === 'POST' && $action === '') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === '') {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (is_array($body) && !empty($body['action'])) {
            $action = (string) $body['action'];
        }
    }
}

$tab = sppdNormalizeTab((string) ($_GET['tab'] ?? $_POST['tab'] ?? 'needs_classification'));
$module = trim((string) ($_GET['module'] ?? $_POST['module'] ?? ''));

try {
switch ($action) {
    case 'init':
        $count = sppdCountUnpaidPurchaseOrders($pdo);
        $accountsPayable = sppdFetchAccountsPayableBalance($pdo);
        $overduePayables = sppdFetchOverduePayables($pdo);
        sppd_api_json([
            'success' => true,
            'tab' => $tab,
            'tabLabel' => sppdValidTabs()[$tab] ?? 'purchases to be paid',
            'module' => $module,
            'summary' => [
                'unpaidCount' => $count,
                'currency' => 'TZS',
                'accountsPayable' => (float) ($accountsPayable['balance'] ?? 0),
                'accountsPayableCurrency' => (string) ($accountsPayable['currency'] ?? 'TZS'),
                'accountsPayableSource' => (string) ($accountsPayable['source'] ?? 'ledger'),
                'overduePayables' => (float) ($overduePayables['amount'] ?? 0),
                'overduePayablesCount' => (int) ($overduePayables['count'] ?? 0),
                'overduePayablesCurrency' => (string) ($overduePayables['currency'] ?? 'TZS'),
            ],
            'summaryTraces' => sppdBuildSummaryTraces($pdo),
            'payeeOptions' => sppdFetchPayeeOptions($pdo),
            'accounts' => sppdFetchFinancialAccounts(),
            'paymentMethods' => [
                'Bank Transfer',
                'RTGS / SWIFT',
                'Cash',
                'Mobile Money',
                'Cheque',
            ],
        ]);
        break;

    case 'list':
        if (!sppdIsUnpaidPurchaseOrderTab($tab)) {
            sppd_api_error('Unsupported tab for purchase order listing.', 400);
        }
        $filters = sppdParseFilters($_GET);
        $rows = sppdFetchPurchaseOrders($pdo, $filters);
        $orders = array_values(array_map('sppdMapPurchaseOrder', $rows));
        sppd_api_json([
            'success' => true,
            'tab' => $tab,
            'filters' => $filters,
            'orders' => $orders,
            'count' => count($orders),
        ]);
        break;

    case 'details':
        $poId = (int) ($_GET['po_id'] ?? 0);
        if ($poId <= 0) {
            sppd_api_error('Purchase order id is required.', 400);
        }
        $details = sppdFetchPurchaseOrderDetails($pdo, $poId);
        if ($details === null) {
            sppd_api_error('Purchase order not found.', 404);
        }
        sppd_api_json([
            'success' => true,
            'details' => $details,
        ]);
        break;

    case 'pay':
        if ($method !== 'POST') {
            sppd_api_error('Method not allowed', 405);
        }
        $uploaded = isset($_FILES['swift_file']) && is_array($_FILES['swift_file']) ? $_FILES['swift_file'] : null;
        $result = sppdPayPurchaseOrder($pdo, $_POST, $uploaded);
        if (empty($result['success'])) {
            sppd_api_error((string) ($result['error'] ?? 'Payment failed.'), 400);
        }
        sppd_api_json([
            'success' => true,
            'paymentNumber' => (string) ($result['payment_number'] ?? ''),
            'message' => (string) ($result['message'] ?? 'Payment recorded.'),
        ]);
        break;

    default:
        sppd_api_error('Unknown action', 404);
}
} catch (Throwable $e) {
    sppd_api_error($e->getMessage(), 500);
}
