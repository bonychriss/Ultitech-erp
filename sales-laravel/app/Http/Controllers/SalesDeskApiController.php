<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Bridge sales JSON APIs by including existing ERP api PHP files.
 */
class SalesDeskApiController extends Controller
{
    /** @var array<string,string> */
    private array $map = [
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

    public function desk(Request $request, string $desk): Response
    {
        $desk = strtolower(trim($desk));
        if (!isset($this->map[$desk])) {
            return response()->json(['ok' => false, 'error' => 'Unknown desk.'], 404);
        }

        $path = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->map[$desk]);
        if (!is_file($path)) {
            return response()->json(['ok' => false, 'error' => 'Desk API missing.'], 500);
        }

        // Legacy ERP api scripts read $_GET / $_POST / php://input.
        foreach ($request->query() as $key => $value) {
            $_GET[$key] = $value;
            $_REQUEST[$key] = $value;
        }
        $payload = $request->all();
        if ($payload !== []) {
            foreach ($payload as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $_POST[$key] = $value;
                    $_REQUEST[$key] = $value;
                }
            }
        }

        try {
            ob_start();
            // Make JSON body available again for scripts that re-read php://input.
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
            $decoded['engine'] = 'Laravel + React';
            return response()->json($decoded, $status);
        }

        return response($body, $status)->header('Content-Type', 'application/json; charset=utf-8');
    }
}
