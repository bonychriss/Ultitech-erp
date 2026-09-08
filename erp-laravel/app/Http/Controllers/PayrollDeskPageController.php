<?php

namespace App\Http\Controllers;

use App\Domains\Payroll\DeskShell;
use App\Domains\Payroll\LegacyDeskBridge;
use App\Domains\Payroll\PayrollShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Payroll desk pages via erp-laravel (React shell or legacy PHP).
 */
class PayrollDeskPageController extends Controller
{
    public function show(Request $request, string $desk): View|Response
    {
        $desk = strtolower(trim($desk));
        if (!in_array($desk, DeskShell::laravelDesks(), true)) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Unknown payroll desk</h1>'
                . '<p><code>' . htmlspecialchars($desk, ENT_QUOTES, 'UTF-8') . '</code></p>'
                . '</body></html>',
                404
            )->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if (DeskShell::isReactDesk($desk)) {
            return $this->showReact($request, $desk);
        }

        $result = (new LegacyDeskBridge())->render($desk);

        return response($result['html'], $result['ok'] ? 200 : 503)
            ->header('Content-Type', $result['contentType']);
    }

    private function showReact(Request $request, string $desk): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];
        $meta = DeskShell::reactDesks()[$desk];

        $apiBase = function_exists('app_url')
            ? rtrim((string) app_url('/modules/payroll/api'), '/')
            : '/public_html/modules/payroll/api';

        $viewData = (new PayrollShell())->viewData([
            'apiBase' => $apiBase,
            'payrollPage' => $meta['page'],
            'pageTitle' => $meta['title'],
            'headerTitle' => $meta['header'],
            'companySlug' => (string) ($erp['company_slug'] ?? ''),
            'backUrl' => (string) ($erp['back_url'] ?? ''),
        ]);

        if ($viewData === null) {
            // Fall back to legacy PHP desk if React dist is missing.
            $result = (new LegacyDeskBridge())->render($desk);

            return response($result['html'], $result['ok'] ? 200 : 503)
                ->header('Content-Type', $result['contentType']);
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
