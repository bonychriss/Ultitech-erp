<?php

declare(strict_types=1);

function crmDeskBootstrap(): PDO
{
    static $booted = false;
    if (!$booted) {
        require_once dirname(__DIR__, 3) . '/includes/functions.php';
        require_once __DIR__ . '/crm-engine.php';
        $booted = true;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection is not available.');
    }

    crmEngineEnsureSchema($pdo);

    return $pdo;
}

function crmDeskRequireAccess(): void
{
    crmDeskBootstrap();
    requireLogin();

    if (!isset($_GET['module']) || (string) $_GET['module'] === '') {
        $_GET['module'] = 'crm';
    }
    $_SESSION['active_module'] = 'crm';
}

function crmDeskWebBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#^(.*?/modules/crm)(?:/|$)#i', $script, $m)) {
        return rtrim($m[1], '/');
    }

    if (function_exists('app_url')) {
        return rtrim((string) app_url('/modules/crm'), '/');
    }

    return '/modules/crm';
}

function crmDeskPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

    return crmDeskWebBasePath() . '/' . $relativePath;
}

function crmDeskAppAssetUrl(string $relativePath): string
{
    $relativePath = '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
    if (function_exists('app_url')) {
        return rtrim((string) app_url($relativePath), '/');
    }

    return $relativePath;
}

function crmDeskParseContactId(array $query = []): int
{
    $id = $query['id'] ?? null;
    if (is_scalar($id) && ctype_digit((string) $id)) {
        return (int) $id;
    }

    return 0;
}

function crmDeskContactViewUrl(int $contactId): string
{
    return crmDeskPublicUrl('my-clients/view.php') . '?module=crm&id=' . max(0, $contactId);
}

function crmDeskDashboardUrl(): string
{
    return crmDeskPublicUrl('my-clients/index.php') . '?module=crm&tab=dashboard';
}

function crmDeskCustomersListUrl(): string
{
    return crmDeskPublicUrl('my-clients/index.php') . '?module=crm&tab=customers';
}

function crmDeskProspectsListUrl(): string
{
    return crmDeskPublicUrl('my-clients/index.php') . '?module=crm&tab=prospects';
}

function crmDeskMarketUrl(): string
{
    return crmDeskPublicUrl('market/index.php') . '?module=crm';
}

function crmDeskMyClientsBaseUrl(): string
{
    return crmDeskDashboardUrl();
}

/**
 * @return array{assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function crmDeskLoadReactAssets(): ?array
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

    $assetBase = crmDeskPublicUrl('frontend/dist/assets/');
    $apiUrl = crmDeskPublicUrl('api/index.php');
    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => $assetBase,
        'apiUrl' => $apiUrl,
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

function crmDeskShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 3) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('renderSystemFontHeadMarkup')) {
            ob_start();
            renderSystemFontHeadMarkup();
            $fontMarkup = ob_get_clean();
            if (is_string($fontMarkup) && $fontMarkup !== '') {
                $parts[] = trim($fontMarkup);
            }
        }
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    $dashCssPath = dirname(__DIR__, 2) . '/sales/dashboard/dashboard.css';
    if (is_file($dashCssPath)) {
        $dashCssVer = (int) filemtime($dashCssPath);
        $dashCssUrl = function_exists('app_url')
            ? app_url('/modules/sales/dashboard/dashboard.css') . '?v=' . $dashCssVer
            : '/modules/sales/dashboard/dashboard.css?v=' . $dashCssVer;
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars($dashCssUrl, ENT_QUOTES, 'UTF-8') . '">';
    }

    return implode("\n    ", $parts);
}

/**
 * @param array<string, mixed> $bootPayload
 */
function crmDeskRenderReactShell(array $bootPayload, string $pageTitle, string $headerTitle): void
{
    $assets = crmDeskLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>CRM</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>CRM</h1>';
        echo '<p>The React UI has not been built yet. Run <code>npm install</code> and <code>npm run build</code> inside <code>modules/crm/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    if (!isset($bootPayload['ui_font']) || !is_array($bootPayload['ui_font'])) {
        $bootPayload['ui_font'] = crmDeskUiFontPayload();
    }

    $page_title = $pageTitle;
    $employeeHeaderTitle = $headerTitle;
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--crm-desk';

    $crmHeadMarkup = '<link rel="stylesheet" crossorigin href="' . htmlspecialchars($assets['assetBase'] . $assets['cssFile'] . '?v=' . $assets['cssVersion'], ENT_QUOTES, 'UTF-8') . '">'
        . "\n" . '<script>window.__CRM_API_BASE__ = ' . json_encode($assets['apiUrl'], JSON_UNESCAPED_SLASHES) . ';'
        . 'window.__CRM_BOOT__ = ' . json_encode($bootPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';</script>';

    require __DIR__ . '/crm-react-shell.php';
    exit;
}

/**
 * Personalization font for CRM Market embeds (Client Market iframe).
 *
 * @return array{key:string,label:string,stack:string,google:?string,local_css:?string,css_url:?string}
 */
function crmDeskUiFontPayload(): array
{
    $key = function_exists('getEffectiveFontKey') ? (string) getEffectiveFontKey() : 'poppins';
    $def = function_exists('getSystemFontDefinition') ? getSystemFontDefinition($key) : null;
    $stack = is_array($def) && !empty($def['stack'])
        ? (string) $def['stack']
        : "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
    $google = is_array($def) && !empty($def['google']) ? (string) $def['google'] : null;
    $localCss = null;
    if (is_array($def) && !empty($def['local_css']) && function_exists('app_url')) {
        $localCss = app_url((string) $def['local_css']);
    }
    $cssUrl = function_exists('erp_system_font_css_url') ? erp_system_font_css_url() : null;

    return [
        'key' => $key,
        'label' => is_array($def) ? (string) ($def['label'] ?? $key) : $key,
        'stack' => $stack,
        'google' => $google,
        'local_css' => $localCss,
        'css_url' => $cssUrl,
    ];
}

function crmDeskScopeUserId(): ?int
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);

    return $userId > 0 ? $userId : null;
}

/**
 * @return array{id:int,name:string,username:string}
 */
function crmDeskCurrentUser(): array
{
    $name = trim((string) ($_SESSION['full_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($_SESSION['username'] ?? 'User'));
    }

    return [
        'id' => (int) ($_SESSION['user_id'] ?? 0),
        'name' => $name !== '' ? $name : 'User',
        'username' => (string) ($_SESSION['username'] ?? ''),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function crmDeskListContacts(
    PDO $pdo,
    int $companyId,
    ?string $search = null,
    ?string $status = null,
    ?int $userId = null
): array {
    $userId = $userId ?? crmDeskScopeUserId();

    if ($userId !== null && $userId > 0) {
        crmSalesBridgeEnsureUserContactsSynced($pdo, $companyId, $userId);
        $customerIds = crmSalesBridgeFetchUserCustomerIds($userId);
        $contacts = crmEngineListContacts($pdo, $companyId, $search, $status, $userId, $customerIds);
    } else {
        crmSalesBridgeEnsureAllContactsSynced($pdo, $companyId);
        $contacts = crmEngineListContacts($pdo, $companyId, $search, $status, null, null);
    }

    $contacts = crmSalesBridgeAttachInvoiceTotals($contacts);
    return crmDeskDecorateAssignedContacts($contacts);
}

function crmDeskDecorateAssignedContacts(array $contacts): array
{
    $viewer = (int) (crmDeskScopeUserId() ?? 0);
    foreach ($contacts as &$contact) {
        $owner = (int) ($contact['created_by'] ?? 0);
        $contact['assigned_to_me'] = $viewer > 0 && $owner === $viewer;
        $contact['assigned_user_id'] = $owner;
    }
    unset($contact);
    usort($contacts, static function (array $a, array $b): int {
        $am = !empty($a['assigned_to_me']) ? 0 : 1;
        $bm = !empty($b['assigned_to_me']) ? 0 : 1;
        if ($am !== $bm) {
            return $am <=> $bm;
        }
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });
    return $contacts;
}

/**
 * @return array<string, int>
 */
function crmDeskStats(PDO $pdo, int $companyId, ?int $userId = null): array
{
    $userId = $userId ?? crmDeskScopeUserId();

    if ($userId !== null && $userId > 0) {
        crmSalesBridgeEnsureUserContactsSynced($pdo, $companyId, $userId);
        $customerIds = crmSalesBridgeFetchUserCustomerIds($userId);

        return crmEngineStats($pdo, $companyId, $userId, $customerIds);
    }

    crmSalesBridgeEnsureAllContactsSynced($pdo, $companyId);

    return crmEngineStats($pdo, $companyId, null, null);
}

function crmDeskFetchPayload(PDO $pdo): array
{
    require_once __DIR__ . '/crm-sales-bridge.php';
    crmSalesBridgeLoadDeps();

    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : (int) ($_SESSION['company_id'] ?? 0);
    $company = function_exists('getCurrentCompany') ? (getCurrentCompany() ?: []) : [];

    return [
        'company' => [
            'id' => $companyId,
            'name' => (string) ($company['company_name'] ?? ($_SESSION['company_name'] ?? 'Company')),
            'slug' => (string) ($company['company_slug'] ?? ($_SESSION['company_slug'] ?? '')),
        ],
        'user' => crmDeskCurrentUser(),
        'stats' => crmDeskStats($pdo, $companyId),
        'contacts' => crmDeskListContacts($pdo, $companyId),
        'defaults' => crmSalesBridgeCustomerFormDefaults(),
        'options' => customerDeskFormOptions(),
        'statuses' => crmSalesBridgeContactStatuses(),
        'links' => [
            'page' => crmDeskDashboardUrl(),
            'dashboard' => crmDeskDashboardUrl(),
            'customersList' => crmDeskCustomersListUrl(),
            'prospectsList' => crmDeskProspectsListUrl(),
            'market' => crmDeskMarketUrl(),
            'contactViewBase' => crmDeskPublicUrl('my-clients/view.php') . '?module=crm',
            'api' => crmDeskPublicUrl('api/index.php'),
            'modules' => function_exists('company_url') ? company_url('select-module') : '/select-module.php',
            'salesCustomers' => function_exists('company_url')
                ? company_url('modules/sales/customers/index.php') . '?module=sales'
                : (function_exists('app_url')
                    ? app_url('/modules/sales/customers/index.php') . '?module=sales'
                    : '/modules/sales/customers/index.php?module=sales'),
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function crmDeskMarketPayload(): array
{
    require_once __DIR__ . '/crm-market-bridge.php';
    require_once __DIR__ . '/crm-market-nav.php';

    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : (int) ($_SESSION['company_id'] ?? 0);
    $company = function_exists('getCurrentCompany') ? (getCurrentCompany() ?: []) : [];
    $pdo = null;
    try {
        $pdo = crmDeskBootstrap();
    } catch (Throwable $e) {
        $pdo = null;
    }

    $status = crmMarketStatus($companyId);
    $rawView = strtolower(trim((string) ($_GET['view'] ?? 'home')));
    $view = crmMarketCurrentView();
    $embedPath = crmMarketViewPath($rawView);
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $leads = ($view === 'home' || $view === 'history') && $userId > 0
        ? crmMarketListLeadsForUser($userId, 100, '', $companyId)
        : crmMarketListLeads(100, '', null, $companyId);
    $imported = ($pdo instanceof PDO && $companyId > 0)
        ? crmMarketImportedIds($pdo, $companyId)
        : [];
    $importedMap = array_fill_keys($imported, true);
    foreach ($leads as &$lead) {
        $lead['imported'] = isset($importedMap[$lead['id']]);
    }
    unset($lead);

    require_once __DIR__ . '/crm-sales-bridge.php';
    crmSalesBridgeLoadDeps();

    return [
        'page' => 'market',
        'company' => [
            'id' => $companyId,
            'name' => (string) ($company['company_name'] ?? ($_SESSION['company_name'] ?? 'Company')),
        ],
        'user' => crmDeskCurrentUser(),
        'defaults' => crmSalesBridgeCustomerFormDefaults(),
        'options' => customerDeskFormOptions(),
        'statuses' => crmSalesBridgeContactStatuses(),
        'market' => [
            'status' => $status,
            'leads' => $leads,
            'imported_ids' => $imported,
            'view' => $view,
            'embed_path' => $embedPath,
        ],
        'links' => [
            'dashboard' => crmDeskDashboardUrl(),
            'customersList' => crmDeskCustomersListUrl(),
            'prospectsList' => crmDeskProspectsListUrl(),
            'market' => crmDeskMarketUrl(),
            'api' => crmDeskPublicUrl('api/index.php'),
            'clientMarketApp' => $status['app_url'],
            'contactViewBase' => crmDeskPublicUrl('my-clients/view.php') . '?module=crm',
            'nothingAnimation' => crmDeskAppAssetUrl('/assets/animations/nothing.lottie'),
            'searchAnimation' => crmDeskAppAssetUrl('/assets/animations/Search.lottie'),
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function crmContactViewFetchPayload(PDO $pdo, int $contactId): array
{
    require_once __DIR__ . '/crm-sales-bridge.php';
    crmSalesBridgeLoadDeps();

    $companyId = function_exists('currentCompanyId') ? (int) currentCompanyId() : (int) ($_SESSION['company_id'] ?? 0);
    if ($companyId <= 0 || $contactId <= 0) {
        throw new RuntimeException('Contact id is required.');
    }

    $contact = crmEngineGetContact($pdo, $companyId, $contactId);
    if ($contact === null) {
        throw new RuntimeException('Contact not found.');
    }

    $viewerId = crmDeskScopeUserId();
    if ($viewerId !== null && $viewerId > 0) {
        crmSalesBridgeAssertUserContactAccess($pdo, $contact, $viewerId);
    }

    $contact = crmSalesBridgeAttachInvoiceTotals([$contact])[0] ?? $contact;
    $customerId = (int) ($contact['customer_id'] ?? 0);
    $sales = crmSalesBridgeFetchCustomerSalesDetail($customerId);

    $company = function_exists('getCurrentCompany') ? (getCurrentCompany() ?: []) : [];

    return [
        'page' => 'contact-view',
        'contactId' => $contactId,
        'company' => [
            'id' => $companyId,
            'name' => (string) ($company['company_name'] ?? ($_SESSION['company_name'] ?? 'Company')),
        ],
        'user' => crmDeskCurrentUser(),
        'contact' => $contact,
        'sales' => $sales,
        'links' => [
            'dashboard' => crmDeskDashboardUrl(),
            'customersList' => crmDeskCustomersListUrl(),
            'contactView' => crmDeskContactViewUrl($contactId),
            'api' => crmDeskPublicUrl('api/index.php'),
            'salesCustomers' => function_exists('company_url')
                ? company_url('modules/sales/customers/index.php') . '?module=sales'
                : (function_exists('app_url')
                    ? app_url('/modules/sales/customers/index.php') . '?module=sales'
                    : '/modules/sales/customers/index.php?module=sales'),
        ],
    ];
}

function crmDeskJsonResponse(bool $success, mixed $data = null, ?string $message = null, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
