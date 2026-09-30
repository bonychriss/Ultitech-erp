<?php

namespace App\Http\Controllers;

use App\Domains\AiAgent\AiAgentShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * AI Agent page via Blade + React. Receivables stay at #ai-receivables.
 */
class AiAgentPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];
        $erp = is_array($erp) ? $erp : [];

        $viewData = (new AiAgentShell())->viewData([
            'erp' => $erp,
            'companySlug' => (string) ($erp['company_slug'] ?? ''),
            'backUrl' => (string) ($erp['back_url'] ?? ''),
            'csrf' => (string) ($erp['csrf'] ?? ''),
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>AI Agent UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>modules/ai-agent/frontend</code>.</p>'
                . '</body></html>',
                503
            );
        }

        $GLOBALS['page_title'] = $viewData['pageTitle'];
        $page_title = $viewData['pageTitle'];
        $employeeHeaderTitle = $viewData['employeeHeaderTitle'];
        $employeeHeaderSubtitle = $viewData['employeeHeaderSubtitle'];
        $hideHeaderCompanyBranding = $viewData['hideHeaderCompanyBranding'];
        $employeeHeaderExtraClass = $viewData['employeeHeaderExtraClass'];

        return view('erp.react-shell', $viewData + [
            'page_title' => $page_title,
            'employeeHeaderTitle' => $employeeHeaderTitle,
            'employeeHeaderSubtitle' => $employeeHeaderSubtitle,
            'hideHeaderCompanyBranding' => $hideHeaderCompanyBranding,
            'employeeHeaderExtraClass' => $employeeHeaderExtraClass,
        ]);
    }
}
