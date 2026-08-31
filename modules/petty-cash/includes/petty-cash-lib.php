<?php

declare(strict_types=1);

function pettyCashDeskBootstrap(): PDO
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 3) . '/includes/functions.php';
        require_once dirname(__DIR__, 3) . '/erp/petty-cash/includes/petty_cash_functions.php';
        require_once __DIR__ . '/balances_integration.php';
        $booted = true;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }

    // Keep the active company/tenant PDO for vouchers & replenishments.
    // Balances lookups use petty_cash_sync_balances_pdo() only where needed.
    petty_cash_balances_bootstrap();
    ensurePettyCashSchema();
    petty_cash_module_ensure_schema($pdo);

    return $pdo;
}

function pettyCashDeskRequireAccess(): void
{
    pettyCashDeskBootstrap();
    requireLogin();

    if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
        $_GET['module'] = 'petty_cash';
    }
    $_SESSION['active_module'] = 'petty_cash';
}

function pettyCashDeskWebBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $marker = '/modules/petty-cash';
    $pos = strpos($script, $marker);
    if ($pos !== false) {
        return substr($script, 0, $pos + strlen($marker));
    }
    if ($script !== '') {
        return rtrim(dirname($script), '/');
    }

    if (function_exists('app_url')) {
        return app_url('/modules/petty-cash');
    }

    return rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
}

function pettyCashDeskPublicUrl(string $relativePath, array $query = []): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $base = pettyCashDeskWebBasePath();
    $url = $base . '/' . $relativePath;
    if ($query !== []) {
        $qs = http_build_query($query);
        $url .= (str_contains($url, '?') ? '&' : '?') . $qs;
    }

    return $url;
}

/**
 * Active company slug for tenant API/page URLs.
 */
function pettyCashDeskCompanySlug(): string
{
    $slug = '';
    if (function_exists('getRequestedCompanySlug')) {
        $slug = strtolower(trim((string) getRequestedCompanySlug()));
    }
    if ($slug === '' && !empty($_SESSION['company_slug'])) {
        $slug = strtolower(trim((string) $_SESSION['company_slug']));
    }
    if ($slug === '' && !empty($_GET['company_slug'])) {
        $slug = strtolower(trim((string) $_GET['company_slug']));
    }

    return preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) ? $slug : '';
}

function pettyCashModuleUrl(string $script, array $query = []): string
{
    if (!isset($query['module'])) {
        $query['module'] = 'petty_cash';
    }
    $slug = pettyCashDeskCompanySlug();
    if ($slug !== '' && !isset($query['company_slug'])) {
        $query['company_slug'] = $slug;
    }

    return pettyCashDeskPublicUrl($script, $query);
}

/**
 * @return array{distHtml:string,assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function pettyCashDeskLoadReactAssets(): ?array
{
    $uiDir = dirname(__DIR__) . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '' || $cssFile === '') {
        return null;
    }

    $assetBase = pettyCashDeskPublicUrl('frontend/dist/assets/');
    $apiUrl = pettyCashDeskPublicUrl('api');
    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'distHtml' => $distHtml,
        'assetBase' => $assetBase,
        'apiUrl' => $apiUrl,
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
        'company_slug' => pettyCashDeskCompanySlug(),
    ];
}

function pettyCashDeskShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 3) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    // Explicit system font (do not rely only on style.css @import — mobile Safari is unreliable there).
    if (function_exists('erp_get_system_font_assets_html')) {
        $fontHtml = trim(erp_get_system_font_assets_html());
        if ($fontHtml !== '') {
            $parts[] = $fontHtml;
        }
    } elseif (function_exists('erp_system_font_css_url')) {
        $parts[] = '<link rel="stylesheet" id="erp-system-font" href="'
            . htmlspecialchars(erp_system_font_css_url(), ENT_QUOTES, 'UTF-8') . '">';
    }

    return implode("\n    ", $parts);
}

/**
 * @return array<string, mixed>
 */
function pettyCashDeskScope(): array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $canManage = function_exists('pettyCashCanManage') && pettyCashCanManage();

    return [
        'user_id' => $userId,
        'can_manage' => $canManage,
        'custodian_id' => $canManage ? null : $userId,
    ];
}

/**
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function pettyCashDeskParseVoucherFilters(array $query): array
{
    $scope = pettyCashDeskScope();
    $filters = [
        'exclude_cancelled' => empty($query['include_cancelled']),
        'search' => trim((string) ($query['search'] ?? '')),
        'status' => trim((string) ($query['status'] ?? '')),
        'category' => trim((string) ($query['category'] ?? '')),
        'date_from' => trim((string) ($query['date_from'] ?? '')),
        'date_to' => trim((string) ($query['date_to'] ?? '')),
    ];

    if ($scope['custodian_id']) {
        $filters['custodian_id'] = (int) $scope['custodian_id'];
    }

    return $filters;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function pettyCashDeskFormatVoucherRows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'voucher_number' => (string) ($row['voucher_number'] ?? ''),
            'date' => (string) ($row['date'] ?? ''),
            'category' => (string) ($row['category'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'is_posted' => (int) ($row['is_posted'] ?? 0),
            'custodian_id' => (int) ($row['custodian_id'] ?? 0),
            'custodian_name' => (string) ($row['custodian_name'] ?? ''),
            'created_by_name' => (string) ($row['created_by_name'] ?? ''),
            'approved_by_name' => (string) ($row['approved_by_name'] ?? ''),
            'has_receipt' => trim((string) ($row['receipt_path'] ?? '')) !== '',
            'view_url' => pettyCashModuleUrl('view-voucher.php', ['id' => (int) ($row['id'] ?? 0)]),
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function pettyCashDeskFormatReplenishmentRows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $out[] = [
            'id' => $id,
            'replenishment_number' => (string) ($row['replenishment_number'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'custodian_id' => (int) ($row['custodian_id'] ?? 0),
            'custodian_name' => (string) ($row['custodian_name'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'approved_at' => (string) ($row['approved_at'] ?? ''),
            'petty_cash_account_name' => (string) ($row['petty_cash_account_name'] ?? ''),
            'source_account_name' => (string) ($row['source_account_name'] ?? ''),
            'confirm_url' => pettyCashModuleUrl('replenishments/confirm-approve.php', ['rep_id' => $id]),
            'view_url' => pettyCashModuleUrl('replenishments/confirm-approve.php', ['rep_id' => $id, 'view' => '1']),
        ];
    }

    return $out;
}

function pettyCashUploadDir(): string
{
    return dirname(__DIR__, 3) . '/assets/uploads/petty-cash/';
}

/**
 * @param array<string, mixed> $cfg Passed to window.__PETTY_CASH_CFG__
 */
function pettyCashRenderReactPage(string $reactPage, string $pageTitle, array $cfg = []): void
{
    pettyCashDeskRequireAccess();

    $assets = pettyCashDeskLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>' . htmlspecialchars($pageTitle) . '</title></head><body style="font-family:var(--erp-font-family, inherit);padding:2rem;">';
        echo '<h1>' . htmlspecialchars($pageTitle) . '</h1>';
        echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/petty-cash/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = $pageTitle;
    $employeeHeaderTitle = $pageTitle;
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';
    $bodyExtraClass = 'page-exp-desk exp-dashboard-page';

    $windowCfg = array_merge([
        'page' => $reactPage,
        'module_base' => pettyCashDeskWebBasePath(),
        'company_slug' => pettyCashDeskCompanySlug(),
    ], $cfg);
    $pettyCashHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__PETTY_CASH_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__PETTY_CASH_PAGE__ = ' . json_encode($reactPage, JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__PETTY_CASH_CFG__ = ' . json_encode($windowCfg, JSON_UNESCAPED_SLASHES) . ';</script>';
    ob_start();
    require dirname(__DIR__, 3) . '/includes/nav-back-script.php';
    $pettyCashHeadMarkup .= "\n" . (string) ob_get_clean();

    require dirname(__DIR__) . '/includes/petty-cash-react-shell.php';
    exit;
}
