<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\AccountingShell;
use App\Domains\Accounting\Nav;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Accounting hub HTML shell via Blade + React.
 */
class AccountingPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];
        $slug = (string) ($erp['company_slug'] ?? '');

        $viewData = (new AccountingShell())->viewData([
            'pageTitle' => 'Accounting',
            'headerTitle' => 'Accounting',
            'companySlug' => $slug,
            'backUrl' => (string) ($erp['back_url'] ?? ''),
            'sections' => Nav::sections($slug),
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Accounting UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>modules/accounting/frontend</code>.</p>'
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
