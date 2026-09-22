<?php

namespace App\Http\Controllers;

use App\Domains\Deliveries\DeliveriesShell;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Deliveries hub + dashboard via Blade + React UI.
 */
class DeliveriesPageController extends Controller
{
    public function hub(Request $request): View|Response
    {
        return $this->render($request, 'hub');
    }

    public function dashboard(Request $request): View|Response
    {
        return $this->render($request, 'dashboard');
    }

    public function createDelivery(Request $request): View|Response
    {
        return $this->render($request, 'create-delivery');
    }

    public function orderDetails(Request $request): View|Response
    {
        return $this->render($request, 'order-details');
    }

    public function deliveryNotes(Request $request): View|Response
    {
        return $this->render($request, 'delivery-notes');
    }

    public function createDeliveryNote(Request $request): View|Response
    {
        return $this->render($request, 'create-delivery-note');
    }

    private function render(Request $request, string $page): View|Response
    {
        $erp = $request->attributes->get('erp') ?? [];

        $viewData = (new DeliveriesShell())->viewData([
            'erp' => is_array($erp) ? $erp : [],
            'page' => $page,
            'companySlug' => (string) ($erp['company_slug'] ?? ''),
            'backUrl' => (string) ($erp['back_url'] ?? ''),
            'createDispatch' => $request->boolean('create_dispatch'),
            'orderId' => (int) $request->query('order_id', 0),
        ]);

        if ($viewData === null) {
            return response(
                '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Deliveries UI not built</h1>'
                . '<p>Run <code>npm install && npm run build</code> in <code>deliveries/deliveries-ui/frontend</code>.</p>'
                . '</body></html>',
                503
            );
        }

        if (!empty($viewData['notFound'])) {
            $msg = htmlspecialchars((string) ($viewData['error'] ?? 'Order not found.'), ENT_QUOTES, 'UTF-8');
            return response(
                '<!DOCTYPE html><html><head><title>Order Not Found</title></head>'
                . '<body style="font-family:sans-serif;padding:2rem;">'
                . '<h1>Order not found</h1><p>' . $msg . '</p></body></html>',
                404
            );
        }

        $GLOBALS['page_title'] = $viewData['pageTitle'];
        $page_title = $viewData['pageTitle'];
        $employeeHeaderTitle = $viewData['employeeHeaderTitle'];
        $hideHeaderCompanyBranding = $viewData['hideHeaderCompanyBranding'];
        $hideSidebar = !empty($viewData['hideSidebar']);
        $employeeHeaderExtraClass = $viewData['employeeHeaderExtraClass'];
        $employeeHeaderCenterHtml = $viewData['employeeHeaderCenterHtml'] ?? null;
        $employeeHeaderRightHtml = $viewData['employeeHeaderRightHtml'] ?? null;

        return view('erp.react-shell', $viewData + [
            'page_title' => $page_title,
            'employeeHeaderTitle' => $employeeHeaderTitle,
            'hideHeaderCompanyBranding' => $hideHeaderCompanyBranding,
            'hideSidebar' => $hideSidebar,
            'employeeHeaderExtraClass' => $employeeHeaderExtraClass,
            'employeeHeaderCenterHtml' => $employeeHeaderCenterHtml,
            'employeeHeaderRightHtml' => $employeeHeaderRightHtml,
        ]);
    }
}
