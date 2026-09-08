<?php

namespace App\Http\Controllers;

use App\Domains\Suggest\SuggestShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Suggest React page via Blade (Phase 3).
 */
class SuggestPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];
        $suggestUrl = (string) ($erp['suggest_url'] ?? '');
        if ($suggestUrl === '' && function_exists('app_url')) {
            $suggestUrl = app_url('/suggest.php');
        }

        $apiUrl = $suggestUrl . (str_contains($suggestUrl, '?') ? '&' : '?') . 'api=suggestions';

        $viewData = (new SuggestShell())->viewData([
            'apiUrl' => $apiUrl,
            'backUrl' => (string) ($erp['back_url'] ?? ''),
            'userName' => (string) ($erp['full_name'] ?? ''),
            'companySlug' => (string) ($erp['company_slug'] ?? ''),
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Suggest UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>suggest-laravel/frontend</code>.</p>'
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
