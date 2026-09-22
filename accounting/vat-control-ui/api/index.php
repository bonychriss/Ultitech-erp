<?php
/**
 * VAT Control JSON API (React UI).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/vat-lib.php';

function vat_api_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($payload, $flags);
    if ($json === false) {
        $json = json_encode(['ok' => false, 'error' => 'Failed to encode API response.'], JSON_UNESCAPED_UNICODE);
    }
    echo $json;
    exit;
}

function vat_api_error(string $message, int $code = 400): void
{
    vat_api_json(['ok' => false, 'error' => $message], $code);
}

try {
    vatRequireAccess();
    $pdo = vatBootstrap();
    vatEnsurePeriodsTable($pdo);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $code = (int) (http_response_code() ?: 500);
    if ($code < 400) {
        $code = 500;
    }
    if (stripos($msg, 'Not logged in') !== false) {
        $code = 401;
    }
    vat_api_error($msg, $code);
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'init');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    switch ($action) {
        case 'init':
            vat_api_json([
                'ok' => true,
                ...vatBuildDashboard($pdo),
            ]);
            break;

        case 'category_years':
            $category = (string) ($_GET['category'] ?? '');
            vat_api_json([
                'ok' => true,
                ...vatBuildCategoryYears($pdo, $category),
            ]);
            break;

        case 'year_months':
            $category = (string) ($_GET['category'] ?? '');
            $year = (int) ($_GET['year'] ?? 0);
            vat_api_json([
                'ok' => true,
                ...vatBuildYearMonths($pdo, $category, $year),
            ]);
            break;

        case 'month_detail':
            $ym = (string) ($_GET['ym'] ?? '');
            vat_api_json([
                'ok' => true,
                ...vatBuildMonthDetail($pdo, $ym),
            ]);
            break;

        case 'transactions':
            $ym = (string) ($_GET['ym'] ?? '');
            $source = (string) ($_GET['source'] ?? '');
            vat_api_json([
                'ok' => true,
                ...vatBuildTransactions($pdo, $ym, $source),
            ]);
            break;

        case 'reconcile_period':
            if ($method !== 'POST') {
                vat_api_error('POST required.', 405);
            }
            $ym = (string) ($_POST['ym'] ?? $_GET['ym'] ?? '');
            // Also accept JSON body
            if ($ym === '') {
                $raw = file_get_contents('php://input');
                if (is_string($raw) && $raw !== '') {
                    $body = json_decode($raw, true);
                    if (is_array($body)) {
                        $ym = (string) ($body['ym'] ?? '');
                    }
                }
            }
            vat_api_json([
                'ok' => true,
                ...vatReconcilePeriod($pdo, $ym),
            ]);
            break;

        case 'close_period':
            if ($method !== 'POST') {
                vat_api_error('POST required.', 405);
            }
            $ym = (string) ($_POST['ym'] ?? $_GET['ym'] ?? '');
            if ($ym === '') {
                $raw = file_get_contents('php://input');
                if (is_string($raw) && $raw !== '') {
                    $body = json_decode($raw, true);
                    if (is_array($body)) {
                        $ym = (string) ($body['ym'] ?? '');
                    }
                }
            }
            vat_api_json([
                'ok' => true,
                ...vatClosePeriod($pdo, $ym),
            ]);
            break;

        default:
            vat_api_error('Unknown action.', 404);
    }
} catch (InvalidArgumentException $e) {
    vat_api_error($e->getMessage(), 400);
} catch (Throwable $e) {
    vat_api_error($e->getMessage(), 500);
}
