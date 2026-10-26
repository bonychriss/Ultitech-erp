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

        $view = (string) ($_GET['view'] ?? request()->query('view', request()->route('view', '')));
        if ($view === '') {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            if (preg_match('#/website/advertisements(?:/email)?(?:/|\?|$)#i', $uri)) {
                $view = 'advertisements-email';
                $_GET['view'] = $view;
            } elseif (preg_match('#/website/(dashboard|quotes)(?:/|\?|$)#i', $uri, $m)) {
                $view = strtolower($m[1]);
                $_GET['view'] = $view;
            }
        }

        if ($view === 'dashboard') {
            $viewData['pageTitle'] = 'Website dashboard';
            $viewData['employeeHeaderTitle'] = 'Dashboard';
        }

        if ($view === 'advertisements-email' || $view === 'advertisements') {
            $appRoot = rtrim((string) config('erp.app_root'), '\\/');
            $adLib = $appRoot . '/stock/includes/web-advertisements-lib.php';
            $deskData = $appRoot . '/stock/includes/blade-desk-data.php';
            if (is_file($deskData)) {
                require_once $deskData;
            }
            if (is_file($adLib)) {
                require_once $adLib;
            }
            try {
                $pdo = function_exists('stock_blade_bootstrap') ? stock_blade_bootstrap() : null;
                $_SESSION['active_module'] = 'website';
                $_GET['module'] = 'website';
                if ($pdo instanceof \PDO && function_exists('webAdEmailPageData')) {
                    $adView = (new DeskShell())->reactPage(
                        'Email advertisement',
                        'email-advertisement',
                        webAdEmailPageData($pdo),
                        [
                            'employeeHeaderTitle' => 'Email advertisement',
                            'bodyClassExtra' => 'page-email-advertisement',
                            'extraHead' => <<<'CSS'
<style id="ead-voucher-canvas">
/* Match create-voucher slate canvas (#f1f5f9) */
body.page-email-advertisement,
body.page-email-advertisement.dashboard,
body.page-email-advertisement .layout-main-wrapper,
body.page-email-advertisement .layout-main-wrapper > .flex-grow-1,
body.page-email-advertisement .employee-header.employee-header--products-desk,
body.page-email-advertisement main.main-content.products-desk-react-root {
    background: #f1f5f9 !important;
}
:root { --bg-body: #f1f5f9; }
</style>
CSS
                        ]
                    );
                    if ($adView !== null) {
                        $viewData = $adView;
                    }
                } else {
                    $viewData['pageTitle'] = 'Email advertisement';
                    $viewData['employeeHeaderTitle'] = 'Email advertisement';
                }
            } catch (Throwable $e) {
                report($e);
                $viewData['pageTitle'] = 'Email advertisement';
                $viewData['employeeHeaderTitle'] = 'Email advertisement';
            }
        }

        if ($view === 'quotes') {
            $accessLib = rtrim((string) config('erp.app_root'), '\\/') . '/modules/sales/quote-requests/includes/quote-requests-lib.php';
            if (is_file($accessLib)) {
                require_once $accessLib;
            }
            if (function_exists('salesQuoteRequestsUserCanAccess') && !salesQuoteRequestsUserCanAccess()) {
                return response(
                    '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;">'
                    . '<h1>Access denied</h1>'
                    . '<p>You do not have permission to view website quote requests. Ask an administrator to grant access in Settings.</p>'
                    . '</body></html>',
                    403
                )->header('Content-Type', 'text/html; charset=UTF-8');
            }

            $viewData['pageTitle'] = 'Quote requests';
            $viewData['employeeHeaderTitle'] = '';
            $viewData['footerScripts'] = '';
            $viewData['beforeRoot'] = '';
            $quoteLib = rtrim((string) config('erp.app_root'), '\\/') . '/stock/includes/web-services-lib.php';
            if (is_file($quoteLib)) {
                require_once $quoteLib;
                $quoteNumber = trim((string) ($_GET['quote'] ?? request()->query('quote', '')));
                if ($quoteNumber !== '' && function_exists('webQuoteRequestDetailData')) {
                    $detailView = (new DeskShell())->reactPage(
                        $quoteNumber,
                        'quote-request-detail',
                        webQuoteRequestDetailData(substr($quoteNumber, 0, 64)),
                        ['employeeHeaderTitle' => '']
                    );
                    if ($detailView !== null) {
                        $viewData = $detailView;
                    }
                } elseif (function_exists('webQuoteRequestsPanel')) {
                    $viewData['beforeRoot'] = webQuoteRequestsPanel(true);
                }
            }
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
