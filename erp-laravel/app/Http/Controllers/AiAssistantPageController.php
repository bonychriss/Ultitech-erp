<?php

namespace App\Http\Controllers;

use App\Domains\AiAssistant\AiAssistantShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * AI Assistant chat via Blade + React.
 */
class AiAssistantPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];
        $erp = is_array($erp) ? $erp : [];

        $viewData = (new AiAssistantShell())->viewData([
            'erp' => $erp,
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>AI Assistant UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>admin/ai-assistant-ui/frontend</code>.</p>'
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
