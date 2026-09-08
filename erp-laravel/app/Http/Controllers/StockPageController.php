<?php

namespace App\Http\Controllers;

use App\Domains\Stock\DeskShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;

/**
 * Stock home (dashboard) via erp-laravel Blade React shell.
 */
class StockPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        return $this->renderBlade('dashboard');
    }

    private function renderBlade(string $desk): View|Response
    {
        try {
            $viewData = (new DeskShell())->viewData($desk);
        } catch (Throwable $e) {
            report($e);

            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Stock desk error</h1>'
                . '<p>' . htmlspecialchars($e->getMessage() !== '' ? $e->getMessage() : 'Unexpected error.', ENT_QUOTES, 'UTF-8') . '</p>'
                . '</body></html>',
                500
            )->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Stock UI not available</h1>'
                . '<p>React dist missing or unknown desk <code>'
                . htmlspecialchars($desk, ENT_QUOTES, 'UTF-8')
                . '</code>. Build <code>stock/stock-ui</code>.</p>'
                . '</body></html>',
                503
            )->header('Content-Type', 'text/html; charset=UTF-8');
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
