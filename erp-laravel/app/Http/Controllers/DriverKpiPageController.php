<?php

namespace App\Http\Controllers;

use App\Domains\DriverKpi\DriverKpiShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Driver KPI (Delivery / Ride recordings) HTML shell via Blade + React UI.
 */
class DriverKpiPageController extends Controller
{
    public function show(Request $request): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];

        $viewData = (new DriverKpiShell())->viewData([
            'erp' => is_array($erp) ? $erp : [],
            'service' => (string) $request->query('service', 'delivery'),
            'weekOffset' => (int) $request->query('week', 0),
            'userId' => (int) $request->query('user_id', 0),
            'companySlug' => (string) ($erp['company_slug'] ?? ''),
            'backUrl' => (string) ($erp['back_url'] ?? ''),
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Driver KPI UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>deliveries/deliveries-ui/frontend</code>.</p>'
                . '</body></html>',
                503
            );
        }

        $GLOBALS['page_title'] = $viewData['pageTitle'];
        $page_title = $viewData['pageTitle'];
        $employeeHeaderTitle = $viewData['employeeHeaderTitle'];
        $hideHeaderCompanyBranding = $viewData['hideHeaderCompanyBranding'];
        $hideSidebar = !empty($viewData['hideSidebar']);
        $employeeHeaderExtraClass = $viewData['employeeHeaderExtraClass'];

        return view('erp.react-shell', $viewData + [
            'page_title' => $page_title,
            'employeeHeaderTitle' => $employeeHeaderTitle,
            'hideHeaderCompanyBranding' => $hideHeaderCompanyBranding,
            'hideSidebar' => $hideSidebar,
            'employeeHeaderExtraClass' => $employeeHeaderExtraClass,
        ]);
    }
}
