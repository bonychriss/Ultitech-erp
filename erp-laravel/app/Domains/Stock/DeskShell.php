<?php

namespace App\Domains\Stock;

/**
 * Stock desk registry + Blade React shells for pilot desks.
 * Phase 1: LegacyDeskBridge for most desks.
 * Phase 2 pilot: Blade + stock-ui for dashboard/products/brands/product-create.
 */
final class DeskShell
{
    /**
     * desk key => path relative to app root (public_html).
     *
     * @return array<string,string>
     */
    public static function deskEntries(): array
    {
        return [
            'dashboard' => 'stock/dashboard.php',
            'catalogue' => 'stock/catalogue.php',
            'settings' => 'stock/settings.php',
            'product-detail' => 'stock/product-detail.php',

            'products' => 'stock/modules/products/index.php',
            'product-view' => 'stock/modules/products/view.php',
            'product-create' => 'stock/modules/products/add.php',
            'product-edit' => 'stock/modules/products/edit.php',
            'products-import' => 'stock/modules/products/bulk_import.php',
            'categories' => 'stock/modules/products/categories.php',
            'category-add' => 'stock/modules/products/add_category.php',
            'category-edit' => 'stock/modules/products/edit_category.php',

            'brands' => 'stock/modules/brands/index.php',
            'brand-edit' => 'stock/modules/brands/edit.php',

            'uploads' => 'stock/modules/uploads/index.php',
            'uploads-upload' => 'stock/modules/uploads/upload.php',
            'uploads-recycle' => 'stock/modules/uploads/recycle_bin.php',

            'suppliers' => 'stock/modules/suppliers/index.php',
            'supplier-add' => 'stock/modules/suppliers/add.php',
            'supplier-edit' => 'stock/modules/suppliers/edit.php',
            'supplier-view' => 'stock/modules/suppliers/view.php',

            'statements' => 'stock/modules/statements/supplier.php',

            'shipments' => 'stock/modules/shipments/index.php',
            'shipment-create' => 'stock/modules/shipments/create.php',
            'shipment-edit' => 'stock/modules/shipments/edit.php',
            'shipment-view' => 'stock/modules/shipments/view.php',
            'shipment-receive' => 'stock/modules/shipments/receive.php',
            'shipment-import' => 'stock/modules/shipments/import.php',

            'shippers' => 'stock/modules/shippers/index.php',
            'shipper-add' => 'stock/modules/shippers/add.php',
            'shipper-edit' => 'stock/modules/shippers/edit.php',

            'purchases' => 'stock/modules/purchases/index.php',
            'purchase-create' => 'stock/modules/purchases/domestic_create.php',
            'purchase-edit' => 'stock/modules/purchases/edit.php',
            'purchase-view' => 'stock/modules/purchases/view_po.php',
            'purchase-receive' => 'stock/modules/purchases/domestic_receive.php',
            'purchase-classification' => 'stock/modules/purchases/edit_classification.php',
            'purchase-payments' => 'stock/modules/purchases/supplier-payments.php',
            'purchase-receipt-audit' => 'stock/modules/purchases/receipt_audit.php',

            'replenishment' => 'stock/modules/reports/replenishment.php',
            'reports' => 'stock/modules/reports/stock.php',
            'reports-purchases' => 'stock/modules/reports/purchases.php',

            'movements' => 'stock/modules/stock/movements.php',
            'stock-adjust' => 'stock/modules/stock/adjust.php',
            'stock-batches' => 'stock/modules/stock/batches.php',

            'transfers' => 'stock/modules/transfers/index.php',
            'fleet-parts' => 'stock/modules/fleet_parts/index.php',
            'analytics' => 'stock/modules/analytics/index.php',
        ];
    }

    /** @return list<string> */
    public static function laravelDesks(): array
    {
        return array_keys(self::deskEntries());
    }

    /**
     * Pilot desks rendered via Blade + stock-ui (not LegacyDeskBridge on GET).
     *
     * @return list<string>
     */
    public static function bladeDesks(): array
    {
        return ['dashboard', 'products', 'brands', 'product-create'];
    }

    public static function isBladeDesk(string $desk): bool
    {
        return in_array(strtolower(trim($desk)), self::bladeDesks(), true);
    }

    public static function deskRegex(): string
    {
        return implode('|', array_map(
            static fn (string $d): string => preg_quote($d, '/'),
            self::laravelDesks()
        ));
    }

    public static function entryPath(string $desk): ?string
    {
        $desk = strtolower(trim($desk));
        $entries = self::deskEntries();
        if (!isset($entries[$desk])) {
            return null;
        }

        $root = rtrim((string) config('erp.app_root'), '\\/');
        $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entries[$desk]);

        return is_file($full) ? $full : null;
    }

    /**
     * @return array{
     *   pageTitle:string,
     *   bodyClass:string,
     *   headMarkup:string,
     *   footerScripts:string,
     *   employeeHeaderTitle:string|null,
     *   hideHeaderCompanyBranding:bool,
     *   employeeHeaderExtraClass:string,
     *   mainRootClass:string
     * }|null
     */
    public function viewData(string $desk): ?array
    {
        $desk = strtolower(trim($desk));
        if ($desk === '' || $desk === 'home') {
            $desk = 'dashboard';
        }
        if (!self::isBladeDesk($desk)) {
            return null;
        }

        $this->requireDataLib();
        $assets = (new StockShell())->loadAssets();
        if ($assets === null) {
            return null;
        }

        return match ($desk) {
            'dashboard' => $this->pack(
                'Stock',
                'page-products-desk page-stock-dash',
                'dashboard',
                stock_blade_dashboard_data(),
                $assets,
                [
                    'employeeHeaderTitle' => null,
                    'mainRootClass' => 'stock-dash-react-root',
                ]
            ),
            'products' => $this->pack(
                'Products',
                'page-products-desk',
                'products-list',
                stock_blade_products_list_data(),
                $assets,
                ['employeeHeaderTitle' => 'Products']
            ),
            'brands' => $this->pack(
                'Brands',
                'page-products-desk',
                'brands-list',
                stock_blade_brands_list_data(),
                $assets,
                ['employeeHeaderTitle' => 'Brands', 'sweetAlert' => true]
            ),
            'product-create' => $this->pack(
                'Add Product',
                'page-products-desk',
                'product-create',
                stock_blade_product_create_data(),
                $assets,
                [
                    'employeeHeaderTitle' => 'Add Product',
                    'extraHead' => $this->productCreateMobileCss(),
                ]
            ),
            default => null,
        };
    }

    private function requireDataLib(): void
    {
        $lib = rtrim((string) config('erp.app_root'), '\\/') . '/stock/includes/blade-desk-data.php';
        if (!is_file($lib)) {
            throw new \RuntimeException('stock/includes/blade-desk-data.php is missing.');
        }
        require_once $lib;
    }

    /**
     * @param array<string,mixed> $pageData
     * @param array{assetBase:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string} $assets
     * @param array{
     *   sweetAlert?:bool,
     *   extraHead?:string,
     *   employeeHeaderTitle?:string|null,
     *   mainRootClass?:string
     * } $opts
     * @return array{
     *   pageTitle:string,
     *   bodyClass:string,
     *   headMarkup:string,
     *   footerScripts:string,
     *   employeeHeaderTitle:string|null,
     *   hideHeaderCompanyBranding:bool,
     *   employeeHeaderExtraClass:string,
     *   mainRootClass:string
     * }
     */
    private function pack(
        string $title,
        string $bodyClass,
        string $reactPage,
        array $pageData,
        array $assets,
        array $opts = []
    ): array {
        $sweetAlert = !empty($opts['sweetAlert']);
        $extraHead = (string) ($opts['extraHead'] ?? '');
        $mainRootClass = (string) ($opts['mainRootClass'] ?? 'products-desk-react-root');
        $headerTitle = array_key_exists('employeeHeaderTitle', $opts)
            ? $opts['employeeHeaderTitle']
            : $title;

        $boot = [
            'page' => $reactPage,
            'data' => $pageData,
        ];
        $bootScript = 'window.__STOCK_PAGE__ = ' . json_encode(
            $boot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
        ) . ';'
            . 'window.__STOCK_DESK_ENGINE__ = ' . json_encode('erp-laravel Domains/Stock', JSON_UNESCAPED_SLASHES) . ';';

        $cssUrl = $assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'];
        $jsUrl = $assets['assetBase'] . $assets['jsFile'] . '?v=' . $assets['jsVersion'];

        $headMarkup = $this->commonHeadExtras($sweetAlert)
            . $this->productsDeskChromeCss() . "\n"
            . ($extraHead !== '' ? $extraHead . "\n" : '')
            . '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') . '">' . "\n"
            . '<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">' . "\n"
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n"
            . '<script>' . $bootScript . '</script>';

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
            'employeeHeaderExtraClass' => 'employee-header--products-desk',
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

    private function productsDeskChromeCss(): string
    {
        return <<<'CSS'
<style id="stock-blade-chrome">
body.page-products-desk.dashboard .layout-main-wrapper,
body.page-stock-dash.dashboard .layout-main-wrapper { align-items: stretch; }
body.page-products-desk.dashboard .layout-main-wrapper > .flex-grow-1,
body.page-stock-dash.dashboard .layout-main-wrapper > .flex-grow-1 {
    min-height: 0;
    display: flex;
    flex-direction: column;
}
body.page-products-desk,
body.page-products-desk.dashboard,
body.page-products-desk .layout-main-wrapper,
body.page-products-desk .layout-main-wrapper > .flex-grow-1,
body.page-stock-dash,
body.page-stock-dash.dashboard,
body.page-stock-dash .layout-main-wrapper,
body.page-stock-dash .layout-main-wrapper > .flex-grow-1 {
    background: #f8fafc !important;
}
body.page-products-desk .employee-header.employee-header--products-desk,
body.page-stock-dash .employee-header.employee-header--products-desk {
    background: #f8fafc !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 1.25rem !important;
    margin-bottom: 0;
    height: auto !important;
    min-height: 0;
    position: sticky !important;
    top: 0 !important;
    z-index: 1020 !important;
    align-items: stretch !important;
}
body.page-products-desk .employee-header--products-desk::after,
body.page-stock-dash .employee-header--products-desk::after { display: none !important; }
body.page-products-desk .employee-header--products-desk .header-content,
body.page-stock-dash .employee-header--products-desk .header-content {
    display: flex !important;
    flex-wrap: nowrap !important;
    align-items: center !important;
    padding: 0.75rem 0 0.5rem !important;
    min-height: 0;
    width: 100%;
    background: transparent !important;
    gap: 0.5rem 1rem;
}
body.page-products-desk .employee-header--products-desk .employee-header-page-heading {
    margin-left: 0 !important;
    min-width: 0;
    flex: 1 1 auto;
}
body.page-products-desk .employee-header--products-desk .employee-header-page-title {
    font-size: clamp(1.125rem, 2vw, 1.5rem) !important;
    font-weight: 700 !important;
    color: #0f172a !important;
    letter-spacing: -0.02em;
    white-space: nowrap;
}
body.page-products-desk .employee-header--products-desk .header-right.header-actions-tray,
body.page-stock-dash .employee-header--products-desk .header-right.header-actions-tray {
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    margin-left: auto !important;
    flex: 0 0 auto !important;
    gap: 0.5rem !important;
}
main.main-content.products-desk-react-root,
main.main-content.stock-dash-react-root {
    flex: 1 1 auto;
    width: 100% !important;
    max-width: none !important;
    padding: 0 1.25rem 2rem !important;
    overflow: auto !important;
    box-sizing: border-box;
    background: #f8fafc !important;
}
main.main-content.products-desk-react-root #root,
main.main-content.stock-dash-react-root #root {
    width: 100%;
    max-width: none;
    margin: 0;
    min-height: 320px;
    min-width: 0;
}
@media (max-width: 767.98px) {
    body.page-products-desk .employee-header.employee-header--products-desk,
    body.page-stock-dash .employee-header.employee-header--products-desk {
        padding: 0 0.75rem !important;
    }
    main.main-content.products-desk-react-root,
    main.main-content.stock-dash-react-root {
        padding: 0 0.75rem 1.5rem !important;
    }
}
html[data-theme="dark"] body.page-products-desk,
html[data-theme="dark"] body.page-products-desk.dashboard,
html[data-theme="dark"] body.page-products-desk .layout-main-wrapper,
html[data-theme="dark"] body.page-products-desk .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-products-desk main.main-content.products-desk-react-root,
html[data-theme="dark"] body.page-stock-dash,
html[data-theme="dark"] body.page-stock-dash.dashboard,
html[data-theme="dark"] body.page-stock-dash .layout-main-wrapper,
html[data-theme="dark"] body.page-stock-dash .layout-main-wrapper > .flex-grow-1,
html[data-theme="dark"] body.page-stock-dash main.main-content.stock-dash-react-root {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-products-desk .employee-header.employee-header--products-desk,
html[data-theme="dark"] body.page-stock-dash .employee-header.employee-header--products-desk {
    background: #0f172a !important;
}
html[data-theme="dark"] body.page-products-desk .employee-header--products-desk .employee-header-page-title {
    color: #f8fafc !important;
}
</style>
CSS;
    }

    private function productCreateMobileCss(): string
    {
        return <<<'CSS'
<style id="prod-create-mobile-force">
@media (max-width: 900px) {
    body.dashboard .prod-create-shell,
    body.dashboard .prod-create-layout,
    body.dashboard .prod-create-main,
    body.dashboard .prod-create-section,
    body.dashboard .prod-create-row,
    body.dashboard .prod-create-row > * {
        height: auto !important;
        min-height: 0 !important;
        max-height: none !important;
        flex: none !important;
        align-content: start !important;
    }
    body.dashboard .prod-create-row {
        display: block !important;
        margin-top: 0 !important;
        margin-bottom: 16px !important;
    }
    body.dashboard .prod-create-label {
        display: block !important;
        padding: 0 0 4px !important;
        margin: 0 0 4px 0 !important;
    }
    body.dashboard .prod-create-help {
        display: block !important;
        margin: 4px 0 0 0 !important;
    }
    body.dashboard .prod-create-actions {
        display: flex !important;
        flex-direction: column !important;
        gap: 0.65rem !important;
    }
    body.dashboard .prod-create-actions a,
    body.dashboard .prod-create-actions button {
        width: 100% !important;
    }
}
body.page-products-desk a.prod-create-btn-cancel,
body.page-products-desk button.prod-create-btn-save {
    border-radius: 9999px !important;
}
</style>
CSS;
    }
}
