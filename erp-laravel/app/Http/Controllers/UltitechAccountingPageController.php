<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\UltitechAccountingShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * New Accounting module hosted inside ERP chrome (upgrade path; classic remains fallback).
 */
class UltitechAccountingPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];
        $boot = is_array($GLOBALS['ULTITECH_ACCOUNTING_BOOT'] ?? null)
            ? $GLOBALS['ULTITECH_ACCOUNTING_BOOT']
            : [];

        $viewData = (new UltitechAccountingShell())->viewData([
            'pageTitle' => 'Accounting',
            'headerTitle' => 'Accounting',
            'boot' => $boot + [
                'hostedInErp' => true,
                'companySlug' => (string) ($erp['company_slug'] ?? ''),
                'classicAccountingUrl' => (string) ($erp['classic_accounting_url'] ?? $erp['back_url'] ?? ''),
            ],
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;max-width:42rem">'
                . '<h1>New Accounting UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>ultitech-accounting/frontend</code>.</p>'
                . '<p><a href="' . htmlspecialchars((string) ($erp['classic_accounting_url'] ?? '#'), ENT_QUOTES, 'UTF-8') . '">Open classic Accounting</a></p>'
                . '</body></html>',
                503
            );
        }

        $GLOBALS['page_title'] = $viewData['pageTitle'];
        $_SESSION['active_module'] = 'accounting';

        return view('erp.react-shell', $viewData + [
            'page_title' => $viewData['pageTitle'],
            'employeeHeaderTitle' => $viewData['employeeHeaderTitle'],
            'hideHeaderCompanyBranding' => $viewData['hideHeaderCompanyBranding'],
            'hideSidebar' => false,
            'active_module' => 'accounting',
            'employeeHeaderExtraClass' => $viewData['employeeHeaderExtraClass'],
        ]);
    }
}
