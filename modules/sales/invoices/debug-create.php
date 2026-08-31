<?php
/**
 * Debug blank create.php?module=sales (direct invoice create).
 *
 * Usage (browser, must be logged in):
 *   .../invoices/debug-create.php?module=sales
 *   .../invoices/debug-create.php?module=sales&format=json
 *   .../invoices/debug-create.php?module=sales&probe=create   (include create.php stages only  no full render)
 *   .../invoices/debug-create.php?module=sales&probe=init     (run sales_invoice_create_init_data)
 *   .../invoices/debug-create.php?module=sales&probe=shell    (attempt React shell render  will HTML-exit on success)
 *
 * Remove this file after debugging.
 */

declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
$stages = [];
$fatal = null;
$format = 'html';
$probe = 'all';
$module = 'sales';

set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function () use (&$stages, &$fatal, &$format, $isCli) {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array((int) $err['type'], $fatalTypes, true)) {
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(500);
    }
    $payload = [
        'ok' => false,
        'fatal_shutdown' => $err,
        'stages_completed' => $stages,
        'note' => 'PHP fatal error killed the request (common cause of a blank create.php).',
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Debug create invoice</title>';
    echo '<style>body{font-family:monospace;background:#0f172a;color:#fecaca;padding:1.25rem}pre{white-space:pre-wrap;word-break:break-word;color:#e2e8f0}</style>';
    echo '</head><body><h1>Fatal during debug-create</h1><pre>' . htmlspecialchars((string) $json, ENT_QUOTES, 'UTF-8') . '</pre></body></html>';
});

/**
 * @param array<string, mixed> $info
 */
function debug_create_stage(array &$stages, string $name, bool $ok, array $info = []): void
{
    $stages[] = array_merge(['stage' => $name, 'ok' => $ok], $info);
}

/**
 * @param array<string, mixed> $payload
 */
function debug_create_output(array $payload, string $format, bool $isCli, int $exitCode = 0): void
{
    if ($format === 'json' || ($isCli && $format !== 'html')) {
        if (!$isCli && !headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            if ($exitCode !== 0) {
                http_response_code(500);
            }
        }
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit($exitCode);
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        if ($exitCode !== 0) {
            http_response_code(500);
        }
    }

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $createUrl = (string) ($payload['urls']['create_php'] ?? 'create.php?module=sales');
    $initUrl = (string) ($payload['urls']['create_init_api'] ?? '');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Debug create invoice</title>';
    echo '<style>
body{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:#0f172a;color:#e2e8f0;padding:1.25rem;line-height:1.45}
h1{margin:0 0 .75rem;font-size:1.25rem}
.ok{color:#4ade80}.bad{color:#f87171}
a{color:#38bdf8}
.links{margin:.75rem 0 1rem}
.links a{margin-right:1rem}
pre{white-space:pre-wrap;word-break:break-word;background:#020617;padding:1rem;border-radius:.5rem;border:1px solid #1e293b}
</style></head><body>';
    echo '<h1>Debug invoice <span class="' . (!empty($payload['ok']) ? 'ok">create OK' : 'bad">create FAIL') . '</span></h1>';
    echo '<div class="links">';
    echo '<a href="' . htmlspecialchars($createUrl, ENT_QUOTES, 'UTF-8') . '">Open create.php</a>';
    if ($initUrl !== '') {
        echo '<a href="' . htmlspecialchars($initUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">Open create-init API</a>';
    }
    echo '<a href="?module=sales&format=json">JSON</a>';
    echo '<a href="?module=sales&probe=init">Probe init data</a>';
    echo '</div>';
    echo '<pre>' . htmlspecialchars((string) $json, ENT_QUOTES, 'UTF-8') . '</pre></body></html>';
    exit($exitCode);
}

if (!$isCli) {
    $format = strtolower(trim((string) ($_GET['format'] ?? 'html')));
    $probe = strtolower(trim((string) ($_GET['probe'] ?? 'all')));
    $module = trim((string) ($_GET['module'] ?? 'sales'));
    if ($module === '') {
        $module = 'sales';
    }
} else {
    global $argv;
    $format = in_array('--json', $argv ?? [], true) ? 'json' : 'text';
    $probe = 'all';
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with((string) $arg, '--probe=')) {
            $probe = strtolower(substr((string) $arg, 8));
        }
    }
}

try {
    require_once __DIR__ . '/../../../includes/config.php';
    debug_create_stage($stages, 'config.php', true);

    require_once __DIR__ . '/../../../includes/functions.php';
    debug_create_stage($stages, 'includes/functions.php', true);

    require_once __DIR__ . '/../functions.php';
    debug_create_stage($stages, 'sales/functions.php', true);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    debug_create_stage($stages, 'session_start', true, [
        'session_status' => session_status(),
        'user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'company_id' => (int) ($_SESSION['company_id'] ?? 0),
        'company_slug' => (string) ($_SESSION['company_slug'] ?? ''),
    ]);

    if (!$isCli) {
        requireLogin();
        debug_create_stage($stages, 'requireLogin', true);
    }
} catch (Throwable $e) {
    $fatal = [
        'stage' => 'bootstrap',
        'message' => $e->getMessage(),
        'type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => array_slice(array_map(static function ($frame) {
            return [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
            ];
        }, $e->getTrace()), 0, 12),
    ];
    debug_create_output([
        'ok' => false,
        'fatal' => $fatal,
        'stages' => $stages,
        'php' => [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'display_errors' => ini_get('display_errors'),
            'error_reporting' => error_reporting(),
            'memory_limit' => ini_get('memory_limit'),
        ],
    ], $format, $isCli, 1);
}

global $pdo;
$companyId = (int) (currentCompanyId() ?? 0);
$salesDb = function_exists('sales_pdo') ? sales_pdo() : $pdo;
$predefinedType = strtolower(trim((string) ($_GET['type'] ?? 'spare')));
if (!in_array($predefinedType, ['truck', 'spare'], true)) {
    $predefinedType = 'spare';
}

$report = [
    'ok' => false,
    'probe' => $probe,
    'module' => $module,
    'type' => $predefinedType,
    'fatal' => null,
    'stages' => &$stages,
    'session' => [
        'user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'company_id' => (int) ($_SESSION['company_id'] ?? 0),
        'company_slug' => (string) ($_SESSION['company_slug'] ?? ''),
    ],
    'company_id_resolved' => $companyId,
    'flags' => [],
    'files' => [],
    'react_assets' => null,
    'urls' => [],
    'data_counts' => [],
    'blockers' => [],
    'init_probe' => null,
    'php' => [
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'display_errors' => ini_get('display_errors'),
        'error_reporting' => error_reporting(),
        'memory_limit' => ini_get('memory_limit'),
        'headers_sent' => headers_sent(),
        'output_buffering' => ini_get('output_buffering'),
        'ob_level' => ob_get_level(),
    ],
];

try {
    $report['flags'] = [
        'isRoadmaster' => function_exists('isRoadmaster') ? (bool) isRoadmaster() : null,
        'isUltimate' => function_exists('isUltimate') ? (bool) isUltimate() : null,
        'salesSupportsTruckInvoices' => function_exists('salesSupportsTruckInvoices') ? (bool) salesSupportsTruckInvoices() : null,
        'salesInvoiceCreateUsesReactShell' => function_exists('salesInvoiceCreateUsesReactShell')
            ? (bool) salesInvoiceCreateUsesReactShell($predefinedType)
            : null,
        'salesDocumentCreateRenderReactShell_exists' => function_exists('salesDocumentCreateRenderReactShell'),
        'sales_invoice_create_init_data_exists' => function_exists('sales_invoice_create_init_data'),
        'sales_pdo_exists' => function_exists('sales_pdo'),
        'sales_db_is_pdo' => $salesDb instanceof PDO,
        'same_pdo_as_global' => ($pdo instanceof PDO && $salesDb instanceof PDO && $pdo === $salesDb),
    ];

    $paths = [
        'create.php' => __DIR__ . '/create.php',
        'revenue_ledger.php' => __DIR__ . '/../../../includes/revenue_ledger.php',
        'bot_exchange_rates.php' => __DIR__ . '/../../../includes/bot_exchange_rates.php',
        'invoices-lib.php' => __DIR__ . '/includes/invoices-lib.php',
        'invoices-react-shell.php' => __DIR__ . '/includes/invoices-react-shell.php',
        'create-invoice-view.php' => __DIR__ . '/partials/create-invoice-view.php',
        'invoice-direct-create.php' => __DIR__ . '/includes/invoice-direct-create.php',
        'create-init.php' => __DIR__ . '/api/create-init.php',
        'header_employee.php' => __DIR__ . '/../../../includes/header_employee.php',
        'frontend/dist/index.html' => __DIR__ . '/frontend/dist/index.html',
    ];
    foreach ($paths as $label => $path) {
        $report['files'][$label] = [
            'path' => $path,
            'exists' => is_file($path),
            'readable' => is_file($path) && is_readable($path),
            'bytes' => is_file($path) ? (int) filesize($path) : 0,
        ];
    }

    // Mirror create.php: revenue_ledger is required before the form.
    $ledgerPath = __DIR__ . '/../../../includes/revenue_ledger.php';
    if (!is_file($ledgerPath)) {
        $report['blockers'][] = 'Missing includes/revenue_ledger.php (create.php requires it).';
        debug_create_stage($stages, 'revenue_ledger.php', false, ['exists' => false]);
    } else {
        require_once $ledgerPath;
        debug_create_stage($stages, 'revenue_ledger.php', true);
    }

    if (is_file(__DIR__ . '/../../../includes/bot_exchange_rates.php')) {
        require_once __DIR__ . '/../../../includes/bot_exchange_rates.php';
        debug_create_stage($stages, 'bot_exchange_rates.php', true);
    } else {
        debug_create_stage($stages, 'bot_exchange_rates.php', true, ['skipped' => true]);
    }

    require_once __DIR__ . '/includes/invoices-lib.php';
    debug_create_stage($stages, 'invoices-lib.php', true, [
        'salesDocumentCreateRenderReactShell' => function_exists('salesDocumentCreateRenderReactShell'),
        'invoicesDeskLoadReactAssets' => function_exists('invoicesDeskLoadReactAssets'),
        'sales_invoice_create_init_data' => function_exists('sales_invoice_create_init_data'),
    ]);

    $assets = function_exists('invoicesDeskModuleAssetUrls')
        ? invoicesDeskModuleAssetUrls()
        : (function_exists('invoicesDeskLoadReactAssets') ? invoicesDeskLoadReactAssets() : null);

    $companySlug = strtolower(trim((string) ($_SESSION['company_slug'] ?? '')));
    if ($companySlug === '' && function_exists('getRequestedCompanySlug')) {
        $companySlug = strtolower(trim((string) getRequestedCompanySlug()));
    }

    if ($assets === null) {
        $report['react_assets'] = [
            'loaded' => false,
            'reason' => 'dist/index.html missing or JS/CSS asset filenames not parsed',
            'dist_index_exists' => is_file(__DIR__ . '/frontend/dist/index.html'),
        ];
        if (!empty($report['flags']['salesInvoiceCreateUsesReactShell'])) {
            $report['blockers'][] = 'React shell is enabled but dist assets failed to load (create.php falls through to PHP partial).';
        }
        debug_create_stage($stages, 'react_assets', false, $report['react_assets']);
    } else {
        $cssPath = __DIR__ . '/frontend/dist/assets/' . $assets['cssFile'];
        $jsPath = __DIR__ . '/frontend/dist/assets/' . $assets['jsFile'];
        $apiUrl = (string) ($assets['apiUrl'] ?? '');
        $apiHasSlug = $companySlug !== '' && str_contains($apiUrl, '/' . $companySlug . '/');
        $report['react_assets'] = [
            'loaded' => true,
            'assetBase' => $assets['assetBase'],
            'apiUrl' => $apiUrl,
            'api_url_includes_company_slug' => $companySlug === '' ? null : $apiHasSlug,
            'company_slug' => $companySlug,
            'cssFile' => $assets['cssFile'],
            'jsFile' => $assets['jsFile'],
            'css_exists' => is_file($cssPath),
            'js_exists' => is_file($jsPath),
            'cssVersion' => $assets['cssVersion'],
            'jsVersion' => $assets['jsVersion'],
        ];
        if (!is_file($cssPath) || !is_file($jsPath)) {
            $report['blockers'][] = 'Asset filenames parsed but file(s) missing on disk under frontend/dist/assets/.';
        }
        if ($companySlug !== '' && !$apiHasSlug) {
            $report['blockers'][] = 'API base "' . $apiUrl . '" is missing /' . $companySlug . '/  React create-init will hit login HTML and the page looks blank.';
        }
        debug_create_stage($stages, 'react_assets', is_file($cssPath) && is_file($jsPath) && ($companySlug === '' || $apiHasSlug), $report['react_assets']);
    }

    // Lightweight product/customer counts (same queries create.php uses).
    $productCount = null;
    $customerCount = null;
    $productError = null;
    $customerError = null;
    if ($salesDb instanceof PDO) {
        try {
            $productCount = (int) $salesDb->query('SELECT COUNT(*) FROM products')->fetchColumn();
        } catch (Throwable $e) {
            $productError = $e->getMessage();
        }
        try {
            $customerCount = (int) $salesDb->query("SELECT COUNT(*) FROM customers WHERE status = 'active'")->fetchColumn();
        } catch (Throwable $e) {
            $customerError = $e->getMessage();
        }
    } else {
        $report['blockers'][] = 'Sales PDO connection unavailable.';
    }
    $report['data_counts'] = [
        'products' => $productCount,
        'products_error' => $productError,
        'active_customers' => $customerCount,
        'customers_error' => $customerError,
    ];
    debug_create_stage($stages, 'data_counts', $productError === null && $customerError === null, $report['data_counts']);

    $nextInvoiceNumber = null;
    $nextInvoiceNumberError = null;
    if ($salesDb instanceof PDO && function_exists('sales_next_invoice_number')) {
        try {
            $nextInvoiceNumber = sales_next_invoice_number($salesDb, $companyId);
        } catch (Throwable $e) {
            $nextInvoiceNumberError = $e->getMessage();
            $report['blockers'][] = 'sales_next_invoice_number failed: ' . $e->getMessage();
        }
    }
    $report['next_invoice_number'] = $nextInvoiceNumber;
    $report['next_invoice_number_error'] = $nextInvoiceNumberError;
    debug_create_stage($stages, 'next_invoice_number', $nextInvoiceNumberError === null, [
        'value' => $nextInvoiceNumber,
        'error' => $nextInvoiceNumberError,
    ]);

    $pageTitle = function_exists('salesInvoiceCreatePageTitle')
        ? salesInvoiceCreatePageTitle($predefinedType)
        : 'Create Invoice';

    $createUrl = function_exists('sales_module_url')
        ? sales_module_url('invoices/create.php', ['module' => $module, 'type' => $predefinedType])
        : ('create.php?module=' . rawurlencode($module) . '&type=' . rawurlencode($predefinedType));
    $initApiUrl = function_exists('sales_module_url')
        ? sales_module_url('invoices/api/create-init.php', ['module' => $module])
        : ('api/create-init.php?module=' . rawurlencode($module));
    $report['urls'] = [
        'create_php' => $createUrl,
        'create_init_api' => $initApiUrl,
        'this_debug' => function_exists('sales_module_url')
            ? sales_module_url('invoices/debug-create.php', ['module' => $module])
            : ('debug-create.php?module=' . rawurlencode($module)),
    ];

    // Probe create-init payload (what the React app fetches).
    if ($probe === 'all' || $probe === 'init') {
        try {
            $initData = sales_invoice_create_init_data();
            $report['init_probe'] = [
                'ok' => true,
                'keys' => is_array($initData) ? array_keys($initData) : [],
                'product_count' => isset($initData['products']) && is_array($initData['products']) ? count($initData['products']) : null,
                'customer_count' => isset($initData['customers']) && is_array($initData['customers']) ? count($initData['customers']) : null,
                'page_title' => $initData['page_title'] ?? ($initData['title'] ?? null),
                'error' => $initData['error'] ?? null,
            ];
            debug_create_stage($stages, 'sales_invoice_create_init_data', true, $report['init_probe']);
        } catch (Throwable $e) {
            $report['init_probe'] = [
                'ok' => false,
                'message' => $e->getMessage(),
                'type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ];
            $report['blockers'][] = 'sales_invoice_create_init_data() threw: ' . $e->getMessage();
            debug_create_stage($stages, 'sales_invoice_create_init_data', false, $report['init_probe']);
        }
    }

    // Optional: actually render React shell (exits with HTML on success).
    if ($probe === 'shell') {
        if (empty($report['flags']['salesInvoiceCreateUsesReactShell'])) {
            $report['blockers'][] = 'React shell flag is false; shell probe skipped.';
        } elseif ($assets === null) {
            $report['blockers'][] = 'Cannot probe shell: assets null.';
        } else {
            debug_create_stage($stages, 'probe_shell_start', true, ['page_title' => $pageTitle]);
            // Flush JSON/HTML report markers before shell takes over.
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
                header('X-Debug-Create-Probe: shell');
            }
            echo '<!-- debug-create probe=shell starting salesDocumentCreateRenderReactShell -->';
            salesDocumentCreateRenderReactShell($pageTitle);
            // If we get here, shell returned false instead of exiting.
            $report['blockers'][] = 'salesDocumentCreateRenderReactShell() returned without exiting (assets likely null at render time).';
            debug_create_stage($stages, 'probe_shell', false, ['returned' => true]);
        }
    }

    // Simulate create.php decision tree (no full page render).
    if ($probe === 'all' || $probe === 'create') {
        $useReact = !empty($report['flags']['salesInvoiceCreateUsesReactShell']);
        $decision = [
            'use_react_shell' => $useReact,
            'would_exit_via_react_shell' => $useReact && $assets !== null,
            'would_fall_through_to_php_partial' => !$useReact || $assets === null,
            'php_partial_exists' => is_file(__DIR__ . '/partials/create-invoice-view.php'),
            'header_employee_exists' => is_file(__DIR__ . '/../../../includes/header_employee.php'),
            'page_title' => $pageTitle,
        ];
        $report['create_php_decision'] = $decision;
        if ($useReact && $assets !== null && !is_file(__DIR__ . '/../../../includes/header_employee.php')) {
            $report['blockers'][] = 'React shell includes header_employee.php which is missing — blank/fatal page.';
        }
        debug_create_stage($stages, 'create_php_decision', true, $decision);
    }

    // Capture shell HTML without exiting the full page (for blank-page diagnosis).
    if ($probe === 'shell-html' || $probe === 'all') {
        $shellProbe = [
            'attempted' => false,
            'ok' => false,
            'html_bytes' => 0,
            'has_doctype' => false,
            'has_root' => false,
            'has_module_script' => false,
            'api_base_in_html' => null,
            'js_src' => null,
            'css_href' => null,
            'error' => null,
            'snippet_head' => null,
            'snippet_tail' => null,
        ];
        if ($assets === null || empty($report['flags']['salesInvoiceCreateUsesReactShell'])) {
            $shellProbe['error'] = 'Skipped (no assets or React shell disabled).';
        } else {
            $shellProbe['attempted'] = true;
            try {
                ob_start();
                $page_title = $pageTitle;
                $employeeHeaderTitle = $pageTitle;
                $hideHeaderCompanyBranding = true;
                $employeeHeaderExtraClass = 'employee-header--inv-desk';
                $bodyExtraClass = 'page-inv-desk';
                $invoicesPage = 'create';
                $invoicesHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
                    . "\n" . '<script>window.__INVOICES_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
                    . 'window.__INVOICES_PAGE__ = "create";</script>';
                require __DIR__ . '/includes/invoices-react-shell.php';
                $html = (string) ob_get_clean();
                $shellProbe['html_bytes'] = strlen($html);
                $shellProbe['has_doctype'] = stripos($html, '<!DOCTYPE') !== false;
                $shellProbe['has_root'] = str_contains($html, 'id="root"');
                $shellProbe['has_module_script'] = str_contains($html, 'type="module"');
                if (preg_match('/__INVOICES_API_BASE__\s*=\s*"([^"]*)"/', $html, $m)) {
                    $shellProbe['api_base_in_html'] = $m[1];
                }
                if (preg_match('/<script type="module"[^>]*src="([^"]+)"/', $html, $m)) {
                    $shellProbe['js_src'] = $m[1];
                }
                if (preg_match('/<link rel="stylesheet" crossorigin href="([^"]+)"/', $html, $m)) {
                    $shellProbe['css_href'] = $m[1];
                }
                $shellProbe['snippet_head'] = substr($html, 0, 400);
                $shellProbe['snippet_tail'] = substr($html, -400);
                $shellProbe['ok'] = $shellProbe['has_doctype'] && $shellProbe['has_root'] && $shellProbe['has_module_script'];
                if (!$shellProbe['ok']) {
                    $report['blockers'][] = 'React shell HTML incomplete (missing doctype/root/module script).';
                }
            } catch (Throwable $e) {
                if (ob_get_level() > 0) {
                    ob_end_clean();
                }
                $shellProbe['error'] = [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
                $report['blockers'][] = 'Shell HTML render threw: ' . $e->getMessage();
            }
        }
        $report['shell_html_probe'] = $shellProbe;
        debug_create_stage($stages, 'shell_html_probe', !empty($shellProbe['ok']), $shellProbe);
    }

    $report['ok'] = $report['blockers'] === [] && $fatal === null;
    $report['hint'] = $report['ok']
        ? 'Bootstrap and assets look fine. If create.php is still blank, open it and wait 4s for the yellow mount fallback, or check browser console / Network for the JS module and create-init.php.'
        : 'Fix blockers below. A blank page usually means a PHP fatal (see stages / shutdown) or React shell HTML with a JS crash.';
} catch (Throwable $e) {
    $report['ok'] = false;
    $report['fatal'] = [
        'message' => $e->getMessage(),
        'type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => array_slice(array_map(static function ($frame) {
            return [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
            ];
        }, $e->getTrace()), 0, 15),
    ];
    debug_create_stage($stages, 'uncaught', false, $report['fatal']);
}

debug_create_output($report, $format, $isCli, $report['ok'] ? 0 : 1);
