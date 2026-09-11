<?php

namespace App\Http\Controllers;

use App\Domains\CashBook\CashBookShell;
use App\Domains\CashBook\DeskShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Cash Book desk pages (book ledger, categories, reports).
 */
class CashBookDeskPageController extends Controller
{
    public function show(Request $request, string $desk): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];
        $desk = strtolower(trim($desk));
        $meta = DeskShell::reactDesks()[$desk] ?? null;
        if ($meta === null) {
            return response('Cash Book desk not found.', 404);
        }

        $bookId = (int) ($erp['book_id'] ?? $request->query('id', 0));
        $header = (string) $meta['header'];
        if ($desk === 'book' && $bookId <= 0) {
            $redirect = function_exists('app_url')
                ? app_url('/modules/petty-cash/index.php')
                : '/modules/petty-cash/index.php';
            $q = http_build_query(['module' => 'petty_cash']);

            return redirect($redirect . '?' . $q);
        }

        $apiBase = function_exists('app_url')
            ? rtrim((string) app_url('/modules/petty-cash/api'), '/')
            : '/public_html/modules/petty-cash/api';

        $viewData = (new CashBookShell())->viewData([
            'apiBase' => $apiBase,
            'cashBookPage' => (string) $meta['page'],
            'pageTitle' => (string) $meta['title'],
            'headerTitle' => $header,
            'companySlug' => (string) ($erp['company_slug'] ?? ''),
            'backUrl' => (string) ($erp['back_url'] ?? ''),
            'bookId' => $bookId,
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
