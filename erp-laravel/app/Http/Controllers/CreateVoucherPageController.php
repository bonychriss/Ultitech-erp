<?php

namespace App\Http\Controllers;

use App\Domains\Voucher\VoucherShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Create Payment Voucher HTML shell via Blade + React (employee/create-voucher-ui).
 */
class CreateVoucherPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];
        if (!is_array($erp)) {
            $erp = [];
        }

        $clientCfg = $this->clientCfg($erp);
        $viewData = (new VoucherShell())->viewData([
            'pageTitle' => 'Create Voucher',
            'headerTitle' => '',
            'clientCfg' => $clientCfg,
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Create Voucher</h1>'
                . '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>employee/create-voucher-ui/frontend/</code>.</p>'
                . '</body></html>',
                503
            );
        }

        $GLOBALS['page_title'] = $viewData['pageTitle'];
        $page_title = $viewData['pageTitle'];
        $employeeHeaderTitle = $viewData['employeeHeaderTitle'];
        $hideHeaderCompanyBranding = $viewData['hideHeaderCompanyBranding'];
        $employeeHeaderExtraClass = $viewData['employeeHeaderExtraClass'];

        return view('erp.react-shell', $viewData + [
            'page_title' => $page_title,
            'employeeHeaderTitle' => $employeeHeaderTitle,
            'hideHeaderCompanyBranding' => $hideHeaderCompanyBranding,
            'employeeHeaderExtraClass' => $employeeHeaderExtraClass,
        ]);
    }

    /**
     * @param array<string,mixed> $erp
     * @return array<string,mixed>
     */
    private function clientCfg(array $erp): array
    {
        $root = rtrim((string) config('erp.app_root'), '\\/');
        $cfgFile = $root . '/employee/create-voucher-ui/client-cfg.php';
        if (is_file($cfgFile)) {
            require_once $cfgFile;
        }

        $payload = is_array($erp['client_cfg_data'] ?? null) ? $erp['client_cfg_data'] : [];
        if (function_exists('createVoucherBuildClientCfg')) {
            return createVoucherBuildClientCfg($payload);
        }

        return [
            'postUrl' => (string) ($erp['voucher_url'] ?? 'create-voucher.php'),
            'cancelUrl' => 'my-vouchers.php',
            'module' => (string) ($erp['module'] ?? 'voucher'),
            'preparedBy' => (string) ($erp['full_name'] ?? ''),
            'today' => date('Y-m-d'),
            'canRestrict' => !empty($erp['is_admin']) || !empty($erp['is_finance']),
            'currencies' => ['TZS', 'USD', 'CNY'],
            'purposes' => [
                ['value' => 'general', 'label' => 'General Payment'],
                ['value' => 'stock_purchase', 'label' => 'Stock Purchase'],
            ],
            'paymentTypes' => ['Bank Transfer', 'Cash Payment', 'Cheque', 'Mobile Payment'],
            'budgetTypes' => [],
            'payees' => [],
            'users' => [],
            'financeUsers' => [],
            'salesOrders' => [],
            'flash' => $payload['flash'] ?? null,
            'error' => (string) ($payload['error'] ?? ''),
        ];
    }
}
