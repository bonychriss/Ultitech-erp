<?php

return [
    'enabled' => (bool) env('ERP_SYNC_ENABLED', false),
    'connection' => env('ERP_CONNECTION', 'erp_ultimate'),
    'journal_limit' => (int) env('ERP_SYNC_JOURNAL_LIMIT', 50),
    'default_database' => env('ERP_DB_DATABASE', 'new_trading_voucher-35313030c7e2'),
    'public_root' => env('ERP_PUBLIC_ROOT', dirname(base_path(), 2)),
    'public_url' => env('ERP_PUBLIC_URL', ''),
];
