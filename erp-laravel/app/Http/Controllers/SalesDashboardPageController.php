<?php

namespace App\Http\Controllers;

use App\Domains\Sales\DashboardShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Sales dashboard HTML shell via Blade (Phase 2).
 */
class SalesDashboardPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];
        $slug = (string) ($erp['company_slug'] ?? '');

        $initUrl = function_exists('company_url') && $slug !== ''
            ? company_url('sales', $slug)
            : (function_exists('app_url') ? app_url('/sales.php') : '/sales.php');
        $initUrl .= (str_contains($initUrl, '?') ? '&' : '?') . 'api=init';

        $apiBase = function_exists('app_url')
            ? rtrim((string) app_url('/modules/sales/dashboard/api'), '/')
            : '/public_html/modules/sales/dashboard/api';

        $viewData = (new DashboardShell())->viewData([
            'initUrl' => $initUrl,
            'apiBase' => $apiBase,
            'module' => 'sales',
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Sales UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>modules/sales/dashboard/frontend</code>.</p>'
                . '</body></html>',
                503
            );
        }

        // Globals expected by header_employee.php
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
}
