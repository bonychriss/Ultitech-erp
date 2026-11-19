<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ERP ↔ Accounting SSO bridge
    |--------------------------------------------------------------------------
    | Shared secret must match ERP $ULTITECH_ACCOUNTING_BRIDGE_SECRET.
    | Accounting data stays in this app's DB; ERP accounting is never written.
    */
    'enabled' => (bool) env('ERP_BRIDGE_ENABLED', true),
    'secret' => (string) env('ERP_BRIDGE_SECRET', ''),
    'max_age_seconds' => (int) env('ERP_BRIDGE_MAX_AGE', 180),
    'default_role_code' => (string) env('ERP_BRIDGE_ROLE', 'admin'),
];
