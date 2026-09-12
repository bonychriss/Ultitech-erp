<?php

namespace App\Http\Controllers;

use App\Domains\Revenue\DeskShell;
use App\Domains\Revenue\RevenueShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Revenue desk pages (create, import).
 */
class RevenueDeskPageController extends Controller
{
    public function show(Request $request, string $desk): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];
        $desk = strtolower(trim($desk));
        $meta = DeskShell::reactDesks()[$desk] ?? null;
        if ($meta === null) {
            return response('Revenue desk not found.', 404);
        }

        $apiBase = function_exists('app_url')
            ? rtrim((string) app_url('/modules/revenue/api'), '/')
            : '/public_html/modules/revenue/api';

        $viewData = (new RevenueShell())->viewData([
            'apiBase' => $apiBase,
            'revenuePage' => (string) $meta['page'],
            'pageTitle' => (string) $meta['title'],
            'headerTitle' => (string) $meta['header'],
            'companySlug' => (string) ($erp['company_slug'] ?? ''),
            'backUrl' => (string) ($erp['back_url'] ?? ''),
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Revenues UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>modules/revenue/frontend</code>.</p>'
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
