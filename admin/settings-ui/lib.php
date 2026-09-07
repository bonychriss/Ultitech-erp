<?php

declare(strict_types=1);

/**
 * Asset/URL helpers for the React Admin Settings hub.
 */

function adminSettingsUiBootstrap(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    require_once dirname(__DIR__, 2) . '/includes/config.php';
    require_once dirname(__DIR__, 2) . '/includes/functions.php';
    $booted = true;
}

function adminSettingsUiRequireAccess(): void
{
    adminSettingsUiBootstrap();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    requireAdmin();
    if (function_exists('ensureSystemSettingsSchema')) {
        ensureSystemSettingsSchema();
    }
    $_SESSION['active_module'] = 'settings';
}

function adminSettingsUiWebBasePath(): string
{
    if (function_exists('app_url')) {
        return rtrim((string) app_url('/admin'), '/');
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== '') {
        $dir = rtrim(dirname($script), '/');
        if (preg_match('#^(.*?)/[A-Za-z0-9-]+/admin$#', $dir, $m)) {
            return rtrim($m[1] . '/admin', '/') ?: '/admin';
        }
        if (substr($dir, -6) === '/admin') {
            return $dir;
        }
    }
    return '/admin';
}

function adminSettingsUiPublicUrl(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return adminSettingsUiWebBasePath() . '/settings-ui/' . $relativePath;
}

function adminSettingsUiQs(array $extra = []): string
{
    $qs = http_build_query(array_merge($_GET ?: [], $extra));
    return $qs === '' ? '' : ('?' . $qs);
}

/**
 * @return array{assetBase:string,apiUrl:string,cssFile:string,jsFile:string,cssVersion:string,jsVersion:string}|null
 */
function adminSettingsUiLoadReactAssets(): ?array
{
    $uiDir = __DIR__ . '/frontend';
    $distIndex = $uiDir . '/dist/index.html';
    if (!is_file($distIndex)) {
        return null;
    }

    $distHtml = file_get_contents($distIndex) ?: '';
    preg_match('/src="\.\/assets\/([^"]+\.js)"/', $distHtml, $jsMatch);
    preg_match('/href="\.\/assets\/([^"]+\.css)"/', $distHtml, $cssMatch);
    $jsFile = $jsMatch[1] ?? '';
    $cssFile = $cssMatch[1] ?? '';
    if ($jsFile === '') {
        return null;
    }

    $cssPath = $uiDir . '/dist/assets/' . $cssFile;
    $jsPath = $uiDir . '/dist/assets/' . $jsFile;

    return [
        'assetBase' => adminSettingsUiPublicUrl('frontend/dist/assets/'),
        'apiUrl' => adminSettingsUiPublicUrl('api'),
        'cssFile' => $cssFile,
        'jsFile' => $jsFile,
        'cssVersion' => is_file($cssPath) ? (string) filemtime($cssPath) : (string) time(),
        'jsVersion' => is_file($jsPath) ? (string) filemtime($jsPath) : (string) time(),
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function adminSettingsUiHubCards(): array
{
    $qs = adminSettingsUiQs([]);
    $qsSettings = adminSettingsUiQs(['module' => 'settings']);
    $qsSales = adminSettingsUiQs(['module' => 'sales']);

    // management.php treats module=settings as AI settings - never pass that for company tools.
    $mgmtQsParams = [];
    $hubCompanySlug = strtolower(trim((string) ($_GET['company_slug'] ?? '')));
    $hubCompanyId = (int) ($_GET['company_id'] ?? 0);
    if ($hubCompanySlug !== '') {
        $mgmtQsParams['company_slug'] = $hubCompanySlug;
    }
    if ($hubCompanyId > 0) {
        $mgmtQsParams['company_id'] = $hubCompanyId;
    }
    $mgmtQs = $mgmtQsParams === [] ? '' : ('?' . http_build_query($mgmtQsParams));

    $listUsersHubUrl = 'list-company-users.php' . ($hubCompanySlug !== '' ? '?company=' . rawurlencode($hubCompanySlug) : '');
    $aiHubParams = array_filter([
        'module' => 'settings',
        'company_slug' => $hubCompanySlug !== '' ? $hubCompanySlug : null,
        'company_id' => $hubCompanyId > 0 ? $hubCompanyId : null,
    ]);
    $aiHubUrl = 'management.php?' . http_build_query($aiHubParams);

    $cards = [
        [
            'id' => 'company',
            'title' => 'Company settings',
            'description' => 'Profile, branding, modules, tax and numbering per company.',
            'href' => 'company-settings.php' . $qs,
            'accent' => '#2563eb',
            'icon' => 'building',
            'superAdminOnly' => false,
        ],
        [
            'id' => 'register_company',
            'title' => 'Register new company',
            'description' => 'Create a company tenant with default module setup.',
            'href' => '#register-company',
            'accent' => '#1d4ed8',
            'icon' => 'plus-square',
            'action' => 'register_company',
            'systemAdminOnly' => true,
        ],
        [
            'id' => 'company_management',
            'title' => 'Company management',
            'description' => 'Monitor companies and switch active company context.',
            'href' => 'management.php' . $mgmtQs,
            'accent' => '#0f766e',
            'icon' => 'sitemap',
            'systemAdminOnly' => true,
        ],
        [
            'id' => 'company_users',
            'title' => 'Company users & emails',
            'description' => 'All tenant users and login emails per company (login index).',
            'href' => $listUsersHubUrl,
            'accent' => '#6366f1',
            'icon' => 'users',
            'systemAdminOnly' => true,
        ],
        [
            'id' => 'sync_login',
            'title' => 'Sync login index',
            'description' => 'Rebuild user_company_index from tenant databases.',
            'href' => 'sync-user-company-index.php',
            'accent' => '#7c3aed',
            'icon' => 'sync',
            'systemAdminOnly' => true,
        ],
        [
            'id' => 'ai',
            'title' => 'AI Integration',
            'description' => 'System-wide OpenAI key, usage limits, and connection test.',
            'href' => $aiHubUrl,
            'accent' => '#10b981',
            'icon' => 'robot',
            'superAdminOnly' => true,
        ],
        [
            'id' => 'register_employee',
            'title' => 'Register employee',
            'description' => 'Add team members with roles and departments.',
            'href' => 'register_employee.php' . $qs,
            'accent' => '#2b2f42',
            'icon' => 'user-plus',
            'superAdminOnly' => false,
        ],
        [
            'id' => 'whatsapp',
            'title' => 'WhatsApp group',
            'description' => 'WhatsApp link for voucher sharing and notifications.',
            'href' => 'whatsapp-settings.php' . $qs,
            'accent' => '#128C7E',
            'icon' => 'whatsapp',
            'badge' => 'New',
            'superAdminOnly' => false,
        ],
        [
            'id' => 'time',
            'title' => 'Time & format',
            'description' => 'Timezone, 12/24 hour display, and time overrides.',
            'href' => 'time-settings.php' . $qs,
            'accent' => '#2563eb',
            'icon' => 'clock',
            'superAdminOnly' => false,
        ],
        [
            'id' => 'attendance',
            'title' => 'Attendance',
            'description' => 'Work hours, grace periods, and attendance options.',
            'href' => '../attendance/settings.php' . $qs,
            'accent' => '#2563eb',
            'icon' => 'calendar-check',
            'superAdminOnly' => false,
        ],
        [
            'id' => 'sales',
            'title' => 'Sales settings',
            'description' => 'Quotations, catalog display, and company logo for sales docs.',
            'href' => '../modules/sales/settings/index.php' . $qsSales,
            'accent' => '#3b82f6',
            'icon' => 'cart',
            'superAdminOnly' => false,
        ],
        [
            'id' => 'stock_purchase',
            'title' => 'Stock purchase & vouchers',
            'description' => 'Set PO type (Internal / Abroad), link Stock Purchase payment vouchers, and route to Finance approval.',
            'href' => 'stock-purchase-settings.php' . $qsSettings,
            'accent' => '#a855f7',
            'icon' => 'invoice',
            'superAdminOnly' => false,
        ],
        [
            'id' => 'email',
            'title' => 'Email SMTP settings',
            'description' => 'SMTP/IMAP servers and the system mailbox used by payroll, sales, purchases, and more.',
            'href' => 'email-settings.php' . $qs,
            'accent' => '#a855f7',
            'icon' => 'mail',
            'superAdminOnly' => false,
        ],
        [
            'id' => 'factory_reset',
            'title' => 'Factory Reset',
            'description' => 'Wipe all operational transactions, stock logs, attendance, and uploaded files for this company.',
            'href' => '#factory-reset',
            'accent' => '#dc2626',
            'icon' => 'trash',
            'action' => 'factory_reset',
            'danger' => true,
            'superAdminOnly' => false,
        ],
    ];

    $isSuper = function_exists('isSuperAdmin') && isSuperAdmin();
    $isSystemAdmin = function_exists('isUltimateSystemAdmin') && isUltimateSystemAdmin();
    return array_values(array_filter($cards, static function (array $card) use ($isSuper, $isSystemAdmin): bool {
        if (!empty($card['systemAdminOnly'])) {
            return $isSystemAdmin;
        }
        return empty($card['superAdminOnly']) || $isSuper;
    }));
}

/**
 * @return array<string,mixed>
 */
function adminSettingsUiInitPayload(): array
{
    $systemFontKey = function_exists('getSystemFontKey') ? getSystemFontKey() : 'dm_sans';
    $systemFontCatalog = function_exists('getSystemFontCatalog') ? getSystemFontCatalog() : [];
    $systemFontDef = function_exists('getSystemFontDefinition')
        ? getSystemFontDefinition($systemFontKey)
        : ['label' => 'Poppins', 'stack' => "'Poppins', sans-serif", 'google' => ''];

    $fonts = [];
    foreach ($systemFontCatalog as $fontId => $fontMeta) {
        $fonts[] = [
            'id' => (string) $fontId,
            'label' => (string) ($fontMeta['label'] ?? $fontId),
            'stack' => (string) ($fontMeta['stack'] ?? ''),
            'google' => (string) ($fontMeta['google'] ?? ''),
        ];
    }

    $flash = null;
    if (!empty($_SESSION['flash_message'])) {
        $flash = [
            'type' => (($_SESSION['flash_type'] ?? '') === 'error') ? 'error' : 'success',
            'message' => (string) $_SESSION['flash_message'],
        ];
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
    }

    $companyName = defined('COMPANY_NAME') ? (string) COMPANY_NAME : 'Company';

    $hubCompanySlug = strtolower(trim((string) ($_GET['company_slug'] ?? '')));
    $hubCompanyId = (int) ($_GET['company_id'] ?? 0);
    $mgmtQsParams = [];
    if ($hubCompanySlug !== '') {
        $mgmtQsParams['company_slug'] = $hubCompanySlug;
    }
    if ($hubCompanyId > 0) {
        $mgmtQsParams['company_id'] = $hubCompanyId;
    }
    $mgmtQs = $mgmtQsParams === [] ? '' : ('?' . http_build_query($mgmtQsParams));

    return [
        'companyName' => $companyName,
        'todayLabel' => date('l, d M Y'),
        'isSuperAdmin' => function_exists('isSuperAdmin') && isSuperAdmin(),
        'isSystemAdmin' => function_exists('isUltimateSystemAdmin') && isUltimateSystemAdmin(),
        'flash' => $flash,
        'font' => [
            'current' => $systemFontKey,
            'label' => (string) ($systemFontDef['label'] ?? 'Poppins'),
            'stack' => (string) ($systemFontDef['stack'] ?? "'Poppins', sans-serif"),
            'catalog' => $fonts,
        ],
        'cards' => adminSettingsUiHubCards(),
        'registerCompany' => [
            'formAction' => 'management.php' . $mgmtQs,
            'returnTo' => 'settings.php' . adminSettingsUiQs([]),
            'companiesUrl' => 'management.php' . $mgmtQs,
        ],
        'api' => [
            'saveFont' => adminSettingsUiPublicUrl('api/save-font.php'),
            'factoryReset' => adminSettingsUiPublicUrl('api/factory-reset.php'),
        ],
    ];
}

/**
 * @return array{ok:bool,message:string,truncated?:int}
 */
function adminSettingsUiExecuteFactoryReset(string $confirmText): array
{
    global $pdo;

    if (strtolower(trim($confirmText)) !== 'reset') {
        return ['ok' => false, 'message' => 'Confirmation failed. Please type exactly "RESET" to confirm.'];
    }

    $companyId = (int) (currentCompanyId() ?? 0);
    if ($companyId <= 0) {
        return ['ok' => false, 'message' => 'Unable to resolve company context.'];
    }

    if (!($pdo instanceof PDO)) {
        return ['ok' => false, 'message' => 'Database connection unavailable.'];
    }

    try {
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $exclude = [
            'users', 'companies', 'company_settings', 'system_settings',
            'attendance_settings', 'payroll_settings', 'payroll_tax_bands',
            'sales_settings', 'erp_settings', 'document_layouts',
            'email_templates', 'erp_email_templates', 'language_translations',
            'migrations', 'failed_jobs', 'jobs', 'sessions',
            'erp_users', 'erp_roles', 'erp_user_roles', 'erp_departments',
            'branches',
        ];

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $truncatedCount = 0;
        foreach ($tables as $table) {
            if (in_array(strtolower((string) $table), $exclude, true)) {
                continue;
            }
            $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', (string) $table) . '`');
            $truncatedCount++;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $tenantStorage = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tenant_' . $companyId;
        if (is_dir($tenantStorage)) {
            $deleteDir = static function ($dir) use (&$deleteDir) {
                if (!is_dir($dir)) {
                    return false;
                }
                $items = array_diff(scandir($dir) ?: [], ['.', '..']);
                foreach ($items as $item) {
                    $path = $dir . DIRECTORY_SEPARATOR . $item;
                    if (is_dir($path)) {
                        $deleteDir($path);
                    } else {
                        @unlink($path);
                    }
                }
                return @rmdir($dir);
            };
            $deleteDir($tenantStorage);
        }

        return [
            'ok' => true,
            'message' => 'Factory reset completed successfully. Cleared ' . $truncatedCount . ' operational tables and all uploaded files.',
            'truncated' => $truncatedCount,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Factory reset failed: ' . $e->getMessage()];
    }
}

function adminSettingsUiShellHeadExtras(): string
{
    $parts = [
        '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">',
    ];

    if (function_exists('app_url')) {
        $erpStylePath = dirname(__DIR__, 2) . '/assets/css/style.css';
        $erpStyleVer = is_file($erpStylePath) ? (int) filemtime($erpStylePath) : time();
        $parts[] = '<link rel="stylesheet" href="' . htmlspecialchars(app_url('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $erpStyleVer . '">';
        if (function_exists('erp_dark_theme_css_url')) {
            $parts[] = '<link rel="stylesheet" id="erp-dark-theme" href="' . htmlspecialchars(erp_dark_theme_css_url(), ENT_QUOTES, 'UTF-8') . '">';
        }
    }

    if (function_exists('renderSystemFontHeadMarkup')) {
        ob_start();
        renderSystemFontHeadMarkup();
        $fontMarkup = trim((string) ob_get_clean());
        if ($fontMarkup !== '') {
            $parts[] = $fontMarkup;
        }
    }

    return implode("\n    ", $parts);
}

function adminSettingsRenderReactShell(): void
{
    $assets = adminSettingsUiLoadReactAssets();
    if ($assets === null) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Settings hub</title></head><body style="font-family:sans-serif;padding:2rem;">';
        echo '<h1>Settings hub</h1>';
        echo '<p>Run <code>npm install</code> and <code>npm run build</code> in <code>admin/settings-ui/frontend/</code>.</p>';
        echo '</body></html>';
        exit;
    }

    $page_title = 'Settings hub';
    $employeeHeaderTitle = 'Settings hub';
    $hideHeaderCompanyBranding = true;
    $employeeHeaderExtraClass = 'employee-header--exp-desk';
    $init = adminSettingsUiInitPayload();
    $cfgJson = json_encode($init, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($cfgJson === false) {
        $cfgJson = '{}';
    }

    $GLOBALS['_erp_header_style_linked'] = true;
    require __DIR__ . '/react-shell.php';
}
