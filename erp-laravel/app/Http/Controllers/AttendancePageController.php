<?php

namespace App\Http\Controllers;

use App\Domains\Attendance\AttendanceShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Attendance clock / analytics HTML shells via Blade + React UI.
 */
class AttendancePageController extends Controller
{
    public function show(Request $request): View|Response
    {
        return $this->render($request, 'clock');
    }

    public function analytics(Request $request): View|Response
    {
        $period = (int) $request->query('period', 30);
        return $this->render($request, 'analytics', $period);
    }

    public function overtime(Request $request): View|Response
    {
        $period = (int) $request->query('period', 30);
        return $this->render($request, 'overtime', $period);
    }

    private function render(Request $request, string $page, ?int $period = null): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];

        $viewData = (new AttendanceShell())->viewData([
            'erp' => is_array($erp) ? $erp : [],
            'page' => $page,
            'period' => $period,
            'companySlug' => (string) ($erp['company_slug'] ?? ''),
            'backUrl' => (string) ($erp['back_url'] ?? ''),
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Attendance UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>attendance/attendance-ui</code>.</p>'
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
