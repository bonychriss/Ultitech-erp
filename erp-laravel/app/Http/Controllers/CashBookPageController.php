<?php

namespace App\Http\Controllers;

use App\Domains\CashBook\CashBookShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Cash Book home (books list) HTML shell via Blade.
 */
class CashBookPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];

        $apiBase = function_exists('app_url')
            ? rtrim((string) app_url('/modules/petty-cash/api'), '/')
            : '/public_html/modules/petty-cash/api';

        $viewData = (new CashBookShell())->viewData([
            'apiBase' => $apiBase,
            'cashBookPage' => 'books',
            'pageTitle' => 'Cash Book',
            'headerTitle' => 'Cash Book',
            'companySlug' => (string) ($erp['company_slug'] ?? ''),
            'backUrl' => (string) ($erp['back_url'] ?? ''),
            'bookId' => 0,
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Cash Book UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>modules/petty-cash/frontend</code>.</p>'
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
