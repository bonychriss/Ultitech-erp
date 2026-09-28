<?php

namespace App\Http\Controllers;

use App\Domains\WeeklyTasks\DashboardShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Weekly tasks dashboard HTML shell via Blade + React UI.
 */
class WeeklyTasksPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];

        $viewData = (new DashboardShell())->viewData([
            'erp' => is_array($erp) ? $erp : [],
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Weekly tasks UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>weekly-tasks-ui/frontend</code>.</p>'
                . '</body></html>',
                503
            );
        }

        $GLOBALS['page_title'] = $viewData['pageTitle'];

        return view('erp.react-shell', $viewData + [
            'page_title' => $viewData['pageTitle'],
            'employeeHeaderTitle' => $viewData['employeeHeaderTitle'],
            'hideHeaderCompanyBranding' => $viewData['hideHeaderCompanyBranding'],
            'employeeHeaderExtraClass' => $viewData['employeeHeaderExtraClass'],
        ]);
    }
}
