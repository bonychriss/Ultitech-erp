<?php

namespace App\Domains\Sales;

/**
 * Blade + React dist shells for Sales desks (Phase 2).
 * Print / payment / admin stay on legacy modules/sales PHP.
 */
final class DeskShell
{
    /** @return list<string> */
    public static function laravelDesks(): array
    {
        return [
            'invoices',
            'orders',
            'quotations',
            'customers',
            'my-sales',
            'pricelist',
            'settings',
            'catalogue',
            'quote-create',
            'invoice-create',
            'order-view',
            'invoice-view',
            'quote-edit',
        ];
    }

    public static function deskRegex(): string
    {
        return implode('|', array_map(
            static fn (string $d): string => preg_quote($d, '/'),
            self::laravelDesks()
        ));
    }

    /**
     * @return array{
     *   pageTitle:string,
     *   bodyClass:string,
     *   headMarkup:string,
     *   footerScripts:string,
     *   employeeHeaderTitle:string,
     *   hideHeaderCompanyBranding:bool,
     *   employeeHeaderExtraClass:string,
     *   mainRootClass:string
     * }|null
     */
    public function viewData(string $desk): ?array
    {
        $desk = strtolower(trim($desk));
        if (!in_array($desk, self::laravelDesks(), true)) {
            return null;
        }

        return match ($desk) {
            'invoices' => $this->invoices(),
            'orders' => $this->orders(),
            'quotations' => $this->quotations(),
            'customers' => $this->customers(),
            'my-sales' => $this->mySales(),
            'pricelist' => $this->pricelist(),
            'settings' => $this->settings(),
            'catalogue' => $this->catalogue(),
            'quote-create' => $this->quoteCreate(),
            'invoice-create' => $this->invoiceCreate(),
            'order-view' => $this->orderView(),
            'invoice-view' => $this->invoiceView(),
            'quote-edit' => $this->quoteEdit(),
            default => null,
        };
    }

    /**
     * @param array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string} $assets
     * @param array{
     *   sweetAlert?:bool,
     *   extraHead?:string,
     *   includeBootstrap?:bool,
     *   mainRootClass?:string,
     *   employeeHeaderExtraClass?:string,
     *   employeeHeaderTitle?:string
     * } $opts
     * @return array{
     *   pageTitle:string,
     *   bodyClass:string,
     *   headMarkup:string,
     *   footerScripts:string,
     *   employeeHeaderTitle:string,
     *   hideHeaderCompanyBranding:bool,
     *   employeeHeaderExtraClass:string,
     *   mainRootClass:string
     * }
     */
    private function pack(string $title, string $bodyClass, array $assets, string $bootScript, array $opts = []): array
    {
        $sweetAlert = !empty($opts['sweetAlert']);
        $includeBootstrap = array_key_exists('includeBootstrap', $opts) ? (bool) $opts['includeBootstrap'] : true;
        $extraHead = (string) ($opts['extraHead'] ?? '');
        $mainRootClass = (string) ($opts['mainRootClass'] ?? 'exp-desk-react-root');
        $headerClass = (string) ($opts['employeeHeaderExtraClass'] ?? 'employee-header--exp-desk');
        $headerTitle = array_key_exists('employeeHeaderTitle', $opts)
            ? (string) $opts['employeeHeaderTitle']
            : $title;

        $head = $this->commonHeadExtras($sweetAlert);
        if ($extraHead !== '') {
            $head .= $extraHead . "\n";
        }

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $headMarkup = $head
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">' . "\n";
        if ($includeBootstrap) {
            $headMarkup .= '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n";
        }
        $headMarkup .= '<script>' . $bootScript . '</script>';

        $footerScripts = '<script type="module" crossorigin src="'
            . htmlspecialchars($jsUrl, ENT_QUOTES, 'UTF-8')
            . '"></script>';

        return [
            'pageTitle' => $title,
            'bodyClass' => trim($bodyClass . ' page-erp-laravel'),
            'headMarkup' => $headMarkup,
            'footerScripts' => $footerScripts,
            'employeeHeaderTitle' => $headerTitle,
            'hideHeaderCompanyBranding' => true,
            'employeeHeaderExtraClass' => $headerClass,
            'mainRootClass' => $mainRootClass,
        ];
    }

    private function commonHeadExtras(bool $sweetAlert): string
    {
        $parts = [
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
        ];
        if ($sweetAlert) {
            $parts[] = '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
        }
        if (function_exists('app_url')) {
            $erpStylePath = rtrim((string) config('erp.app_root'), '\\/') . '/assets/css/style.css';
            $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
            $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
            if (function_exists('erp_dark_theme_css_url')) {
                $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
            }
        }

        return implode("\n", $parts) . "\n";
    }

    private function listBody(string $extra): string
    {
        return trim('page-exp-desk exp-dashboard-page ' . $extra);
    }

    private function parseId(): int
    {
        $id = $_GET['id'] ?? null;
        if (is_scalar($id) && ctype_digit((string) $id)) {
            return (int) $id;
        }

        return 0;
    }

    private function invoices(): ?array
    {
        $lib = $this->root() . '/modules/sales/invoices/includes/invoices-lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;
        if (function_exists('invoicesDeskBootstrap')) {
            invoicesDeskBootstrap();
        }
        if (!function_exists('invoicesDeskModuleAssetUrls')) {
            return null;
        }
        $assets = invoicesDeskModuleAssetUrls();
        if ($assets === null) {
            return null;
        }

        $script = 'window.__INVOICES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__INVOICES_PAGE__ = ' . json_encode('list', JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__SALES_DESK_ENGINE__ = ' . json_encode('erp-laravel Domains/Sales', JSON_UNESCAPED_SLASHES) . ';';

        return $this->pack(
            'Invoices',
            $this->listBody('page-invoices-desk invoices-dashboard-page'),
            $assets,
            $script,
            ['sweetAlert' => true]
        );
    }

    private function orders(): ?array
    {
        $lib = $this->root() . '/modules/sales/orders/includes/orders-lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;
        if (function_exists('ordersDeskBootstrap')) {
            ordersDeskBootstrap();
        }
        if (!function_exists('ordersDeskLoadReactAssets')) {
            return null;
        }
        $assets = ordersDeskLoadReactAssets();
        if ($assets === null) {
            return null;
        }

        $cfg = [
            'module' => isset($_GET['module']) ? (string) $_GET['module'] : 'sales',
            'engine' => 'erp-laravel Domains/Sales',
        ];
        $script = 'window.__SALES_ORDERS_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__SALES_ORDERS_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__ORDERS_DESK_PAGE__ = ' . json_encode('sales_orders', JSON_UNESCAPED_SLASHES) . ';';

        return $this->pack(
            'Sales Orders',
            $this->listBody('page-orders-desk'),
            $assets,
            $script,
            ['sweetAlert' => true]
        );
    }

    private function quotations(): ?array
    {
        $lib = $this->root() . '/modules/sales/orders/includes/orders-lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;
        if (function_exists('ordersDeskBootstrap')) {
            ordersDeskBootstrap();
        }
        if (!function_exists('ordersDeskLoadReactAssets')) {
            return null;
        }
        $assets = ordersDeskLoadReactAssets();
        if ($assets === null) {
            return null;
        }

        $module = isset($_GET['module']) ? (string) $_GET['module'] : 'sales';
        $cfg = [
            'module' => $module,
            'engine' => 'erp-laravel Domains/Sales',
        ];
        $initUrl = function_exists('sales_laravel_api_url')
            ? sales_laravel_api_url('quotations', ['module' => $module])
            : ($assets['apiUrl'] . '/init.php');

        $script = 'window.__QUOTATIONS_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__QUOTATIONS_INIT_URL__ = ' . json_encode($initUrl, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__QUOTATIONS_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__ORDERS_DESK_PAGE__ = ' . json_encode('quotations', JSON_UNESCAPED_SLASHES) . ';';

        return $this->pack(
            'Quotations',
            $this->listBody('page-orders-desk'),
            $assets,
            $script,
            ['sweetAlert' => true]
        );
    }

    private function customers(): ?array
    {
        $lib = $this->root() . '/modules/sales/customers/includes/catalogue-lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;
        if (function_exists('customerCatalogueDeskBootstrap')) {
            customerCatalogueDeskBootstrap();
        }
        if (!function_exists('customersDeskLoadReactAssets')) {
            return null;
        }
        $assets = customersDeskLoadReactAssets();
        if ($assets === null) {
            return null;
        }

        $module = function_exists('customerCatalogueModuleQuery')
            ? customerCatalogueModuleQuery()
            : (isset($_GET['module']) ? (string) $_GET['module'] : 'sales');
        $cfg = ['module' => $module, 'engine' => 'erp-laravel Domains/Sales'];
        $deskPage = 'index';

        $script = 'window.__CUSTOMERS_DESK_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__CUSTOMERS_DESK_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__CUSTOMERS_DESK_PAGE__ = ' . json_encode($deskPage, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__CUSTOMER_CATALOGUE_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__CUSTOMER_CATALOGUE_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';';

        return $this->pack(
            'Customers',
            $this->listBody('page-customer-index-desk'),
            $assets,
            $script
        );
    }

    private function mySales(): ?array
    {
        $lib = $this->root() . '/modules/sales/includes/my-sales-lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;
        if (function_exists('mySalesDeskBootstrap')) {
            mySalesDeskBootstrap();
        }
        if (!function_exists('mySalesDeskLoadReactAssets')) {
            return null;
        }
        $assets = mySalesDeskLoadReactAssets();
        if ($assets === null) {
            return null;
        }

        $themeColor = '#3b82f6';
        if (function_exists('mySalesInitData')) {
            try {
                $init = mySalesInitData();
                if (is_array($init)) {
                    $themeColor = (string) (($init['company']['theme_color'] ?? '') ?: $themeColor);
                }
            } catch (\Throwable $e) {
                // Shell can still render.
            }
        }

        $cfg = [
            'module' => function_exists('mySalesDeskModuleQuery') ? mySalesDeskModuleQuery() : 'sales',
            'theme_color' => $themeColor,
            'engine' => 'erp-laravel Domains/Sales',
        ];
        $script = 'window.__MY_SALES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__MY_SALES_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';';

        return $this->pack(
            'My Sales',
            $this->listBody('page-my-sales-desk'),
            $assets,
            $script
        );
    }

    private function pricelist(): ?array
    {
        $lib = $this->root() . '/modules/sales/includes/pricelist-lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;
        if (function_exists('pricelistDeskBootstrap')) {
            pricelistDeskBootstrap();
        }
        if (!function_exists('pricelistDeskLoadReactAssets')) {
            return null;
        }
        $assets = pricelistDeskLoadReactAssets();
        if ($assets === null) {
            return null;
        }

        $cfg = [
            'module' => function_exists('pricelistDeskModuleQuery') ? pricelistDeskModuleQuery() : 'sales',
            'engine' => 'erp-laravel Domains/Sales',
        ];
        $script = 'window.__PRICELIST_DESK_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__PRICELIST_DESK_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';';

        return $this->pack(
            'Price list',
            $this->listBody('page-pricelist-desk'),
            $assets,
            $script
        );
    }

    private function catalogue(): ?array
    {
        $salesFn = $this->root() . '/modules/sales/functions.php';
        $lib = $this->root() . '/modules/sales/catalogue-ui/lib.php';
        $load = $this->root() . '/modules/sales/catalogue-ui/load-catalogue-data.php';
        if (!is_file($lib) || !is_file($load)) {
            return null;
        }
        if (is_file($salesFn)) {
            require_once $salesFn;
        }
        require_once $lib;
        require_once $load;

        if (!function_exists('salesCatalogueUiLoadReactAssets')) {
            return null;
        }
        $assets = salesCatalogueUiLoadReactAssets();
        if ($assets === null) {
            return null;
        }

        $module = isset($_GET['module']) ? (string) $_GET['module'] : 'sales';
        $apiQuery = array_filter([
            'module' => $module,
            'doc' => isset($_GET['doc']) ? (string) $_GET['doc'] : null,
            'return' => isset($_GET['return']) ? (string) $_GET['return'] : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $initUrl = function_exists('sales_laravel_api_url')
            ? sales_laravel_api_url('catalogue', $apiQuery)
            : (string) ($assets['initUrl'] ?? '');

        $initData = [];
        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo instanceof \PDO && function_exists('sales_load_catalogue_payload')) {
            try {
                $payload = sales_load_catalogue_payload($pdo);
                if (is_array($payload) && !empty($payload['ok'])) {
                    $initData = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                }
            } catch (\Throwable $e) {
                // React can still fetch via initUrl.
            }
        }

        $cfg = [
            'initUrl' => $initUrl,
            'data' => $initData,
            'engine' => 'erp-laravel Domains/Sales',
        ];
        $script = 'window.__CATALOGUE_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';';

        $extraHead = '<style>
html:has(body.page-sales-catalogue), body.page-sales-catalogue { background: #fff !important; }
body.page-sales-catalogue { color: #1f2937; min-height: 100vh; }
body.page-sales-catalogue .layout-main-wrapper,
body.page-sales-catalogue .layout-main-wrapper > .flex-grow-1 { background: #fff !important; width: 100%; }
body.page-sales-catalogue header.employee-header { background: #fff !important; box-shadow: none !important; border-bottom: 1px solid rgba(15,23,42,.06); }
main.main-content.sales-catalogue-shell {
  flex: 1 1 auto; width: 100% !important; max-width: none !important; margin: 0 !important;
  padding: 1rem 1rem 2rem !important; overflow: auto !important; background: #fff !important;
  min-height: calc(100vh - 80px);
}
@media (min-width: 993px) {
  main.main-content.sales-catalogue-shell { padding: 1.25rem 1.75rem 2rem !important; }
}
main.main-content.sales-catalogue-shell #root { width: 100%; min-height: 40vh; }
</style>';

        return $this->pack(
            'Sales Catalogue',
            'page-sales-catalogue',
            $assets,
            $script,
            [
                'sweetAlert' => false,
                'includeBootstrap' => false,
                'extraHead' => $extraHead,
                'mainRootClass' => 'sales-catalogue-shell',
                'employeeHeaderExtraClass' => 'employee-header--sales-catalogue',
                'employeeHeaderTitle' => '',
            ]
        );
    }

    private function settings(): ?array
    {
        $lib = $this->root() . '/modules/sales/settings/includes/settings-lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;
        if (function_exists('settingsDeskBootstrap')) {
            settingsDeskBootstrap();
        }
        if (!function_exists('settingsDeskLoadReactAssets')) {
            return null;
        }
        $assets = settingsDeskLoadReactAssets();
        if ($assets === null) {
            return null;
        }

        $cfg = [
            'module' => isset($_GET['module']) ? (string) $_GET['module'] : 'sales',
            'engine' => 'erp-laravel Domains/Sales',
        ];
        $script = 'window.__SALES_SETTINGS_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__SALES_SETTINGS_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';';

        return $this->pack(
            'Sales Settings',
            $this->listBody('page-settings-desk page-sales-settings'),
            $assets,
            $script
        );
    }

    private function quoteCreate(): ?array
    {
        return $this->documentCreate('Create Quotation', 'quote');
    }

    private function invoiceCreate(): ?array
    {
        $fns = $this->root() . '/modules/sales/functions.php';
        if (is_file($fns)) {
            require_once $fns;
        }

        $type = strtolower(trim((string) ($_GET['type'] ?? 'spare')));
        if (!in_array($type, ['truck', 'spare'], true)) {
            $type = 'spare';
        }
        $title = function_exists('salesInvoiceCreatePageTitle')
            ? salesInvoiceCreatePageTitle($type)
            : 'Create Invoice';

        return $this->documentCreate($title, 'invoice');
    }

    private function documentCreate(string $title, string $documentType): ?array
    {
        $lib = $this->root() . '/modules/sales/invoices/includes/invoices-lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;
        if (function_exists('invoicesDeskBootstrap')) {
            invoicesDeskBootstrap();
        }
        if (!function_exists('invoicesDeskModuleAssetUrls')) {
            return null;
        }
        $assets = invoicesDeskModuleAssetUrls();
        if ($assets === null) {
            return null;
        }

        $documentType = strtolower(trim($documentType)) === 'quote' ? 'quote' : 'invoice';
        $script = 'window.__INVOICES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__INVOICES_PAGE__ = ' . json_encode('create', JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__INVOICES_DOCUMENT_TYPE__ = ' . json_encode($documentType, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__SALES_DESK_ENGINE__ = ' . json_encode('erp-laravel Domains/Sales', JSON_UNESCAPED_SLASHES) . ';';

        return $this->pack(
            $title,
            'page-inv-desk inv-dashboard-page',
            $assets,
            $script,
            [
                'sweetAlert' => true,
                'mainRootClass' => 'inv-desk-react-root',
                'employeeHeaderExtraClass' => 'employee-header--inv-desk',
            ]
        );
    }

    private function quoteEdit(): ?array
    {
        $lib = $this->root() . '/modules/sales/orders/includes/order-edit-lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;
        if (function_exists('ordersDeskBootstrap')) {
            ordersDeskBootstrap();
        }

        $orderId = $this->parseId();
        if ($orderId <= 0 && function_exists('ordersViewParseId')) {
            $orderId = ordersViewParseId($_GET);
        }
        if ($orderId <= 0) {
            throw new \RuntimeException('Order id is required.');
        }

        try {
            $init = sales_quote_edit_init_data($orderId);
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage() !== '' ? $e->getMessage() : 'Quote edit failed.', 0, $e);
        }

        $invLib = $this->root() . '/modules/sales/invoices/includes/invoices-lib.php';
        require_once $invLib;
        if (!function_exists('invoicesDeskModuleAssetUrls')) {
            return null;
        }
        $assets = invoicesDeskModuleAssetUrls();
        if ($assets === null) {
            return null;
        }

        $title = (string) ($init['page_title'] ?? 'Edit Quotation');
        $script = 'window.__INVOICES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__INVOICES_PAGE__ = ' . json_encode('quote_edit', JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__INVOICES_DOCUMENT_TYPE__ = ' . json_encode('quote', JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__INVOICES_ORDER_ID__ = ' . (int) $orderId . ';'
            . 'window.__SALES_DESK_ENGINE__ = ' . json_encode('erp-laravel Domains/Sales', JSON_UNESCAPED_SLASHES) . ';';
        if (function_exists('sales_laravel_api_url')) {
            $script .= 'window.__INVOICES_QUOTE_EDIT_INIT_URL__ = ' . json_encode(
                sales_laravel_api_url('quote-edit-init', ['id' => $orderId, 'module' => 'sales']),
                JSON_UNESCAPED_SLASHES
            ) . ';'
                . 'window.__INVOICES_QUOTE_EDIT_SAVE_URL__ = ' . json_encode(
                    sales_laravel_api_url('quote-edit-save', ['id' => $orderId, 'module' => 'sales']),
                    JSON_UNESCAPED_SLASHES
                ) . ';';
        }

        return $this->pack(
            $title,
            'page-inv-desk inv-dashboard-page',
            $assets,
            $script,
            [
                'sweetAlert' => true,
                'mainRootClass' => 'inv-desk-react-root',
                'employeeHeaderExtraClass' => 'employee-header--inv-desk',
            ]
        );
    }

    private function orderView(): ?array
    {
        $lib = $this->root() . '/modules/sales/orders/includes/orders-view-lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;
        if (function_exists('ordersDeskBootstrap')) {
            ordersDeskBootstrap();
        }
        if (!function_exists('ordersDeskLoadReactAssets')) {
            return null;
        }
        $assets = ordersDeskLoadReactAssets();
        if ($assets === null) {
            return null;
        }

        $orderId = function_exists('ordersViewParseId') ? ordersViewParseId($_GET) : $this->parseId();
        if ($orderId <= 0) {
            throw new \RuntimeException('Order id is required.');
        }

        try {
            $preview = salesOrderViewLoadContext($orderId);
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage() !== '' ? $e->getMessage() : 'Order not found.', 0, $e);
        }

        $module = function_exists('ordersViewModuleQuery') ? ordersViewModuleQuery() : 'sales';
        $displayNumber = (string) ($preview['display_order_number'] ?? 'Order');
        $title = (string) ($preview['document_label'] ?? 'Order') . ' ' . $displayNumber;

        $cfg = [
            'module' => $module,
            'order_id' => $orderId,
            'engine' => 'erp-laravel Domains/Sales',
        ];
        $initUrl = function_exists('sales_laravel_api_url')
            ? sales_laravel_api_url('order-view-init', ['id' => $orderId, 'module' => $module])
            : ($assets['apiUrl'] . '/view-init.php?id=' . $orderId);
        $statusUrl = function_exists('sales_laravel_api_url')
            ? sales_laravel_api_url('order-view-status', ['id' => $orderId, 'module' => $module])
            : ($assets['apiUrl'] . '/view-status.php?id=' . $orderId);

        $script = 'window.__SALES_ORDERS_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__SALES_ORDERS_INIT_URL__ = ' . json_encode($initUrl, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__SALES_ORDERS_STATUS_URL__ = ' . json_encode($statusUrl, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__SALES_ORDERS_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__ORDERS_DESK_PAGE__ = ' . json_encode('order_view', JSON_UNESCAPED_SLASHES) . ';';

        $extraHead = '';
        if (function_exists('ordersViewDeskShellHeadExtras') && function_exists('salesOrderViewLoadCompanySettings')) {
            $pdo = function_exists('sales_pdo') ? sales_pdo() : ($GLOBALS['pdo'] ?? null);
            $settings = $pdo instanceof \PDO ? salesOrderViewLoadCompanySettings($pdo) : [];
            $extraHead = ordersViewDeskShellHeadExtras(is_array($settings) ? $settings : []);
        }

        return $this->pack(
            $title,
            'page-order-view ov-page',
            $assets,
            $script,
            [
                'sweetAlert' => false,
                'includeBootstrap' => false,
                'extraHead' => $extraHead,
                'mainRootClass' => 'ov-react-root',
                'employeeHeaderExtraClass' => 'employee-header--order-view',
                'employeeHeaderTitle' => '',
            ]
        );
    }

    private function invoiceView(): ?array
    {
        $lib = $this->root() . '/modules/sales/invoices/includes/invoices-view-lib.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;
        if (function_exists('invoicesDeskBootstrap')) {
            invoicesDeskBootstrap();
        }
        if (!function_exists('invoicesDeskModuleAssetUrls')) {
            return null;
        }
        $assets = invoicesDeskModuleAssetUrls();
        if ($assets === null) {
            return null;
        }

        $invoiceId = function_exists('invoicesViewParseId') ? invoicesViewParseId($_GET) : $this->parseId();
        if ($invoiceId <= 0) {
            throw new \RuntimeException('Invoice id is required.');
        }

        try {
            $preview = salesInvoiceViewLoadContext($invoiceId);
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage() !== '' ? $e->getMessage() : 'Invoice not found.', 0, $e);
        }

        $module = function_exists('invoicesViewModuleQuery') ? invoicesViewModuleQuery() : 'sales';
        $displayNumber = (string) ($preview['display_invoice_number'] ?? 'Invoice');
        $title = 'Invoice ' . $displayNumber;

        $cfg = [
            'module' => $module,
            'invoice_id' => $invoiceId,
            'engine' => 'erp-laravel Domains/Sales',
        ];
        $script = 'window.__INVOICES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__INVOICES_CFG__ = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES) . ';'
            . 'window.__INVOICES_PAGE__ = ' . json_encode('invoice_view', JSON_UNESCAPED_SLASHES) . ';';

        $extraHead = '';
        if (function_exists('invoicesViewDeskShellHeadExtras') && function_exists('salesOrderViewLoadCompanySettings')) {
            $pdo = function_exists('sales_pdo') ? sales_pdo() : ($GLOBALS['pdo'] ?? null);
            $settings = $pdo instanceof \PDO ? salesOrderViewLoadCompanySettings($pdo) : [];
            $extraHead = invoicesViewDeskShellHeadExtras(is_array($settings) ? $settings : []);
        }

        return $this->pack(
            $title,
            'page-invoice-view ov-page',
            $assets,
            $script,
            [
                'sweetAlert' => false,
                'includeBootstrap' => false,
                'extraHead' => $extraHead,
                'mainRootClass' => 'ov-react-root',
                'employeeHeaderExtraClass' => 'employee-header--invoice-view',
                'employeeHeaderTitle' => '',
            ]
        );
    }

    private function root(): string
    {
        return rtrim((string) config('erp.app_root'), '\\/');
    }
}
