<?php

namespace App\Domains\Stock;

/**
 * Stock desk registry — all user-facing pages routed via erp-laravel.
 * Phase 1: legacy PHP include (LegacyDeskBridge). Blade shells can replace later.
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
}
