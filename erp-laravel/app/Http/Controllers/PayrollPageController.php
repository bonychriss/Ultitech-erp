<?php

namespace App\Http\Controllers;

use App\Domains\Payroll\PayrollShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Payroll dashboard HTML shell via Blade.
 */
class PayrollPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];

        $apiBase = function_exists('company_url')
            ? rtrim((string) company_url('modules/payroll/api', (string) ($erp['company_slug'] ?? '')), '/')
            : (function_exists('app_url')
                ? rtrim((string) app_url('/modules/payroll/api'), '/')
                : '/public_html/modules/payroll/api');

        $viewData = (new PayrollShell())->viewData([
            'apiBase' => $apiBase,
            'payrollPage' => 'dashboard',
            'pageTitle' => 'Payroll',
            'headerTitle' => 'Payroll',
            'companySlug' => (string) ($erp['company_slug'] ?? ''),
            'backUrl' => (string) ($erp['back_url'] ?? ''),
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Payroll UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>modules/payroll/frontend</code>.</p>'
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
}
