<?php

namespace App\Http\Controllers;

use App\Domains\Voucher\ViewVoucherShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * View Payment Voucher HTML shell via Blade + React (view-voucher-ui).
 */
class ViewVoucherPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];
        if (!is_array($erp)) {
            $erp = [];
        }

        $clientCfg = is_array($erp['client_cfg'] ?? null) ? $erp['client_cfg'] : [];
        $hideSidebar = !empty($erp['hide_sidebar']);
        $fromPurchases = !empty($erp['from_purchases']);
        $activeModule = (string) ($erp['active_module'] ?? ($fromPurchases ? 'accounting' : 'voucher'));
        $viewData = (new ViewVoucherShell())->viewData([
            'pageTitle' => (string) ($erp['page_title'] ?? 'Payment Voucher'),
            'headerTitle' => (string) ($erp['header_title'] ?? 'Voucher Preview'),
            'headerSubtitle' => (string) ($erp['header_subtitle'] ?? ''),
            'headerRightHtml' => (string) ($erp['header_right_html'] ?? ''),
            'clientCfg' => $clientCfg,
            'hideSidebar' => $hideSidebar,
            'fromPurchases' => $fromPurchases,
            'activeModule' => $activeModule,
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Payment Voucher</h1>'
                . '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>view-voucher-ui/frontend/</code>.</p>'
                . '</body></html>',
                503
            );
        }

        $GLOBALS['page_title'] = $viewData['pageTitle'];
        $page_title = $viewData['pageTitle'];
        $employeeHeaderTitle = $viewData['employeeHeaderTitle'];
        $employeeHeaderSubtitle = $viewData['employeeHeaderSubtitle'] ?? '';
        $employeeHeaderRightHtml = $viewData['employeeHeaderRightHtml'] ?? '';
        $hideHeaderCompanyBranding = $viewData['hideHeaderCompanyBranding'];
        $employeeHeaderExtraClass = $viewData['employeeHeaderExtraClass'];
        $hideSidebar = !empty($viewData['hideSidebar']);
        $active_module = (string) ($viewData['active_module'] ?? $activeModule);

        return view('erp.react-shell', $viewData + [
            'page_title' => $page_title,
            'employeeHeaderTitle' => $employeeHeaderTitle,
            'employeeHeaderSubtitle' => $employeeHeaderSubtitle,
            'employeeHeaderRightHtml' => $employeeHeaderRightHtml,
            'hideHeaderCompanyBranding' => $hideHeaderCompanyBranding,
            'employeeHeaderExtraClass' => $employeeHeaderExtraClass,
            'hideSidebar' => $hideSidebar,
            'active_module' => $active_module,
        ]);
    }
}
