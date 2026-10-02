<?php

namespace App\Http\Controllers;

use App\Domains\Stock\DeskShell;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;

/**
 * Website module page. Reuses the stock React bundle for the webServices screen.
 */
class WebsitePageController extends Controller
{
    public function show(): View|Response
    {
        try {
            $viewData = (new DeskShell())->viewData('web-services');
        } catch (Throwable $e) {
            report($e);

            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Website module error</h1>'
                . '<p>' . htmlspecialchars($e->getMessage() !== '' ? $e->getMessage() : 'Unexpected error.', ENT_QUOTES, 'UTF-8') . '</p>'
                . '</body></html>',
                500
            )->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Website UI not available</h1>'
                . '<p>The stock React bundle is missing.</p>'
                . '</body></html>',
                503
            )->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $beforeRoot = '';
        $quoteLib = rtrim((string) config('erp.app_root'), '\\/') . '/stock/includes/web-services-lib.php';
        if (is_file($quoteLib)) {
            require_once $quoteLib;
            if (function_exists('webQuoteRequestsPanel')) {
                $beforeRoot = webQuoteRequestsPanel();
            }
        }

        $GLOBALS['page_title'] = $viewData['pageTitle'];
        $page_title = $viewData['pageTitle'];
        $employeeHeaderTitle = $viewData['employeeHeaderTitle'];
        $hideHeaderCompanyBranding = $viewData['hideHeaderCompanyBranding'];
        $employeeHeaderExtraClass = $viewData['employeeHeaderExtraClass'];

        return view('erp.react-shell', $viewData + [
            'beforeRoot' => $beforeRoot,
            'page_title' => $page_title,
            'employeeHeaderTitle' => $employeeHeaderTitle,
            'hideHeaderCompanyBranding' => $hideHeaderCompanyBranding,
            'employeeHeaderExtraClass' => $employeeHeaderExtraClass,
        ]);
    }
}
