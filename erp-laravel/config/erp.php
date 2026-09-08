<?php

declare(strict_types=1);

/**
 * ERP platform config for erp-laravel (module-by-module migration).
 */
return [
    'app_root' => dirname(__DIR__, 2),

    'modules' => [
        'home' => [
            'enabled' => true,
            'legacy_api' => false,
            'entry' => 'index.php',
        ],
        'trial' => [
            'enabled' => true,
            'legacy_api' => false,
            'entry' => 'free-trial.php',
        ],
        'sales' => [
            'enabled' => true,
            'legacy_api' => true, // Phase 1: include modules/sales/**/api/*.php
            'entry' => 'sales.php',
        ],
        'suggest' => [
            'enabled' => true,
            'legacy_api' => false,
            'entry' => 'suggest.php',
        ],
        'stock' => [
            'enabled' => true,
            'legacy_api' => true, // Phase 1: stock/dashboard.php via LegacyDashboardBridge
            'entry' => 'stock.php',
        ],
    ],

    'react_dist' => [
        'home' => 'home-ui/frontend/dist',
        'trial' => 'login-ui/frontend/dist',
        'sales_dashboard' => 'modules/sales/dashboard/frontend/dist',
        'sales_invoices' => 'modules/sales/invoices/frontend/dist',
        'sales_orders' => 'modules/sales/orders/frontend/dist',
        'sales_customers' => 'modules/sales/customers/frontend/dist',
        'sales_my_sales' => 'modules/sales/my-sales/frontend/dist',
        'sales_pricelist' => 'modules/sales/pricelist/frontend/dist',
        'sales_settings' => 'modules/sales/settings/frontend/dist',
        'suggest' => 'suggest-laravel/frontend/dist',
        'stock' => 'stock/stock-ui/dist',
    ],

    'sales_laravel_desks' => [
        'invoices',
        'orders',
        'quotations',
        'customers',
        'my-sales',
        'pricelist',
        'settings',
        'quote-create',
        'invoice-create',
        'order-view',
        'invoice-view',
        'quote-edit',
    ],
];
