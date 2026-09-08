<?php

namespace App\Domains\Sales;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Phase 1 Sales domain: bridge JSON desks to legacy modules/sales API PHP files.
 */
final class LegacyApiBridge
{
    /** @var array<string,string> */
    private const MAP = [
        'dashboard' => 'modules/sales/dashboard/api/init.php',
        'my-sales' => 'modules/sales/my-sales/api/init.php',
        'invoices' => 'modules/sales/invoices/api/list-init.php',
        'orders' => 'modules/sales/orders/api/sales-orders-init.php',
        'customers' => 'modules/sales/customers/api/index-init.php',
        'catalogue' => 'modules/sales/catalogue-ui/api/init.php',
        'pricelist' => 'modules/sales/pricelist/api/init.php',
        'settings' => 'modules/sales/settings/api/init.php',
        'quotations' => 'modules/sales/orders/api/init.php',
        'create-init' => 'modules/sales/invoices/api/create-init.php',
        'create-quote' => 'modules/sales/invoices/api/create-quote.php',
        'create-invoice' => 'modules/sales/invoices/api/create-invoice.php',
        'order-view-init' => 'modules/sales/orders/api/view-init.php',
        'order-view-status' => 'modules/sales/orders/api/view-status.php',
        'invoice-view-init' => 'modules/sales/invoices/api/view-init.php',
        'quote-edit-init' => 'modules/sales/invoices/api/quote-edit-init.php',
        'quote-edit-save' => 'modules/sales/invoices/api/quote-edit-save.php',
        'convert-invoice' => 'modules/sales/orders/api/convert-invoice.php',
        'exchange-rate' => 'modules/sales/payments/exchange_rate.php',
    ];

    public static function deskRegex(): string
    {
        return implode('|', array_keys(self::MAP));
    }

    public function handle(Request $request, string $desk): Response
    {
        $desk = strtolower(trim($desk));
        if (!isset(self::MAP[$desk])) {
            return response()->json(['ok' => false, 'error' => 'Unknown desk.'], 404);
        }

        $appRoot = (string) config('erp.app_root', dirname(__DIR__, 4));
        $path = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::MAP[$desk]);
        if (!is_file($path)) {
            return response()->json(['ok' => false, 'error' => 'Desk API missing.'], 500);
        }

        foreach ($request->query() as $key => $value) {
            $_GET[$key] = $value;
            $_REQUEST[$key] = $value;
        }
        foreach ($request->all() as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $_POST[$key] = $value;
                $_REQUEST[$key] = $value;
            }
        }

        try {
            ob_start();
            if ($request->getContent() !== '') {
                $GLOBALS['ERP_SALES_RAW_BODY'] = $request->getContent();
            }
            require $path;
            $body = (string) ob_get_clean();
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            report($e);

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Desk API failed.',
            ], 500);
        }

        $status = http_response_code();
        if (!is_int($status) || $status < 100) {
            $status = 200;
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $decoded['ok'] = $decoded['ok'] ?? true;
            $decoded['engine'] = 'erp-laravel Domains/Sales';
            return response()->json($decoded, $status);
        }

        return response($body, $status)->header('Content-Type', 'application/json; charset=utf-8');
    }
}
